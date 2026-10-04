# Repository administration baseline — 2026-10-04

## Scope and policy

Close the repository-side gap identified in issue #2 after the M6 delivery.
Accepted ADR-0025 requires protected main and focused short-lived branches.
The application code and CI definitions are unchanged.

Target: `kefyusuf/laravel-bloom-gate`, branch `main`.
Source baseline: `241af307d80583e059aa99a71786afeefce27cee`.

## Observed before configuration

- No branch protection on main and no repository rulesets.
- Repository description and topics were absent.
- Private vulnerability reporting was disabled.
- All three merge methods were enabled; automatic branch deletion was disabled.

## Applied configuration and read-back

| Setting | Verified value |
|---|---|
| Main pull-request requirement | Enabled |
| Required check | `quality`, GitHub Actions app ID `15368` |
| Branch must be up to date | Yes (`strict:true`) |
| Administrator enforcement | Enabled |
| Required external review approvals | `0` |
| Code-owner / last-push approval | Disabled |
| Force pushes / main deletion | Disabled |
| Repository description | Existing Composer package description |
| Topics | laravel, bloom-filter, redis, php, probabilistic-data-structures |
| Private vulnerability reporting | Enabled |

The exact check name and app ID were observed on the existing successful main
check run before applying protection. The required Quality workflow has no
pull-request path filter, so documentation PRs also receive the required check.

Zero external approvals preserves the single-maintainer workflow while requiring
PR integration. This does not bypass CI or administrator enforcement. Independent
review remains part of the project workflow. GitHub documents the zero-reviewer
option in its [branch protection API](https://docs.github.com/en/rest/branches/branch-protection#update-branch-protection).

Merge methods remain enabled; squash remains the project-preferred method.
Automatic branch deletion remains disabled to preserve alternative implementation
history, including the superseded PR #66 branch. Cleanup stays explicit.

## Verification procedure and limits

The applied settings were read back through the GitHub API. No direct push,
force push or deletion attempt was made against main; their restrictions were
verified from configuration without risking the branch.

This focused documentation PR is the functional check: its current head must pass
Quality and merge normally through squash, without `--admin` or protection bypass.
Issue #2 is closed by this PR only after that normal merge succeeds. Read the PR
and issue state to verify the resulting integration outcome.

The first API request included both deprecated `contexts` and app-bound `checks`;
GitHub rejected it with HTTP 422 without applying protection. Using `checks` alone
resolved the schema conflict. No protection was relaxed to complete the workflow.

Local request payloads are retained under `.build/repository-administration/`.
This is a configuration snapshot, not authority for later state: refresh the live
settings after repository administration changes.
