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
        /*
         * Fix Enx:: nel modulo originale mancava l'implementazione di questo metodo!
         */
        try {
            $cache = Cache::getInstance();
            if (!($cache instanceof \Ngs\Redis\Classes\Cache\CacheRedis)) {
                return;
            }
            if (!isset($this->def['table'])) {
                return;
            }
            $tablesToInvalidate = [_DB_PREFIX_ . $this->def['table']];
            if (!empty($this->def['multilang'])) {
                $tablesToInvalidate[] = _DB_PREFIX_ . $this->def['table'] . '_lang';
            }
            if (!empty($this->def['multishop'])) {
                $tablesToInvalidate[] = _DB_PREFIX_ . $this->def['table'] . '_shop';
            }
            // Dipendenze di prodotto: queste tabelle influenzano il rendering del prodotto
            if ($this->def['table'] === 'product_attribute') {
                $tablesToInvalidate[] = _DB_PREFIX_ . 'product';
            }
            if ($this->def['table'] === 'stock_available') {
                $tablesToInvalidate[] = _DB_PREFIX_ . 'product';
            }
            $cache->invalidateTags($tablesToInvalidate);
        } catch (\Throwable $e) {
            // Un errore di cache non deve MAI bloccare un'operazione sul DB
        }
    }
}
