<?php


namespace IainConnor\CircuitBreaker\Identifier;


use Psr\Http\Message\RequestInterface;

interface ServiceIdentifier
{
    /**
     * Generate the identifier for the service behind the given request.
     *
     * @param RequestInterface $request
     *
     * @return string
     */
    public function getServiceIdentifierForRequest(RequestInterface $request): string;
}