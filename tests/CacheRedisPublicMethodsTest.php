<?php

declare(strict_types=1);

define('_PS_VERSION_', '8.2.1');
define('_PS_MODULE_DIR_', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('_DB_PREFIX_', 'ps_');
define('_COOKIE_KEY_', 'test-cookie-key');

abstract class CacheCore
{
    protected $keys = [];

    public function get($key)
    {
        if (!isset($this->keys[$key])) {
            return false;
        }

        return $this->_get($key);
    }

    public function set($key, $value, $ttl = 0)
    {
        if ($this->_set($key, $value, $ttl)) {
            $this->keys[$key] = ($ttl == 0) ? 0 : time() + $ttl;
            $this->_writeKeys();

            return true;
        }

        return false;
    }

    public function exists($key)
    {
        if (!isset($this->keys[$key])) {
            return false;
        }

        return $this->_exists($key);
    }

    abstract protected function _get($key);

    abstract protected function _set($key, $value, $ttl = 0);

    abstract protected function _exists($key);

    abstract protected function _writeKeys();
}

require_once dirname(__DIR__) . '/classes/cache/CacheRedis.php';

use Ngs\Redis\Classes\Cache\CacheRedis;

final class FakeRedisClient
{
    private $values = [];

    public function set($key, $value)
    {
        $this->values[$key] = $value;

        return true;
    }

    public function setex($key, $ttl, $value)
    {
        return $this->set($key, $value);
    }

    public function get($key)
    {
        return $this->values[$key] ?? null;
    }

    public function exists($key)
    {
        return array_key_exists($key, $this->values) ? 1 : 0;
    }
}

function createCacheWithFakeClient(): array
{
    $reflection = new ReflectionClass(CacheRedis::class);
    /** @var CacheRedis $cache */
    $cache = $reflection->newInstanceWithoutConstructor();
    $client = new FakeRedisClient();

    $clientProperty = $reflection->getProperty('client');
    $clientProperty->setAccessible(true);
    $clientProperty->setValue($cache, $client);

    $connectedProperty = $reflection->getProperty('is_connected');
    $connectedProperty->setAccessible(true);
    $connectedProperty->setValue($cache, true);

    return [$cache, $client];
}

function seedBackend(CacheRedis $cache, $key, $value): void
{
    $writer = function ($key, $value): void {
        $this->_set($key, $value, 0);
    };

    $writer->call($cache, $key, $value);
}

function getLocalKeys(CacheRedis $cache): array
{
    $property = (new ReflectionClass(CacheCore::class))->getProperty('keys');
    $property->setAccessible(true);

    return $property->getValue($cache);
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function testGetReadsBackendKeyWhenLocalRegistryIsEmpty(): void
{
    [$cache] = createCacheWithFakeClient();
    seedBackend($cache, 'query_hash', ['rows' => [['id_product' => 42]]]);

    assertSameValue(
        ['rows' => [['id_product' => 42]]],
        $cache->get('query_hash'),
        'CacheRedis::get() must read Redis even when CacheCore keys[] is empty.'
    );
}

function testExistsReadsBackendKeyWhenLocalRegistryIsEmpty(): void
{
    [$cache] = createCacheWithFakeClient();
    seedBackend($cache, 'query_hash', ['rows' => []]);

    assertSameValue(
        true,
        $cache->exists('query_hash'),
        'CacheRedis::exists() must query Redis even when CacheCore keys[] is empty.'
    );
}

function testSetBypassesLocalRegistry(): void
{
    [$cache] = createCacheWithFakeClient();

    assertSameValue(true, $cache->set('query_hash', ['rows' => []], 60), 'CacheRedis::set() must write to Redis.');
    assertSameValue([], getLocalKeys($cache), 'CacheRedis::set() must not populate CacheCore keys[].');
}

$tests = [
    'testGetReadsBackendKeyWhenLocalRegistryIsEmpty',
    'testExistsReadsBackendKeyWhenLocalRegistryIsEmpty',
    'testSetBypassesLocalRegistry',
];

foreach ($tests as $test) {
    $test();
}

echo count($tests) . " tests passed.\n";
