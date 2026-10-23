<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/composer.json';
$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$required = ['laravel/framework', 'laravel/sanctum', 'filament/filament', 'spatie/laravel-permission'];
foreach ($required as $package) {
    if (!isset($data['require'][$package])) {
        fwrite(STDERR, "Missing package: {$package}\n");
        exit(1);
    }
}
foreach (['scripts', 'extra'] as $key) {
    if (isset($data[$key]) && !is_array($data[$key])) {
        fwrite(STDERR, "Invalid composer field: {$key}\n");
        exit(1);
    }
}
echo "S1 backend dependency preflight: PASS (not an application boot test)\n";
