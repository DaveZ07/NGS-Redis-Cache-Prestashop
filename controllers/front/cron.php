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

class Ngs_RedisCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        // Security check
        $token = Tools::getValue('token');
        $storedToken = Configuration::get('NGS_REDIS_CRON_TOKEN');
        
        if (!$storedToken || $token !== $storedToken) {
            header('HTTP/1.0 401 Unauthorized');
            die('Unauthorized');
        }

        parent::initContent();
        $this->ajax = true;

        $type = Tools::getValue('type');

        switch ($type) {
            case 'clear':
                $this->clearCache();
                break;
            default:
                die(json_encode(['status' => 'error', 'message' => 'Unknown action']));
        }
    }

    protected function clearCache()
    {
        $cache = Cache::getInstance();
        if ($cache instanceof \Ngs\Redis\Classes\Cache\CacheRedis) {
            $cache->flush();
            die(json_encode(['status' => 'success', 'message' => 'Cache cleared']));
        }
        die(json_encode(['status' => 'error', 'message' => 'Redis cache not active']));
    }
}
