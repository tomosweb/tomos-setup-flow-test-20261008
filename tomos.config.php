<?php

$githubRepository = getenv('GITHUB_REPOSITORY');
if (!is_string($githubRepository) || !preg_match('/^([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)$/D', $githubRepository, $matches)) {
    throw new RuntimeException('GITHUB_REPOSITORY must be set to owner/repository.');
}

$owner = strtolower($matches[1]);
$repository = strtolower($matches[2]);
$siteName = getenv('TOMOS_SITE_NAME');
if (!is_string($siteName) || trim($siteName) === '') {
    $siteName = $repository;
}

$basePath = $repository === $owner . '.github.io' ? '' : '/' . $repository;
$tomosRoot = getenv('TOMOS_ROOT');
if (!is_string($tomosRoot) || trim($tomosRoot) === '') {
    throw new RuntimeException('TOMOS_ROOT must point to the pinned Tomos checkout.');
}

// Test fixture: site images are owned by the repository, not the pinned Core.
$testAssetDir = __DIR__ . '/theme-assets';
if (!is_dir($testAssetDir)) mkdir($testAssetDir, 0755, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/zY4AAAAASUVORK5CYII=');
file_put_contents($testAssetDir . '/ogp.png', $png);
file_put_contents($testAssetDir . '/favicon.png', $png);
register_shutdown_function(static function (): void {
    $output = __DIR__ . '/build/tomos-github-pages';
    $html = @file_get_contents($output . '/index.html');
    $ok = is_string($html) && str_contains($html, '/theme-assets/ogp.png')
        && str_contains($html, '/theme-assets/favicon.png')
        && is_file($output . '/theme-assets/ogp.png')
        && is_file($output . '/theme-assets/favicon.png');
    if (!$ok) throw new RuntimeException("CONFIG_BRANDING_NO_WORKFLOW_FAIL");
    echo "CONFIG_BRANDING_NO_WORKFLOW_PASS\n";
});

// TOMOS_SITE_BRANDING_V1
// Site-owned branding images; no extra GitHub App permissions are required.
$tomosBrandingSource = __DIR__ . '/theme-assets';
$tomosBrandingDestination = rtrim($tomosRoot, DIRECTORY_SEPARATOR) . '/theme-assets';
if (is_dir($tomosBrandingSource)) {
    foreach (['ogp', 'favicon'] as $tomosBrandingKind) {
        foreach (['png', 'jpg', 'webp'] as $tomosBrandingExtension) {
            $tomosBrandingName = $tomosBrandingKind . '.' . $tomosBrandingExtension;
            $tomosBrandingFile = $tomosBrandingSource . '/' . $tomosBrandingName;
            if (!is_file($tomosBrandingFile) || is_link($tomosBrandingFile)) continue;
            if (filesize($tomosBrandingFile) > 10485760) {
                throw new RuntimeException('Site branding image exceeds 10 MB');
            }
            if (!is_dir($tomosBrandingDestination)
                && !mkdir($tomosBrandingDestination, 0755, true)
                && !is_dir($tomosBrandingDestination)) {
                throw new RuntimeException('Cannot prepare site branding assets');
            }
            if (!copy($tomosBrandingFile, $tomosBrandingDestination . '/' . $tomosBrandingName)) {
                throw new RuntimeException('Cannot stage site branding image');
            }
        }
    }
}

return [
    'site' => [
        'name' => $siteName,
        'description' => '',
        'url' => 'https://' . $owner . '.github.io',
        'base_path' => $basePath,
        'language' => 'ja',
    ],
    'theme' => [
        'name' => 'tomos-minimal',
    ],
    'paths' => [
        'content_dir' => __DIR__ . '/content',
        'cache_dir' => __DIR__ . '/.tomos-cache',
        'theme_dir' => rtrim($tomosRoot, DIRECTORY_SEPARATOR) . '/themes',
    ],
    'features' => [
        'search' => true,
        'tags' => true,
        'rss' => true,
        'sitemap' => true,
    ],
];
