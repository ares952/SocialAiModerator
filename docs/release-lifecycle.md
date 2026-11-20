# Životní cyklus vydání

Tento dokument je jediný zdroj pravdy pro přesné příkazy a jejich pořadí při vydání, aktualizaci
instance a obnově. Skilly v `.agents/skills/` na něj odkazují a nesmí vymýšlet jiné kroky.
Všechny příkazy se spouštějí z kořene repozitáře dané instance.

## Verzování

- Verze aplikace je v `version.txt` ve formátu `MAJOR.MINOR.PATCH` (bez prefixu), začátek `0.0.0`.
- **PATCH**: změna chování, oprava, refaktoring (i přesun/přejmenování tříd) nebo změna překladů
  bez změny struktury DB. Zvyšuje se i tehdy, když větev už nevydané zvýšení obsahuje.
- **MINOR** (PATCH = 0): změna vyžadující změnu struktury DB, tj. nová migrace
  v `migrations/structures/` nebo `migrations/basic-data/`.
- **MAJOR** (MINOR = PATCH = 0): velká změna nebo výslovný požadavek. Agent ji může navrhnout,
  provést jen po potvrzení.
- Tag vydání je anotovaný `vMAJOR.MINOR.PATCH` přesně podle `version.txt` na commitu v `origin/main`.
  Publikovaný tag se nikdy nemaže, nepřesouvá ani nevytváří znovu; chyba = nový PATCH a nový tag.

## Vytvoření tagu

Předpoklady: větev `main`, čistý pracovní strom, `HEAD` = `origin/main`, platná verze v `version.txt`,
tag zatím neexistuje lokálně ani na `origin`.

```sh
git fetch origin refs/heads/main:refs/remotes/origin/main --tags
VERSION=$(tr -d '[:space:]' < version.txt)
TAG="v$VERSION"
git tag -a "$TAG" -m "Release $TAG"
git push origin "$TAG"
git ls-remote --tags origin "refs/tags/$TAG" "refs/tags/$TAG^{}"
```

## Aktualizace instance

Platí pro každou instanci (test, produkce). Kroky 2–12 mění sdílený stav; agent je provádí jen
s potvrzením uživatele.

1. **Příprava (jen čtení).**
   - `git status --porcelain` je prázdný (kromě ignorovaných lokálních souborů).
   - `git fetch origin --tags`; cílový tag je anotovaný a jeho `version.txt` odpovídá názvu.
   - Zaznamenat výchozí stav: `git rev-parse HEAD`, `cat version.txt`, `docker compose ps`.
   - Přečíst všechny sekce `docs/upgrade-notes.md` novější než nasazená verze až po cílovou.
   - `git diff <výchozí> <cíl> -- deploy/ config/*.template migrations/` a připravit ruční změny
     lokálních souborů (`config/`, `.env`, `docker-compose.override.yaml`).
2. **Maintenance:** `bin/maintenance on`
3. **Zastavit worker:** `docker compose stop worker`
4. **Záloha DB:**

   ```sh
   umask 077; mkdir -p data/backups
   BACKUP="data/backups/$(date -u +%Y%m%dT%H%M%SZ)-v$(tr -d '[:space:]' < version.txt).sql.gz"
   docker compose exec -T db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" \
       --single-transaction --routines --triggers --add-drop-database --databases "$MARIADB_DATABASE"' \
       | gzip > "$BACKUP"
   gzip -t "$BACKUP" && ls -l "$BACKUP"
   ```

5. **Kód:** `git checkout --detach "$TAG"` (na testovací instanci lze po dohodě i větev).
6. **Lokální konfigurace:** promítnout změny šablon a poznámky z `docs/upgrade-notes.md`.
7. **Image a kontejnery bez workeru:**
   `docker compose build --pull php` (jen při změně `deploy/compose/Dockerfile.php` nebo aktualizaci
   image), pak `docker compose up -d --wait web` (spustí i `php` a `db`).
8. **Závislosti:** test `docker compose exec -T php composer install --no-interaction`,
   produkce `... composer install --no-interaction --no-dev --optimize-autoloader`.
9. **Cache:** `rm -rf temp/cache` (nikdy nemazat `temp/maintenance.lock`).
10. **DB a překlady:**
    `docker compose exec -T php bin/console migrations:continue`, pak
    `docker compose exec -T php bin/console translations:import`.
11. **Ověření při zapnuté maintenance:**
    - `docker compose exec -T web wget -qO- http://127.0.0.1/health/ready` vrací `{"status":"ok"}`;
    - `docker compose exec -T web wget -qO- http://127.0.0.1/` vrací stránku aplikace
      (127.0.0.1 je v `config/nginx/maintenance-allowlist.geo`);
    - `docker compose exec -T php bin/console translations:import --check`;
    - na testovací instanci `docker compose exec -T php composer test` a `composer analyse`;
    - end-to-end testy kódu nasazované verze `bin/e2e` (samostatný stack s dočasnými daty, data
      instance nepoužívá).
12. **Spuštění:** `docker compose up -d --wait` (worker), `bin/maintenance off`, ověřit veřejnou URL
    (`APP_URL` v `config/secrets/app.env`) a `docker compose ps`.

### Selhání a návrat

Maintenance zůstává zapnutá. Před krokem 10 stačí vrátit kód (`git checkout --detach <výchozí>`)
a zopakovat kroky 7–12. Po kroku 10 je nutné obnovit i DB ze zálohy z kroku 4:

```sh
gunzip -c "$BACKUP" | docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'
```

Poté `translations:import --force`, kroky 8–9 a 11–12. Zálohy v `data/backups/` nikdy nemazat
v rámci aktualizace.

## Maintenance

- `bin/maintenance on|off|status` vytváří/maže `temp/maintenance.lock`; nginx pak vrací 503
  se stránkou z `www-maintenance/` a hlavičkou `Retry-After`.
- Výjimky: `/health/*`, `/__maintenance__/*` a klienti z `config/nginx/maintenance-allowlist.geo`
  (skutečná IP klienta za proxy z `config/nginx/trusted-proxies.inc`).
- Meta webhooky dostanou během maintenance 503 a Meta je doručí znovu; maintenance proto
  držet co nejkratší.

## Aktualizace image

- `php`: `php:8.5-fpm-alpine` je pohyblivý tag řady 8.5; patch se získá `docker compose build --pull php`.
- `web`: `nginx:1.30-alpine` je pohyblivý tag řady 1.30; `docker compose pull web`.
- `redis`: `redis:8.10-alpine` je pohyblivý tag řady 8.10; `docker compose pull redis`. Restart
  sessions nemaže (AOF v `data/redis/`). Přechod na jinou řadu jen se souhlasem uživatele.
- `db`: MariaDB je připnutá na přesný patch nejnovější LTS řady (`mariadb:12.3.x`). Nový patch téže
  řady = změna `deploy/compose/docker-compose.yaml` (PATCH verze aplikace), záloha DB před
  restartem a `mariadb-upgrade`. Přechod na jinou řadu (nová LTS) jen se souhlasem uživatele.
- Po každé změně image: `docker compose up -d --wait`, kontroly z kroku 11.
