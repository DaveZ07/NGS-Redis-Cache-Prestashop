<?php
/**
 * NGS Redis Cache
 *
 * @author    davez (https://github.com/DaveZ07)
 * @copyright Copyright © 2025 NGS Software. All rights reserved.
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

// Ensure autoloader is loaded
if (file_exists(_PS_MODULE_DIR_ . 'ngs_redis/vendor/autoload.php')) {
    require_once _PS_MODULE_DIR_ . 'ngs_redis/vendor/autoload.php';
}

// Manually register autoloader for module classes if not already registered
spl_autoload_register(function ($class) {
    if (strpos($class, 'Ngs\\Redis\\Classes\\') === 0) {
        $relative = substr($class, strlen('Ngs\\Redis\\Classes\\'));
        $file = _PS_MODULE_DIR_ . 'ngs_redis/classes/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Ngs\Redis\Classes\Cache\CacheRedis as NgsCacheRedis;

class CacheRedis extends NgsCacheRedis
{
    public function __construct()
    {
        parent::__construct();
    }
}
