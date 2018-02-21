<?php

namespace IainConnor\CircuitBreaker\Listener;

use IainConnor\CircuitBreaker\CircuitBreakerStats;
use Psr\Http\Message\RequestInterface;

interface CircuitBreakerListener
{
    /**
     * Called when the breaker is reset AKA the circuit is re-closed AKA requests will be allowed through.
     *
     * @param string              $serviceIdentifier
     * @param CircuitBreakerStats $breakerStats
     * @param string              $previousStatus
     */
    public function onBreakerReset(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        string $previousStatus
    ): void;

    /**
     * Called when the breaker is tripped AKA the circuit is opened AKA requests will NOT be allowed through.
     *
     * @param string              $serviceIdentifier
     * @param CircuitBreakerStats $breakerStats
     * @param string              $previousStatus
     */
    public function onBreakerTripped(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        string $previousStatus
    ): void;

    /**
     * Called when the breaker would have been tripped AKA the circuit is opened AKA requests will NOT be allowed
     * through, but was not because the breaker is not enabled.
     *
     * @param string              $serviceIdentifier
     * @param CircuitBreakerStats $breakerStats
     * @param string              $previousStatus
     */
    public function onBreakerTheoreticallyTripped(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        string $previousStatus
    ): void;

    /**
     * Called when a request through the circuit breaker is rejected because the breaker is tripped AKA the circuit is
     * open.
     *
     * @param string              $serviceIdentifier
     * @param CircuitBreakerStats $breakerStats
     * @param RequestInterface    $request
     */
    public function onRequestRejected(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        RequestInterface $request
    ): void;

    /**
     * Called when a request through the circuit breaker would rejected because the breaker is tripped AKA the circuit
     * is open, but is not actually rejected because the breaker is not enabled.
     *
     * @param string              $serviceIdentifier
     * @param CircuitBreakerStats $breakerStats
     * @param RequestInterface    $request
     */
    public function onRequestTheoreticallyRejected(
        string $serviceIdentifier,
        CircuitBreakerStats $breakerStats,
        RequestInterface $request
    ): void;

    /**
     * Called when the breaker is in the process of being closed.
     *
     * @param string              $serviceIdentifier
     * @param CircuitBreakerStats $breakerStats
     */
    public function onBreakerClosing(string $serviceIdentifier, CircuitBreakerStats $breakerStats): void;
}