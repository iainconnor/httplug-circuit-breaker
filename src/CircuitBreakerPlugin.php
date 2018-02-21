<?php

namespace IainConnor\CircuitBreaker;

use Http\Client\Common\Plugin;
use IainConnor\CircuitBreaker\FailureIdentifier\ResponseFailureIdentifier;
use IainConnor\CircuitBreaker\Identifier\ServiceIdentifier;
use IainConnor\CircuitBreaker\Listener\CircuitBreakerListener;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CircuitBreakerPlugin implements Plugin
{
    const EVENT_REQUEST_FAILURE = 'FAIL';
    const EVENT_REQUEST_SUCCESS = 'SUCCESS';
    const EVENT_REQUEST_REJECTION = 'REJECT';
    const STATUS_CLOSED = 'CLOSED';
    const STATUS_CLOSING = 'CLOSING';
    const STATUS_OPEN = 'OPEN';

    /** @var CacheItemPoolInterface Metrics are stored in this cache. */
    private $cache;

    /** @var CircuitBreakerListener[] A set of listeners that receive notifications when circuits change status. */
    private $listeners;

    /** @var ResponseFailureIdentifier A Class that identifies Responses and Exceptions that represent failures in calling a service. */
    private $responseFailureIdentifier;

    /** @var ServiceIdentifier A Class that generates identifiers for the service behind a Request. */
    private $serviceIdentifier;

    /** @var bool Whether this breaker is enabled or not. A disabled breaker will still log theoretical actions, but will never actually reject a request. */
    private $enabled;

    /** @var int Failure rate threshold, as a percentage. */
    private $failureThreshold;

    /** @var int Minimum number of requests before any change can be considered. */
    private $minRequests;

    /** @var \DateInterval The duration a series of requests should be considered for. */
    private $considerationDuration;

    /**
     * CircuitBreakerPlugin constructor.
     *
     * @param CacheItemPoolInterface    $cache                     Metrics are stored in this cache.
     * @param ResponseFailureIdentifier $responseFailureIdentifier A Class that identifies Responses and Exceptions
     *                                                             that represent failures in calling a service.
     * @param ServiceIdentifier         $serviceIdentifier         A Class that generates identifiers for the service
     *                                                             behind a Request.
     * @param int                       $failureThreshold          Failure rate threshold, as a percentage.
     * @param int                       $minRequests               Minimum number of requests before any change can be
     *                                                             considered.
     * @param \DateInterval|null        $considerationDuration     The duration a series of requests should be
     *                                                             considered for.
     * @param bool                      $enabled                   Whether this breaker is enabled or not. A disabled
     *                                                             breaker will still log theoretical actions, but will
     *                                                             never actually reject a request.
     * @param CircuitBreakerListener[]  $listeners                 A set of listeners that receive notifications when
     *                                                             circuits change status.
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        ResponseFailureIdentifier $responseFailureIdentifier,
        ServiceIdentifier $serviceIdentifier,
        int $failureThreshold = 50,
        int $minRequests = 3,
        ?\DateInterval $considerationDuration = null,
        bool $enabled = true,
        array $listeners = []
    ) {
        $this->cache                     = $cache;
        $this->responseFailureIdentifier = $responseFailureIdentifier;
        $this->serviceIdentifier         = $serviceIdentifier;
        $this->failureThreshold          = $failureThreshold;
        $this->minRequests               = $minRequests;
        $this->considerationDuration     = $considerationDuration ?: new \DateInterval('PT15M');
        $this->enabled                   = $enabled;
        $this->listeners                 = $listeners;
    }

    /**
     * @inheritDoc
     */
    public function handleRequest(RequestInterface $request, callable $next, callable $first)
    {
        $serviceIdentifier = $this->serviceIdentifier->getServiceIdentifierForRequest($request);
        $status            = $this->getStatus($serviceIdentifier);

        if ($status === CircuitBreakerPlugin::STATUS_OPEN) {
            if ($this->enabled) {
                foreach ($this->listeners as $listener) {
                    $listener->onRequestRejected($serviceIdentifier, $this->getStatus($serviceIdentifier), $request);
                }

                throw new OpenCircuitException($serviceIdentifier, $request);
            } else {
                foreach ($this->listeners as $listener) {
                    $listener->onRequestTheoreticallyRejected(
                        $serviceIdentifier,
                        $this->getStatus($serviceIdentifier),
                        $request
                    );
                }
            }
        }

        return $next($request)->then(function (ResponseInterface $response) use ($serviceIdentifier, $status) {
            $event = $this->responseFailureIdentifier->isResponseFailure($response, $serviceIdentifier) ?
                CircuitBreakerPlugin::EVENT_REQUEST_FAILURE :
                CircuitBreakerPlugin::EVENT_REQUEST_SUCCESS;

            $this->handleTransition(
                $serviceIdentifier,
                $status,
                $this->recordEvent($event, $serviceIdentifier)
            );

            return $response;
        }, function (\Throwable $throwable) use ($serviceIdentifier, $status) {
            if ($this->responseFailureIdentifier->isExceptionFailure($throwable, $serviceIdentifier)) {
                $this->handleTransition(
                    $serviceIdentifier,
                    $status,
                    $this->recordEvent(CircuitBreakerPlugin::EVENT_REQUEST_FAILURE, $serviceIdentifier)
                );
            }
        });
    }

    public function getStatus(string $serviceIdentifier): string
    {
        $stats = $this->getStats($serviceIdentifier);

        $hasEnoughRequests = $stats->getRequestsSentToService() >= $this->minRequests;
        $hasEnoughFailures = $stats->getFailureRatio() >= $this->failureThreshold;

        if ($hasEnoughRequests && $hasEnoughFailures) {
            return CircuitBreakerPlugin::STATUS_OPEN;
        }

        return CircuitBreakerPlugin::STATUS_CLOSED;
    }

    public function getStats(string $serviceIdentifier): CircuitBreakerStats
    {
        $item = $this->cache->getItem($this->getCacheKey($serviceIdentifier));

        if ($item->isHit()) {
            return $item->get();
        }

        return new CircuitBreakerStats();
    }

    /**
     * Resets all metrics stored for the given service.
     *
     * @param string $serviceIdentifier
     */
    public function reset(string $serviceIdentifier): void
    {
        $this->cache->deleteItem($this->getCacheKey($serviceIdentifier));
    }

    /**
     * @param int $failureThreshold Failure rate threshold, as a percentage.
     */
    public function setFailureThreshold(int $failureThreshold)
    {
        $this->failureThreshold = $failureThreshold;
    }

    /**
     * @param int $minRequests Minimum number of requests before any change can be considered.
     */
    public function setMinRequests(int $minRequests)
    {
        $this->minRequests = $minRequests;
    }

    /**
     * @param \DateInterval $considerationDuration The duration a series of requests should be considered for.
     */
    public function setConsiderationDuration(\DateInterval $considerationDuration)
    {
        $this->considerationDuration = $considerationDuration;
    }

    /**
     * Controls whether the circuit breaker is enabled or not.
     *
     * If disabled, stats will still be gathered and potential events will still be logged, but the circuit will never
     * be tripped.
     *
     * @param boolean $enabled
     */
    public function setEnabled(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    /**
     * Return if the breaker for the given service is closed AKA the breaker is NOT tripped AKA requests will be
     * allowed through.
     *
     * @param string $serviceIdentifier
     *
     * @return bool
     */
    public function isClosed(string $serviceIdentifier): bool
    {
        return $this->getStatus($serviceIdentifier) === CircuitBreakerPlugin::STATUS_CLOSED;
    }

    /**
     * Return if the breaker for the given service is open AKA the breaker is tripped AKA requests will be NOT allowed
     * through.
     *
     * @param string $serviceIdentifier
     *
     * @return bool
     */
    public function isOpen(string $serviceIdentifier): bool
    {
        return $this->getStatus($serviceIdentifier) === CircuitBreakerPlugin::STATUS_OPEN;
    }

    /**
     * Return if the breaker for the given service is in the process of closing AKA the breaker is partially tripped
     * AKA some requests will be allowed through.
     *
     * @param string $serviceIdentifier
     *
     * @return bool
     */
    public function isClosing(string $serviceIdentifier): bool
    {
        return $this->getStatus($serviceIdentifier) === CircuitBreakerPlugin::STATUS_CLOSING;
    }

    /**
     * Return if the breaker for the given service is closed AKA the breaker is NOT tripped AKA requests will be
     * allowed through.
     *
     * @param string $serviceIdentifier
     *
     * @return bool
     */
    public function isAllowingRequests(string $serviceIdentifier): bool
    {
        return $this->isClosed($serviceIdentifier) || $this->isClosing($serviceIdentifier);
    }

    /**
     * Return if the breaker for the given service is open AKA the breaker is tripped AKA requests will be NOT allowed
     * through.
     *
     * @param string $serviceIdentifier
     *
     * @return bool
     */
    public function isRejectingRequests(string $serviceIdentifier): bool
    {
        return $this->isOpen($serviceIdentifier);
    }

    /**
     * Return if the breaker for the given service is open AKA the breaker is tripped AKA requests will be NOT allowed
     * through.
     *
     * @param string $serviceIdentifier
     *
     * @return bool
     */
    public function isTripped(string $serviceIdentifier): bool
    {
        return $this->isOpen($serviceIdentifier);
    }

    /**
     * Adds a listener to be called when interesting events on circuit breakers happen.
     *
     * @param CircuitBreakerListener $listener
     */
    public function addListener(CircuitBreakerListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Adds a list of listeners to be called when interesting events on circuit breakers happen.
     *
     * @param CircuitBreakerListener[] $listeners
     */
    public function addListeners(array $listeners): void
    {
        $this->listeners = array_merge(array_values($listeners));
    }

    /**
     * Sets the list of listeners to be called when interesting events on circuit breakers happen.
     *
     * @param CircuitBreakerListener[] $listeners
     */
    public function setListeners(array $listeners): void
    {
        $this->listeners = $listeners;
    }

    /**
     * Record an occurrence of the given event for the given service.
     *
     * @param string $event
     * @param string $serviceIdentifier
     *
     * @return string The updated status after recording this event.
     */
    protected function recordEvent(string $event, string $serviceIdentifier): string
    {
        $this->increment($event, $this->getCacheKey($serviceIdentifier));

        return $this->getStatus($serviceIdentifier);
    }

    /**
     * Retrieve the cache key for the stats of the given service.
     *
     * @param string $serviceIdentifier
     *
     * @return string
     */
    private function getCacheKey(string $serviceIdentifier): string
    {
        return trim(CircuitBreakerPlugin::class, '/') . '/' . $serviceIdentifier;
    }

    /**
     * Increment the value for `event` in the cache at `key` by `val`.
     *
     * @param string $event
     * @param string $key
     * @param int    $val
     */
    private function increment(string $event, string $key, int $val = 1): void
    {
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            /** @var CircuitBreakerStats $stats */
            $stats = $item->get();
            switch ($event) {
                case CircuitBreakerPlugin::EVENT_REQUEST_SUCCESS:
                    $stats->setSuccesses(max(0, $stats->getSuccesses() + $val));
                    break;
                case CircuitBreakerPlugin::EVENT_REQUEST_FAILURE:
                    $stats->setFailures(max(0, $stats->getFailures() + $val));
                    break;
                case CircuitBreakerPlugin::EVENT_REQUEST_REJECTION:
                    $stats->setRejections(max(0, $stats->getRejections() + $val));
                    break;
            }
            $item->set($stats);
        } else {
            $item->set(
                new CircuitBreakerStats(
                    $event === CircuitBreakerPlugin::EVENT_REQUEST_SUCCESS ? max(0, $val) : 0,
                    $event === CircuitBreakerPlugin::EVENT_REQUEST_FAILURE ? max(0, $val) : 0,
                    $event === CircuitBreakerPlugin::EVENT_REQUEST_REJECTION ? max(0, $val) : 0
                )
            );
        }

        $item->expiresAfter($this->considerationDuration);
        $this->cache->save($item);
    }

    /**
     * Decrement the value for `event` in the cache at `key` by `val`.
     * Value in cache will not be allowed to go below 0.
     *
     * @param string $event
     * @param string $key
     * @param int    $val
     */
    private function decrement(string $event, string $key, int $val = 1): void
    {
        $this->increment($event, $key, $val * -1);
    }

    /**
     * Handle the transition from one status to another, calling any interested listeners.
     *
     * @param string $serviceIdentifier
     * @param string $previousStatus
     * @param string $newStatus
     */
    private function handleTransition(string $serviceIdentifier, string $previousStatus, string $newStatus): void
    {
        if ($previousStatus !== $newStatus) {
            $stats = $this->getStats($serviceIdentifier);

            foreach ($this->listeners as $listener) {
                switch ($newStatus) {
                    case CircuitBreakerPlugin::STATUS_CLOSED:
                        $listener->onBreakerReset($serviceIdentifier, $stats, $previousStatus);
                        break;
                    case CircuitBreakerPlugin::STATUS_OPEN:
                        if ($this->enabled) {
                            $listener->onBreakerTripped($serviceIdentifier, $stats, $previousStatus);
                        } else {
                            $listener->onBreakerTheoreticallyTripped($serviceIdentifier, $stats, $previousStatus);
                        }
                        break;
                    case CircuitBreakerPlugin::STATUS_CLOSING:
                        $listener->onBreakerClosing($serviceIdentifier, $stats);
                        break;
                }
            }
        }
    }
}