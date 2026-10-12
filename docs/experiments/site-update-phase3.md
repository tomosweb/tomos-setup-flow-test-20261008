# Tomos GitHub edition: site update experiment (Phase 3)

Status: experimental only. No production workflow or site content changed.

## Verified baseline

- Existing GitHub Pages workflow pins TOMOS_VERSION to a commit SHA.
- A template update does not migrate an existing site repository.
- The current workflow deploys only on main push or manual dispatch, not pull_request.

## Intended update modes

1. Routine Core releases: use a developer-controlled, compatibility-tested stable reference. Do not blindly track main or an unverified latest tag. Verify the resolved commit and builder contract before deployment. The first migration away from pinned SHA must be explicit and safe.
2. Exceptional structural changes: show a conditional Site Update action in the posting or site-management flow. Read current repository state, validate an allowlisted migration and expected file SHAs, require explicit user approval, then commit only managed files. Preserve content, images, custom themes and settings.

## Required gates before code rollout

- Determine whether the existing GitHub App has workflow write permission; do not request a permission expansion or reauthorization implicitly.
- Build and test an updater on a disposable repository before touching any existing user repository.
- Refuse unexpected workflow edits or conflicting HEADs; do not force push.
- Ensure a failed build does not replace the published Pages artifact.
- Test legacy pinned workflow, current template workflow, repeat invocation, conflicts, missing permission, and failed build.
- Provide explicit result states: no update / update available / updating / completed / needs authorization / failed.

## Rollout constraints

This document records the experimental specification only. Do not merge to main, deploy, or mutate other repositories without a separate approval and successful tests.
