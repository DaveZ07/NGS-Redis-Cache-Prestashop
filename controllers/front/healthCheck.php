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

class Ngs_RedisHealthCheckModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $token = Tools::getValue('token');
        $storedToken = Configuration::get('NGS_REDIS_CRON_TOKEN');
        
        if (!$storedToken || $token !== $storedToken) {
            header('HTTP/1.0 401 Unauthorized');
            die('Unauthorized');
        }

        parent::initContent();
        $this->ajax = true;

        $cache = Cache::getInstance();
        if ($cache instanceof \Ngs\Redis\Classes\Cache\CacheRedis) {
            try {
                $key = 'ngs_redis_health_check_' . time();
                $cache->set($key, 'OK', 60);
                $value = $cache->get($key);
                
                if ($value === 'OK') {
                    die(json_encode(['status' => 'ok', 'message' => 'Redis is working']));
                } else {
                    die(json_encode(['status' => 'error', 'message' => 'Redis write/read failed']));
                }
            } catch (Exception $e) {
                die(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
            }
        } else {
            die(json_encode(['status' => 'warning', 'message' => 'Redis cache is not active']));
        }
    }
}
