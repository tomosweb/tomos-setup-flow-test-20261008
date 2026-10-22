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


// Research integration: immutable verified Core, selected by Tomos (never by end users).
$selectedCoreSha = '76d2aa7a26e8f3672b1c2ac496707fcf6b6311f8';
require_once __DIR__ . '/tomos-core-loader.php';
$selectedRoot = tomosSiteLoadApprovedCore($selectedCoreSha, $tomosRoot, __DIR__);
$tomosRoot = $selectedRoot;
echo "CORE_SELECTED_WITHOUT_WORKFLOWS_PASS " . $selectedCoreSha . "\n";

// TEMPORARY TEST ONLY: exercise site-owned branding with the original pinned Core
// without editing GitHub Actions. Remove this branch after verification.
$brandingRoot = __DIR__ . '/theme-assets';
if (!is_dir($brandingRoot) && !mkdir($brandingRoot, 0755, true)) {
    throw new RuntimeException('Cannot create site branding fixture directory');
}
$brandingPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lGQAAAAASUVORK5CYII=', true);
if (!is_string($brandingPng)) {
    throw new RuntimeException('Invalid test PNG');
}
foreach (['ogp.png', 'favicon.png'] as $filename) {
    $sitePath = $brandingRoot . '/' . $filename;
    if (file_put_contents($sitePath, $brandingPng) === false) {
        throw new RuntimeException('Unable to prepare test branding asset');
    }
    $coreAssetsDir = rtrim($tomosRoot, DIRECTORY_SEPARATOR) . '/theme-assets';
    if (!is_dir($coreAssetsDir) && !mkdir($coreAssetsDir, 0755, true)) {
        throw new RuntimeException('Unable to prepare pinned Core assets directory');
    }
    if (!copy($sitePath, $coreAssetsDir . '/' . $filename)) {
        throw new RuntimeException('Unable to copy site branding into pinned Core');
    }
}
register_shutdown_function(static function () use ($selectedRoot): void {
    $out = __DIR__ . '/build/tomos-github-pages';
    $html = @file_get_contents($out . '/index.html');
    $loadedBuilder = new ReflectionClass('Tomos\\StaticSiteBuilder');
    $builderSource = (string) @file_get_contents($loadedBuilder->getFileName());
    $actualCoreChanged = str_starts_with($loadedBuilder->getFileName(), $selectedRoot . '/')
        && str_contains($builderSource, 'TOMOS_TEST_CORE_SWITCH_20261022');
    echo $actualCoreChanged ? "NEW_CORE_IMPLEMENTATION_LOADED_PASS\n" : "NEW_CORE_IMPLEMENTATION_LOADED_FAIL\n";
    $ok = $actualCoreChanged && is_string($html)
        && str_contains($html, '/theme-assets/ogp.png')
        && str_contains($html, '/theme-assets/favicon.png')
        && is_file($out . '/theme-assets/ogp.png')
        && is_file($out . '/theme-assets/favicon.png');
    echo $ok ? "BRANDING_WITHOUT_WORKFLOWS_PASS\n" : "BRANDING_WITHOUT_WORKFLOWS_FAIL\n";
    if (!$ok) { exit(1); }
});

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
