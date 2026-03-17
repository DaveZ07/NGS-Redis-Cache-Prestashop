<?php
/**
 * NGS Redis Cache
 *
 * @author    davez (https://github.com/DaveZ07)
 * @copyright Copyright © 2025 NGS Software. All rights reserved.
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace Ngs\Redis\Classes\Cache;

if (!defined('_PS_VERSION_')) {
    exit;
}

use CacheCore;
use Predis\Client;

class CacheRedis extends CacheCore
{
    protected const CACHE_PAYLOAD_VERSION = 1;
    protected const DEFAULT_QUERY_TTL = 604800;

    protected $client;
    protected $is_connected = false;
    protected $blacklist = [];
    protected $blacklist_controllers = [];
    protected $prefix = 'ngs_';
    protected $options = [];
    protected $query_ttl = self::DEFAULT_QUERY_TTL;

    public function __construct()
    {
        $this->connect();
    }

    public function connect()
    {
        $configFile = _PS_MODULE_DIR_ . 'ngs_redis/config/redis.php';

        if (file_exists($configFile)) {
            $settings = require $configFile;
        } else {
            $settings = [
                'connection_type' => 'single',
                'host' => '127.0.0.1',
                'port' => 6379,
                'auth' => '',
                'db' => 0,
                'prefix' => 'ngs_',
                'blacklist' => [],
                'blacklist_controllers' => [],
                'query_ttl' => self::DEFAULT_QUERY_TTL,
            ];
        }

        $this->blacklist = $settings['blacklist'] ?? [];
        $this->blacklist_controllers = $settings['blacklist_controllers'] ?? [];
        $this->prefix = $settings['prefix'] ?? 'ngs_';
        $this->query_ttl = max(0, (int) ($settings['query_ttl'] ?? self::DEFAULT_QUERY_TTL));
        $this->options = [
            'disable_order_page' => $settings['disable_order_page'] ?? false,
            'disable_checkout' => $settings['disable_checkout'] ?? false,
            'disable_webservice' => $settings['disable_webservice'] ?? false,
            'disable_product_listing' => $settings['disable_product_listing'] ?? false,
        ];

        if (isset($settings['connection_type']) && $settings['connection_type'] === 'sentinel') {
            $sentinels = $settings['sentinel_hosts'] ?? [];
            $options = [
                'replication' => 'sentinel',
                'service' => $settings['sentinel_service'] ?? 'mymaster',
                'prefix' => $this->prefix,
            ];

            if (!empty($settings['auth'])) {
                $options['parameters'] = [
                    'password' => $settings['auth'],
                    'database' => $settings['db'] ?? 0,
                ];
            } else {
                $options['parameters'] = [
                    'database' => $settings['db'] ?? 0,
                ];
            }

            try {
                $this->client = new Client($sentinels, $options);
                $this->client->connect();
                $this->is_connected = $this->client->isConnected();
            } catch (\Throwable $e) {
                $this->is_connected = false;
            }
        } elseif (isset($settings['connection_type']) && $settings['connection_type'] === 'cluster') {
            $nodes = $settings['cluster_nodes'] ?? [];
            $options = [
                'cluster' => 'redis',
                'prefix' => $this->prefix,
            ];

            if (!empty($settings['auth'])) {
                $options['parameters'] = [
                    'password' => $settings['auth'],
                ];
            }

            try {
                $this->client = new Client($nodes, $options);
                $this->client->connect();
                $this->is_connected = $this->client->isConnected();
            } catch (\Throwable $e) {
                $this->is_connected = false;
            }
        } else {
            // Single connection
            if (!empty($settings['unix_socket'])) {
                $config = [
                    'scheme' => 'unix',
                    'path' => $settings['unix_socket'],
                    'database' => $settings['db'] ?? 0,
                ];
            } else {
                $config = [
                    'scheme' => 'tcp',
                    'host'   => $settings['host'] ?? '127.0.0.1',
                    'port'   => $settings['port'] ?? 6379,
                    'database' => $settings['db'] ?? 0,
                ];
            }

            if (!empty($settings['auth'])) {
                $config['password'] = $settings['auth'];
            }

            try {
                $this->client = new Client($config, ['prefix' => $this->prefix]);
                $this->client->connect();
                $this->is_connected = $this->client->isConnected();
            } catch (\Throwable $e) {
                $this->is_connected = false;
            }
        }
    }

    public function setQuery($query, $result)
    {
        if (!$this->is_connected) {
            return;
        }

        // Check options
        $context = \Context::getContext();
        if ($context && isset($context->controller)) {
            try {
                $controllerName = $context->controller->php_self ?? '';

                if ($this->options['disable_order_page'] && ($controllerName === 'order' || $controllerName === 'order-opc')) {
                    return;
                }
                if ($this->options['disable_checkout'] && in_array($controllerName, ['order', 'order-opc', 'cart'])) {
                    return;
                }
                if ($this->options['disable_webservice'] && defined('_PS_WEBSERVICE_PATH_')) {
                    return;
                }
                if ($this->options['disable_product_listing'] && in_array($controllerName, ['category', 'manufacturer', 'supplier', 'prices-drop', 'new-products', 'best-sales', 'search'])) {
                    return;
                }

                // Check generic blacklist controllers
                if (!empty($this->blacklist_controllers) && in_array($controllerName, $this->blacklist_controllers)) {
                    return;
                }
            } catch (\Throwable $e) {
                // Context or controller not fully initialized, ignore controller-specific rules
            }
        }

        // Check blacklist
        foreach ($this->blacklist as $table) {
            if (stripos($query, $table) !== false) {
                return;
            }
        }

        $key = $this->getQueryHash($query);
        if ($this->_set($key, $result, $this->query_ttl)) {
            $tables = $this->extractTables($query);
            foreach ($tables as $table) {
                $tagKey = 'tag:' . $table;
                $this->client->sadd($tagKey, [$key]);
                if ($this->query_ttl > 0) {
                    // Keep query keys and their tag index on the same retention window.
                    $this->client->expire($tagKey, $this->query_ttl);
                }
            }
        }
    }

    public function getQueryHash($query)
    {
        return md5($query);
    }

    protected function extractTables($query)
    {
        // Simple regex to extract table names from SQL
        preg_match_all('/(?:FROM|JOIN)\s+[`]?(' . _DB_PREFIX_ . '[a-zA-Z0-9_]+)[`]?/i', $query, $matches);
        
        if (!empty($matches[1])) {
            return array_unique($matches[1]);
        }
        return [];
    }

    public function invalidateTags(array $tables)
    {
        if (!$this->is_connected) {
            return;
        }

        foreach ($tables as $table) {
            $tagKey = 'tag:' . $table;

            // Get all keys associated with this table
            $keys = $this->client->smembers($tagKey);
            if (!empty($keys)) {
                $this->client->del($keys);
            }
            $this->client->del([$tagKey]);
        }
    }

    protected function _set($key, $value, $ttl = 0)
    {
        if (!$this->is_connected) {
            return false;
        }

        $encoded = $this->encodeCachePayload($value);
        if ($encoded === false) {
            return false;
        }

        if ($ttl === 0) {
            return $this->client->set($key, $encoded);
        }

        return $this->client->setex($key, $ttl, $encoded);
    }

    protected function _get($key)
    {
        if (!$this->is_connected) {
            return false;
        }

        $value = $this->client->get($key);
        if ($value === null || $value === false) {
            return false;
        }

        $success = false;
        $decoded = $this->decodeSignedPayload($value, $success);
        if ($success) {
            return $decoded;
        }

        $decoded = $this->decodeLegacyPayload($value, $success);
        if ($success) {
            return $decoded;
        }

        return false;
    }

    protected function encodeCachePayload($value)
    {
        try {
            $serialized = serialize($value);
            $payload = base64_encode($serialized);
            $encoded = json_encode([
                'v' => self::CACHE_PAYLOAD_VERSION,
                'p' => $payload,
                'm' => hash_hmac('sha256', $payload, $this->getPayloadSigningKey()),
            ]);
        } catch (\Throwable $e) {
            return false;
        }

        return is_string($encoded) ? $encoded : false;
    }

    protected function decodeSignedPayload($value, &$success)
    {
        $success = false;
        $envelope = json_decode($value, true);

        if (
            !is_array($envelope)
            || ($envelope['v'] ?? null) !== self::CACHE_PAYLOAD_VERSION
            || !isset($envelope['p'], $envelope['m'])
            || !is_string($envelope['p'])
            || !is_string($envelope['m'])
        ) {
            return false;
        }

        $expectedMac = hash_hmac('sha256', $envelope['p'], $this->getPayloadSigningKey());
        if (!hash_equals($expectedMac, $envelope['m'])) {
            return false;
        }

        $payload = base64_decode($envelope['p'], true);
        if ($payload === false) {
            return false;
        }

        $decoded = $this->unserializePayload($payload);
        if (!$this->wasUnserializeSuccessful($payload, $decoded)) {
            return false;
        }

        $success = true;

        return $decoded;
    }

    protected function decodeLegacyPayload($value, &$success)
    {
        $success = false;
        $decoded = $this->unserializePayload($value, ['allowed_classes' => false]);

        if (!$this->wasUnserializeSuccessful($value, $decoded)) {
            return false;
        }

        if ($this->containsObject($decoded)) {
            return false;
        }

        $success = true;

        return $decoded;
    }

    protected function unserializePayload($payload, array $options = [])
    {
        set_error_handler(static function () {
            return true;
        });

        try {
            if (empty($options)) {
                return unserialize($payload);
            }

            return unserialize($payload, $options);
        } catch (\Throwable $e) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    protected function wasUnserializeSuccessful($payload, $decoded)
    {
        return $decoded !== false || $payload === 'b:0;';
    }

    protected function containsObject($value)
    {
        if (is_object($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->containsObject($item)) {
                return true;
            }
        }

        return false;
    }

    protected function getPayloadSigningKey()
    {
        return _COOKIE_KEY_ . '|' . static::class . '|' . $this->prefix;
    }

    protected function _exists($key)
    {
        if (!$this->is_connected) {
            return false;
        }

        return (bool)$this->client->exists($key);
    }

    protected function _delete($key)
    {
        if (!$this->is_connected) {
            return false;
        }

        return (bool)$this->client->del($key);
    }

    protected function _writeKeys()
    {
        // Not needed for Redis
    }

    public function flush()
    {
        if (!$this->is_connected) {
            return false;
        }

        return $this->client->flushdb();
    }
}
