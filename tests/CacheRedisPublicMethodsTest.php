<?php

declare(strict_types=1);

define('_PS_VERSION_', '8.2.1');
define('_PS_MODULE_DIR_', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('_DB_PREFIX_', 'ps_');
define('_COOKIE_KEY_', 'test-cookie-key');

abstract class CacheCore
{
    protected $keys = [];
    protected $blacklist = [
        'cart',
        'cart_cart_rule',
        'cart_product',
        'connections',
        'connections_source',
        'connections_page',
        'customer',
        'customer_group',
        'customized_data',
        'guest',
        'pagenotfound',
        'page_viewed',
        'employee',
        'log',
    ];

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

    public function deleteQuery($query)
    {
        // The real core implementation uses its own table map, which CacheRedis does not populate.
    }

    abstract protected function _get($key);

    abstract protected function _set($key, $value, $ttl = 0);

    abstract protected function _exists($key);

    abstract protected function _writeKeys();
}

class Context
{
    public static function getContext()
    {
        return null;
    }
}

require_once dirname(__DIR__) . '/classes/cache/CacheRedis.php';

use Ngs\Redis\Classes\Cache\CacheRedis;

final class FakeRedisClient
{
    private $values = [];
    private $sets = [];

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
        return (array_key_exists($key, $this->values) || array_key_exists($key, $this->sets)) ? 1 : 0;
    }

    public function sadd($key, array $values)
    {
        if (!isset($this->sets[$key])) {
            $this->sets[$key] = [];
        }

        foreach ($values as $value) {
            $this->sets[$key][$value] = true;
        }

        return count($values);
    }

    public function smembers($key)
    {
        return array_keys($this->sets[$key] ?? []);
    }

    public function expire($key, $ttl)
    {
        return true;
    }

    public function del($keys)
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $deleted = 0;

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->values)) {
                unset($this->values[$key]);
                ++$deleted;
            }
            if (array_key_exists($key, $this->sets)) {
                unset($this->sets[$key]);
                ++$deleted;
            }
        }

        return $deleted;
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

function testCoreVolatileEmployeeQueriesAreNotCached(): void
{
    [$cache, $client] = createCacheWithFakeClient();
    $query = 'SELECT `id_last_order` FROM `ps_employee` WHERE `id_employee` = 1';

    $cache->setQuery($query, [['id_last_order' => 42]]);

    assertSameValue(
        0,
        $client->exists($cache->getQueryHash($query)),
        'Queries using PrestaShop core volatile tables must not be cached.'
    );
}

function testCacheRedisUsesTheBlacklistInheritedFromCacheCore(): void
{
    $property = (new ReflectionClass(CacheRedis::class))->getProperty('blacklist');

    assertSameValue(
        CacheCore::class,
        $property->getDeclaringClass()->getName(),
        'CacheRedis must inherit the core blacklist so future PrestaShop additions are preserved.'
    );
}

function testBlacklistDoesNotMatchUnprefixedSqlFragments(): void
{
    [$cache, $client] = createCacheWithFakeClient();
    $query = 'SELECT `catalog_visibility`, "employee" AS `label` FROM `ps_product`';

    $cache->setQuery($query, [['catalog_visibility' => 'both', 'label' => 'employee']]);

    assertSameValue(
        1,
        $client->exists($cache->getQueryHash($query)),
        'Blacklist entries must match prefixed table names, not arbitrary SQL fragments.'
    );
}

function testDeleteQueryInvalidatesRedisTagsForUpdatedTables(): void
{
    [$cache, $client] = createCacheWithFakeClient();
    $select = 'SELECT `name` FROM `ps_product` WHERE `id_product` = 42';
    $queryKey = $cache->getQueryHash($select);

    $cache->setQuery($select, [['name' => 'Before update']]);
    assertSameValue(1, $client->exists($queryKey), 'The query must be cached before invalidation.');

    $cache->deleteQuery('UPDATE `ps_product` SET `name` = "After update" WHERE `id_product` = 42');

    assertSameValue(
        0,
        $client->exists($queryKey),
        'CacheRedis::deleteQuery() must invalidate queries tagged with an updated table.'
    );
}

function testNotificationUpdateRemovesEmployeeCacheCreatedByPreviousVersion(): void
{
    [$cache, $client] = createCacheWithFakeClient();
    $staleQuery = 'SELECT `id_last_order` FROM `ps_employee` WHERE `id_employee` = 1';
    $queryKey = $cache->getQueryHash($staleQuery);

    seedBackend($cache, $queryKey, [['id_last_order' => 41]]);
    $client->sadd('tag:ps_employee', [$queryKey]);

    $cache->deleteQuery(
        'UPDATE `ps_employee` SET `id_last_order` = '
        . '(SELECT IFNULL(MAX(`id_order`), 0) FROM `ps_orders`) WHERE `id_employee` = 1'
    );

    assertSameValue(
        0,
        $client->exists($queryKey),
        'Updating notification state must remove stale employee cache created before this fix.'
    );
}

$tests = [
    'testGetReadsBackendKeyWhenLocalRegistryIsEmpty',
    'testExistsReadsBackendKeyWhenLocalRegistryIsEmpty',
    'testSetBypassesLocalRegistry',
    'testCoreVolatileEmployeeQueriesAreNotCached',
    'testCacheRedisUsesTheBlacklistInheritedFromCacheCore',
    'testBlacklistDoesNotMatchUnprefixedSqlFragments',
    'testDeleteQueryInvalidatesRedisTagsForUpdatedTables',
    'testNotificationUpdateRemovesEmployeeCacheCreatedByPreviousVersion',
];

foreach ($tests as $test) {
    $test();
}

echo count($tests) . " tests passed.\n";
