<?php


namespace IainConnor\CircuitBreaker\FailureIdentifier;


use Http\Client\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

class StandardResponseFailureIdentifier implements ResponseFailureIdentifier
{
    /**
     * @inheritDoc
     */
    public function isResponseFailure(ResponseInterface $response, string $serviceIdentifier): bool
    {
        return $response->getStatusCode() >= 500 && $response->getStatusCode() <= 599;
    }

    /**
     * @inheritDoc
     */
    public function isExceptionFailure(\Throwable $throwable, string $serviceIdentifier): bool
    {
        return $throwable instanceof TransferException;
    }

}