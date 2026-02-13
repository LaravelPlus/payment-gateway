#!/bin/bash

# Payment Gateway Package Installer
# Run this script from your Laravel project root after copying the package

set -e

echo "╔════════════════════════════════════════════╗"
echo "║   Payment Gateway - Composer Setup         ║"
echo "╚════════════════════════════════════════════╝"
echo ""

# Check if packages directory exists
if [ ! -d "packages/laravelplus/payment-gateway" ]; then
    echo "❌ Error: Package not found at packages/laravelplus/payment-gateway"
    echo "   Please copy the package first:"
    echo "   mkdir -p packages/laravelplus && cp -r /path/to/payment-gateway packages/laravelplus/"
    exit 1
fi

# Check if composer.json exists
if [ ! -f "composer.json" ]; then
    echo "❌ Error: composer.json not found. Are you in a Laravel project root?"
    exit 1
fi

echo "📦 Adding package repository to composer.json..."

# Use PHP to modify composer.json (safer than sed/jq)
php << 'PHP'
<?php
$composerFile = 'composer.json';
$composer = json_decode(file_get_contents($composerFile), true);

// Add repository if not exists
$repoExists = false;
if (isset($composer['repositories'])) {
    foreach ($composer['repositories'] as $repo) {
        if (isset($repo['url']) && str_contains($repo['url'], 'payment-gateway')) {
            $repoExists = true;
            break;
        }
    }
}

if (!$repoExists) {
    $composer['repositories'][] = [
        'type' => 'path',
        'url' => 'packages/laravelplus/payment-gateway',
        'options' => ['symlink' => true]
    ];
}

// Add require if not exists
if (!isset($composer['require']['laravelplus/payment-gateway'])) {
    $composer['require']['laravelplus/payment-gateway'] = '@dev';
}

file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "✅ composer.json updated\n";
PHP

echo ""
echo "📥 Running composer update..."
composer update laravelplus/payment-gateway --no-interaction

echo ""
echo "🚀 Running package installer..."
php artisan payment-gateway:install

echo ""
echo "✅ Installation complete!"
