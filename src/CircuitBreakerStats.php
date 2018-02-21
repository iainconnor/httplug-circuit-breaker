<?php

namespace IainConnor\CircuitBreaker;

class CircuitBreakerStats
{
    /** @var int A count of successful calls to the service. */
    private $successes;

    /** @var int A count of failures in calling the service. */
    private $failures;

    /** @var int A count of rejected calls to the service. */
    private $rejections;

    /**
     * @param int $successes
     * @param int $failures
     * @param int $rejections
     */
    public function __construct($successes = 0, $failures = 0, $rejections = 0)
    {
        $this->successes  = $successes;
        $this->failures   = $failures;
        $this->rejections = $rejections;
    }

    /**
     * @return int
     */
    public function getSuccesses(): int
    {
        return $this->successes;
    }

    /**
     * @param int $successes
     */
    public function setSuccesses(int $successes)
    {
        $this->successes = $successes;
    }

    /**
     * @return int
     */
    public function getFailures(): int
    {
        return $this->failures;
    }

    /**
     * @param int $failures
     */
    public function setFailures(int $failures)
    {
        $this->failures = $failures;
    }

    /**
     * @return int
     */
    public function getRejections(): int
    {
        return $this->rejections;
    }

    /**
     * @param int $rejections
     */
    public function setRejections(int $rejections)
    {
        $this->rejections = $rejections;
    }

    /**
     * @return int
     */
    public function getAttemptedRequests(): int
    {
        return $this->successes + $this->failures;
    }

    /**
     * @return int
     */
    public function getSuccessRatio(): int
    {
        return round(($this->successes / $this->getAttemptedRequests()) * 100);
    }

    /**
     * @return int
     */
    public function getFailureRatio(): int
    {
        return 100 - $this->getSuccessRatio();
    }

    public function __toString(): string
    {
        return
            'successes ' . $this->successes . ', ' .
            'failures ' . $this->failures . ', ' .
            'rejections ' . $this->rejections;
    }

    public function toArray(): array
    {
        return [
            'successes' => $this->successes,
            'failures' => $this->failures,
            'rejections' => $this->rejections
        ];
    }
}