# Tomos GitHub edition: site update experiment (Phase 3)

Status: experimental only. Do not merge, deploy or change a user's workflow.

## Confirmed baseline

- Existing Pages workflow pins `TOMOS_VERSION` to a specific commit.
- Updating the template does not migrate existing site repositories.
- This repository's actual workflow Git blob SHA is `3a8e5ab270a4276a646cde9cb7a3c59deadac159`.
- The workflow deploy job runs on `main` push / explicit workflow dispatch, not on pull requests.

## Prototype behavior

- `check_tomos_core_update.py`: compare current core SHA against approved compatible release metadata.
- `site_update_presentation.py`: show **サイトを更新** only when approval has been established. Hide internal `requires_review` / malformed input statuses from users.
- `plan_workflow_update.py`: an **offline read-only** plan for changing *only* the 40-character `TOMOS_VERSION` in the approved, unchanged workflow. The planner computes and validates Git blob SHA and produces the expected precondition SHA and proposed new blob SHA; it does not send GitHub requests or write files.
- The sample `stable-core-manifest.json` approves **no upgrade**. It registers one known legacy workflow and the exact same current Core version so it cannot cause an upgrade.

## Checks and tests

Run from the repository root:

```sh
python3 -m unittest discover -s tests -p 'test_*.py' -v
python3 scripts/plan_workflow_update.py \
  --workflow .github/workflows/github-pages.yml \
  --expected-blob-sha 3a8e5ab270a4276a646cde9cb7a3c59deadac159 \
  --release docs/experiments/stable-core-manifest.json
```

The initial 19 tests passed against a local copy of the branch files and the actual workflow content on 2026-10-12. The real workflow hash was reproduced exactly. This was **not** a hosted GitHub Actions test or live deployment.

Tested cases: approved update, unchanged version, unapproved update hidden, invalid input, customized workflow, stale read, duplicate version field, manifest mismatch, duplicate sources, and actual workflow baseline.

## Required next gates

1. Decide and implement **routine auto-update** independently of exceptional workflow migration: a developer-controlled stable channel only after compatibility testing, not an arbitrary latest branch. Initial pinned-to-channel migration is a separate, explicit action.
2. The release manifest must be loaded from a trusted developer-controlled source, never from editable content in the user's site repository. Add integrity, freshness and rollback policy before production use.
3. Confirm GitHub App **Workflows** permission and explicit user approval for workflow writes. A Contents-only permission must not be assumed sufficient.
4. Add authenticated update endpoint with optimistic GitHub file SHA precondition; re-read current repo state before writing; fail without force on conflicts or GitHub errors. No persistent user PAT.
5. Test on a disposable repository with real GitHub workflow writes and actual Pages build before allowing updates to any user repository.
6. For structural changes, use separate approved complete-file migrations; never apply a version-line patch to an unknown or user-modified workflow.
7. Failed build must not publish a new artifact; do not report completion before Actions verifies success. Do not silently roll back user commits.

## Rollout constraints

No production workflow, site content, settings or deployed Pages artifact has been changed. This prototype is **not** a production updater.

## Phase 4 prototype verification (2026-10-12)

- Implemented `scripts/SiteUpdatePrototype.php` as a **non-deployed** PHP GitHub App gateway prototype, with mockable transport and a repository hard gate for `tomosweb/tomos-setup-flow-test-20261008`.
- Uses only a token scoped to that one repository and requests `contents:write`, `workflows:write`, `metadata:read`; refuses an installation that lacks `workflows:write`.
- Reads the workflow and verifies its Git blob SHA against the developer-approved manifest, validates signed short-lived user consent (intent token), re-reads immediately before the commit, and uses GitHub Contents API `sha` precondition on `main`.
- A successful GitHub PUT returns `build_pending`, **not** `completed`; actual deployment verification remains a separate step.
- Unknown, customized, stale or conflicting workflow is never overwritten. No persistently stored user credential or PAT.
- Added `tests/site_update_prototype_check.php` to cover eight mocked gateway scenarios.
- Kept original workflow bytes in `tests/fixtures/github-pages-legacy.yml`. The experimental branch changed its own workflow Core SHA from `13e6d8f4634e687ae774a0e3a6d483f6a095b832` to `5b92b99114d719c083040b4af77f547da9987b32` and added Python/PHP tests to the PR build job.
- GitHub Actions [run 38216930649](https://github.com/tomosweb/tomos-setup-flow-test-20261008/actions/runs/38216930649): **19 Python tests PASS; 8 PHP scenarios PASS; Static Site Builder contract PASS; site build and artifact PASS; Pages deployment SKIPPED**.
- The experiment's actual workflow modification was made through the **ChatGPT GitHub connector**. This does NOT prove that the separate **Tomos GitHub App** has permission to update workflow files.
- Current official-site `publish-api/github/_common.php` requests `contents` and `metadata` only; `workflows` permission is not declared in the documented app configuration. Existing Tomos App installations must approve newly requested repository permissions before use.

### Not yet verified / deliberately blocked

1. The real Tomos GitHub App's effective `workflows:write` installation permission (do not silently modify production App registration).
2. Real Tomos App installation-token workflow PUT; mocked PHP calls are not proof.
3. Real post-update build tracking for a `main` commit, user-facing progress/result/error UI, and a trustworthy developer-hosted release manifest.
4. Routine stable-channel auto-updates and the pinned-workflow-to-channel migration (the experimental revision change is NOT this feature).
5. End-to-end security integration with existing HTTP endpoints, CSRF checks and authenticated Write/Workspace/Publisher sessions.

The experimental PHP class must not be copied directly into production without security review, refactoring of the hardcoded repo gate, and dedicated HTTP-boundary tests.
