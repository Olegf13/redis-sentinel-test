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

$counter = 100;
while (true) {
    echo $sentinel->getMasterAddr(), \PHP_EOL;
    try {
        $redisHost = $sentinel->getRedis()->getHost();
        $setResult = $sentinel->getRedis()->set('some', $counter++);
        echo 'Set result is ', \var_export($setResult, true), \PHP_EOL;
    } catch (\RedisException $e) {
        echo 'We were trying to write into: ', $redisHost, \PHP_EOL;
        throw $e;
    }

    echo 'Key `some` value is: ', $sentinel->getRedis()->get('some'), \PHP_EOL;

//    try {
//        $blPopResult = $sentinel->getRedis()->blPop('some_list', 5);
//        echo 'BLPOP result: ', var_export($blPopResult, true), \PHP_EOL;
//    } catch (RedisException $e) {
//        if (\mb_strpos($e->getMessage(), 'read error on connection') !== 0) {
//            throw $e;
//        }
//        echo 'WARNING: ', $e->getMessage(), \PHP_EOL;
//    }

//    $result = $sentinel->getSentinel()->master('mymaster');
//    print_r($result);

    echo \PHP_EOL, \PHP_EOL;
    usleep(50 * 1000);
}
