<?php

namespace IainConnor\CircuitBreaker;

use Http\Client\Common\Plugin;
use IainConnor\CircuitBreaker\FailureIdentifier\FailureIdentifier;
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

    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var CircuitBreakerListener[] */
    private $listeners;

    /** @var FailureIdentifier */
    private $failureIdentifier;

    /** @var ServiceIdentifier */
    private $serviceIdentifier;

    /** @var bool */
    private $enabled;

    /**
     * CircuitBreakerPlugin constructor.
     *
     * @param CacheItemPoolInterface   $cache
     * @param FailureIdentifier        $failureIdentifier
     * @param ServiceIdentifier        $serviceIdentifier
     * @param bool                     $enabled
     * @param CircuitBreakerListener[] $listeners
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        FailureIdentifier $failureIdentifier,
        ServiceIdentifier $serviceIdentifier,
        bool $enabled = true,
        array $listeners = []
    ) {
        $this->cache             = $cache;
        $this->failureIdentifier = $failureIdentifier;
        $this->serviceIdentifier = $serviceIdentifier;
        $this->enabled           = $enabled;
        $this->listeners         = $listeners;
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
            $event = $this->failureIdentifier->isResponseFailure($response, $serviceIdentifier) ?
                CircuitBreakerPlugin::EVENT_REQUEST_FAILURE :
                CircuitBreakerPlugin::EVENT_REQUEST_SUCCESS;

            $this->handleTransition(
                $serviceIdentifier,
                $status,
                $this->recordEvent($event, $serviceIdentifier)
            );

            return $response;
        }, function (\Throwable $throwable) use ($serviceIdentifier, $status) {
            if ($this->failureIdentifier->isExceptionFailure($throwable, $serviceIdentifier)) {
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
        // @TODO
    }

    public function getStats(string $serviceIdentifier): CircuitBreakerStats
    {
        // @TODO
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
        $this->increment($this->getCacheKey($event, $serviceIdentifier));

        return $this->getStatus($serviceIdentifier);
    }

    /**
     * Retrieve the cache key for the given event type in the given service.
     *
     * @param string $event
     * @param string $serviceIdentifier
     *
     * @return string
     */
    private function getCacheKey(string $event, string $serviceIdentifier): string
    {
        return CircuitBreakerPlugin::class . '/' . $serviceIdentifier . '/' . $event;
    }

    /**
     * Increment the value in the cache at `key` by `val`.
     *
     * @param     $key
     * @param int $val
     */
    private function increment($key, $val = 1): void
    {
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            $val = $item->get() + $val;
        }

        $item->set(max(0, $val));

        $this->cache->save($item);
    }

    /**
     * Decrement the value in the cache at `key` by `val`.
     * Value in cache will not be allowed to go below 0.
     *
     * @param     $key
     * @param int $val
     */
    private function decrement($key, $val = 1): void
    {
        $this->increment($key, $val * -1);
    }

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