<?php

namespace IainConnor\CircuitBreaker;

use Http\Client\Exception\TransferException;
use Psr\Http\Message\RequestInterface;

class OpenCircuitException extends TransferException
{
    /** @var string */
    public $serviceIdentifier;

    /** @var RequestInterface */
    public $request;

    /**
     * CircuitBreakerTrippedException constructor.
     *
     * @param string           $serviceIdentifier
     * @param RequestInterface $request
     * @param int              $code
     * @param \Exception       $previous
     */
    public function __construct(
        string $serviceIdentifier,
        RequestInterface $request,
        int $code = 0,
        \Exception $previous = null
    ) {
        $this->serviceIdentifier = $serviceIdentifier;
        $this->request           = $request;

        parent::__construct('The request to ' . $request->getMethod() . ' ' . $request->getUri()->__toString() . '` was rejected due to an open circuit for service `' . $serviceIdentifier . '`.',
            $code, $previous);
    }
}