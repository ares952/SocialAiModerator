# Testování

| Úroveň | Kde běží | Data | Příkaz |
| --- | --- | --- | --- |
| Unit | kontejner `php` instance | žádná | `docker compose exec php composer test` |
| Integrační | kontejner `php` instance | databáze `<DB_NAME>_test`, před každým souborem znovu vytvořená | `docker compose exec php composer test` |
| End-to-end | samostatný stack `<checkout>-e2e` | dočasná DB, Redis, `temp/` a `log/` v tmpfs | `bin/e2e` |
| Statická analýza | kontejner `php` instance | – | `docker compose exec php composer analyse` |

Všechny testy používají jen fake klienty Mety a OpenAI a syntetická data.

## End-to-end testy (`bin/e2e`)

- `deploy/compose/e2e.yaml` je samostatný Compose projekt s kontejnery `web`, `php`, `worker`,
  `db` a `redis`, s vlastní sítí a bez publikovaných portů. Kód pracovní kopie připojuje jen pro
  čtení; databáze, Redis, `temp/` a `log/` jsou v tmpfs.
- Přístupové údaje se vygenerují náhodně při každém startu do `temp/e2e/` (mimo dohled kontejnerů)
  a po `bin/e2e down` se smažou. Klíč OpenAI není nastaven, Meta se nevolá.
- `bin/e2e` sestaví image, spustí stack, provede migrace a import překladů, spustí worker, pustí
  `tests/E2e` uvnitř kontejneru `php` proti `http://web` a celý stack zahodí.
  `bin/e2e run --keep` stack ponechá (`bin/e2e compose logs`, `bin/e2e test`, `bin/e2e down`).
- Testy v `tests/E2e` se mimo tento stack přeskočí (proměnná `E2E=1`), takže
  `composer test` na instanci se jejích dat nikdy nedotkne.

## Pravidla

- Na instanci (testovací ani produkční) se nezakládají testovací operátoři, účty ani jiná testovací
  data a neposílají se na ni testovací požadavky. Ruční kontrolu nové funkce dělat v
  `bin/e2e run --keep` nebo novým E2E testem.
- Nový uživatelský tok = nový nebo rozšířený test v `tests/E2e`.
