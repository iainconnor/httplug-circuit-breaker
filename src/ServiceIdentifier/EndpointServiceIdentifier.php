<?php


namespace IainConnor\CircuitBreaker\ServiceIdentifier;


use IainConnor\CircuitBreaker\Identifier\ServiceIdentifier;
use Psr\Http\Message\RequestInterface;

class EndpointServiceIdentifier implements ServiceIdentifier
{
    /**
     * @inheritDoc
     */
    public function getServiceIdentifierForRequest(RequestInterface $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri()->getScheme() . '://' . rtrim($request->getUri()->getHost(), '/') . '/' . trim($request->getUri()->getPath(), '/');
    }

}