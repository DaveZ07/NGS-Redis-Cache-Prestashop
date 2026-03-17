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

abstract class ObjectModel extends ObjectModelCore
{
    public function update($null_values = false)
    {
        $result = parent::update($null_values);

        if ($result) {
            $this->ngsInvalidateCache();
        }

        return $result;
    }

    public function add($auto_date = true, $null_values = false)
    {
        $result = parent::add($auto_date, $null_values);

        if ($result) {
            $this->ngsInvalidateCache();
        }

        return $result;
    }

    public function delete()
    {
        $result = parent::delete();

        if ($result) {
            $this->ngsInvalidateCache();
        }

        return $result;
    }

    protected function ngsInvalidateCache()
    {
        // Cache invalidation is handled centrally by module hooks in ngs_redis.php.
    }
}
