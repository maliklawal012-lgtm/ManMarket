<?php
declare(strict_types=1);

/**
 * Chargeur .env minimal, sans dependance externe.
 * Parse KEY=VALUE (commentaires # ignores, lignes vides ignorees).
 * Ne modifie jamais getenv() systeme : stocke dans un registre prive,
 * accessible uniquement via env().
 */
function env_load(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    if (!is_file($path)) {
        throw new RuntimeException("Fichier .env introuvable : {$path}. Copiez .env.example vers .env et renseignez vos valeurs.");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");
        $GLOBALS['__env'][$key] = $value;
    }
}

function env(string $key, ?string $default = null): ?string
{
    return $GLOBALS['__env'][$key] ?? $default;
}

function env_required(string $key): string
{
    $value = env($key);
    if ($value === null || $value === '') {
        throw new RuntimeException("Variable d'environnement requise manquante : {$key}");
    }

    return $value;
}

env_load(__DIR__ . '/../.env');
