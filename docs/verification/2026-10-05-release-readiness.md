# First release candidate readiness — 2026-10-05

The gate below was subsequently completed and the candidate published with
explicit user approval. See the [publication record](2026-10-05-published-candidate.md).

## Proposed outcome

Prepare `v0.1.0-rc.1` as the initial evaluation release candidate. The proposed
public text is [the release notes](../releases/0.1.0-rc.1.md). No tag, GitHub
release or registry publication is created by this preparation PR. Keep the
changelog under Unreleased and let Composer derive versions from an eventual tag.

## Verified baseline

Preparation began on clean `main@82c5441b18e7e7ba9b93856454da4a1ce0062f43`.
Its main Quality run passed, no issues/PRs or release tags were open/present, and
GitHub private vulnerability reporting was enabled. Historical test results are
attached to their exact commits below; they are not substitute tag authorizations.

| Evidence | Result | Candidate / run |
|---|---|---|
| Deep dependency matrix: six PHP/Laravel targets plus minimum set | All seven passed quality, 1,064 full tests / 8,304 assertions, audit and discovery | `0cc351d3cbeb064a9c3c0c9e231cfac538539589`, [Release Gate](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37222048962) |
| Real GitHub ZIP, Composer dist installed reference/type/content and production-only Laravel boot | Passed, PHP 8.4/Laravel 13 Memory consumer | `93f92a37529513fb2e69cb4ddb99485e3544edb5`, [Distribution](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37232344835) |
| Runtime continuity through preparation baseline | `src/`, `config/` and `composer.json` unchanged from the deep-matrix candidate | Verified by Git diff against `82c5441` |
| First release metadata and instructions | MIT package metadata, security policy and canonical coordination/recovery docs present | Current checkout |

## Final publication gate

1. Merge this preparation through normal protected-main PR checks and review.
2. Select the resulting clean main commit. Dispatch Release Gate and Distribution
   on that exact main revision; require all jobs to pass and record their SHAs.
3. Obtain explicit approval for the proposed version, tag and GitHub prerelease.
   Existing continuation authority covers preparation/PR/merge, not publication.
4. If approved, verify main has not advanced; create the annotated tag on the
   checked commit and a GitHub prerelease using the reviewed notes. Preserve
   immutable tag identity. Do not include Packagist submission without separate
   authorization or claim indexing before observing it.

If the selected revision changes, refresh affected evidence. Do not turn a failed
gate into a passing release by omitting jobs or bypassing protection.

## Evaluation versus deployment acceptance

Additional platform, transport or benchmark work is not introduced as an
unbounded preparation backlog. The candidate states the evidence it has.
Production use remains conditional on the application's authoritative history,
all-writer adoption and Redis durability/topology contracts. Those deployment
requirements are not waived by test success or a candidate tag; follow the
canonical operation documentation and qualify the actual environment separately.
