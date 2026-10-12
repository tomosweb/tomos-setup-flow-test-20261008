# GitHub App Workflow update: permission rollout / user consent (design gate)

Status: **design + mocked GitHub App API validation only**, 2026-10-12.
Do not change the registered production GitHub App, deployed Publish API, or any user's repository as part of this experiment.

## Verified GitHub behavior (official docs)

- To edit a repository's `.github/workflows/` files with a GitHub App installation token, the App must be registered with **Repository permissions > Workflows > Read and write**. The installation token permission key is `workflows: write`, together with appropriate Contents access.
- Adding repository permissions to an existing GitHub App **notifies installation owners and requires their approval**. An installation that has not approved retains its earlier rights; it does not receive the new permission.
- The old Contents-based article publishing remains available for installations that have not approved Workflows permission. No PAT or user OAuth token is necessary.
- This is a **global GitHub App registration permission change**, not a single-repository setting change. Do not perform it as part of routine template publishing or site creation.

Primary sources:
- https://docs.github.com/en/apps/maintaining-github-apps/modifying-a-github-app-registration
- https://docs.github.com/en/apps/using-github-apps/approving-updated-permissions-for-a-github-app
- https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/choosing-permissions-for-a-github-app
- https://docs.github.com/en/rest/apps/apps

## Owner rollout (requires a deliberate human decision)

1. Complete and review an authenticated update endpoint and its tests, then prepare a short user-facing explanation of the new permission.
2. In the **owner's registered GitHub App**, open GitHub **Settings > Developer settings > GitHub Apps > Edit > Permissions & events**.
3. Under **Repository permissions**, set **Workflows** to **Read and write**. Retain existing **Contents Read and write** and **Metadata Read-only**. Do not add unrelated permissions, OAuth, webhooks, or PAT storage.
4. Use GitHub's **Add a note to users** field, e.g.:
   > Tomos GitHub版で、必要なときに「サイトを更新」からサイトの仕組みを更新できるようにするため、GitHub ActionsのWorkflowファイルの更新権限を追加します。承認しなくても従来どおり記事を投稿できます。
5. Save changes only after approving the impact: GitHub will send installation owners an approval request. This cannot be limited only to one test repo on an existing app registration.
6. On the test account, approve the new permission on GitHub's App installation settings. Observe whether `GET /app/installations/{installation_id}` exposes `permissions.workflows = "write"`, and whether a **repo-restricted** installation access token can be minted with `{"permissions":{"contents":"write","workflows":"write","metadata":"read"}}`.
7. Test a single approved Workflow SHA update against **only** the disposable test repo; verify commit and Pages build. Run negative tests with an installation that has not approved. Do not extend to live sites until the Human Gate passes.

**Important:** The separate ChatGPT GitHub connector used to author the experimental PR is not the production Tomos GitHub App. Its successful file writes do not prove production App authorization.

## Proposed user experience

| Server-side eligibility | Installation authorization | User-facing result |
| --- | --- | --- |
| No compatible update | Any | Nothing displayed |
| Compatibility unknown, customized workflow or invalid source | Any | Nothing displayed, internal fail-closed status only |
| Compatible update | Already approved Workflows permission | `サイトを更新`; explicit click triggers short-lived signed intent and repository-specific update |
| Compatible update | App installed, Workflows permission not approved | `GitHubで更新を許可`; link to verified installation settings; **no update intent issued** |
| Connection expired/revoked | Invalid connection | Existing GitHub connection flow (not a generic version alert) |

The link is constructed server-side from the verified installation ID (`https://github.com/settings/installations/{id}` for the personal test installation) and must never be taken from a client-supplied URL. For an organization, verify the correct GitHub installation settings destination before production rollout.

The permission step is not an OAuth token request. GitHub receives the permission approval, and the user returns to Tomos; Tomos must refresh the installation permissions before showing `サイトを更新`.

**No update should require user intervention on every post.** Eligibility is checked only as needed, and unknown versions are never exposed as warnings. When there is a real blocked capability (e.g. user attempts to apply a confirmed update without approved permission), explain the required action clearly.

## Safe server sequence

1. Authenticate connection and bound repository with existing Publish API security controls; do not trust arbitrary repository owner/name or installation IDs from the browser.
2. Confirm compatibility via a **developer-controlled, authenticated, integrity-checked release manifest**. A JSON file committed to a user's own repository cannot declare itself compatible.
3. Read current Workflow from the repo's default branch and verify the full Git blob SHA against the developer allowlist; if unknown, stop without altering anything.
4. For check-only operations, request `contents:read` for that repository. A missing Workflows permission must not hide an otherwise verified update.
5. For the user-confirmed write, issue short-lived signed consent bound to installation ID, repository ID, exact file SHA and target Core SHA; re-read repo and permission state before PUT.
6. Mint a **per-repo** token with `workflows:write` and `contents:write`. PUT one exact file with the previously read `sha`; refuse GitHub 409/422. Never force-push or change content or themes.
7. Return **build_pending** upon successful commit, not **updated**. Confirm Actions and deployed Pages separately. Do not announce success until the build/deploy checks pass.

## Verified in this experiment

- `scripts/SiteUpdatePrototype.php`: updated check-only path uses a read-only installation token; write path still requires Workflows permission.
- `tests/site_update_prototype_check.php`: mock installation gains `workflows:write` between checks; permission link is visible before approval, no intent is issued until approval, and actual update is only available after approval.
- This experimental PHP class is **not** integrated with a production HTTP endpoint or deployed; it hardcodes the disposable test repository. It must not be merged or copied into production unchanged.

## Release blockers

- Complete official-site API endpoint security/CSRF and end-to-end browser tests.
- Validate the exact App permission settings under the account that **owns the Tomos App** and its installation approval path.
- Verify approved and unapproved installations both continue existing Publish API tasks.
- Validate actual **Tomos GitHub App token** Workflow write on test repo.
- Implement trustworthy stable manifest, build status monitoring, error handling and any needed rollback policy.
- Separately define routine Core auto-update, which should not prompt users for every release.
