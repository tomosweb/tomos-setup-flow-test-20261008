<?php

declare(strict_types=1);

/**
 * Phase 4 disposable-repository proof of concept. NOT a deployed endpoint.
 *
 * A caller must supply server-controlled, compatibility-reviewed release data,
 * an authenticated installation connection and an HMAC secret. Do not accept
 * the release data or connection from a request body. The HMAC authorizes one
 * explicit update attempt; no PAT or installation token is persisted.
 */
final class TomosSiteUpdatePrototype
{
    private const REPOSITORY = 'tomosweb/tomos-setup-flow-test-20261008';
    private const WORKFLOW = '.github/workflows/github-pages.yml';
    private $transport;
    private $secret;
    private $release;

    public function __construct(callable $transport, string $secret, array $release)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('Missing server secret.');
        }
        $this->transport = $transport;
        $this->secret = $secret;
        $this->release = $release;
    }

    private function api(string $mode, string $method, string $path, ?array $body = null, string $token = ''): array
    {
        $response = ($this->transport)($mode, $method, $path, $body, $token);
        if (!is_array($response) || !isset($response['status']) || !is_int($response['status'])) {
            throw new RuntimeException('github_unavailable');
        }
        return $response;
    }

    private function data(array $response): array
    {
        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($data)) {
            throw new RuntimeException('github_unavailable');
        }
        return $data;
    }

    private static function sha(string $value): bool
    {
        return (bool) preg_match('/\\A[a-f0-9]{40}\\z/', $value);
    }

    public static function blobSha(string $content): string
    {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    private function authorize(array $connection, string $repo, bool $forUpdate = false): array
    {
        // Experimental repository hard gate; the real API must use its authenticated
        // connection / repository binding and never trust client-supplied account IDs.
        if ($repo !== self::REPOSITORY || ($connection['account'] ?? '') !== 'tomosweb'
            || !isset($connection['installation_id']) || !is_int($connection['installation_id'])
            || $connection['installation_id'] <= 0) {
            throw new RuntimeException('repository_not_allowed');
        }

        $installationId = $connection['installation_id'];
        $response = $this->api('app', 'GET', '/app/installations/' . $installationId);
        if ($response['status'] !== 200) {
            throw new RuntimeException('auth_required');
        }
        $install = $this->data($response);
        $permissions = $install['permissions'] ?? null;
        if ((int) ($install['id'] ?? 0) !== $installationId ||
            strcasecmp((string) ($install['account']['login'] ?? ''), 'tomosweb') !== 0 ||
            !empty($install['suspended_at']) || !is_array($permissions)) {
            throw new RuntimeException('auth_required');
        }
        // Reading eligibility requires no new permissions; only applying does.
        $hasContents = in_array($permissions['contents'] ?? '', ['read', 'write'], true);
        $canUpdate = ($permissions['contents'] ?? '') === 'write'
            && ($permissions['workflows'] ?? '') === 'write';
        if (!$hasContents) {
            throw new RuntimeException('permission_required');
        }
        if ($forUpdate && !$canUpdate) {
            throw new RuntimeException('permission_required');
        }

        // Request least privilege: eligibility check only reads; update is separate
        // and limited to this single installation repository.
        $tokenPermissions = $forUpdate
            ? ['contents' => 'write', 'workflows' => 'write', 'metadata' => 'read']
            : ['contents' => 'read', 'metadata' => 'read'];
        $response = $this->api('app', 'POST', '/app/installations/' . $installationId . '/access_tokens', [
            'repositories' => ['tomos-setup-flow-test-20261008'],
            'permissions' => $tokenPermissions,
        ]);
        if ($response['status'] !== 201) {
            throw new RuntimeException('permission_required');
        }
        $token = (string) ($this->data($response)['token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('github_unavailable');
        }

        $response = $this->api('installation', 'GET', '/repos/' . self::REPOSITORY, null, $token);
        if ($response['status'] !== 200) {
            throw new RuntimeException('repository_not_allowed');
        }
        $remote = $this->data($response);
        if (strcasecmp((string) ($remote['full_name'] ?? ''), self::REPOSITORY) !== 0 ||
            strcasecmp((string) ($remote['owner']['login'] ?? ''), 'tomosweb') !== 0 ||
            ($remote['name'] ?? '') !== 'tomos-setup-flow-test-20261008' ||
            ($remote['default_branch'] ?? '') !== 'main' || !is_int($remote['id'] ?? null) ||
            $remote['id'] <= 0) {
            throw new RuntimeException('repository_not_allowed');
        }
        return ['token' => $token, 'id' => $remote['id'],
            'installation_id' => $installationId, 'can_update' => $canUpdate];
    }

    private function workflow(string $token): array
    {
        $path = '/repos/' . self::REPOSITORY . '/contents/' . self::WORKFLOW . '?ref=main';
        $response = $this->api('installation', 'GET', $path, null, $token);
        if ($response['status'] !== 200) {
            throw new RuntimeException('workflow_unavailable');
        }
        $data = $this->data($response);
        $sha = $data['sha'] ?? '';
        if (!is_string($sha) || !self::sha($sha) ||
            ($data['type'] ?? '') !== 'file' || ($data['encoding'] ?? '') !== 'base64') {
            throw new RuntimeException('workflow_unavailable');
        }
        $encoded = str_replace(["\r", "\n"], '', (string) ($data['content'] ?? ''));
        $contents = base64_decode($encoded, true);
        if (!is_string($contents) || strlen($contents) > 65536 || self::blobSha($contents) !== $sha) {
            throw new RuntimeException('workflow_unavailable');
        }
        return ['sha' => $sha, 'content' => $contents];
    }

    private function plan(array $workflow): array
    {
        $release = $this->release;
        $target = $release['core_sha'] ?? '';
        $compatible = $release['compatible_from'] ?? null;
        $sources = $release['workflow_sources'] ?? null;
        if (($release['schema'] ?? null) !== 1 || !is_string($target) || !self::sha($target) ||
            !is_array($compatible) || !is_array($sources) || !$sources || count($sources) > 128) {
            return ['status' => 'no_update'];
        }
        foreach ($compatible as $sha) {
            if (!is_string($sha) || !self::sha($sha)) { return ['status' => 'no_update']; }
        }
        $sourceCore = null;
        $seen = [];
        foreach ($sources as $source) {
            if (!is_array($source) || array_keys($source) !== ['blob_sha', 'core_sha'] ||
                !is_string($source['blob_sha']) || !self::sha($source['blob_sha']) ||
                !is_string($source['core_sha']) || !self::sha($source['core_sha']) ||
                isset($seen[$source['blob_sha']])) {
                return ['status' => 'no_update'];
            }
            $seen[$source['blob_sha']] = true;
            if ($source['blob_sha'] === $workflow['sha']) { $sourceCore = $source['core_sha']; }
        }
        if ($sourceCore === null) { return ['status' => 'no_update']; }
        $matches = [];
        $count = preg_match_all('/^  TOMOS_VERSION: ([0-9a-f]{40})[ \\t]*$/m', $workflow['content'], $matches, PREG_OFFSET_CAPTURE);
        if ($count !== 1 || $matches[1][0][0] !== $sourceCore) {
            return ['status' => 'no_update'];
        }
        if ($sourceCore === $target || !in_array($sourceCore, $compatible, true)) {
            return ['status' => 'no_update'];
        }
        $offset = $matches[1][0][1];
        $next = substr_replace($workflow['content'], $target, $offset, 40);
        return ['status' => 'update_available', 'expected_blob_sha' => $workflow['sha'],
            'content' => $next, 'target' => $target];
    }

    private function intent(array $claims): string
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $this->secret);
    }

    private function verifyIntent(string $intent): array
    {
        if (strlen($intent) > 2048 || substr_count($intent, '.') !== 1) {
            throw new RuntimeException('invalid_intent');
        }
        [$encoded, $mac] = explode('.', $intent);
        if (strlen($encoded) > 1400 || !preg_match('/\\A[A-Za-z0-9_-]+\\z/', $encoded) ||
            !hash_equals(hash_hmac('sha256', $encoded, $this->secret), $mac)) {
            throw new RuntimeException('invalid_intent');
        }
        $data = json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true), true);
        if (!is_array($data) || ($data['v'] ?? null) !== 1 ||
            !is_int($data['expires_at'] ?? null) || $data['expires_at'] <= time() ||
            $data['expires_at'] > time() + 300 || ($data['repo'] ?? '') !== self::REPOSITORY ||
            !is_int($data['installation_id'] ?? null) || !is_int($data['repository_id'] ?? null) ||
            !is_string($data['expected_blob_sha'] ?? null) || !self::sha($data['expected_blob_sha']) ||
            !is_string($data['target'] ?? null) || !self::sha($data['target'])) {
            throw new RuntimeException('invalid_intent');
        }
        return $data;
    }

    public function check(array $connection, string $repo): array
    {
        try {
            $auth = $this->authorize($connection, $repo);
            $plan = $this->plan($this->workflow($auth['token']));
            if ($plan['status'] !== 'update_available') {
                return ['show_update' => false];
            }
            if (!$auth['can_update']) {
                // A genuinely available update must not disappear only because
                // the installation has not approved the additional permission.
                // This URL points to GitHub's installation settings, not OAuth.
                return ['show_update' => true, 'can_update' => false,
                    'label' => 'GitHubで更新を許可',
                    'manage_url' => 'https://github.com/settings/installations/' . $auth['installation_id']];
            }
            $intent = $this->intent(['v' => 1, 'repo' => $repo, 'installation_id' => $auth['installation_id'],
                'repository_id' => $auth['id'], 'expected_blob_sha' => $plan['expected_blob_sha'],
                'target' => $plan['target'], 'expires_at' => time() + 300]);
            return ['show_update' => true, 'can_update' => true, 'label' => 'サイトを更新',
                'intent' => $intent];
        } catch (RuntimeException $e) {
            // UI: never show internal compatibility errors as update offers.
            return ['show_update' => false];
        }
    }

    /** Called only following a real explicit user POST with CSRF defense. */
    public function apply(array $connection, string $repo, string $intent): array
    {
        try {
            $claims = $this->verifyIntent($intent);
            $auth = $this->authorize($connection, $repo, true);
            if ($auth['installation_id'] !== $claims['installation_id'] || $auth['id'] !== $claims['repository_id']) {
                throw new RuntimeException('invalid_intent');
            }
            $plan = $this->plan($this->workflow($auth['token']));
            if ($plan['status'] !== 'update_available' ||
                $plan['expected_blob_sha'] !== $claims['expected_blob_sha'] ||
                $plan['target'] !== $claims['target']) {
                throw new RuntimeException('conflict');
            }
            $result = $this->api('installation', 'PUT',
                '/repos/' . self::REPOSITORY . '/contents/' . self::WORKFLOW, [
                'message' => 'Tomos: Update GitHub site Core',
                'content' => base64_encode($plan['content']),
                'sha' => $plan['expected_blob_sha'],
                'branch' => 'main',
            ], $auth['token']);
            if (in_array($result['status'], [409, 422], true)) {
                throw new RuntimeException('conflict');
            }
            if ($result['status'] === 403) { throw new RuntimeException('permission_required'); }
            if ($result['status'] !== 200) { throw new RuntimeException('github_unavailable'); }
            $answer = $this->data($result);
            $commit = $answer['commit']['sha'] ?? '';
            if (!is_string($commit) || !self::sha($commit)) {
                throw new RuntimeException('github_unavailable');
            }
            // A successful PUT does not mean GitHub Pages has deployed!
            return ['status' => 'build_pending', 'commit_sha' => $commit];
        } catch (RuntimeException $e) {
            return ['status' => $e->getMessage()];
        }
    }
}
