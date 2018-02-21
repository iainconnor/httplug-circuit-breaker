<?php


namespace IainConnor\CircuitBreaker\Listener;


use IainConnor\CircuitBreaker\CircuitBreakerStats;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

class LoggingCircuitBreakerListener implements CircuitBreakerListener
{
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function onBreakerReset(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        string $previousStatus
    ): void
    {
        $this->logger->info('The circuit breaker for service `' . $serviceIdentifier . '` has been reset. Requests will be allowed to this service again.', [
            'identifier' => $serviceIdentifier,
            'previous_status' => $previousStatus,
            'breaker_stats' => $breakerStats->toArray()
        ]);
    }

    /**
     * @inheritDoc
     */
    public function onBreakerTripped(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        string $previousStatus
    ): void
    {
        $this->logger->critical('The circuit breaker for service `' . $serviceIdentifier . '` has been tripped! No further requests to this service will be allowed until the breaker is reset.', [
            'identifier' => $serviceIdentifier,
            'previous_status' => $previousStatus,
            'breaker_stats' => $breakerStats->toArray()
        ]);
    }

    /**
     * @inheritDoc
     */
    public function onBreakerTheoreticallyTripped(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        string $previousStatus
    ): void
    {
        $this->logger->warning('The circuit breaker for service `' . $serviceIdentifier . '` would have been tripped, but the breaker was not enabled. Had the breaker been enabled, no further requests to this service will be allowed until the breaker was reset.', [
            'identifier' => $serviceIdentifier,
            'previous_status' => $previousStatus,
            'breaker_stats' => $breakerStats->toArray()
        ]);
    }

    /**
     * @inheritDoc
     */
    public function onRequestRejected(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        RequestInterface $request
    ): void
    {
        $this->logger->notice('A request to service `' . $serviceIdentifier . '` has been rejected due to a tripped breaker.', [
            'identifier' => $serviceIdentifier,
            'request' => $request->getMethod() . ' ' . $request->getUri()->__toString(),
            'breaker_stats' => $breakerStats->toArray()
        ]);
    }

    /**
     * @inheritDoc
     */
    public function onRequestTheoreticallyRejected(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        RequestInterface $request
    ): void
    {
        $this->logger->info('A request to service `' . $serviceIdentifier . '` would have been rejected due to a tripped breaker, but the breaker is not enabled.', [
            'identifier' => $serviceIdentifier,
            'request' => $request->getMethod() . ' ' . $request->getUri()->__toString(),
            'breaker_stats' => $breakerStats->toArray()
        ]);
    }

    /**
     * @inheritDoc
     */
    public function onBreakerClosing(string $serviceIdentifier, CircuitBreakerStats $breakerStats): void
    {
        $this->logger->debug('The circuit breaker for service `' . $serviceIdentifier . '` is closing. Some requests will be allowed to this service while it is tested.', [
            'identifier' => $serviceIdentifier,
            'breaker_stats' => $breakerStats->toArray()
        ]);
    }
}