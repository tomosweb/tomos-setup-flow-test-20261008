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
