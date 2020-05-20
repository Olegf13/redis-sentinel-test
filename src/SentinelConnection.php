<?php

declare(strict_types=1);

namespace Olegf13\Sentinel;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Redis Sentinel.
 */
class SentinelConnection
{
    /** @var string Default Redis master name. */
    public const DEFAULT_MASTER = 'mymaster';

    /** @var int Sleep interval in microseconds (i.e. 50 ms). */
    private const SLEEP_INTERVAL = 50 * 1000;

    /** @var string Redis master flag which states that master is operating and not in a failover. */
    private const MASTER_FLAG_OPERATING = 'master';

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

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param RedisSentinelConnectionParams $connection
     * @param string $masterName
     * @param LoggerInterface|null $logger
     * @throws \RedisException
     */
    public function __construct(
        RedisSentinelConnectionParams $connection,
        string $masterName = self::DEFAULT_MASTER,
        LoggerInterface $logger = null
    ) {
        if ($logger === null) {
            $logger = new NullLogger();
        }
        $this->setLogger($logger);

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
        if ($this->isMasterHasChanged()) {
            $this->getLogger()->info(
                'Redis master has changed after the previous call, so we need to reconnect',
                [
                    'current_redis_master_addr' => (string) $this->currentMasterAddr,
                    'new_redis_master_addr' => (string) $this->getMasterAddr(),
                ]
            );
            $this->setRedis($this->discoverRedisMaster());
        }

        // Here, we need to use `PING` cmd each time before any other Redis command.
        // Otherwise, we'd need to `try ... catch` any Redis usage in code,
        // because we can fail on any ordinary cmd like `SET` or "long" `GET` (with time-consuming data receive),
        // and we'd get `RedisException: read error on connection to ...` during failovers.
        try {
            $this->redis->ping('');
        } catch (\RedisException $e) {
            $this->getLogger()->info('Redis PING resulted in an exception, probably we are in a failover');
            $this->setRedis($this->discoverRedisMaster());
        }

        return $this->redis;
    }

    /**
     * Returns Redis master address (host:port).
     * Supports `__toString()` conversion.
     *
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
     * Be careful with this method.
     * @throws \RedisException in case when discovering Redis master failed.
     */
    public function forceFailover(): void
    {
        $this->getLogger()->info('Triggered forced failover');
        $this->failover();
        $this->setRedis($this->discoverRedisMaster());
    }

    /**
     * @param LoggerInterface $logger
     */
    private function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return string Observable Redis master name.
     */
    private function getMasterName(): string
    {
        return $this->masterName;
    }

    /**
     * @param \Redis $redis
     */
    private function setRedis(\Redis $redis): void
    {
        $this->redis = $redis;
    }

    /**
     * @noinspection PhpUndefinedClassInspection
     * @return \RedisSentinel
     */
    private function getSentinel(): \RedisSentinel
    {
        return $this->sentinel;
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
        $this->getLogger()->debug('Successfully connected to Sentinel');
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
        $this->getLogger()->debug('Redis master name OK');
    }

    /**
     * @return array
     */
    private function getMasterInfo(): array
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return $this->getSentinel()->master($this->getMasterName());
    }

    /**
     * @return \Redis
     * @throws \RedisException in case when discovering Redis master failed.
     */
    private function discoverRedisMaster(): \Redis
    {
        $this->checkQuorum();

        if ($this->isMasterOperating() === false) {
            $this->getLogger()->debug(
                'Waiting for Redis master to finish failover',
                ['info' => $this->getMasterInfo()]
            );
            for ($i = 0; $i < $this->maxSleepTimeUs(); $i += static::SLEEP_INTERVAL) {
                \usleep(static::SLEEP_INTERVAL);
                if ($this->isMasterOperating()) {
                    $this->getLogger()->debug('Waiting for Redis master failover finished OK');
                    break;
                }
            }
        }

        $redis = $this->newRedisConnection();
        $this->currentMasterAddr = $this->getMasterAddr();

        return $redis;
    }

    /**
     * @return bool
     */
    private function isMasterOperating(): bool
    {
        $masterInfo = $this->getMasterInfo();

        return $masterInfo['flags'] === static::MASTER_FLAG_OPERATING;
    }

    /**
     * @return RedisSentinelConnectionParams
     */
    private function getSentinelConnectionParams(): RedisSentinelConnectionParams
    {
        return $this->sentinelConnectionParams;
    }

    /**
     * @return RedisConnectionParams
     */
    private function getRedisConnectionParams(): RedisConnectionParams
    {
        $masterInfo = $this->getMasterInfo();
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
     * @return \Redis
     * @throws \RedisException
     */
    private function newRedisConnection(): \Redis
    {
        $redis = new \Redis();
        $params = $this->getRedisConnectionParams();
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

        return $redis;
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
        $this->getLogger()->debug('Quorum check OK');
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
            $this->getLogger()->debug(
                'Probably failover is already in progress',
                ['exception' => $e]
            );
        }

        $this->getLogger()->debug('Waiting for Redis master addr to change...');
        for ($i = 0; $i < $this->maxSleepTimeUs(); $i += static::SLEEP_INTERVAL) {
            if ($this->isMasterHasChanged()) {
                $this->getLogger()->debug('Master addr changed OK');
                break;
            }
            \usleep(static::SLEEP_INTERVAL);
        }
        if (!$this->isMasterHasChanged()) {
            throw new \RedisException('Master addr failed to change during available sleep time');
        }
    }

    /**
     * @return bool
     * @throws \RedisException
     */
    private function isMasterHasChanged(): bool
    {
        return (string) $this->currentMasterAddr !== (string) $this->getMasterAddr();
    }

    /**
     * Max sleep time in microseconds (taken from `down-after-milliseconds` param value).
     *
     * @return int
     */
    private function maxSleepTimeUs(): int
    {
        $masterInfo = $this->getMasterInfo();
        return ($masterInfo['down-after-milliseconds'] * 1000);
    }

    /**
     * @return LoggerInterface
     */
    private function getLogger(): LoggerInterface
    {
        return $this->logger;
    }
}
