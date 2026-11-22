#!/bin/bash
# Prepares the per-server (gitignored) files of this checkout from the tracked templates.
# Idempotent: existing files are never overwritten, so it is safe to re-run after a pull that
# added a new template. Review and edit the created files before `docker compose up`.
set -euo pipefail
umask 0002

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

random() {
    openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "$1"
}

db_password="$(random 32)"

# php and worker run with the shared development group, so files they create stay group-writable.
shared_group="${SHARED_GROUP:-socialai}"
if ! app_gid="$(getent group "$shared_group" | cut -d: -f3)" || [[ -z "$app_gid" ]]; then
    app_gid="$(id -g)"
    echo "note: group '$shared_group' does not exist, using the primary group of $(id -un)" >&2
fi

# copy <template> <target> [mode]
copy() {
    local template="$1" target="$2" mode="${3:-0664}"
    if [[ -e "$target" ]]; then
        echo "keep    $target"
        return
    fi
    mkdir -p "$(dirname "$target")"
    sed \
        -e "s|__APP_UID__|$(id -u)|g" \
        -e "s|__APP_GID__|${app_gid}|g" \
        -e "s|__DB_PASSWORD__|${db_password}|g" \
        -e "s|__DB_ROOT_PASSWORD__|$(random 32)|g" \
        -e "s|__APP_ENCRYPTION_KEY__|$(openssl rand -base64 32)|g" \
        -e "s|__META_WEBHOOK_VERIFY_TOKEN__|$(random 40)|g" \
        "$template" > "$target"
    chmod "$mode" "$target"
    echo "create  $target"
}

# app.env and db.env share the generated DB password, so create them together or not at all.
exists() {
    [[ -e "$1" ]] && echo 1 || echo 0
}
if [[ "$(exists config/secrets/app.env)" != "$(exists config/secrets/db.env)" ]]; then
    echo "config/secrets/app.env and db.env must both exist or both be missing" >&2
    exit 1
fi

mkdir -p config/secrets
chmod 0700 config/secrets

copy deploy/compose/env.template .env
copy deploy/compose/override.template.yaml docker-compose.override.yaml
copy deploy/compose/app.env.template config/secrets/app.env 0600
copy deploy/compose/db.env.template config/secrets/db.env 0600
copy deploy/php/zz-override.ini.template config/php/zz-override.ini
copy deploy/php/zz-override.conf.template config/php-fpm/zz-override.conf
copy deploy/mariadb/zz-override.cnf.template config/mariadb/zz-override.cnf
copy deploy/nginx/trusted-proxies.inc.template config/nginx/trusted-proxies.inc
copy deploy/nginx/maintenance-allowlist.geo.template config/nginx/maintenance-allowlist.geo
copy config/local.neon.template config/local.neon

mkdir -p data/mariadb data/redis log temp
# DB dumps (bin/backup, release updates): only the owner reads them; the application containers
# mount a tmpfs over this directory, so it must exist before they start.
if [[ ! -d data/backups ]]; then
    (umask 0077 && mkdir -p data/backups)
fi

echo
echo "Next: edit .env, config/local.neon, config/nginx/* and config/secrets/app.env for this server,"
echo "then follow \"Nový server\" in deploy/README.md."
