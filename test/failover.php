<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Olegf13\Sentinel\RedisSentinelConnectionParams;
use Olegf13\Sentinel\SentinelConnection;

$sentinelConnection = (new RedisSentinelConnectionParams('sentinel'))
    ->setTimeout(1.0)
    ->setRetryInterval(100)
    ->setReadTimeout(1.0);
$sentinel = new SentinelConnection($sentinelConnection);

echo 'Redis master: ', $sentinel->getMasterAddr(), \PHP_EOL;
while ((string) $sentinel->getMasterAddr() !== '172.17.0.2:6379') {
    echo 'FAILOVER started!', \PHP_EOL, 'You will see changed Redis master IP in a moment', \PHP_EOL, \PHP_EOL;
    $sentinel->forceFailover();
}

echo 'Done!', \PHP_EOL;
