---
name: socialai-version-bump
description: Decide and apply the Social AI Moderator version bump in version.txt (PATCH, MINOR for DB structure changes, MAJOR on request) together with the matching docs/upgrade-notes.md section. Use when finishing a change, preparing a release, or when the user asks to bump or set the version.
---

# Social AI Moderator version bump

Respond in the user's language. Read the repository `AGENTS.md` and **Verzování** in
[the release lifecycle](../../../docs/release-lifecycle.md); it defines the rules.

## Decide the level

1. Read `version.txt`; after trimming whitespace it must match
   `^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$`. Reject malformed input.
2. Inspect the change: `git status --short`, `git diff` and the commits since the last release
   tag (`git describe --tags --abbrev=0`, `git diff <tag>.. --stat`).
3. Choose:
   - **MINOR** when a new file exists in `migrations/structures/` or `migrations/basic-data/`
     since the last release (any DB structure or seed change);
   - **MAJOR** only when the user requests it; suggest it for a large incompatible change and
     wait for explicit confirmation, explaining that installations must first reach the latest
     release of the previous major line;
   - otherwise **PATCH** (behavior change, bugfix, refactoring, translations, deploy config).
   Bump even if the branch already contains an unreleased bump, so every change set is distinct.
   Never edit an already applied migration to avoid a MINOR bump.

## Apply

1. Write the new version as the only line of `version.txt` (keep the trailing newline).
2. Decide whether the deployment needs a manual step, check or special order not covered by
   `docs/release-lifecycle.md` (new local config key or template, permission repair, data fix,
   changed service). If yes, add a `## X.Y.Z` section at the top of `docs/upgrade-notes.md` in
   its documented format, in Czech, without credentials or private config contents. It is not a
   changelog: do not describe ordinary changes.
3. Run `docker compose exec php composer test` and `composer analyse`; the footer of the
   application shows the new version after `rm -rf temp/cache`.

State the old and new version and whether an upgrade note was added. Bumping does not commit,
tag or deploy; ask before committing.
