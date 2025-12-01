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

require_once __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function ($class) {
    if (strpos($class, 'Ngs\\Redis\\Classes\\') === 0) {
        $relative = substr($class, strlen('Ngs\\Redis\\Classes\\'));
        $file = __DIR__ . '/classes/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }
    if (strpos($class, 'Ngs\\Redis\\') === 0) {
        $relative = substr($class, strlen('Ngs\\Redis\\'));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }
});

use Ngs\Redis\Form\NgsRedisCachingTypeForm;

class Ngs_Redis extends Module
{
    public function __construct()
    {
        $this->name = 'ngs_redis';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'NGS Software';
        $this->need_instance = 1;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('NGS Redis Cache');
        $this->description = $this->l('Advanced Redis Cache support for PrestaShop 8+');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        $this->createDefaultConfig();

        // Generate a random token for CRON/HealthCheck if not exists
        if (!Configuration::get('NGS_REDIS_CRON_TOKEN')) {
            Configuration::updateValue('NGS_REDIS_CRON_TOKEN', Tools::passwdGen(32));
        }

        // Clear class index to ensure overrides are detected
        if (file_exists(_PS_CACHE_DIR_ . 'class_index.php')) {
            @unlink(_PS_CACHE_DIR_ . 'class_index.php');
        }

        return parent::install()
            && $this->installTab()
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('actionClearCompileCache')
            && $this->registerHook('actionObjectUpdateAfter')
            && $this->registerHook('actionObjectDeleteAfter')
            && $this->registerHook('actionObjectAddAfter')
            && $this->registerHook('actionObjectImageAddAfter')
            && $this->registerHook('actionObjectImageDeleteAfter')
            && $this->registerHook('actionObjectImageUpdateAfter')
            && $this->registerHook('actionDispatcherAfter')
            && $this->registerHook('actionOnImageResizeAfter')
            && $this->registerHook('actionFormBuilderModifier')
            && $this->registerHook('actionPerformancePagecachingForm');
    }

    public function createDefaultConfig()
    {
        $configPath = _PS_MODULE_DIR_ . 'ngs_redis/config/redis.php';
        if (!file_exists($configPath)) {
            $content = "<?php\nreturn [\n    'host' => '127.0.0.1',\n    'port' => 6379,\n];\n";
            // Ensure directory exists
            if (!is_dir(dirname($configPath))) {
                mkdir(dirname($configPath), 0755, true);
            }
            file_put_contents($configPath, $content);
        }
    }

    public function disable($force_all = false)
    {
        $this->disableCacheIfRedisIsActive();
        return parent::disable($force_all);
    }

    public function uninstall()
    {
        $this->disableCacheIfRedisIsActive();
        return $this->uninstallTab() && parent::uninstall();
    }


    private function disableCacheIfRedisIsActive()
    {
        $parametersFile = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        if (!file_exists($parametersFile)) {
            return;
        }

        $config = require $parametersFile;
        
        if (isset($config['parameters']['ps_cache_enable']) && 
            $config['parameters']['ps_cache_enable'] === true && 
            isset($config['parameters']['ps_caching']) && 
            $config['parameters']['ps_caching'] === 'Redis') {
            
            $content = file_get_contents($parametersFile);
            // Safely replace true with false for ps_cache_enable
            $content = preg_replace("/('ps_cache_enable'\s*=>\s*)true/", "$1false", $content);
            
            file_put_contents($parametersFile, $content);
        }
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminNgsRedisConfiguration'));
    }

    public function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminNgsRedisConfiguration';
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'NGS Redis Cache';
        }
        $tab->id_parent = (int)Tab::getIdFromClassName('AdminAdvancedParameters');
        $tab->module = $this->name;
        return $tab->add();
    }

    public function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminNgsRedisConfiguration');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('controller') == 'AdminNgsRedisConfiguration') {
            $this->context->controller->addCSS($this->_path . 'views/css/redis-admin.css');
            $this->context->controller->addJS($this->_path . 'views/js/redis-admin.js');
        }
    }

    public function hookActionClearCompileCache()
    {
        $cache = Cache::getInstance();
        if ($cache instanceof \Ngs\Redis\Classes\Cache\CacheRedis) {
            $cache->flush();
        }
    }

    public function hookActionObjectUpdateAfter($params)
    {
        $this->invalidateObjectCache($params['object']);
    }

    public function hookActionObjectDeleteAfter($params)
    {
        $this->invalidateObjectCache($params['object']);
    }

    public function hookActionObjectAddAfter($params)
    {
        $this->invalidateObjectCache($params['object']);
    }

    public function hookActionObjectImageAddAfter($params)
    {
        $this->invalidateImageCache($params['object']);
    }

    public function hookActionObjectImageDeleteAfter($params)
    {
        $this->invalidateImageCache($params['object']);
    }

    public function hookActionObjectImageUpdateAfter($params)
    {
        $this->invalidateImageCache($params['object']);
    }

    public function hookActionDispatcherAfter($params)
    {

    }

    public function hookActionOnImageResizeAfter($params)
    {
        if (isset($params['id_product'])) {
             $cache = Cache::getInstance();
            if ($cache instanceof \Ngs\Redis\Classes\Cache\CacheRedis) {
                $cache->invalidateTags([_DB_PREFIX_ . 'product']);
            }
        }
    }

    public function hookActionFormBuilderModifier(array $params)
    {
        if ($params['id'] === 'PrestaShopBundle\Form\Admin\AdvancedParameters\Performance\CachingType' || $params['id'] === 'performance_caching_block') {
            /** @var \Symfony\Component\Form\FormBuilderInterface $formBuilder */
            $formBuilder = $params['form_builder'];
            NgsRedisCachingTypeForm::modifyForm($formBuilder);
        }
    }

    public function hookActionPerformancePagecachingForm(array $params)
    {
        if (isset($params['form_builder'])) {
            NgsRedisCachingTypeForm::modifyForm($params['form_builder']);
        }
    }

    protected function invalidateImageCache($object)
    {
        if (!Validate::isLoadedObject($object)) {
            return;
        }
        
        $this->invalidateObjectCache($object);
        if (isset($object->id_product)) {
            $cache = Cache::getInstance();
            if ($cache instanceof \Ngs\Redis\Classes\Cache\CacheRedis) {
                $cache->invalidateTags([_DB_PREFIX_ . 'product']);
            }
        }
    }

    protected function invalidateObjectCache($object)
    {
        if (!Validate::isLoadedObject($object)) {
            return;
        }

        // Determine table name from object definition
        $def = ObjectModel::getDefinition($object);
        if (isset($def['table'])) {
            $tableName = _DB_PREFIX_ . $def['table'];
            
            $cache = Cache::getInstance();
            
            if ($cache instanceof \Ngs\Redis\Classes\Cache\CacheRedis) {
                $tablesToInvalidate = [$tableName];
                
                if (isset($def['multilang']) && $def['multilang']) {
                    $tablesToInvalidate[] = $tableName . '_lang';
                }
                
                if (isset($def['multishop']) && $def['multishop']) {
                    $tablesToInvalidate[] = $tableName . '_shop';
                }

                if ($tableName === _DB_PREFIX_ . 'product_attribute') {
                    $tablesToInvalidate[] = _DB_PREFIX_ . 'product';
                }

                if ($tableName === _DB_PREFIX_ . 'stock_available') {
                    $tablesToInvalidate[] = _DB_PREFIX_ . 'product';
                }

                $cache->invalidateTags($tablesToInvalidate);
            }
        }
    }
}
