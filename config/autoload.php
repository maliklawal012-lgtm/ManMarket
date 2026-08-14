<?php
declare(strict_types=1);

/**
 * Autoloader minimal, sans Composer.
 * Mappe le namespace App\ vers /src, style PSR-4.
 * Ex: App\Services\WalletService -> src/Services/WalletService.php
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
