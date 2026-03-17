<?php
/**
 * NGS Redis Cache
 *
 * @author    davez (https://github.com/DaveZ07)
 * @copyright Copyright © 2025 NGS Software. All rights reserved.
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

// L'autoloader per Ngs\Redis\Classes\ è già registrato in ngs_redis.php (modulo principale).
// Il vendor/autoload.php viene caricato da Cache::getInstance() se necessario.
// Nessun autoloader ridondante qui.

use Ngs\Redis\Classes\Cache\CacheRedis as NgsCacheRedis;

class CacheRedis extends NgsCacheRedis
{
    public function __construct()
    {
        parent::__construct();
    }
}
