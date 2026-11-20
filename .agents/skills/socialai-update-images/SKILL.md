---
name: socialai-update-images
description: Rebuild and update the container images of a Social AI Moderator instance (php base image patch, nginx, MariaDB patch within the current LTS series) with inventory, backup, recreate and validation. Use for security/patch image updates or image rebuilds without changing the application release.
---

# Social AI Moderator image update

Respond in the user's language. Read the repository `AGENTS.md`, [deploy/README.md](../../../deploy/README.md)
and **Aktualizace image** in [the release lifecycle](../../../docs/release-lifecycle.md).
Creating this skill or an inventory request does not authorize changing running services.

## Inventory (read-only)

Determine the instance (test or production); ask if unclear. Default scope is this one Compose
project, never other projects on the host. Collect for every service in
`docker compose config --services`:

- configured image or build (`deploy/compose/docker-compose.yaml`, `deploy/compose/Dockerfile.php`);
- running image ID (`docker compose images`) and actual version
  (`docker compose exec php php -v`, `docker compose exec web nginx -v`,
  `docker compose exec db mariadbd --version`).

Never print the resolved Compose config with environment values.

## Candidates

- `php` (`php:8.5-fpm-alpine`) and `web` (`nginx:1.30-alpine`) use moving series tags: the
  candidate is the newest image of the same tag (`docker pull`), compared by version and digest.
- `db` is pinned to an exact patch of the latest MariaDB LTS series. Check `docker pull mariadb:lts`
  and `mariadb:<series>`; a newer patch of the same series is a candidate and requires editing
  the pin (a PATCH bump of the application, see `socialai-version-bump`). A newer LTS series is
  only reported and needs the user's explicit decision.
- A registry failure leaves an image unverified; never treat it as up to date.

Present the plan (service, current → candidate version, downtime, backup for `db`) and wait for
approval.

## Execute

1. `bin/maintenance on` when `php` or `db` is recreated; `docker compose stop worker`.
2. Before a `db` change: back up the database exactly as in step 4 of **Aktualizace instance**.
3. `docker compose build --pull php` and/or `docker compose pull web db`.
4. `docker compose up -d --wait`. After a MariaDB patch update run
   `docker compose exec -T db sh -c 'exec mariadb-upgrade -uroot -p"$MARIADB_ROOT_PASSWORD"'`.
5. Validate as in step 11 (health, homepage through the allowlist, versions again; on the test
   instance `composer test`), then `bin/maintenance off` and check the public URL.

On failure keep maintenance on, report, and offer to return to the previous image (rebuild from
the previous pin, or restore the DB backup for a failed `db` upgrade) only with approval.
Remove only dangling images of this project after success, never other projects' images.
