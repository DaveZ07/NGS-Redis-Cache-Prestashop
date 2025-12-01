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

class Dispatcher extends DispatcherCore
{
    public static function ngsRedisGetAvailableCachingType()
    {
        return [
            'CacheRedis' => 'Redis',
        ];
    }

    public static function ngsRedisGetExtensionsListCachingType()
    {
        return [
            'Redis' => [],
        ];
    }
}
