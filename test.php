<?php

declare(strict_types=1);

require_once __DIR__ . '/Sentinel.php';

$sentinel = new Sentinel('sentinel', 26379);

$counter = 100;
while (true) {
    echo $sentinel->getRedisMasterHost(), \PHP_EOL;
    $sentinel->getRedis()->set('some', $counter++);
    echo 'Key `some` value is: ', $sentinel->getRedis()->get('some'), \PHP_EOL;

    echo \PHP_EOL, \PHP_EOL;
    sleep(1);
}
