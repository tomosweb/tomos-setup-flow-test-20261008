<?php
declare(strict_types=1);

/**
 * Tomos GitHub edition Core loader (proof of integration, not released).
 * Called by tomos.config.php while the existing pinned workflow stays unchanged.
 * Only immutable, administrator-approved SHA values may be passed.
 */
function tomosSiteLoadApprovedCore(string $sha, string $legacyRoot, string $siteRoot): string
{
    if (!preg_match('/\A[a-f0-9]{40}\z/D', $sha)) {
        throw new RuntimeException('Invalid immutable Tomos Core version');
    }
    $coreDir = rtrim((string)(getenv('RUNNER_TEMP') ?: sys_get_temp_dir()), DIRECTORY_SEPARATOR)
        . '/tomos-approved-core-' . $sha;
    $repository = 'https://github.com/tomosweb/tomos.git';
    if (!is_dir($coreDir . '/.git')) {
        $command = 'git clone --no-checkout --filter=blob:none '
            . escapeshellarg($repository) . ' ' . escapeshellarg($coreDir) . ' 2>&1';
        exec($command, $log, $code);
        if ($code !== 0) throw new RuntimeException('Tomos Core checkout unavailable');
    }
    $command = 'git -C ' . escapeshellarg($coreDir) . ' fetch --depth 1 origin '
        . escapeshellarg($sha) . ' 2>&1';
    exec($command, $log, $code);
    if ($code !== 0) throw new RuntimeException('Approved Tomos Core not available');
    $command = 'git -C ' . escapeshellarg($coreDir) . ' checkout --force --detach '
        . escapeshellarg($sha) . ' 2>&1';
    exec($command, $log, $code);
    if ($code !== 0) throw new RuntimeException('Approved Tomos Core checkout failed');
    $actual = trim((string)shell_exec(
        'git -C ' . escapeshellarg($coreDir) . ' rev-parse HEAD 2>&1'
    ));
    if (!hash_equals($sha, $actual)) throw new RuntimeException('Tomos Core SHA mismatch');
    if (!is_file($coreDir . '/core/StaticSiteBuilder.php')
        || !is_file($coreDir . '/core/StaticSiteConfig.php')
        || !is_file($coreDir . '/tools/build-static-site.php')) {
        throw new RuntimeException('Approved Tomos Core is incompatible with GitHub edition');
    }
    spl_autoload_register(static function (string $class) use ($coreDir): void {
        if (strncmp($class, 'Tomos\\', 6) !== 0) return;
        $file = $coreDir . '/core/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) require_once $file;
    }, true, true);
    putenv('TOMOS_ROOT=' . $coreDir);
    return $coreDir;
}
