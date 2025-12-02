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
        $this->version = '1.0.1';
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

        if (
            isset($config['parameters']['ps_cache_enable']) &&
            $config['parameters']['ps_cache_enable'] === true &&
            isset($config['parameters']['ps_caching']) &&
            $config['parameters']['ps_caching'] === 'Redis'
        ) {

            $content = file_get_contents($parametersFile);
            // Safely replace true with false for ps_cache_enable
            $content = preg_replace("/('ps_cache_enable'\s*=>\s*)true/", "$1false", $content);

            file_put_contents($parametersFile, $content);
        }
    }

    public function getContent()
    {
        // update handler
        if (Tools::isSubmit('updateModule')) {
            if ($this->updateModule()) {
                $this->context->controller->confirmations[] = $this->l('Module updated successfully');
            } else {
                $this->context->controller->errors[] = $this->l('Update failed. Check permissions or internet connection.');
            }
        }

        $latestVersion = $this->checkUpdate();
        if ($latestVersion && version_compare($latestVersion, $this->version, '>')) {
            $updateUrl = $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name . '&updateModule=1';
            $configUrl = $this->context->link->getAdminLink('AdminNgsRedisConfiguration');

            $output = $this->displayConfirmation(
                sprintf($this->l('New version %s is available! Current version: %s.'), $latestVersion, $this->version)
            );

            $output .= '
            <div class="panel">
                <div class="panel-heading"><i class="icon-cloud-upload"></i> ' . $this->l('Update Available') . '</div>
                <div class="row">
                    <div class="col-lg-12">
                        <p>' . sprintf($this->l('A new version %s is available for download.'), $latestVersion) . '</p>
                        <p>' . $this->l('Click the button below to update the module automatically.') . '</p>
                        <br/>
                        <a href="' . $updateUrl . '" class="btn btn-warning btn-lg">
                            <i class="icon-refresh"></i> ' . $this->l('Update Now') . '
                        </a>
                        <a href="' . $configUrl . '" class="btn btn-default btn-lg">
                            <i class="icon-cog"></i> ' . $this->l('Go to Configuration') . '
                        </a>
                        <br/><br/>
                        <p>' . $this->l('Note that after updating the module, in module manager you will see an old version until you restart the module') . '</p>
                    </div>
                </div>
            </div>';

            return $output;
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminNgsRedisConfiguration'));
    }

    public function checkUpdate()
    {
        // Check for updates once a day to avoid blocking api
        $lastCheck = Configuration::get('NGS_REDIS_LAST_UPDATE_CHECK');
        $latestVersion = Configuration::get('NGS_REDIS_LATEST_VERSION');

        if (time() - (int) $lastCheck > 3600 * 24 || !$latestVersion || Tools::getValue('checkUpdate')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/DaveZ07/ngs_redis/releases/latest');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop-Module-Updater');
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (isset($data['tag_name'])) {
                    $latestVersion = str_replace('v', '', $data['tag_name']);
                    Configuration::updateValue('NGS_REDIS_LATEST_VERSION', $latestVersion);
                    Configuration::updateValue('NGS_REDIS_LAST_UPDATE_CHECK', time());
                    if (isset($data['assets'][0]['browser_download_url'])) {
                        Configuration::updateValue('NGS_REDIS_DOWNLOAD_URL', $data['assets'][0]['browser_download_url']);
                    }
                }
            } else {
                PrestaShopLogger::addLog('NGS Redis Update Error: ' . $error . ' (HTTP ' . $httpCode . ')', 3);
            }
        }

        return $latestVersion;
    }

    // Download and install update
    public function updateModule()
    {
        $downloadUrl = Configuration::get('NGS_REDIS_DOWNLOAD_URL');
        if (!$downloadUrl) {
            $this->checkUpdate();
            $downloadUrl = Configuration::get('NGS_REDIS_DOWNLOAD_URL');
            if (!$downloadUrl) {
                PrestaShopLogger::addLog('NGS Redis Update: No download URL found.', 3);
                return false;
            }
        }
        $zipFile = _PS_MODULE_DIR_ . 'ngs_redis.zip';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $downloadUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop-Module-Updater');
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || empty($content)) {
            PrestaShopLogger::addLog('NGS Redis Update: Download failed. HTTP ' . $httpCode . '. Error: ' . $error, 3);
            return false;
        }

        if (file_put_contents($zipFile, $content) === false) {
            PrestaShopLogger::addLog('NGS Redis Update: Could not write zip file.', 3);
            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFile) === TRUE) {
            if (!$zip->extractTo(_PS_MODULE_DIR_)) {
                PrestaShopLogger::addLog('NGS Redis Update: Extraction failed.', 3);
                $zip->close();
                return false;
            }
            $zip->close();
            unlink($zipFile);
            Configuration::updateValue('NGS_REDIS_LATEST_VERSION', '');
            Configuration::updateValue('NGS_REDIS_DOWNLOAD_URL', '');
            Tools::clearSmartyCache();
            Tools::clearXMLCache();
            Media::clearCache();

            return true;
        } else {
            PrestaShopLogger::addLog('NGS Redis Update: Could not open zip file.', 3);
        }

        return false;
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
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminAdvancedParameters');
        $tab->module = $this->name;
        return $tab->add();
    }

    public function uninstallTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminNgsRedisConfiguration');
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
