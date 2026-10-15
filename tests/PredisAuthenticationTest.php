<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function checkAuth($connection, array $expected): void
{
    $property = new ReflectionProperty(Predis\Connection\AbstractConnection::class, 'initCommands');
    $property->setAccessible(true);
    $actual = [];
    foreach ($property->getValue($connection) as $command) {
        if ($command->getId() === 'AUTH') {
            $actual[] = $command->getArguments();
        }
    }
    if ($actual !== $expected) {
        throw new RuntimeException('Incorrect AUTH command arguments.');
    }
}

$cases = [
    [['username' => 'acl-user', 'password' => 'test-password'], [['acl-user', 'test-password']]],
    [['password' => 'test-password'], [['test-password']]],
    [['username' => '', 'password' => 'test-password'], [['test-password']]],
    [[], []],
    [['username' => '0', 'password' => '0'], [['0', '0']]],
];

foreach ($cases as [$credentials, $auth]) {
    foreach (['tcp', 'unix'] as $scheme) {
        $client = new Predis\Client($credentials + ['scheme' => $scheme, 'path' => '/tmp/test.sock']);
        checkAuth($client->getConnection(), $auth);
    }

    $client = new Predis\Client(['tcp://127.0.0.1:6379'], ['cluster' => 'redis', 'parameters' => $credentials]);
    checkAuth($client->getConnection()->getConnectionById('127.0.0.1:6379'), $auth);
    // Newly discovered cluster nodes also inherit credentials from the factory.
    checkAuth($client->getOptions()->connections->create(['host' => '127.0.0.2']), $auth);

    $client = new Predis\Client(['tcp://127.0.0.1:26379'], [
        'replication' => 'sentinel', 'service' => 'mymaster',
        'parameters' => $credentials + ['database' => 2],
    ]);
    // Predis must not send data-node credentials to the Sentinel itself.
    checkAuth($client->getConnection()->getSentinelConnection(), []);
    // Discovered master/replica connections use this same factory.
    checkAuth($client->getOptions()->connections->create(['host' => '127.0.0.2', 'role' => 'master']), $auth);
}

echo "5 Predis authentication scenarios passed (TCP, Unix, cluster and Sentinel).\n";
