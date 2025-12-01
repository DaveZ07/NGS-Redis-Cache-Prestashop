<?php
/**
 * NGS Redis Cache
 *
 * @author    davez (https://github.com/DaveZ07)
 * @copyright 2024 davez.ovh - All rights reserved
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

class AdminNgsRedisConfigurationController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->className = 'Configuration';
        $this->table = 'configuration';
        
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();
        
        $this->content .= $this->renderForm();
        $this->context->smarty->assign('content', $this->content);
    }

    private function getConfigPath()
    {
        return _PS_MODULE_DIR_ . 'ngs_redis/config/redis.php';
    }

    private function loadConfig()
    {
        // Load from file if exists, otherwise from DB
        if (file_exists($this->getConfigPath())) {
            $fileConfig = require $this->getConfigPath();
            if (is_array($fileConfig)) {
                return $fileConfig;
            }
        }

        return [
            'host' => Configuration::get('NGS_REDIS_HOST', '127.0.0.1'),
            'port' => Configuration::get('NGS_REDIS_PORT', 6379),
            'auth' => Configuration::get('NGS_REDIS_AUTH', ''),
            'db' => Configuration::get('NGS_REDIS_DB', 0),
            'prefix' => Configuration::get('NGS_REDIS_PREFIX', 'ngs_'),
            'connection_type' => Configuration::get('NGS_REDIS_CONNECTION_TYPE', 'single'),
            'sentinel_hosts' => json_decode(Configuration::get('NGS_REDIS_SENTINEL_HOSTS', '[]'), true),
            'sentinel_service' => Configuration::get('NGS_REDIS_SENTINEL_SERVICE', 'mymaster'),
            'cluster_nodes' => json_decode(Configuration::get('NGS_REDIS_CLUSTER_NODES', '[]'), true),
            'unix_socket' => Configuration::get('NGS_REDIS_UNIX_SOCKET', ''),
            'blacklist' => json_decode(Configuration::get('NGS_REDIS_BLACKLIST', '[]'), true),
            'blacklist_controllers' => json_decode(Configuration::get('NGS_REDIS_BLACKLIST_CONTROLLERS', '[]'), true),
            'disable_order_page' => Configuration::get('NGS_REDIS_DISABLE_ORDER_PAGE', false),
            'disable_checkout' => Configuration::get('NGS_REDIS_DISABLE_CHECKOUT', false),
            'disable_webservice' => Configuration::get('NGS_REDIS_DISABLE_WEBSERVICE', false),
            'disable_product_listing' => Configuration::get('NGS_REDIS_DISABLE_PRODUCT_LISTING', false),
        ];
    }

    private function saveConfig($host, $port, $auth, $db, $prefix, $blacklist, $options = [])
    {
        // Basic validation
        if (empty($host)) $host = '127.0.0.1';
        if (empty($port) || !is_numeric($port)) $port = 6379;
        if (!is_numeric($db)) $db = 0;
        if (empty($prefix)) $prefix = 'ngs_';
        
        // Process blacklist (textarea to array)
        $blacklistArray = array_filter(array_map('trim', explode("\n", $blacklist)));
        
        // Process blacklist controllers
        $blacklistControllersArray = [];
        if (isset($options['blacklist_controllers'])) {
            $blacklistControllersArray = array_filter(array_map('trim', explode("\n", $options['blacklist_controllers'])));
        }

        // Process Sentinel hosts
        $sentinelHostsArray = [];
        if (isset($options['sentinel_hosts'])) {
            $sentinelHostsArray = array_filter(array_map('trim', explode("\n", $options['sentinel_hosts'])));
        }

        // Process Cluster nodes
        $clusterNodesArray = [];
        if (isset($options['cluster_nodes'])) {
            $clusterNodesArray = array_filter(array_map('trim', explode("\n", $options['cluster_nodes'])));
        }

        Configuration::updateValue('NGS_REDIS_HOST', $host);
        Configuration::updateValue('NGS_REDIS_PORT', (int)$port);
        Configuration::updateValue('NGS_REDIS_UNIX_SOCKET', $options['unix_socket'] ?? '');
        Configuration::updateValue('NGS_REDIS_AUTH', $auth);
        Configuration::updateValue('NGS_REDIS_DB', (int)$db);
        Configuration::updateValue('NGS_REDIS_PREFIX', $prefix);
        
        Configuration::updateValue('NGS_REDIS_CONNECTION_TYPE', $options['connection_type'] ?? 'single');
        Configuration::updateValue('NGS_REDIS_SENTINEL_HOSTS', json_encode($sentinelHostsArray));
        Configuration::updateValue('NGS_REDIS_SENTINEL_SERVICE', $options['sentinel_service'] ?? 'mymaster');
        Configuration::updateValue('NGS_REDIS_CLUSTER_NODES', json_encode($clusterNodesArray));

        Configuration::updateValue('NGS_REDIS_BLACKLIST', json_encode($blacklistArray));
        Configuration::updateValue('NGS_REDIS_BLACKLIST_CONTROLLERS', json_encode($blacklistControllersArray));
        
        Configuration::updateValue('NGS_REDIS_DISABLE_ORDER_PAGE', (bool)($options['disable_order_page'] ?? false));
        Configuration::updateValue('NGS_REDIS_DISABLE_CHECKOUT', (bool)($options['disable_checkout'] ?? false));
        Configuration::updateValue('NGS_REDIS_DISABLE_WEBSERVICE', (bool)($options['disable_webservice'] ?? false));
        Configuration::updateValue('NGS_REDIS_DISABLE_PRODUCT_LISTING', (bool)($options['disable_product_listing'] ?? false));

        // Save to file to avoid recursion in CacheRedis
        $configContent = "<?php\nreturn [\n";
        $configContent .= "    'host' => '" . addslashes($host) . "',\n";
        $configContent .= "    'port' => " . (int)$port . ",\n";
        $configContent .= "    'unix_socket' => '" . addslashes($options['unix_socket'] ?? '') . "',\n";
        $configContent .= "    'auth' => '" . addslashes($auth) . "',\n";
        $configContent .= "    'db' => " . (int)$db . ",\n";
        $configContent .= "    'prefix' => '" . addslashes($prefix) . "',\n";
        $configContent .= "    'connection_type' => '" . addslashes($options['connection_type'] ?? 'single') . "',\n";
        $configContent .= "    'sentinel_hosts' => " . var_export($sentinelHostsArray, true) . ",\n";
        $configContent .= "    'sentinel_service' => '" . addslashes($options['sentinel_service'] ?? 'mymaster') . "',\n";
        $configContent .= "    'cluster_nodes' => " . var_export($clusterNodesArray, true) . ",\n";
        $configContent .= "    'blacklist' => " . var_export($blacklistArray, true) . ",\n";
        $configContent .= "    'blacklist_controllers' => " . var_export($blacklistControllersArray, true) . ",\n";
        $configContent .= "    'disable_order_page' => " . ($options['disable_order_page'] ? 'true' : 'false') . ",\n";
        $configContent .= "    'disable_checkout' => " . ($options['disable_checkout'] ? 'true' : 'false') . ",\n";
        $configContent .= "    'disable_webservice' => " . ($options['disable_webservice'] ? 'true' : 'false') . ",\n";
        $configContent .= "    'disable_product_listing' => " . ($options['disable_product_listing'] ? 'true' : 'false') . ",\n";
        $configContent .= "];\n";
        
        file_put_contents($this->getConfigPath(), $configContent);
    }

    public function renderForm()
    {
        $config = $this->loadConfig();

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->module->l('Redis Configuration'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->module->l('Connection Type'),
                        'name' => 'NGS_REDIS_CONNECTION_TYPE',
                        'options' => [
                            'query' => [
                                ['id' => 'single', 'name' => $this->module->l('Single Instance')],
                                ['id' => 'sentinel', 'name' => $this->module->l('Redis Sentinel')],
                                ['id' => 'cluster', 'name' => $this->module->l('Redis Cluster')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'required' => true,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->module->l('Sentinel Hosts'),
                        'name' => 'NGS_REDIS_SENTINEL_HOSTS',
                        'required' => false,
                        'desc' => $this->module->l('One host per line in format host:port (e.g. 192.168.1.10:26379)'),
                        'form_group_class' => 'sentinel_field',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Sentinel Service Name'),
                        'name' => 'NGS_REDIS_SENTINEL_SERVICE',
                        'required' => false,
                        'desc' => $this->module->l('The name of the master set (e.g. mymaster)'),
                        'form_group_class' => 'sentinel_field',
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->module->l('Cluster Nodes'),
                        'name' => 'NGS_REDIS_CLUSTER_NODES',
                        'required' => false,
                        'desc' => $this->module->l('One node per line (e.g. tcp://127.0.0.1:6379)'),
                        'form_group_class' => 'cluster_field',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Redis Host'),
                        'name' => 'NGS_REDIS_HOST',
                        'required' => true,
                        'desc' => $this->module->l('Default: 127.0.0.1'),
                        'form_group_class' => 'single_field',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Redis Port'),
                        'name' => 'NGS_REDIS_PORT',
                        'required' => true,
                        'desc' => $this->module->l('Default: 6379'),
                        'form_group_class' => 'single_field',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Unix Socket Path'),
                        'name' => 'NGS_REDIS_UNIX_SOCKET',
                        'required' => false,
                        'desc' => $this->module->l('Leave empty to use TCP. If set, Host and Port will be ignored. (e.g. /var/run/redis/redis.sock)'),
                        'form_group_class' => 'single_field',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Redis Password'),
                        'name' => 'NGS_REDIS_AUTH',
                        'required' => false,
                        'desc' => $this->module->l('Leave empty if no password')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Redis Database ID'),
                        'name' => 'NGS_REDIS_DB',
                        'required' => true,
                        'desc' => $this->module->l('Default: 0')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Cache Key Prefix'),
                        'name' => 'NGS_REDIS_PREFIX',
                        'required' => true,
                        'desc' => $this->module->l('Default: ngs_')
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->module->l('Blacklisted Tables'),
                        'name' => 'NGS_REDIS_BLACKLIST',
                        'required' => false,
                        'desc' => $this->module->l('One table per line. These tables will not be cached.')
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->module->l('Blacklisted Controllers'),
                        'name' => 'NGS_REDIS_BLACKLIST_CONTROLLERS',
                        'required' => false,
                        'desc' => $this->module->l('One controller name per line (e.g. order, cart, my-account). These pages will not use Redis cache.')
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->module->l('Disable Cache for Order Page'),
                        'name' => 'NGS_REDIS_DISABLE_ORDER_PAGE',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->module->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Disabled')]
                        ]
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->module->l('Disable Cache for Checkout (Address, Payment, Carrier)'),
                        'name' => 'NGS_REDIS_DISABLE_CHECKOUT',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->module->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Disabled')]
                        ]
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->module->l('Disable Cache for Webservice'),
                        'name' => 'NGS_REDIS_DISABLE_WEBSERVICE',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->module->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Disabled')]
                        ]
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->module->l('Disable Cache for Product Listing'),
                        'name' => 'NGS_REDIS_DISABLE_PRODUCT_LISTING',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->module->l('Enabled')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Disabled')]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Cron URL to Clear Cache'),
                        'name' => 'NGS_REDIS_CRON_URL',
                        'readonly' => true,
                        'desc' => $this->module->l('Use this URL to clear the cache periodically via a cron job.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->module->l('Health Check URL'),
                        'name' => 'NGS_REDIS_HEALTH_URL',
                        'readonly' => true,
                        'desc' => $this->module->l('Use this URL to check Redis status externally.')
                    ],
                ],
                'submit' => [
                    'title' => $this->module->l('Save'),
                ]
            ]
        ];

        $fields_form['form']['buttons'] = [
            [
                'href' => $this->context->link->getAdminLink('AdminPerformance') . '#configuration_fieldset_caching',
                'title' => $this->module->l('Performance Settings'),
                'icon' => 'process-icon-cogs',
                'class' => 'pull-left btn-default'
            ],
            [
                'type' => 'submit',
                'title' => $this->module->l('Test Connection'),
                'name' => 'submitTestConnection',
                'icon' => 'process-icon-refresh',
                'class' => 'pull-right'
            ],
            [
                'type' => 'submit',
                'title' => $this->module->l('Clear All Caches (Redis + FileSystem)'),
                'name' => 'submitClearAllCaches',
                'icon' => 'process-icon-eraser',
                'class' => 'pull-right btn-danger',
                'confirm' => $this->module->l('Are you sure you want to clear ALL caches (Redis + Smarty + XML)?')
            ],
            [
                'type' => 'submit',
                'title' => $this->module->l('Force Enable Redis in PrestaShop'),
                'name' => 'submitForceEnable',
                'icon' => 'process-icon-save',
                'class' => 'pull-right btn-warning',
                'confirm' => $this->module->l('This will modify app/config/parameters.php to force Redis as the caching system. Continue?')
            ]
        ];

        $helper = new HelperForm();

        $helper->module = $this->module;
        $helper->name_controller = 'AdminNgsRedisConfiguration';
        $helper->token = Tools::getAdminTokenLite('AdminNgsRedisConfiguration');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->module->name;
        $helper->default_form_language = $this->context->language->id;
        
        $helper->fields_value['NGS_REDIS_CONNECTION_TYPE'] = $config['connection_type'] ?? 'single';
        $helper->fields_value['NGS_REDIS_SENTINEL_HOSTS'] = implode("\n", $config['sentinel_hosts'] ?? []);
        $helper->fields_value['NGS_REDIS_SENTINEL_SERVICE'] = $config['sentinel_service'] ?? 'mymaster';
        $helper->fields_value['NGS_REDIS_CLUSTER_NODES'] = implode("\n", $config['cluster_nodes'] ?? []);
        
        $helper->fields_value['NGS_REDIS_HOST'] = $config['host'] ?? '127.0.0.1';
        $helper->fields_value['NGS_REDIS_PORT'] = $config['port'] ?? 6379;
        $helper->fields_value['NGS_REDIS_UNIX_SOCKET'] = $config['unix_socket'] ?? '';
        $helper->fields_value['NGS_REDIS_AUTH'] = $config['auth'] ?? '';
        $helper->fields_value['NGS_REDIS_DB'] = $config['db'] ?? 0;
        $helper->fields_value['NGS_REDIS_PREFIX'] = $config['prefix'] ?? 'ngs_';
        $helper->fields_value['NGS_REDIS_BLACKLIST'] = implode("\n", $config['blacklist'] ?? []);
        $helper->fields_value['NGS_REDIS_BLACKLIST_CONTROLLERS'] = implode("\n", $config['blacklist_controllers'] ?? []);
        $helper->fields_value['NGS_REDIS_DISABLE_ORDER_PAGE'] = $config['disable_order_page'] ?? false;
        $helper->fields_value['NGS_REDIS_DISABLE_CHECKOUT'] = $config['disable_checkout'] ?? false;
        $helper->fields_value['NGS_REDIS_DISABLE_WEBSERVICE'] = $config['disable_webservice'] ?? false;
        $helper->fields_value['NGS_REDIS_DISABLE_PRODUCT_LISTING'] = $config['disable_product_listing'] ?? false;
        $helper->fields_value['NGS_REDIS_CRON_URL'] = $this->context->link->getModuleLink('ngs_redis', 'cron', ['token' => Configuration::get('NGS_REDIS_CRON_TOKEN'), 'type' => 'clear']);
        $helper->fields_value['NGS_REDIS_HEALTH_URL'] = $this->context->link->getModuleLink('ngs_redis', 'healthCheck', ['token' => Configuration::get('NGS_REDIS_CRON_TOKEN')]);

        $stats = $this->getRedisStats($config);
        $this->content .= $this->renderStats($stats);

        return $helper->generateForm([$fields_form]);
    }

    private function getRedisStats($config)
    {
        try {
            if (!class_exists('Predis\Client')) {
                require_once _PS_MODULE_DIR_ . 'ngs_redis/vendor/autoload.php';
            }

            if (isset($config['connection_type']) && $config['connection_type'] === 'sentinel') {
                $sentinels = $config['sentinel_hosts'] ?? [];
                $options = [
                    'replication' => 'sentinel',
                    'service' => $config['sentinel_service'] ?? 'mymaster',
                ];
                
                if (!empty($config['auth'])) {
                    $options['parameters'] = [
                        'password' => $config['auth'],
                        'database' => $config['db'] ?? 0,
                    ];
                } else {
                    $options['parameters'] = [
                        'database' => $config['db'] ?? 0,
                    ];
                }
                $client = new Predis\Client($sentinels, $options);
            } elseif (isset($config['connection_type']) && $config['connection_type'] === 'cluster') {
                $nodes = $config['cluster_nodes'] ?? [];
                $options = [
                    'cluster' => 'redis',
                ];
                if (!empty($config['auth'])) {
                    $options['parameters'] = [
                        'password' => $config['auth'],
                    ];
                }
                $client = new Predis\Client($nodes, $options);
            } else {
                if (!empty($config['unix_socket'])) {
                    $clientConfig = [
                        'scheme' => 'unix',
                        'path' => $config['unix_socket'],
                        'database' => $config['db'] ?? 0,
                    ];
                } else {
                    $clientConfig = [
                        'scheme' => 'tcp',
                        'host'   => $config['host'] ?? '127.0.0.1',
                        'port'   => $config['port'] ?? 6379,
                        'database' => $config['db'] ?? 0,
                    ];
                }
                
                if (!empty($config['auth'])) {
                    $clientConfig['password'] = $config['auth'];
                }
                $client = new Predis\Client($clientConfig);
            }

            $client->connect();
            
            if ($client->isConnected()) {
                $info = $client->info();
                
                // Handle Cluster Info (array of infos)
                if (isset($config['connection_type']) && $config['connection_type'] === 'cluster') {
                    $firstNodeInfo = reset($info);
                    if (is_array($firstNodeInfo) && isset($firstNodeInfo['Stats'])) {
                        $info = $firstNodeInfo;
                    }
                    try {
                        $dbsize = 0;
                        foreach ($client->getProfile()->getSupportedCommands() as $command) {
                            if ($command === 'DBSIZE') {
                            }
                        }
                        $dbsize = 'Cluster (N/A)';
                    } catch (Exception $e) {
                        $dbsize = 'Error';
                    }
                } else {
                    $dbsize = $client->dbsize();
                }
                
                $hits = $info['Stats']['keyspace_hits'] ?? 0;
                $misses = $info['Stats']['keyspace_misses'] ?? 0;
                $total = $hits + $misses;
                $hitRate = $total > 0 ? round(($hits / $total) * 100, 2) : 0;

                return [
                    'connected' => true,
                    'version' => $info['Server']['redis_version'] ?? 'Unknown',
                    'used_memory' => $info['Memory']['used_memory_human'] ?? 'Unknown',
                    'uptime' => ($info['Server']['uptime_in_days'] ?? 0) . ' days',
                    'keys' => $dbsize,
                    'hits' => $hits,
                    'misses' => $misses,
                    'hit_rate' => $hitRate,
                    'mem_fragmentation_ratio' => $info['Memory']['mem_fragmentation_ratio'] ?? 'Unknown',
                ];
            }
        } catch (Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
        return ['connected' => false, 'error' => 'Unknown error'];
    }

    private function renderStats($stats)
    {
        $this->context->smarty->assign('stats', $stats);
        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'ngs_redis/views/templates/admin/stats.tpl');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitAddconfiguration')) {
            $host = Tools::getValue('NGS_REDIS_HOST');
            $port = Tools::getValue('NGS_REDIS_PORT');
            $unixSocket = Tools::getValue('NGS_REDIS_UNIX_SOCKET');
            $auth = Tools::getValue('NGS_REDIS_AUTH');
            $db = Tools::getValue('NGS_REDIS_DB');
            $prefix = Tools::getValue('NGS_REDIS_PREFIX');
            $blacklist = Tools::getValue('NGS_REDIS_BLACKLIST');
            $blacklistControllers = Tools::getValue('NGS_REDIS_BLACKLIST_CONTROLLERS');
            
            $options = [
                'connection_type' => Tools::getValue('NGS_REDIS_CONNECTION_TYPE'),
                'sentinel_hosts' => Tools::getValue('NGS_REDIS_SENTINEL_HOSTS'),
                'sentinel_service' => Tools::getValue('NGS_REDIS_SENTINEL_SERVICE'),
                'cluster_nodes' => Tools::getValue('NGS_REDIS_CLUSTER_NODES'),
                'unix_socket' => $unixSocket,
                'blacklist_controllers' => $blacklistControllers,
                'disable_order_page' => Tools::getValue('NGS_REDIS_DISABLE_ORDER_PAGE'),
                'disable_checkout' => Tools::getValue('NGS_REDIS_DISABLE_CHECKOUT'),
                'disable_webservice' => Tools::getValue('NGS_REDIS_DISABLE_WEBSERVICE'),
                'disable_product_listing' => Tools::getValue('NGS_REDIS_DISABLE_PRODUCT_LISTING'),
            ];
            
            $this->saveConfig($host, $port, $auth, $db, $prefix, $blacklist, $options);
            
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminNgsRedisConfiguration') . '&conf=6');
        } elseif (Tools::isSubmit('submitTestConnection')) {
            $this->processTestConnection();
        } elseif (Tools::isSubmit('submitFlushCache')) {
            $this->processFlushCache();
        } elseif (Tools::isSubmit('submitForceEnable')) {
            $this->processForceEnable();
        } elseif (Tools::isSubmit('submitClearAllCaches')) {
            $this->processClearAllCaches();
        } elseif (Tools::isSubmit('clear_cache')) {
            $this->clearRedisCache();
        }
    }

    public function processClearAllCaches()
    {
        $this->clearRedisCache();
        Tools::clearAllCache();
        $this->confirmations[] = $this->module->l('All caches (Redis and FileSystem) have been cleared.');
    }

    public function processForceEnable()
    {
        $configFile = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        if (!file_exists($configFile)) {
            $this->errors[] = $this->module->l('Could not find app/config/parameters.php');
            return;
        }

        try {
            $config = require $configFile;
            if (!is_array($config)) {
                $this->errors[] = $this->module->l('Invalid parameters.php format');
                return;
            }

            $config['parameters']['ps_cache_enable'] = true;
            $config['parameters']['ps_caching_system'] = 'CacheRedis';
            $config['parameters']['ps_caching'] = 'CacheRedis';

            $content = "<?php return " . var_export($config, true) . ";";
            if (file_put_contents($configFile, $content)) {
                $this->confirmations[] = $this->module->l('Redis has been forced as the caching system in parameters.php');
            } else {
                $this->errors[] = $this->module->l('Failed to write to parameters.php. Check permissions.');
            }
        } catch (Exception $e) {
            $this->errors[] = $this->module->l('Error updating configuration: ') . $e->getMessage();
        }
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_btn['clear_cache'] = [
            'href' => self::$currentIndex . '&token=' . $this->token . '&clear_cache=1',
            'desc' => $this->module->l('Clear Redis Cache'),
            'icon' => 'process-icon-eraser',
        ];
        parent::initPageHeaderToolbar();
    }

    private function clearRedisCache()
    {
        $config = $this->loadConfig();
        try {
            if (!class_exists('Predis\Client')) {
                require_once _PS_MODULE_DIR_ . 'ngs_redis/vendor/autoload.php';
            }

            $client = null;

            if (isset($config['connection_type']) && $config['connection_type'] === 'sentinel') {
                $sentinels = $config['sentinel_hosts'] ?? [];
                $options = [
                    'replication' => 'sentinel',
                    'service' => $config['sentinel_service'] ?? 'mymaster',
                ];
                
                if (!empty($config['auth'])) {
                    $options['parameters'] = [
                        'password' => $config['auth'],
                        'database' => $config['db'] ?? 0,
                    ];
                } else {
                    $options['parameters'] = [
                        'database' => $config['db'] ?? 0,
                    ];
                }
                $client = new Predis\Client($sentinels, $options);
            } elseif (isset($config['connection_type']) && $config['connection_type'] === 'cluster') {
                $nodes = $config['cluster_nodes'] ?? [];
                $options = [
                    'cluster' => 'redis',
                ];
                if (!empty($config['auth'])) {
                    $options['parameters'] = [
                        'password' => $config['auth'],
                    ];
                }
                $client = new Predis\Client($nodes, $options);
            } else {
                // Single
                if (!empty($config['unix_socket'])) {
                    $clientConfig = [
                        'scheme' => 'unix',
                        'path' => $config['unix_socket'],
                        'database' => $config['db'] ?? 0,
                    ];
                } else {
                    $clientConfig = [
                        'scheme' => 'tcp',
                        'host'   => $config['host'] ?? '127.0.0.1',
                        'port'   => $config['port'] ?? 6379,
                        'database' => $config['db'] ?? 0,
                    ];
                }
                
                if (!empty($config['auth'])) {
                    $clientConfig['password'] = $config['auth'];
                }
                $client = new Predis\Client($clientConfig);
            }

            $client->connect();

            if ($config['connection_type'] === 'cluster') {
                foreach ($client->getConnection() as $node) {
                    $nodeClient = new Predis\Client($node);
                    $nodeClient->flushdb();
                }
            } else {
                $client->flushdb();
            }
            
        } catch (Exception $e) {
            $this->errors[] = $this->module->l('Failed to clear cache: ') . $e->getMessage();
        }
    }

    public function processTestConnection()
    {
        $connectionType = Tools::getValue('NGS_REDIS_CONNECTION_TYPE');
        
        if ($connectionType === 'sentinel') {
            $sentinelHosts = Tools::getValue('NGS_REDIS_SENTINEL_HOSTS');
            $sentinelService = Tools::getValue('NGS_REDIS_SENTINEL_SERVICE');
            $sentinels = array_filter(array_map('trim', explode("\n", $sentinelHosts)));
            
            $config = [
                'connection_type' => 'sentinel',
                'sentinel_hosts' => $sentinels,
                'sentinel_service' => $sentinelService,
                'auth' => Tools::getValue('NGS_REDIS_AUTH'),
                'db' => (int)Tools::getValue('NGS_REDIS_DB'),
            ];
        } elseif ($connectionType === 'cluster') {
            $clusterNodes = Tools::getValue('NGS_REDIS_CLUSTER_NODES');
            $nodes = array_filter(array_map('trim', explode("\n", $clusterNodes)));
            
            $config = [
                'connection_type' => 'cluster',
                'cluster_nodes' => $nodes,
                'auth' => Tools::getValue('NGS_REDIS_AUTH'),
                // Cluster usually doesn't use DB index, but i'll pass it just in case or ignore it
            ];
        } else {
            $config = [
                'connection_type' => 'single',
                'host' => Tools::getValue('NGS_REDIS_HOST'),
                'port' => Tools::getValue('NGS_REDIS_PORT'),
                'auth' => Tools::getValue('NGS_REDIS_AUTH'),
                'db' => (int)Tools::getValue('NGS_REDIS_DB'),
            ];
        }
        
        $stats = $this->getRedisStats($config);

        if ($stats['connected']) {
            $this->confirmations[] = $this->module->l('Connection successful!');
        } else {
            $this->errors[] = $this->module->l('Connection failed: ') . $stats['error'];
        }
    }

    public function processFlushCache()
    {
        $this->clearRedisCache();
        $this->confirmations[] = $this->module->l('Cache flushed successfully.');
    }

}
