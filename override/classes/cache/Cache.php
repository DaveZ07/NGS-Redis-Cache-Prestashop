<?php
/**
 * NGS Redis Cache
 *
 * @author    davez (https://github.com/DaveZ07)
 * @copyright Copyright © 2025 NGS Software. All rights reserved.
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class Cache extends CacheCore
{

    public static function getInstance()
    {
        if (!self::$instance) {
            $caching_system = _PS_CACHING_SYSTEM_;
            
            if ($caching_system === 'Redis' || $caching_system === 'CacheRedis') {
                if (!class_exists(\Ngs\Redis\Classes\Cache\CacheRedis::class)) {
                    $autoloadPath = _PS_MODULE_DIR_ . 'ngs_redis/vendor/autoload.php';
                    if (file_exists($autoloadPath)) {
                        require_once $autoloadPath;
                    }
                    $classPath = _PS_MODULE_DIR_ . 'ngs_redis/classes/cache/CacheRedis.php';
                    if (file_exists($classPath)) {
                        require_once $classPath;
                    }
                }
                
                if (class_exists(\Ngs\Redis\Classes\Cache\CacheRedis::class)) {
                    $caching_system = \Ngs\Redis\Classes\Cache\CacheRedis::class;
                }
            }

            self::$instance = new $caching_system();
        }

        return self::$instance;
    }
}
