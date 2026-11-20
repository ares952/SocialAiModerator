# Nasazení

Repozitář je zároveň adresářem Docker Compose projektu. Kořenový `docker-compose.yaml` jen načítá
`deploy/compose/docker-compose.yaml`.

## Co je kde

| Umístění | V gitu | Obsah |
| --- | --- | --- |
| `deploy/` | ano | konfigurace shodná pro všechny servery + šablony `*.template` |
| `config/common.neon`, `config/services.neon`, `config/local.neon.template` | ano | konfigurace aplikace |
| `.env` | ne | interpolace Compose: UID/GID, bind adresa a port, subnet |
| `docker-compose.override.yaml` | ne | doplňky Compose pro daný server |
| `config/local.neon` | ne | Nette konfigurace serveru (důvěryhodná proxy apod.), bez tajemství |
| `config/secrets/app.env` | ne | prostředí kontejnerů `php` a `worker` (DB, Meta, OpenAI, šifrovací klíč) |
| `config/secrets/db.env` | ne | prostředí kontejneru `db` (root heslo, aplikační účet) |
| `config/php/`, `config/php-fpm/`, `config/mariadb/` | ne | `zz-override.*` načítané po sdílené konfiguraci |
| `config/nginx/trusted-proxies.inc` | ne | přesná adresa reverzní proxy pro skutečnou IP klienta v nginx |
| `config/nginx/maintenance-allowlist.geo` | ne | klienti, kteří obcházejí maintenance |
| `data/language_cs.json`, `data/language_en.json` | ano | zdroj překladů UI, importuje `bin/console translations:import` |
| `data/mariadb/`, `data/backups/` | ne | datadir MariaDB, zálohy DB |
| `data/redis/` | ne | data Redisu (sessions administrace, AOF) |
| `www-maintenance/` | ano | stránka údržby (`bin/maintenance on\|off\|status`) |

Kontejnery `php` a `worker` adresář `config/secrets/` nevidí (překrytý `tmpfs`), tajemství dostávají
jen přes `env_file`.

## Nový server

```bash
deploy/bootstrap-environment.sh        # vytvoří lokální soubory ze šablon, nic nepřepíše
$EDITOR .env config/local.neon config/nginx/trusted-proxies.inc config/secrets/app.env
./update-permissions                   # skupina socialai pro vývojové soubory, temp/ a log/
docker compose up -d --build --wait
docker compose exec php composer install
docker compose exec php bin/console migrations:continue
docker compose exec php bin/console translations:import
docker compose exec php bin/console operator:create admin@example.com "Jméno Příjmení"
```

`operator:create` založí prvního administrátora (role `admin`, jinou lze zvolit přes `--role`).
Heslo (alespoň 12 znaků) se zadává dvakrát interaktivně, nikdy jako argument. Další operátory
přidává administrátor v administraci (Operátoři).

## Přístup do administrace

- Celá administrace včetně úvodní stránky vyžaduje přihlášení. Přihlášení platí rok od poslední
  aktivity; sessions jsou v Redisu (služba `redis`, data v `data/redis/`), takže přežijí restart.
- Role: `admin` (vše včetně operátorů), `moderator` (moderace, převzetí konverzací), `viewer`
  (jen čtení, bez obsahu DM). Vždy musí zůstat alespoň jeden aktivní administrátor.
- Deaktivace operátora nebo změna hesla ho okamžitě odhlásí ze všech zařízení.
- Po 5 neúspěšných pokusech na jeden e-mail (20 z jedné adresy) za 15 minut se přihlášení
  dočasně zablokuje (`login` v `config/common.neon`).
- Zapomenuté heslo (např. jediného administrátora):
  `docker compose exec php bin/console operator:password admin@example.com`

Aktualizace, vydání, maintenance a aktualizace image: [docs/release-lifecycle.md](../docs/release-lifecycle.md).

Při změně šablony v `deploy/` nebo `config/*.template` je nutné ručně promítnout změnu do lokálních
souborů na každém serveru (skript existující soubory nepřepisuje).

## Reverzní proxy

TLS ukončuje proxy před kontejnerem `web`. Aplikace jí věří jen podle `http.proxy`
v `config/local.neon`, nginx (logy, allowlist maintenance) podle `config/nginx/trusted-proxies.inc`;
v obou uvádět stejnou přesnou adresu proxy, nikdy celý rozsah LAN.

Proxy musí předávat původní Host a schéma, například pro Apache:

```apache
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https"
ProxyPass        / http://<WEB_BIND>:<WEB_PORT>/
ProxyPassReverse / http://<WEB_BIND>:<WEB_PORT>/
```

`/health/live` a `/health/ready` nevracejí citlivá data. Pokud nemají být dostupné z internetu,
zablokovat je na proxy.

## Porty

Webové rozhraní běží na `WEB_PORT`. Další port pro tutéž instanci (např. DB pro administraci
v `docker-compose.override.yaml`) bere `WEB_PORT + 1`, `+ 2`, …
