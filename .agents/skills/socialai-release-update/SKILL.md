---
name: socialai-release-update
description: Deploy a Social AI Moderator release (tag, or on the test instance an agreed branch) to an instance with maintenance, worker stop, DB backup, image rebuild, composer install, migrations, translation import, validation and rollback. Use for requests to update, deploy or upgrade an instance; tagging is socialai-create-tag.
---

# Social AI Moderator release update

Respond in the user's language. Resolve the repository root of the instance being updated with
`git rev-parse --show-toplevel`, read its `AGENTS.md`, [deploy/README.md](../../../deploy/README.md)
and **Aktualizace instance** in [the release lifecycle](../../../docs/release-lifecycle.md).
That document is the source of truth for commands and order; never invent other steps.

## Scope and authorization

- Determine the instance (test or production) and the target ref. Default target is a published
  annotated tag; a branch is allowed only on the test instance when the user says so. Ask if
  either is unclear.
- Preparation (step 1) is read-only. Steps 2–12 change shared state: present the concrete plan
  (instance, current and target version/commit, upgrade notes to apply, local config changes,
  whether the image is rebuilt, backup path) and wait for explicit approval. An approved plan
  authorizes its execution; a changed target or wider scope needs a new approval.
- Local files (`config/`, `.env`, `docker-compose.override.yaml`) are outside `git diff`; review
  and report their changes separately. Never print secrets or `config/secrets/*` contents.

## Execute

Follow steps 1–12 exactly. Record for the report: start time, source version and commit, target
tag and commit, backup path and size, every command result, warnings.

- Read every `docs/upgrade-notes.md` section newer than the deployed version up to the target and
  apply each applicable note at the step it names.
- Rebuild the `php` image only when `deploy/compose/Dockerfile.php` changed between source and
  target, or when the user also asked for an image update (`socialai-update-images`).
- Never delete `temp/maintenance.lock` while clearing the cache, never run `migrations:reset`,
  never delete backups in `data/backups/`.
- On the test instance step 11 includes `composer test` and `composer analyse`; failures block
  `maintenance off` until the user decides.

## Failure

Stop at the first failed step, keep maintenance on and report the exact failure. Offer the
rollback from **Selhání a návrat** (code only before step 10; code + DB restore after step 10)
and run it only with the user's approval. Do not retry blindly.

## Report

Report source and target version/commit, backup file, migrations applied, translation import
result, validation results, maintenance state, and anything left for the user (including any
upgrade note that requires action on another instance).
