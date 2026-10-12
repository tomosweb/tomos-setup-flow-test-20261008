<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/scripts/SiteUpdatePrototype.php';

function requireTrue(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$old = '13e6d8f4634e687ae774a0e3a6d483f6a095b832';
$new = '5b92b99114d719c083040b4af77f547da9987b32';
$repo = 'tomosweb/tomos-setup-flow-test-20261008';
$connection = ['installation_id' => 1234, 'account' => 'tomosweb'];
$original = file_get_contents(dirname(__DIR__) . '/.github/workflows/github-pages.yml');
requireTrue(is_string($original), 'Could not read real fixture');
$fixtures = 0;

function newFixture(string $original, string $old, string $new, array $connection, array $options = []): array
{
    $context = new stdClass();
    $context->workflow = $original;
    $context->writes = 0;
    $context->requests = [];
    $context->permissions = $options['permissions'] ?? ['contents' => 'write', 'workflows' => 'write'];
    $context->forceConflict = !empty($options['forceConflict']);
    $context->branch = $options['branch'] ?? 'main';
    $context->repository = 'tomosweb/tomos-setup-flow-test-20261008';
    $release = [
        'schema' => 1,
        'core_sha' => $new,
        'compatible_from' => [$old],
        'workflow_sources' => [
            ['blob_sha' => TomosSiteUpdatePrototype::blobSha($original), 'core_sha' => $old],
        ],
    ];

    $transport = static function ($mode, $method, $path, $body, $token) use ($context): array {
        $context->requests[] = [$mode, $method, $path, $body, $token];
        if ($mode === 'app' && $method === 'GET' && $path === '/app/installations/1234') {
            return ['status' => 200, 'body' => json_encode([
                'id' => 1234, 'account' => ['login' => 'tomosweb'], 'permissions' => $context->permissions,
            ])];
        }
        if ($mode === 'app' && $method === 'POST' && $path === '/app/installations/1234/access_tokens') {
            if (($body['permissions']['workflows'] ?? '') !== 'write' ||
                ($body['repositories'] ?? []) !== ['tomos-setup-flow-test-20261008']) {
                throw new RuntimeException('Token permission or repository scope mismatch.');
            }
            return ['status' => 201, 'body' => json_encode(['token' => 'test-token'])];
        }
        if ($mode === 'installation' && $method === 'GET' && $path === '/repos/' . $context->repository) {
            return ['status' => 200, 'body' => json_encode([
                'id' => 9001, 'name' => 'tomos-setup-flow-test-20261008',
                'full_name' => $context->repository, 'owner' => ['login' => 'tomosweb'],
                'default_branch' => $context->branch,
            ])];
        }
        if ($mode === 'installation' && $method === 'GET' && strpos($path, '/contents/.github/workflows/github-pages.yml?ref=main') !== false) {
            return ['status' => 200, 'body' => json_encode([
                'type' => 'file', 'encoding' => 'base64', 'content' => base64_encode($context->workflow),
                'sha' => TomosSiteUpdatePrototype::blobSha($context->workflow),
            ])];
        }
        if ($mode === 'installation' && $method === 'PUT' && strpos($path, '/contents/.github/workflows/github-pages.yml') !== false) {
            $context->writes++;
            if ($context->forceConflict) {
                return ['status' => 409, 'body' => '{}'];
            }
            if ($body['branch'] !== 'main' || $body['sha'] !== TomosSiteUpdatePrototype::blobSha($context->workflow)) {
                throw new RuntimeException('Unsafe workflow write.');
            }
            $updated = base64_decode($body['content'], true);
            if (!is_string($updated)) { throw new RuntimeException('Invalid update bytes.'); }
            $context->workflow = $updated;
            return ['status' => 200, 'body' => json_encode(['commit' => ['sha' => str_repeat('a', 40)]])];
        }
        throw new RuntimeException('Unexpected request: ' . $method . ' ' . $path);
    };
    $updater = new TomosSiteUpdatePrototype($transport, str_repeat('secret', 8), $release);
    return [$updater, $context];
}

[$updater, $ctx] = newFixture($original, $old, $new, $connection);
requireTrue(TomosSiteUpdatePrototype::blobSha('test content' . "\n") === 'd670460b4b4aece5915caf5c68d12f560a9fe3e4', 'Git blob hash algorithm');
$check = $updater->check($connection, $repo);
requireTrue(($check['show_update'] ?? false) === true && $check['label'] === 'サイトを更新', 'Approved update must be visible');
requireTrue($ctx->writes === 0, 'Check must never write');
$result = $updater->apply($connection, $repo, $check['intent']);
requireTrue($result['status'] === 'build_pending' && $result['commit_sha'] === str_repeat('a', 40), 'Write must be pending, not finished');
requireTrue($ctx->writes === 1 && strpos($ctx->workflow, $new) !== false, 'Updated version only');
requireTrue($updater->check($connection, $repo) === ['show_update' => false], 'Repeat check must be hidden');
requireTrue($updater->apply($connection, $repo, $check['intent'])['status'] === 'conflict', 'Repeat apply must not rewrite');
$fixtures++;

[$updater, $ctx] = newFixture($original, $old, $new, $connection, ['permissions' => ['contents' => 'write']]);
requireTrue($updater->check($connection, $repo) === ['show_update' => false], 'Missing workflow permission must be hidden');
requireTrue($ctx->writes === 0 && count($ctx->requests) === 1, 'Permission denial must stop before token request');
$fixtures++;

[$updater, $ctx] = newFixture($original, $old, $new, $connection);
requireTrue($updater->check($connection, 'someone/other-repo') === ['show_update' => false], 'Unlisted repository must be hidden');
requireTrue(count($ctx->requests) === 0, 'Invalid repo must not access GitHub');
$fixtures++;

[$updater, $ctx] = newFixture($original, $old, $new, $connection, ['branch' => 'development']);
requireTrue($updater->check($connection, $repo) === ['show_update' => false], 'Wrong default branch must be rejected');
$fixtures++;

[$updater, $ctx] = newFixture($original, $old, $new, $connection);
$check = $updater->check($connection, $repo);
$ctx->workflow .= "# independently customized\n";
requireTrue($updater->apply($connection, $repo, $check['intent'])['status'] === 'conflict', 'Concurrent customization must stop');
requireTrue($ctx->writes === 0, 'Conflict must never reach PUT');
$fixtures++;

[$updater, $ctx] = newFixture($original, $old, $new, $connection, ['forceConflict' => true]);
$check = $updater->check($connection, $repo);
requireTrue($updater->apply($connection, $repo, $check['intent'])['status'] === 'conflict', 'GitHub 409 must stop');
$fixtures++;

[$updater, $ctx] = newFixture($original, $old, $new, $connection);
$check = $updater->check($connection, $repo);
$invalid = $check['intent'];
$invalid[5] = $invalid[5] === 'A' ? 'B' : 'A';
requireTrue($updater->apply($connection, $repo, $invalid)['status'] === 'invalid_intent', 'Tampered intent must stop');
requireTrue($ctx->writes === 0, 'Tampered intent must not write');
$fixtures++;

[$updater, $ctx] = newFixture($original . "# user modified\n", $old, $new, $connection);
$check = $updater->check($connection, $repo);
requireTrue($check === ['show_update' => false], 'Unknown workflow must not offer update');
$fixtures++;

echo "SiteUpdatePrototype: {$fixtures} scenarios PASS\n";
