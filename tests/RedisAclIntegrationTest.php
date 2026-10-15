<?php

declare(strict_types=1);

// Run on Linux/WSL with Redis 6+ installed: php tests/RedisAclIntegrationTest.php
// Starts its own loopback-only server; never connects to an existing Redis instance.
require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open')) {
    fwrite(STDERR, "This integration test requires Linux/WSL and proc_open.\n");
    exit(1);
}

class CacheCore { protected $blacklist = []; }
class ModuleAdminController
{
    public $module;
    public $errors = [];
    public $confirmations = [];
}
class Tools
{
    public static $values = [];
    public static function getValue($key, $default = false) { return self::$values[$key] ?? $default; }
}

function expectAcl(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporaryBase = realpath(sys_get_temp_dir());
$testRoot = $temporaryBase . '/ngs-redis-acl-' . bin2hex(random_bytes(8));
expectAcl(mkdir($testRoot, 0700), 'Cannot create isolated test directory.');
$process = null;
$clients = [];
$cache = null;
$exitCode = 0;

try {
    mkdir($testRoot . '/ngs_redis', 0700);
    mkdir($testRoot . '/ngs_redis/config', 0700);
    define('_PS_VERSION_', '8.2.1');
    define('_PS_MODULE_DIR_', $testRoot . '/');
    define('_DB_PREFIX_', 'ps_');
    define('_COOKIE_KEY_', bin2hex(random_bytes(32)));
    require dirname(__DIR__) . '/classes/cache/CacheRedis.php';
    require dirname(__DIR__) . '/controllers/admin/AdminNgsRedisConfigurationController.php';

    class IntegrationCacheRedis extends Ngs\Redis\Classes\Cache\CacheRedis
    {
        public function connected(): bool { return $this->is_connected; }
        public function disconnect(): void { $this->client->disconnect(); }
    }

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expectAcl($listener !== false, 'Cannot allocate a loopback port.');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $socket = $testRoot . '/redis.sock';
    $password = bin2hex(random_bytes(24));
    $legacyPassword = bin2hex(random_bytes(24));
    $configPath = $testRoot . '/ngs_redis/config/redis.php';
    $serverConfig = implode("\n", [
        'bind 127.0.0.1', 'protected-mode yes', 'port ' . $port,
        'unixsocket ' . $socket, 'unixsocketperm 700',
        'daemonize no', 'save ""', 'appendonly no', 'dir ' . $testRoot,
        'user default on >' . $legacyPassword . ' ~* +@all',
        'user integration-user on >' . $password . ' ~* +@all',
        'user 0 on >0 ~* +@all', '',
    ]);
    file_put_contents($testRoot . '/redis.conf', $serverConfig);
    chmod($testRoot . '/redis.conf', 0600);
    $process = proc_open(['redis-server', $testRoot . '/redis.conf'], [
        0 => ['pipe', 'r'],
        1 => ['file', $testRoot . '/redis.log', 'a'],
        2 => ['file', $testRoot . '/redis.log', 'a'],
    ], $pipes);
    expectAcl(is_resource($process), 'Cannot start redis-server.');
    fclose($pipes[0]);

    // The private Unix socket proves startup belongs to this test, even if the
    // dynamically selected TCP port was taken before redis-server could bind it.
    $admin = new Predis\Client([
        'scheme' => 'unix', 'path' => $socket, 'password' => $legacyPassword,
        'timeout' => 1, 'read_write_timeout' => 1,
    ]);
    $clients[] = $admin;
    $ready = false;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        expectAcl(proc_get_status($process)['running'], 'Isolated Redis exited during startup.');
        try {
            $ready = (string) $admin->ping() === 'PONG';
            if ($ready) { break; }
        } catch (Predis\Connection\ConnectionException $e) {
            $admin->disconnect();
        }
        usleep(50000);
    }
    expectAcl($ready, 'Isolated Redis did not become ready.');
    $info = $admin->info('server');
    echo 'Redis ' . $info['Server']['redis_version'] . ', PHP ' . PHP_VERSION
        . ', Predis ' . Predis\Client::VERSION . "\n";

    $cases = [
        'named ACL user' => ['integration-user', $password, true],
        'wrong username' => ['missing-user', $password, false],
        'wrong password' => ['integration-user', 'incorrect-password', false],
        'legacy password only' => [null, $legacyPassword, true],
        'empty username' => ['', $legacyPassword, true],
        'zero credentials' => ['0', '0', true],
        'no credentials' => [null, '', false],
    ];
    $checks = 0;
    foreach (['tcp', 'unix'] as $scheme) {
        foreach ($cases as $label => [$username, $auth, $success]) {
            $parameters = [
                'scheme' => $scheme, 'host' => '127.0.0.1', 'port' => $port,
                'path' => $socket, 'database' => 2, 'timeout' => 1, 'read_write_timeout' => 1,
            ];
            if ($username !== null) { $parameters['username'] = $username; }
            if ($auth !== '') { $parameters['password'] = $auth; }
            $client = new Predis\Client($parameters);
            $clients[] = $client;
            $authenticated = false;
            try {
                $authenticated = (string) $client->ping() === 'PONG';
                $expectedUser = $username === null || $username === '' ? 'default' : $username;
                expectAcl((string) $client->executeRaw(['ACL', 'WHOAMI']) === $expectedUser,
                    'Authenticated as an unexpected user.');
            } catch (Predis\Connection\ConnectionException | Predis\Response\ServerException $e) {
                expectAcl(strpos($e->getMessage(), 'WRONGPASS') !== false
                    || strpos($e->getMessage(), 'NOAUTH') !== false,
                    'Failure must be authentication rejection, not a transport error.');
            }
            expectAcl($authenticated === $success, "$scheme / $label: Predis authentication mismatch.");
            ++$checks;
            $client->disconnect();

            $settings = [
                'connection_type' => 'single', 'host' => '127.0.0.1', 'port' => $port,
                'unix_socket' => $scheme === 'unix' ? $socket : '',
                'auth' => $auth, 'db' => 2, 'prefix' => 'acl_integration_',
            ];
            if ($username !== null) { $settings['username'] = $username; }
            file_put_contents($configPath, '<?php return ' . var_export($settings, true) . ';');
            $cache = new IntegrationCacheRedis();
            expectAcl($cache->connected() === $success, "$scheme / $label: runtime connection mismatch.");
            $key = bin2hex(random_bytes(8));
            if ($success) {
                $value = ['case' => $label, 'transport' => $scheme];
                expectAcl((bool) $cache->set($key, $value, 30), 'Runtime SET failed.');
                expectAcl($cache->get($key) === $value, 'Runtime GET failed to round-trip payload.');
                // A separate connection verifies the configured DB and prefix.
                $client->connect();
                expectAcl($client->get('acl_integration_' . $key) !== null, 'DB or key prefix was lost.');
                $client->del(['acl_integration_' . $key]);
                $client->disconnect();
            } else {
                expectAcl($cache->set($key, 'must-not-be-written') === false, 'Failed auth allowed a cache write.');
                expectAcl($cache->get($key) === false, 'Failed auth allowed a cache read.');
            }
            ++$checks;
            $cache->disconnect();
            $cache = null;

            // Exercise the actual admin "Test connection" action with real Predis.
            $controller = (new ReflectionClass('AdminNgsRedisConfigurationController'))->newInstanceWithoutConstructor();
            $controller->module = new class { public function l($text) { return $text; } };
            Tools::$values = [
                'NGS_REDIS_CONNECTION_TYPE' => 'single', 'NGS_REDIS_HOST' => '127.0.0.1',
                'NGS_REDIS_PORT' => $port, 'NGS_REDIS_UNIX_SOCKET' => $settings['unix_socket'],
                'NGS_REDIS_AUTH' => $auth, 'NGS_REDIS_DB' => 2,
            ];
            if ($username !== null) { Tools::$values['NGS_REDIS_USERNAME'] = $username; }
            $controller->processTestConnection();
            expectAcl(count($controller->confirmations) === ($success ? 1 : 0)
                && count($controller->errors) === ($success ? 0 : 1),
                "$scheme / $label: admin connection test mismatch.");
            ++$checks;
            echo "PASS $scheme / $label (Predis, runtime, admin)\n";
        }
    }
    echo "$checks real Redis ACL integration checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($cache !== null) { $cache->disconnect(); }
    foreach ($clients as $client) { $client->disconnect(); }
    if (is_resource($process)) {
        // SIGTERM targets only the proc_open child; persistence is disabled.
        proc_terminate($process);
        for ($attempt = 0; $attempt < 100 && proc_get_status($process)['running']; ++$attempt) {
            usleep(50000);
        }
        if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
        proc_close($process);
    }
    // Validate the exact, freshly created directory before deleting known files.
    expectAcl(realpath($testRoot) === $testRoot && dirname($testRoot) === $temporaryBase
        && !is_link($testRoot) && preg_match('/^ngs-redis-acl-[a-f0-9]{16}$/', basename($testRoot)) === 1,
        'Refusing cleanup of an unexpected directory.');
    foreach (['ngs_redis/config/redis.php', 'redis.conf', 'redis.log', 'redis.sock'] as $relative) {
        $path = $testRoot . '/' . $relative;
        if (file_exists($path) || is_link($path)) { unlink($path); }
    }
    foreach (['/ngs_redis/config', '/ngs_redis', ''] as $relative) {
        if (is_dir($testRoot . $relative)) { rmdir($testRoot . $relative); }
    }
    expectAcl(!file_exists($testRoot), 'Temporary test directory was not removed.');
    echo "Isolated Redis stopped and temporary files removed.\n";
}
exit($exitCode);
