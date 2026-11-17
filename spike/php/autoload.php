<?php

declare(strict_types=1);

// Minimal PSR-4 autoloader for the Phase 0 spike only.
// Phase 1 replaces this with Composer's autoloader (see docs/PHASE0-PLAN.md).
spl_autoload_register(static function (string $class): void {
    $prefix = 'AtLocal\\Spike\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
