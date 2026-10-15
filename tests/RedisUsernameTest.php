<?php

declare(strict_types=1);

// Exercise the real controller and cache configuration paths without a shop or server.
namespace Predis {
    class Client
    {
        public static $calls = [];
        public static $flushes = 0;

        public function __construct($parameters, $options = [])
        {
            self::$calls[] = [$parameters, $options];
        }

        public function connect() {}
        public function isConnected() { return true; }
        public function info() { return []; }
        public function dbsize() { return 0; }
        public function getConnection() { return []; }
        public function getProfile() { throw new \RuntimeException('No profile'); }
        public function flushdb() { ++self::$flushes; }
    }
}

namespace {
    define('_PS_VERSION_', '8.2.1');
    $testRoot = sys_get_temp_dir() . '/ngs-redis-username-' . bin2hex(random_bytes(8));
    define('_PS_MODULE_DIR_', $testRoot . '/');
    mkdir($testRoot . '/ngs_redis/config', 0700, true);
    $configPath = $testRoot . '/ngs_redis/config/redis.php';

    class CacheCore { protected $blacklist = []; }
    class ModuleAdminController
    {
        public $module;
        public $context;
        public $errors = [];
        public $confirmations = [];
        public $content = '';
    }
    class AdminController { public static $currentIndex = ''; }
    class Configuration
    {
        public static $values = [];
        public static function get($key, $default = false) { return self::$values[$key] ?? $default; }
        public static function updateValue($key, $value) { self::$values[$key] = $value; }
    }
    class Tools
    {
        public static $values = [];
        public static function getValue($key, $default = false) { return self::$values[$key] ?? $default; }
        public static function isSubmit($key) { return isset(self::$values[$key]); }
        public static function redirectAdmin($url) {}
        public static function getAdminTokenLite($controller) { return 'test-token'; }
    }
    #[\AllowDynamicProperties]
    class HelperForm
    {
        public $fields_value = [];
        public static $last;
        public static $form;
        public function generateForm($form) { self::$last = $this; self::$form = $form; return ''; }
    }

    require dirname(__DIR__) . '/classes/cache/CacheRedis.php';
    require dirname(__DIR__) . '/controllers/admin/AdminNgsRedisConfigurationController.php';

    function checkSame($expected, $actual, string $message): void
    {
        // Avoid printing credentials even when a regression fails an assertion.
        if ($expected !== $actual) {
            throw new \RuntimeException($message);
        }
    }

    function invokePrivate($object, string $method, array $arguments = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }

    function checkConnection(array $config, bool $runtime): void
    {
        checkSame(1, count(\Predis\Client::$calls), 'Expected one client construction.');
        [$parameters, $options] = \Predis\Client::$calls[0];
        $type = $config['connection_type'];
        if ($type === 'single') {
            $credentials = $parameters;
            checkSame($config['db'], $parameters['database'], 'Single database must be preserved.');
            checkSame(!empty($config['unix_socket']) ? 'unix' : 'tcp', $parameters['scheme'], 'Wrong single transport.');
        } else {
            $credentials = $options['parameters'] ?? [];
            if ($type === 'sentinel') {
                checkSame($config['sentinel_hosts'], $parameters, 'Sentinel endpoints changed.');
                checkSame($config['db'], $credentials['database'], 'Sentinel database must be preserved.');
            } else {
                checkSame($config['cluster_nodes'], $parameters, 'Cluster endpoints changed.');
                checkSame(false, isset($credentials['database']), 'Cluster must not select a database.');
            }
        }
        $expectedUser = (string) ($config['username'] ?? '');
        $expectedPassword = (string) ($config['auth'] ?? '');
        checkSame($expectedUser !== '', array_key_exists('username', $credentials), 'Username presence mismatch.');
        checkSame($expectedUser, $credentials['username'] ?? '', 'Username was changed or omitted.');
        checkSame($expectedPassword !== '', array_key_exists('password', $credentials), 'Password presence mismatch.');
        checkSame($expectedPassword, $credentials['password'] ?? '', 'Password was changed or omitted.');
        if ($runtime) {
            checkSame($config['prefix'], $options['prefix'], 'Runtime prefix changed.');
        }
    }

    $controller = (new \ReflectionClass('AdminNgsRedisConfigurationController'))->newInstanceWithoutConstructor();
    $controller->module = new class {
        public $name = 'ngs_redis';
        public function l($text) { return $text; }
    };
    $controller->context = (object) [
        'language' => (object) ['id' => 1],
        'link' => new class {
            public function getAdminLink($controller) { return 'admin'; }
            public function getModuleLink(...$arguments) { return 'module'; }
        },
        'smarty' => new class {
            public function assign(...$arguments) {}
            public function fetch($path) { return ''; }
        },
    ];

    $cases = 0;
    try {
        // Include the string "0", which empty() must not discard, and characters
        // requiring var_export escaping when written to the PHP config file.
        $authCases = [
            ['username' => "acl'\\user", 'auth' => "pass'\\word"],
            ['username' => '', 'auth' => 'legacy-password'],
            ['auth' => 'legacy-password'],
            ['username' => '', 'auth' => ''],
            ['username' => '0', 'auth' => '0'],
        ];
        foreach (['tcp', 'unix', 'sentinel', 'cluster'] as $mode) {
            foreach ($authCases as $auth) {
                $config = array_merge([
                    'connection_type' => in_array($mode, ['tcp', 'unix'], true) ? 'single' : $mode,
                    'host' => 'redis.example.test', 'port' => 6379,
                    'unix_socket' => $mode === 'unix' ? '/tmp/redis-test.sock' : '',
                    'db' => 2, 'prefix' => 'test_',
                    'sentinel_hosts' => ['tcp://sentinel.example.test:26379'],
                    'sentinel_service' => 'mymaster',
                    'cluster_nodes' => ['tcp://cluster.example.test:6379'],
                ], $auth);
                file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');

                \Predis\Client::$calls = [];
                new \Ngs\Redis\Classes\Cache\CacheRedis();
                checkConnection($config, true);

                \Predis\Client::$calls = [];
                $stats = invokePrivate($controller, 'getRedisStats', [$config]);
                checkSame(true, $stats['connected'], 'Statistics should connect.');
                checkConnection($config, false);

                \Predis\Client::$calls = [];
                invokePrivate($controller, 'clearRedisCache');
                checkConnection($config, false);

                Tools::$values = [
                    'NGS_REDIS_CONNECTION_TYPE' => $config['connection_type'],
                    'NGS_REDIS_HOST' => $config['host'], 'NGS_REDIS_PORT' => $config['port'],
                    'NGS_REDIS_UNIX_SOCKET' => $config['unix_socket'],
                    'NGS_REDIS_USERNAME' => $config['username'] ?? '',
                    'NGS_REDIS_AUTH' => $config['auth'], 'NGS_REDIS_DB' => $config['db'],
                    'NGS_REDIS_PREFIX' => $config['prefix'],
                    'NGS_REDIS_SENTINEL_HOSTS' => implode("\n", $config['sentinel_hosts']),
                    'NGS_REDIS_SENTINEL_SERVICE' => $config['sentinel_service'],
                    'NGS_REDIS_CLUSTER_NODES' => implode("\n", $config['cluster_nodes']),
                    'NGS_REDIS_BLACKLIST' => '', 'NGS_REDIS_BLACKLIST_CONTROLLERS' => '',
                ];
                \Predis\Client::$calls = [];
                // Test Connection must use unsaved form credentials, not the file.
                $staleConfig = array_replace($config, ['username' => 'different-saved-user']);
                file_put_contents($configPath, '<?php return ' . var_export($staleConfig, true) . ';');
                $controller->processTestConnection();
                checkConnection($config, false);

                file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
                $controller->renderForm();
                checkSame($config['username'] ?? '', HelperForm::$last->fields_value['NGS_REDIS_USERNAME'], 'Form must restore the saved username.');
                $inputs = array_column(HelperForm::$form[0]['form']['input'], null, 'name');
                checkSame(false, $inputs['NGS_REDIS_USERNAME']['required'], 'Username must remain optional.');

                Tools::$values['submitAddconfiguration'] = true;
                $controller->postProcess();
                $saved = require $configPath;
                checkSame($config['username'] ?? '', $saved['username'], 'Saved username must round trip safely.');
                checkSame($config['auth'], $saved['auth'], 'Saved password changed.');
                checkSame($saved['username'], Configuration::$values['NGS_REDIS_USERNAME'], 'DB username differs from file.');
                unlink($configPath);
                $fallback = invokePrivate($controller, 'loadConfig');
                checkSame($saved['username'], $fallback['username'], 'DB fallback loses username.');
                checkSame([], $controller->errors, 'Controller reported an error.');
                ++$cases;
            }
        }
        checkSame(15, \Predis\Client::$flushes, 'Single/Sentinel clear must execute FLUSHDB.');
        unset(Configuration::$values['NGS_REDIS_USERNAME']);
        checkSame('', invokePrivate($controller, 'loadConfig')['username'], 'Missing DB username must default to empty.');
        echo $cases . " configuration scenarios passed (runtime, statistics, clear, test, form, file and DB).\n";
    } finally {
        if (file_exists($configPath)) { unlink($configPath); }
        rmdir($testRoot . '/ngs_redis/config');
        rmdir($testRoot . '/ngs_redis');
        rmdir($testRoot);
    }
}
