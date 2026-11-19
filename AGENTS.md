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
| Database | MariaDB 12.3 LTS, own database, DB-backed job queue (no Redis in v1) |
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
├── composer.json, composer.lock
├── phpstan.neon
├── docker-compose.yaml             tracked include stub -> deploy/compose/docker-compose.yaml
├── docker-compose.override.yaml    LOCAL (gitignored), per-server additions (ports, extra mounts)
├── .env                            LOCAL (gitignored), Compose interpolation (ports, subnet, TZ)
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
│   ├── Conversation/               conversations, reply window, takeover/release, echo guard
│   ├── Outbox/                     ActionDispatcher (the ONLY Meta writer), idempotency, states
│   ├── Audit/                      admin_audit writer
│   ├── Console/                    CLI commands (worker, reconcile, retention, ...)
│   └── Presentation/
│       ├── Api/                    Meta webhook, health endpoints
│       ├── Admin/                  dashboard, review, conversations, accounts, policies,
│       │                           knowledge, audit, automation switch
│       ├── Sign/                   admin login
│       └── Error/
├── assets/                         admin JS/CSS sources (if a build step is ever needed)
├── bin/console                     CLI entry point (worker runs as `bin/console worker:run`)
├── config/
│   ├── common.neon, services.neon  tracked application configuration
│   ├── local.neon.template         tracked template
│   └── local.neon                  LOCAL (gitignored), per-server overrides, no secrets
├── migrations/                     versioned DB migrations (structures/, dummy-data/)
├── tests/
│   ├── bootstrap.php
│   ├── Unit/                       PolicyEngine, classification mapping, windows, limits
│   ├── Integration/                webhook HMAC, queue concurrency, outbox, fake Meta/OpenAI
│   ├── Fakes/                      FakeMetaClient, FakeOpenAiClient
│   └── fixtures/                   synthetic or anonymized payloads only
├── docs/                           architecture notes, ADRs, Meta permissions checklist (Czech)
├── deploy/                         tracked, identical for every server
│   ├── README.md                   how to bootstrap a server, what goes into local/
│   ├── bootstrap-environment.sh    creates local/, .env, override and local.neon from templates
│   ├── compose/
│   │   ├── docker-compose.yaml     services: web, php, worker, db
│   │   ├── Dockerfile.php          shared image for php-fpm and the worker
│   │   ├── env.template            -> /.env
│   │   └── override.template.yaml  -> /docker-compose.override.yaml
│   ├── nginx/                      nginx.conf, conf.d/ (container nginx behind the host nginx)
│   ├── php/                        php.ini, www.conf, zz-override.*.template
│   └── mariadb/                    my.cnf, init/, zz-override.cnf.template
├── local/                          LOCAL (gitignored), everything specific to this server
│   ├── config/
│   │   ├── php/zz-override.ini
│   │   ├── php-fpm/zz-override.conf
│   │   ├── mariadb/zz-override.cnf
│   │   └── nginx/                  optional, e.g. trusted proxies
│   ├── secrets/                    mode 0700; files mode 0600
│   │   ├── app.env                 META_APP_SECRET, OPENAI_API_KEY, encryption key, DB user
│   │   └── db.env                  MariaDB root/app credentials
│   ├── volumes/mariadb/            DB datadir
│   └── backups/                    DB dumps
├── data/                           LOCAL (gitignored), specification, notes, labeled samples
├── log/                            Nette/Tracy + JSON app logs (contents gitignored)
├── temp/                           Nette cache, test temp (contents gitignored)
├── vendor/                         Composer (gitignored)
└── www/                            public document root (index.php, admin static assets)
```

### Infrastructure Rules

- `deploy/` holds only configuration that is identical on every server. Anything that differs per server goes into `local/`, `.env`, `docker-compose.override.yaml`, or `config/local.neon`. This includes secrets, bind addresses and ports, the subnet, PHP/FPM/MariaDB tuning, and trusted proxies. Each local file has a tracked `*.template` in `deploy/` or `config/`. When you change a template, update the matching local file too.
- `deploy/compose/docker-compose.yaml` uses paths relative to the repository root (`project_directory: .` in the stub). It mounts `deploy/**` read-only and `local/config/**` read-only as `zz-override.*`, so per-server values win. It loads secrets via `env_file: local/secrets/*.env`.
- The base compose file publishes no DB port. `web` binds only to `127.0.0.1:${WEB_PORT}`. The existing host nginx terminates HTTPS and proxies to it. Compose can only add on top of the base file, so put optional exposure into the override.
- Services: `web` (nginx), `php` (php-fpm), `worker` (same image, `bin/console worker:run`, `restart: unless-stopped`, graceful stop on SIGTERM), `db` (MariaDB). All services have healthchecks and capped json-file logging.
- The application gets secrets only from environment variables (specification §11). Secrets never go into neon files, the repository, fixtures, logs, or exception messages.

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
  - `docker compose exec php vendor/bin/tester tests/`
  - `docker compose exec php vendor/bin/phpstan analyse`
  - `docker compose exec php bin/console migrations:continue`
- Tests use a separate test database and never the development data. Tests use only fake Meta and OpenAI clients. Temporary files go under `temp/tests/` and are cleaned up after the run. Never leave large files in `/tmp`.
- Before you finish, run PHPStan and the relevant tests, and report the results honestly. If you could not run them, say so.
- Do not mark a Meta feature as done until the required permissions are actually granted and verified (specification §3.2). Before implementing concrete Graph endpoints, confirm the login flow and scopes with the user.
- Ask before committing or pushing. Never commit anything from `local/`, `data/`, `.env`, `docker-compose.override.yaml`, `config/local.neon`, real payloads, or tokens.
- Keep changes minimal and related to the task. Do not modify `vendor/`, `temp/`, `log/`, or generated files.
