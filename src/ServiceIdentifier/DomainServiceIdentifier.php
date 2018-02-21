<?php


namespace IainConnor\CircuitBreaker\ServiceIdentifier;


use IainConnor\CircuitBreaker\Identifier\ServiceIdentifier;
use Psr\Http\Message\RequestInterface;

class DomainServiceIdentifier implements ServiceIdentifier
{
    /**
     * @inheritDoc
     */
    public function getServiceIdentifierForRequest(RequestInterface $request): string
    {
        return $request->getUri()->getHost();
    }

}