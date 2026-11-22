# Agent Instructions

## Response Language

- Whenever these instructions are used, begin the first response with a statement that the `AGENTS.md` file is being used.
- Write this opening statement in the same language as the user's current prompt.
- Respond in the same language as the user's current prompt.
- Keep code, identifiers, file paths, and established technical terminology unchanged.
- Code, identifiers, comments, and commit messages are in English. Project documentation in `docs/` is in Czech.

## Project

`social-ai-moderator` is a standalone service that moderates comments and private messages of a business Facebook Page and an Instagram Professional account (primary tenant: Harmony Face Yoga). It ingests Meta webhooks, classifies events with OpenAI, decides actions through a deterministic policy engine, and executes only allowed actions through an outbox. An admin panel covers review, takeover, rules, knowledge base, audit, and statistics.

- The specification is `data/specification.md`. It is local-only and not part of the repository, so ask the user for it if it is missing. Read the relevant sections before implementing anything. If the code and the specification disagree, stop and ask.
- Open questions (specification §13) are not decided. Never invent answers to them. Implement behind configuration, or ask.
- Work in milestone order (specification §10). Do not start a later milestone's external write path before the earlier one is tested.

## Technology Baseline

| Area | Choice |
| --- | --- |
| Runtime | PHP 8.5 (FPM for web, CLI for worker), Docker Compose |
| Framework | Nette 3.x (`nette/application`, `nette/database`, `nette/di`, `nette/forms`, `nette/security`), Latte 3, Tracy |
| Database | MariaDB 12.3 LTS, own database, DB-backed job queue (Redis is not used for the queue) |
| Sessions | Redis 8.10 (`redis` service, phpredis), only for the PHP sessions of the administration |
| Admin UI | Bootstrap 5.3, local copy in `www/assets/vendor/bootstrap-<version>/` (no CDN), light/dark theme |
| Time | PHP and MariaDB work in UTC; the administration converts to the `timezone` parameter |
| CLI | `bin/console` (`contributte/console`) |
| Migrations | `nextras/migrations`, files in `migrations/` |
| Tests | Nette Tester; static analysis PHPStan |
| AI | OpenAI API: moderation endpoint + Structured Outputs (strict JSON Schema) |

Adding a Composer dependency, replacing a baseline choice, or adding a container service requires the user's approval.

## Directory Layout

The repository root is also the Docker Compose project directory. It follows the default `nette/web-project` layout. Infrastructure lives in `deploy/`. Everything that is specific to one server is kept out of git.

```text
/                                   repository root = Compose project directory
├── AGENTS.md
├── README.md
├── version.txt                     application version MAJOR.MINOR.PATCH
├── update-permissions              shares development files with the socialai group
├── composer.json, composer.lock
├── phpstan.neon
├── .agents/skills/                 agent skills (socialai-*); .claude/skills is a symlink to it
├── docker-compose.yaml             tracked include stub -> deploy/compose/docker-compose.yaml
├── docker-compose.override.yaml    LOCAL (gitignored), per-server additions (ports, extra mounts)
├── .env                            LOCAL (gitignored), Compose interpolation (UID/GID, bind, port,
│                                   subnet, TZ)
│
├── app/                            application code (namespace App\)
│   ├── Bootstrap.php
│   ├── Core/                       RouterFactory, JSON logging + correlation ID, crypto, clock
│   ├── Account/                    social accounts, encrypted credentials, token status
│   ├── Meta/                       Graph API client interface + FB/IG implementations,
│   │                               webhook signature check, EventNormalizer
│   ├── Ingest/                     webhook persistence, event_key deduplication, reconciliation
│   ├── Queue/                      DB queue, transactional job reservation, worker loop,
│   │                               retry/backoff with jitter, circuit breaker
│   ├── Ai/                         OpenAI client, Classifier, ReplyGenerator, output validation
│   │   ├── prompts/                versioned prompts (version is stored as prompt_version)
│   │   └── schemas/                strict JSON Schemas for model outputs
│   ├── Moderation/                 PolicyEngine, policies (versioned), rule checks, decisions
│   ├── Knowledge/                  knowledge sources, versions, validity, publishing
│   ├── Comment/                    posts and comments projected from events, visibility
│   ├── Conversation/               conversations, reply window, takeover/release, echo guard
│   ├── Outbox/                     ActionDispatcher (the ONLY Meta writer), idempotency, states
│   ├── Audit/                      admin_audit writer
│   ├── Translation/                UI Translator, PluralRule, JSON import into the DB
│   ├── Console/                    CLI commands (worker, reconcile, retention, ...)
│   └── Presentation/
│       ├── Api/                    Meta webhook, health endpoints
│       ├── Admin/                  dashboard, review, conversations, accounts, policies,
│       │                           knowledge, audit, automation switch
│       ├── Sign/                   admin login
│       └── Error/
├── assets/                         admin JS/CSS sources (if a build step is ever needed)
├── bin/console                     CLI entry point (worker runs as `bin/console worker:run`)
├── bin/e2e                         end-to-end tests in an isolated stack with throwaway data
├── bin/maintenance                 maintenance page on|off|status (temp/maintenance.lock)
├── config/                         everything except the tracked files below is LOCAL (gitignored)
│   ├── common.neon, services.neon  tracked application configuration
│   ├── local.neon.template         tracked template
│   ├── local.neon                  LOCAL, per-server Nette config (trusted proxy, ...), no secrets
│   ├── php/zz-override.ini         LOCAL, per-server PHP settings
│   ├── php-fpm/zz-override.conf    LOCAL, per-server FPM pool settings
│   ├── mariadb/zz-override.cnf     LOCAL, per-server MariaDB settings
│   ├── nginx/                      LOCAL, trusted-proxies.inc, maintenance-allowlist.geo
│   └── secrets/                    LOCAL, mode 0700, files 0600; hidden from php/worker (tmpfs)
│       ├── app.env                 env of php + worker: DB, META_*, OPENAI_*, encryption key
│       └── db.env                  env of db: root password, application account
├── migrations/                     nextras/migrations (structures/, basic-data/, dummy-data/)
├── tests/
│   ├── bootstrap.php
│   ├── Unit/                       PolicyEngine, classification mapping, windows, limits
│   ├── Integration/                webhook HMAC, queue concurrency, outbox, fake Meta/OpenAI
│   ├── E2e/                        HTTP tests through nginx, run only by bin/e2e
│   ├── Fakes/                      FakeMetaClient, FakeOpenAiClient
│   └── fixtures/                   synthetic or anonymized payloads only
├── docs/                           architecture notes, ADRs, Meta permissions checklist (Czech)
├── deploy/                         tracked, identical for every server
│   ├── README.md                   how to bootstrap a server, what goes into local/
│   ├── bootstrap-environment.sh    creates all LOCAL files from templates, never overwrites
│   ├── compose/
│   │   ├── docker-compose.yaml     services: web, php, worker, db, redis
│   │   ├── e2e.yaml                isolated end-to-end stack (bin/e2e), tmpfs data, no ports
│   │   ├── Dockerfile.php          shared image for php-fpm and the worker
│   │   ├── env.template            -> /.env
│   │   ├── override.template.yaml  -> /docker-compose.override.yaml
│   │   ├── app.env.template        -> config/secrets/app.env
│   │   └── db.env.template         -> config/secrets/db.env
│   ├── nginx/                      nginx.conf, conf.d/ (maintenance, realip), *.template
│   ├── php/                        php.ini, www.conf, zz-override.*.template
│   ├── mariadb/                    my.cnf, init/ (creates <db>_test), zz-override.cnf.template
│   └── redis/                      redis.conf (sessions, AOF persistence)
├── data/                           LOCAL (gitignored) except the translation sources:
│   ├── language_cs.json            tracked, Czech UI translations (forms 1, 2, 5)
│   ├── language_en.json            tracked, English UI translations (forms 1, 2)
│   ├── mariadb/                    MariaDB datadir
│   ├── redis/                      Redis data (sessions)
│   └── backups/                    DB dumps from release updates
├── log/                            Nette/Tracy + JSON app logs (contents gitignored)
├── temp/                           Nette cache, test temp (contents gitignored)
├── vendor/                         Composer (gitignored)
├── www/                            public document root (index.php, admin static assets)
└── www-maintenance/                static maintenance page served by nginx
```

### Infrastructure Rules

- `deploy/` holds only configuration that is identical on every server. Anything that differs per server goes into the LOCAL files: `config/` (except the tracked neon files and templates), `.env`, and `docker-compose.override.yaml`. This includes secrets, domain, bind addresses and ports, the subnet, PHP/FPM/MariaDB tuning, and the trusted proxy. Domains, IP addresses, and other server details never go into tracked files. Each local file has a tracked `*.template` in `deploy/` or `config/`. When you change a template, update the matching local file too.
- `config/` uses a whitelist in `.gitignore`. When you add a new shared neon file, add it to the whitelist.
- `deploy/compose/docker-compose.yaml` uses paths relative to the repository root (`project_directory: .` in the stub). It mounts `deploy/**` read-only and `config/{php,php-fpm,mariadb}/zz-override.*` read-only after them, so per-server values win. It loads secrets via `env_file: config/secrets/*.env`, and `config/secrets/` is hidden inside the application containers.
- The base compose file publishes no DB port. `web` binds only to `${WEB_BIND}:${WEB_PORT}`. A reverse proxy in front of it terminates TLS. Nette trusts forwarded headers only from the exact address in `http.proxy` (`config/local.neon`). Compose can only add on top of the base file, so put optional exposure into the override. Extra ports for one instance use `WEB_PORT+1`, `+2`, …
- Services: `web` (nginx), `php` (php-fpm), `worker` (same image, `bin/console worker:run`, `stop_signal: SIGTERM`, graceful stop), `db` (MariaDB), `redis` (sessions only, no published port). `php` and `worker` run as `APP_UID:APP_GID` (the checkout owner), so `temp/` and `log/` stay writable on the host. All services have healthchecks and capped json-file logging.
- The application gets secrets only from environment variables (specification §11). Secrets never go into neon files, the repository, fixtures, logs, or exception messages.

## Shared Development Permissions

- Development files belong to the `socialai` group and stay group-writable. Run `./update-permissions` (`--dry-run` first when unsure) after creating files, after a checkout, or when permissions look wrong. It shares tracked files, sets setgid on their directories, and makes `log/` and `temp/` group-writable.
- Use `umask 0002` in shell commands that create development files. A shell's umask does not apply to other tool calls or editing tools, so verify group ownership and group write permission of created or atomically replaced files.
- If the session does not have the group active yet (`id -Gn` lacks `socialai`), run group-changing commands through `sg socialai -c '...'`.
- Preserve file owners and existing executable bits. Do not recursively change unrelated paths, never touch `config/secrets/`, `data/mariadb/`, `data/redis/` or `vendor/`, and do not create system groups. If you are blocked, report the path and the missing permission.
- The `php` and `worker` containers run as `APP_UID:APP_GID`, where `APP_GID` is the `socialai` group; `deploy/php/umask.php` (auto_prepend_file) sets `umask 0002` for every PHP process, including `docker compose exec`.

## Translations

- Use translation keys for every user-facing text in templates and PHP (`{_'key'}`, `{_'key', $count}`, `{_'key', $count, [name => value]}` with `%count%` / `%name%` placeholders).
- The translation sources are `data/language_cs.json` and `data/language_en.json`. They are a JSON list of `{"key", "lang", "translations": {form: text}}` entries. When you add or change a text, update both files in the same change so that they contain the same set of keys.
- Every key defines all plural forms of its language, even when the wording is identical. In `cs`, use `"1"` for singular, `"2"` for 2–4, and `"5"` for other counts. In `en`, use `"1"` for singular and `"2"` for plural. Write each form according to the language's grammar, and pass the relevant count when you use the key.
- Translation texts are not part of the SQL migrations. Migrations own only the structure (`languages`, `translations`) and the list of languages (`basic-data`). The application imports the JSON files into the DB with `bin/console translations:import`. The import is idempotent and skips unchanged files by hash. Non-manual rows follow the JSON, so keys removed from the file are deleted. Rows edited in the administration (`is_manual = 1`) are never overwritten or removed. The administration reads translations only from the DB.
- `tests/Unit/Translation/TranslationFiles.phpt` enforces valid files, complete plural forms, and identical key sets. Preserve valid JSON, the existing structure, and the `area.section.item` key naming.

## Application Versioning and Releases

- The version is in `version.txt` (`MAJOR.MINOR.PATCH`). The rules are in `docs/release-lifecycle.md`:
  - **PATCH:** a change without a DB structure change.
  - **MINOR:** a new migration.
  - **MAJOR:** only on request.
- Bump the version in every change set with the `socialai-version-bump` skill.
- `docs/upgrade-notes.md` lists one-off deployment actions per version (in Czech, never credentials). With every version bump, decide whether a note is needed and state the result.
- `docs/release-lifecycle.md` is the source of truth for tagging, instance updates, rollback, maintenance, and image updates. Use the skills:
  - `socialai-create-tag`: annotated `vX.Y.Z` from a clean `main` synchronized with `origin/main`.
  - `socialai-release-update`: maintenance, worker stop, DB backup, rebuild, migrations, translation import, validation, rollback.
  - `socialai-update-images`: php/nginx patch images; MariaDB patch within the latest LTS series (currently 12.3).
  - `socialai-maintenance`: `bin/maintenance on|off|status` and verification.
- Published tags are immutable. Steps that change a running instance (maintenance, worker stop, backup, deploy, restore, image recreate) need the user's confirmation of a concrete plan. The only exceptions are applying new migrations and refreshing the test instance after a change set (see Workflow).
- When clearing the cache, delete only `temp/cache`, never `temp/maintenance.lock`.

## Safety Invariants (non-negotiable)

These come from the specification (§3.5, §5, §8.2, §12). Every change must keep them intact, and tests must cover them.

1. **Fail closed.** If there is a model error, an invalid token, an unverified message time, or an unclear state, take no automatic action and route the event to `review`.
2. **Default mode is `observe`.** It must not cause any external write. Implement and test `observe` before any write path.
3. **`ActionDispatcher` is the only component allowed to call Meta write APIs.** It acts only on outbox records. Before each send it re-validates the current state through `PolicyEngine`: policy, mode, panic switch, account, comment state, and the reply window *at the moment of sending*.
4. **The AI is advisory only.** It has no credentials and cannot trigger HTTP calls. Its output is untrusted input to `PolicyEngine`. Forbidden categories are blocked even when the model recommends `reply`, `hide` or `delete`.
5. **Customer content is untrusted data.** It goes into prompts as clearly delimited data and never as instructions. Never fetch URLs from comments or messages (SSRF).
6. **Never delete legitimate criticism.** Prefer reversible `hide` over delete. Delete happens only by explicit rule or human approval.
7. **Always hand off** complaints, refunds, billing, health, and legal topics to a human. Never auto-approve refunds or give individual health advice.
8. **DM rules:** reply only inside a valid window. `human_takeover` suppresses all automatic replies until an explicit release. Own echo messages never trigger replies. Send at most one automatic reply per event. Never use `HUMAN_AGENT` for automated replies.
9. **Idempotency:** `event_key` is unique per platform and account, and `idempotency_key` is unique per action. Workers reserve jobs transactionally, so no job runs twice in parallel. An uncertain send result becomes `unknown` and is reconciled, never blindly retried.
10. **Webhook:** verify HMAC-SHA256 over the raw body in constant time. Return 403 for an invalid signature. Persist the event and return 200 quickly. Never call AI inside the HTTP request.
11. **Data handling:** store external IDs as strings, prefixed by platform. Encrypt tokens and DM text at rest. Minimize personal data sent to OpenAI. Never log full DM text, tokens, or secrets.
12. **Panic switch** (global and per account) blocks all automatic Meta writes while ingest keeps running.

Never connect a live production Page or real accounts in tests or during development without the user's explicit instruction. Do not modify the Harmony Face Yoga repository or database. Any future integration with them is read-only and optional.

## Code Rules

- Keep classes small with one responsibility. Do not create giant `*Service` objects or layers without a purpose. Before adding a class, check whether an existing one can be extended.
- Interfaces are justified only where a real second implementation exists. This applies to external boundaries (Meta client, OpenAI client, clock), which have production and fake implementations. Do not create other single-implementation interfaces.
- Prefer the simplest implementation and reuse existing patterns. Do not add speculative fallbacks, and do not hide errors behind default values. Remove dead code.
- Use short method names that describe the responsibility (2–4 words) and existing domain terms (`event`, `decision`, `action`, `outbox`, `policy`, `window`, `takeover`). Avoid prefixes such as `checkIf`, `perform`, or `handleAndUpdate`.
- Use `declare(strict_types=1);`, typed properties and returns, readonly value objects, and backed enums for categories, actions, modes, and states.
- Write array and object literals with one item per line, and nest them recursively. Expand control-flow blocks and callbacks onto multiple lines.
- Keep business logic out of presenters and Latte templates. Presenters only delegate to the domain modules in `app/`.
- Admin mutations go through Nette forms or signals with CSRF protection. Check roles (`admin`, `moderator`, `viewer`) on the server. Audit sensitive actions with the operator ID.
- Every DB schema change is a new versioned migration. Never edit a migration that was already applied anywhere.
- Prompts and JSON Schemas are versioned files. A changed prompt gets a new version, and every decision stores `model`, `prompt_version`, and `policy_version`.
- Pin the Graph API version through configuration (`META_GRAPH_API_VERSION`). Keep `graph.facebook.com` and `graph.instagram.com` paths separate.
- Thresholds, retention, limits, and knowledge content are editable at runtime (DB or admin) without a redeploy. Never hard-code them.

## Workflow

- Run tools inside containers from the repository root:
  - `docker compose exec php composer install`
  - `docker compose exec php composer test` (Nette Tester)
  - `docker compose exec php composer analyse` (PHPStan level 8)
  - `bin/e2e` (end-to-end tests in an isolated stack, discarded afterwards)
  - `docker compose exec php bin/console migrations:continue`
  - `docker compose exec php bin/console translations:import` (after changing `data/language_*.json`)
- Never run `migrations:reset`. It drops the database.
- After every change that adds a migration, apply it to the test instance right away, without asking. The test instance runs from the development checkout (a branch, not a detached release tag); a checkout on a release tag is updated only through `socialai-release-update`. Steps: `bin/maintenance on`, `docker compose stop worker`, DB backup as in step 4 of `docs/release-lifecycle.md`, `docker compose exec -T php bin/console migrations:continue`, `translations:import`, `rm -rf temp/cache`, the actions of `docs/upgrade-notes.md` for the new version, `docker compose up -d --wait`, `bin/maintenance off`, and a read-only check (health, anonymous page status, no new entries in `log/exception.log`). Report the backup path and the result. If a step fails, keep maintenance on and ask.
- After every finished change set without a migration, refresh the test instance right away, without asking (same branch-checkout condition): `docker compose exec -T php bin/console translations:import`, `rm -rf temp/cache`, `docker compose restart worker`, and the same read-only check. Report the result; if a step fails, ask.
- Tests use a separate test database and never the development data. Smoke and end-to-end tests run only in the e2e stack (`bin/e2e`, docs/testing.md): never create test operators, accounts or other test data on an instance, and never send test requests to it. Tests use only fake Meta and OpenAI clients. Temporary files go under `temp/tests/` and are cleaned up after the run. Never leave large files in `/tmp`.
- Before you finish, run PHPStan and the relevant tests, and report the results honestly. If you could not run them, say so.
- Do not mark a Meta feature as done until the required permissions are actually granted and verified (specification §3.2). Before implementing concrete Graph endpoints, confirm the login flow and scopes with the user.
- Ask before committing or pushing. Never commit LOCAL files (`config/` outside the whitelist, `data/`, `.env`, `docker-compose.override.yaml`), real payloads, or tokens.
- Keep changes minimal and related to the task. Do not modify `vendor/`, `temp/`, `log/`, or generated files.
