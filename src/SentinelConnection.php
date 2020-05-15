<?php

declare(strict_types=1);

namespace Olegf13\Sentinel;

/**
 * Redis Sentinel.
 */
class SentinelConnection
{
    /** @var int Sleep interval in microseconds (i.e. 50 ms). */
    private const SLEEP_INTERVAL = 50 * 1000;

    /**
     * @var \RedisSentinel Connection to Redis Sentinel.
     * @noinspection PhpUndefinedClassInspection
     */
    private $sentinel;

    /** @var \Redis Connection to specific Redis instance. */
    private $redis;

    /** @var string Observable Redis master name. */
    private $masterName;

    /** @var RedisSentinelConnectionParams */
    private $sentinelConnectionParams;

    /** @var RedisMasterAddr */
    private $currentMasterAddr;

    /**
     * @param RedisSentinelConnectionParams $connection
     * @param string $masterName
     * @throws \RedisException
     */
    public function __construct(RedisSentinelConnectionParams $connection, string $masterName = 'mymaster')
    {
        $this->sentinelConnectionParams = $connection;
        $this->initSentinelConnection();
        $this->setMasterName($masterName);
        $this->setRedis($this->discoverRedisMaster());
    }

    /**
     * Returns current Redis master instance.
     *
     * @return \Redis
     * @throws \RedisException in case if Redis master is unreachable.
     */
    public function getRedis(): \Redis
    {
        // Check if Redis master has changed after the previous method call.
        // If it's happened, we discover new Redis master connection from Sentinels.
        if ((string) $this->currentMasterAddr !== (string) $this->getMasterAddr()) {
            echo 'Redis master has changed after the previous call, so we need to reconnect...', \PHP_EOL;
            echo 'Current Redis master addr: ', \var_export($this->currentMasterAddr), \PHP_EOL;
            echo 'New Redis master addr: ', \var_export($this->getMasterAddr()), \PHP_EOL;
            $this->setRedis($this->discoverRedisMaster());
        }

        // Here, we need to use `PING` cmd each time before any other Redis command.
        // Otherwise, we'd need to `try ... catch` any Redis usage in code,
        // because we can fail on any ordinary cmd like `SET` or "long" `GET` (with time-consuming data receive),
        // and we'd get `RedisException: read error on connection to ...` during failovers.
        try {
            $this->redis->ping('');
        } catch (\RedisException $e) {
            echo 'Redis ping resulted in an exception (are we in a failover?)', \PHP_EOL;
            $this->setRedis($this->discoverRedisMaster());
        }

        return $this->redis;
    }

    /**
     * @param \Redis $redis
     */
    private function setRedis(\Redis $redis): void
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
     * @return \RedisSentinel
     * @noinspection PhpUndefinedClassInspection
     */
    public function getSentinel(): \RedisSentinel
    {
        return $this->sentinel;
    }

    /**
     * @return RedisMasterAddr
     * @throws \RedisException
     */
    public function getMasterAddr(): RedisMasterAddr
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $result = $this->getSentinel()->getMasterAddrByName($this->getMasterName());
        if ($result === false) {
            throw new \RedisException('Failed to get connection info for Redis master');
        }

        return RedisMasterAddr::fromArray($result);
    }

    /**
     * @throws \RedisException
     */
    private function initSentinelConnection(): void
    {
        $connection = $this->getSentinelConnectionParams();
        /** @noinspection PhpUndefinedClassInspection */
        $sentinel = new \RedisSentinel(
            $connection->getHost(),
            $connection->getPort(),
            $connection->getTimeout(),
            null,
            $connection->getRetryInterval(),
            $connection->getReadTimeout()
        );
        /** @noinspection PhpUndefinedMethodInspection */
        if ($sentinel->ping() === false) {
            throw new \RedisException('Connection to Sentinel failed');
        }

        $this->sentinel = $sentinel;
    }

    /**
     * @param string $masterName Master name (e.g. "mymaster").
     * @throws \RedisException if there is no monitored master with given name.
     */
    private function setMasterName(string $masterName): void
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $masters = \array_column($this->getSentinel()->masters(), 'name');
        if (!\in_array($masterName, $masters, true)) {
            throw new \RedisException('No Redis master with given name.');
        }

        $this->masterName = $masterName;
    }

    /**
     * Be careful with this method.
     * @throws \RedisException
     */
    public function forceFailover(): void
    {
        $this->failover();
        $this->setRedis($this->discoverRedisMaster());
    }

    /**
     * @return \Redis
     * @throws \RedisException in case when discovering Redis master failed.
     */
    private function discoverRedisMaster(): \Redis
    {
        $this->checkQuorum();

        $masterInfo = $this->getSentinel()->master($this->getMasterName());
        $sleepTimeUsMax = ($masterInfo['down-after-milliseconds'] * 1000);
        for ($i = 0; $i < $sleepTimeUsMax; $i += static::SLEEP_INTERVAL) {
            if ($masterInfo['flags'] === 'master') {
                break;
            }
            echo 'We need to wait for Redis master to finish failover. Flags: ', $masterInfo['flags'], \PHP_EOL;
            \usleep(static::SLEEP_INTERVAL);
            $masterInfo = $this->getSentinel()->master($this->getMasterName());
            continue;
        }

        $params = $this->getRedisConnectionParams($masterInfo);

        $redis = new \Redis();
        $connectResult = $redis->connect(
            $params->getHost(),
            $params->getPort(),
            $params->getTimeout(),
            null,
            $params->getRetryInterval(),
            $params->getReadTimeout()
        );
        if ($connectResult === false) {
            throw new \RedisException('Failed to connect to Redis master');
        }
        $this->currentMasterAddr = $this->getMasterAddr();

        return $redis;
    }

    /**
     * @return RedisSentinelConnectionParams
     */
    private function getSentinelConnectionParams(): RedisSentinelConnectionParams
    {
        return $this->sentinelConnectionParams;
    }

    /**
     * @param array $masterInfo
     * @return RedisConnectionParams
     */
    private function getRedisConnectionParams(array $masterInfo): RedisConnectionParams
    {
        $host = (string) $masterInfo['ip'];
        $port = (int) $masterInfo['port'];

        $sentinelParams = $this->getSentinelConnectionParams();
        $connectTimeout = $sentinelParams->getTimeout();
        $retryInterval = $sentinelParams->getRetryInterval();
        /** @var float Redis read timout will be equal to Sentinel's "master unreachable" timeout. */
        $readTimeout = \round((int) $masterInfo['down-after-milliseconds'] / 1000, 3, \PHP_ROUND_HALF_DOWN);

        return (new RedisConnectionParams($host, $port))
            ->setTimeout($connectTimeout)
            ->setRetryInterval($retryInterval)
            ->setReadTimeout($readTimeout);
    }

    /**
     * @throws \RedisException
     */
    private function checkQuorum(): void
    {
        /** @noinspection PhpUndefinedMethodInspection */
        if ($this->getSentinel()->ckquorum($this->getMasterName()) === false) {
            throw new \RedisException('Quorum check failed (majority of Sentinels are down)');
        }
    }

    /**
     * @throws \RedisException
     */
    private function failover(): void
    {
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $this->getSentinel()->failover($this->getMasterName());
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (\RedisException $e) {
            // log this
            echo 'Probably failover is already in progress', \PHP_EOL, $e->getMessage(), \PHP_EOL;
        }

        $masterInfo = $this->getSentinel()->master($this->getMasterName());
        $sleepTimeUsMax = ($masterInfo['down-after-milliseconds'] * 1000);
        for ($i = 0; $i < $sleepTimeUsMax; $i += static::SLEEP_INTERVAL) {
            // todo: update if to method (twice usage)
            if ((string) $this->currentMasterAddr !== (string) $this->getMasterAddr()) {
                break;
            }
            echo 'Waiting for Redis master addr to change...', $this->getMasterAddr(), \PHP_EOL;
            \usleep(static::SLEEP_INTERVAL);
        }
    }
}
