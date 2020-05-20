<?php

declare(strict_types=1);

namespace Olegf13\Sentinel;

/**
 * Redis master address value object.
 */
class RedisMasterAddr
{
    /** @var int Default Redis port. */
    public const DEFAULT_PORT = 6379;

    /** @var string Host, or host with a schema, or path to a unix domain socket (e.g. "tls://127.0.0.1"). */
    private $host;
    /** @var int Optional (default is 6379). */
    private $port;

    /**
     * @param string $host Host, or host with a schema, or path to a unix domain socket (e.g. "tls://127.0.0.1").
     * @param int $port Optional (default is 6379).
     */
    public function __construct(string $host, int $port = self::DEFAULT_PORT)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @param array $params
     * @return static
     */
    public static function fromArray(array $params): self
    {
        $host = $params[0] ?? null;
        if (($host === null) || !is_string($host)) {
            throw new \InvalidArgumentException('Redis host is missing.');
        }
        $port = static::DEFAULT_PORT;
        if (isset($params[1])) {
            $port = (int) $params[1];
        }

        return new self((string) $host, $port);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->host . ':' . $this->port;
    }

}
