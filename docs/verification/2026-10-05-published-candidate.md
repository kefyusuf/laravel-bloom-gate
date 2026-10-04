# Published evaluation candidate — 2026-10-05

## Result

User approval followed the concrete version/tag/GitHub-prerelease question.
The [v0.1.0-rc.1 release](https://github.com/kefyusuf/laravel-bloom-gate/releases/tag/v0.1.0-rc.1)
was published at `2026-10-04T21:45:55Z` (2026-10-05 00:45:55 Europe/Istanbul).
GitHub readback confirmed `isDraft=false` and `isPrerelease=true`; it was not
marked as the latest stable release.

- Annotated tag object: `8c67b0dc4d9688f7946acc059233e6d09d0bca3a`.
- Peeled source commit: `77401a64b76ea7ceb47a6254fd1f38b3a0d09aca`.
- Local and remote tag resolution matched the selected clean main commit.
- No tag replacement, runtime change, Composer version field or Packagist
  submission was performed.

## Exact-commit verification before publication

| Gate | Result | Run |
|---|---|---|
| Release Gate | All seven targets passed quality, 1,064 full tests / 8,304 assertions each, live Redis, dependency audit and package discovery | [37235504884](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37235504884) |
| Distribution | Source export, exact GitHub ZIP, production-only Composer dist and PHP 8.4/Laravel 13 Memory consumer passed | [37235506941](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37235506941) |
| Main Quality | Passed | [37235502013](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37235502013) |

All three runs reported the full selected source SHA above and success. They
were checked again immediately before tag creation. The publication used the
reviewed release text and the verified annotated tag with GitHub's `--verify-tag`
and `--prerelease` options.

## Documentation follow-up

The tagged source retains the preparation-era draft status and Unreleased
changelog. This follow-up records publication on main; it does not rewrite the
immutable release commit or claim that the tagged files changed afterward.
The GitHub release body is the public publication text.

Production acceptance remains specific to the actual authoritative history,
writer participation and Redis deployment. Candidate publication does not certify
Redis Cluster, unmeasured performance, Packagist indexing or Composer remote
transport. The source ZIP check consumes the downloaded ZIP as a local file.
