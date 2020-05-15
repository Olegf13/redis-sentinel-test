<?php

declare(strict_types=1);

namespace Olegf13\Sentinel;

/**
 * Redis connection params.
 */
class RedisConnectionParams
{
    /** @var string Host, or host with a schema, or path to a unix domain socket (e.g. "tls://127.0.0.1"). */
    private $host;
    /** @var int Optional (default is 6379). */
    private $port;
    /** @var float Timeout in seconds (default is 0 meaning unlimited). */
    private $timeout = 0.0;
    /** @var int Retry interval in milliseconds, i.e. delay between reconnection attempts (default is 0). */
    private $retryInterval = 0;
    /** @var float Read timeout in seconds (default is 0 meaning unlimited). */
    private $readTimeout = 0.0;

    /**
     * @param string $host Host, or host with a schema, or path to a unix domain socket (e.g. "tls://127.0.0.1").
     * @param int $port Optional (default is 6379).
     */
    public function __construct(string $host, int $port = 6379)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @param float $timeout Timeout in seconds (default is 0 meaning unlimited).
     * @return static
     */
    public function setTimeout(float $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * @param int $retryInterval Retry interval in milliseconds, i.e. delay between reconnection attempts (default is 0).
     * @return static
     */
    public function setRetryInterval(int $retryInterval): self
    {
        $this->retryInterval = $retryInterval;
        return $this;
    }

    /**
     * @param float $readTimeout Read timeout in seconds (default is 0 meaning unlimited).
     * @return static
     */
    public function setReadTimeout(float $readTimeout): self
    {
        $this->readTimeout = $readTimeout;
        return $this;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return float
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * @return int
     */
    public function getRetryInterval(): int
    {
        return $this->retryInterval;
    }

    /**
     * @return float
     */
    public function getReadTimeout(): float
    {
        return $this->readTimeout;
    }
}
