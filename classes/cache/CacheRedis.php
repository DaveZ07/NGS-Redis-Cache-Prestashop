<?php
/**
 * NGS Redis Cache
 *
 * @author    davez (https://github.com/DaveZ07)
 * @copyright 2024 davez.ovh - All rights reserved
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace Ngs\Redis\Classes\Cache;

if (!defined('_PS_VERSION_')) {
    exit;
}

use CacheCore;
use Predis\Client;
use Tools;

class CacheRedis extends CacheCore
{
    protected $client;
    protected $is_connected = false;

    public function __construct()
    {
        $this->connect();
    }

    protected $blacklist = [];
    protected $blacklist_controllers = [];
    protected $prefix = 'ngs_';
    protected $options = [];

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
            ];
        }

        $this->blacklist = $settings['blacklist'] ?? [];
        $this->blacklist_controllers = $settings['blacklist_controllers'] ?? [];
        $this->prefix = $settings['prefix'] ?? 'ngs_';
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
                $controllerType = $context->controller->controller_type;
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
        $this->_set($key, $result);

        // Tagging logic
        $tables = $this->extractTables($query);
        foreach ($tables as $table) {
            $this->client->sadd('tag:' . $table, [$key]);
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
            // SMEMBERS returns an array of members
            $keys = $this->client->smembers($tagKey);
            
            if (!empty($keys)) {
                $this->client->del($keys);
                $this->client->del([$tagKey]);
            }
        }
    }


    protected function _set($key, $value, $ttl = 0)
    {
        if (!$this->is_connected) {
            return false;
        }
        if ($ttl === 0) {
            return $this->client->set($key, serialize($value));
        }
        return $this->client->setex($key, $ttl, serialize($value));
    }

    protected function _get($key)
    {
        if (!$this->is_connected) {
            return false;
        }
        $value = $this->client->get($key);
        return $value ? unserialize($value) : false;
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
