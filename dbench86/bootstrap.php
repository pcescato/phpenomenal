<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'DBench86\\') === 0) {
        $path = __DIR__ . '/src/' . strtr(substr($class, 9), '\\', '/') . '.php';
    } elseif (strpos($class, 'Tests\\') === 0) {
        $path = __DIR__ . '/tests/' . strtr(substr($class, 6), '\\', '/') . '.php';
    } else {
        return;
    }

    if (file_exists($path)) {
        require $path;
    }
});

\DBench86\Configuration\Environment::load(__DIR__);

// Explicitly load ExecutionMetrics to ensure EXECUTION_METRICS constant is defined
require_once __DIR__ . '/src/Benchmark/ExecutionMetrics.php';
