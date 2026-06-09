<?php

declare(strict_types=1);

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . PHP_EOL . 'Missing: ' . $needle);
    }
}

$projectRoot = dirname(__DIR__);
$workflow = file_get_contents($projectRoot . '/.github/workflows/release.yml');
$gitignore = file_get_contents($projectRoot . '/.gitignore');

assertContainsText(
    "--exclude='config/redis.php'",
    $workflow,
    'Release archives must not overwrite the runtime Redis configuration during updates.'
);
assertContainsText(
    'config/redis.php',
    $gitignore,
    'The runtime Redis configuration must not be committed accidentally.'
);

echo "2 tests passed.\n";
