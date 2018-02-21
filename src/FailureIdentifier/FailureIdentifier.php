<?php


namespace IainConnor\CircuitBreaker\FailureIdentifier;


use Psr\Http\Message\ResponseInterface;

interface FailureIdentifier
{
    /**
     * Return whether the given response is considered a failure for the given service.
     *
     * @param ResponseInterface $response
     * @param string            $serviceIdentifier
     *
     * @return bool
     */
    public function isResponseFailure(ResponseInterface $response, string $serviceIdentifier): bool;

    /**
     * Return whether the given throwable/exception is considered a failure for the given service.
     *
     * @param \Throwable $throwable
     * @param string     $serviceIdentifier
     *
     * @return bool
     */
    public function isExceptionFailure(\Throwable $throwable, string $serviceIdentifier): bool;
}