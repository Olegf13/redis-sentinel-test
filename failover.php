<?php

declare(strict_types=1);

require_once __DIR__ . '/Sentinel.php';

$sentinel = new Sentinel('sentinel', 26379);

$sentinel->getSentinel()->failover($sentinel->getMasterName());
echo 'FAILOVER started!', \PHP_EOL, 'You will see changed Redis master IP in a moment', \PHP_EOL, \PHP_EOL;

$counter = 100;
while (true) {
    echo 'Redis master: ', $sentinel->getRedisMasterHost(), \PHP_EOL;
    $sentinel->getRedis()->set('some', $counter++);
    echo 'Key `some` value is: ', $sentinel->getRedis()->get('some'), \PHP_EOL;
    echo \PHP_EOL;
    sleep(1);
}
