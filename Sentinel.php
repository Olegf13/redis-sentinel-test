<?php

declare(strict_types=1);

/**
 * Redis Sentinel.
 */
class Sentinel
{
    /** @var int Decribe me. */
    public const SLEEP_INTERVAL = 5;

    /** @var RedisSentinel Connection to Redis Sentinel. */
    private $sentinel;

    /** @var Redis Connection to specific Redis instance. */
    private $redis;

    /** @var string Observable Redis master name. */
    private $masterName;

    /**
     * @return Redis
     * @throws RedisException in case if Redis master is unreachable.
     */
    public function getRedis(): Redis
    {
        try {
            $this->redis->ping('');
        } catch (RedisException $e) {
            $this->setRedis($this->discoverRedisMaster(true));
        }

        return $this->redis;
    }

    /**
     * @param Redis $redis
     */
    private function setRedis(Redis $redis): void
    {
        $this->redis = $redis;
    }

    /**
     * @return string
     */
    public function getMasterName(): string
    {
        return $this->masterName;
    }

    /**
     * @return RedisSentinel
     */
    public function getSentinel(): RedisSentinel
    {
        return $this->sentinel;
    }

    /**
     * @return string
     * @throws RedisException
     */
    public function getRedisMasterHost(): string
    {
        $getMasterAddrResult = $this->getSentinel()->getMasterAddrByName($this->getMasterName());
        if ($getMasterAddrResult === false) {
            throw new RedisException('Failed to get connection info for Redis master');
        }
        return (string) $getMasterAddrResult[0];
    }

    /**
     * @param string $host Sentinel host
     * @param int $port (Optional)
     * @param string $masterName
     * @throws RedisException
     */
    public function __construct(string $host, int $port = 26379, string $masterName = 'mymaster')
    {
        $sentinel = new RedisSentinel($host, $port, 1.0, null, 100, 1.0);
        if ($sentinel->ping() === false) {
            throw new RedisException('Connection to Sentinel failed');
        }

        $this->masterName = $masterName;
        $this->sentinel = $sentinel;
        $this->setRedis($this->discoverRedisMaster());
    }

    /**
     * @param bool $forceFailover
     * @return Redis
     * @throws RedisException in case when discovering Redis master failed.
     */
    private function discoverRedisMaster(bool $forceFailover = false): Redis
    {
        $this->checkQuorum();

        if ($forceFailover && !$this->forceFailover()) {
            throw new RedisException('Failed to force a failover');
        }

        /** @var false|array $getMasterAddrResult */
        $getMasterAddrResult = $this->getSentinel()->getMasterAddrByName($this->getMasterName());
        if ($getMasterAddrResult === false) {
            throw new RedisException('Failed to get connection info for Redis master');
        }
        $host = $getMasterAddrResult[0];
        $port = (int) $getMasterAddrResult[1];

        /** @var float Value in seconds (optional, default is 0.0 meaning unlimited). */
        $connectTimeout = 2.5;
        /** @var null Should be null if $retryInterval is specified. */
        $reserved = null;
        /** @var int Retry interval in milliseconds. */
        $retryInterval = 100;
        /** @var float Value in seconds (optional, default is 0 meaning unlimited). */
        $readTimeout = 1.0;

        $redis = new Redis();
        $connectResult = $redis->connect($host, $port, $connectTimeout, $reserved, $retryInterval, $readTimeout);
        if ($connectResult === false) {
            throw new RedisException('Failed to connect to Redis master');
        }

        return $redis;
    }

    /**
     * @throws RedisException
     */
    private function checkQuorum(): void
    {
        if ($this->getSentinel()->ckquorum($this->getMasterName()) === false) {
            throw new RedisException('Quorum check failed (majority of Sentinels are down)');
        }
    }

    /**
     * @return bool
     */
    private function forceFailover(): bool
    {
        try {
            $this->getSentinel()->failover($this->getMasterName());
        } catch (RedisException $e) {
            // log this
            echo 'Probably failover is already in progress', \PHP_EOL, $e->getMessage(), \PHP_EOL;
        }
        sleep(5);

        // todo: check if master host has changed

        return true;
    }
}
