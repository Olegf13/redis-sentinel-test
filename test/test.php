<?php

declare(strict_types=1);

namespace Olegf13\Sentinel\Test;

require_once __DIR__ . '/../vendor/autoload.php';

use Olegf13\Sentinel\RedisSentinelConnectionParams;
use Olegf13\Sentinel\SentinelConnection;
use Psr\Log\Test\TestLogger;

$sentinelConnection = (new RedisSentinelConnectionParams('sentinel'))
    ->setTimeout(1.0)
    ->setRetryInterval(100)
    ->setReadTimeout(1.0);
$sentinel = new SentinelConnection(
    $sentinelConnection,
    SentinelConnection::DEFAULT_MASTER,
    new TestLogger()
);

try {
    $counter = 100;
    $redisHost = (string) $sentinel->getMasterAddr();
    echo <<<INTRO
Redis FAILOVER test.

Script will cause a failover every some random time, and will tell about it.
Use `Ctrl + C` to exit.

Redis host on execution start: {$redisHost}


INTRO;

    while (true) {
        if ($sentinel->getRedis()->set('some', $counter++) === false) {
            throw new \RedisException('Error! Redis SET failed for some reason');
        }
        $sentinel->getRedis()->get('some');
        \usleep(50 * 1000);
        if (\mt_rand() % \random_int(17, 173) === 0) {
            echo 'Failover started! ', 'Old Redis host: ', $sentinel->getMasterAddr(), ' ... ';
            $sentinel->forceFailover();
            echo 'Failover finished! ', 'Current Redis host: ', $sentinel->getMasterAddr(), \PHP_EOL;
        }
    }
} catch (\Throwable $e) {
    echo 'Exception: ', $e->getMessage(), \PHP_EOL;
}
