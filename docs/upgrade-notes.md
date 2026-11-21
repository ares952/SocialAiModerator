# Poznámky k aktualizacím

Jednorázové kroky a kontroly, které konkrétní verze vyžaduje při nasazení a které
`docs/release-lifecycle.md` sám neprovádí. Nejde o changelog. Při aktualizaci se přečtou všechny
sekce novější než nasazená verze až po cílovou (nejnovější nahoře).

Formát sekce:

```markdown
## X.Y.Z

- **Týká se:** všech instancí | produkce | test
- **Kdy:** před krokem N | po kroku N
- **Akce:** co přesně udělat (bez tajných údajů a obsahu privátní konfigurace)
- **Ověření:** jak potvrdit, že je hotovo
```

## 0.7.0

- **Týká se:** všech instancí
- **Kdy:** po kroku 10 (migrace)
- **Akce:** `docker compose exec -T php bin/console events:normalize`: doplní typ, externí ID
  a čas událostem přijatým před touto verzí (mění jen metadata, ne stav).
- **Ověření:** výpis končí `N events normalized` a v administraci (Události) mají všechny
  události vyplněný typ.

## 0.6.2

- **Týká se:** všech instancí
- **Kdy:** po kroku 12
- **Akce:** `docker compose up -d --force-recreate --no-deps --wait web`
  (`--no-deps`, jinak se znovu vytvoří i `db` a `worker`). Kontejner `web` má `nginx.conf`
  připojený jako soubor, takže nový formát access logu (bez query stringu u požadavků
  s `verify_token`) načte až nově vytvořený kontejner. Ten zároveň zahodí starý log kontejneru,
  ve kterém mohl být Verify token z ověření webhooku v otevřeném tvaru.
- **Ověření:** `docker compose logs web` po dalším ověření webhooku v Meta App Dashboardu
  obsahuje `/webhooks/meta?[redacted]`.

## 0.6.0

- **Týká se:** všech instancí
- **Kdy:** po kroku 10 (migrace)
- **Akce:**
  - spustit `docker compose exec php bin/console encryption:rotate`: přešifruje dosud uložené
    události a evaluační vzorky do formátu s ID klíče (klíč se nemění);
  - v Meta App Dashboardu lze Callback URL změnit na `<APP_URL>/webhooks/meta`; původní
    `/api/meta/webhook` dál funguje;
  - pro každý aktivní účet, který bude volat Graph API, uložit token příkazem
    `bin/console account:token` (`docs/meta-webhooks.md`, oddíl 4); tokeny se do `app.env`
    nezapisují;
  - `APP_ENCRYPTION_KEY` nově přijímá seznam klíčů oddělený čárkou (rotace); stávající hodnota
    platí beze změny. Klíč uložit odděleně od záloh DB (`docs/meta-webhooks.md`, oddíl 6).
- **Ověření:** `encryption:rotate --dry-run` hlásí u všech tabulek `0 to re-encrypt, 0 failed`;
  stránka Účty ukazuje stav tokenu; `GET <APP_URL>/webhooks/meta` bez parametrů vrací 403.

## 0.4.0

- **Týká se:** všech instancí
- **Kdy:** v kroku 6 (lokální konfigurace)
- **Akce:** aplikace nově používá `OPENAI_API_KEY`, `OPENAI_CLASSIFIER_MODEL` a
  `OPENAI_MODERATION_MODEL` z `config/secrets/app.env` pro běhy vyhodnocení AI. Bez klíče a modelu
  aplikace běží, jen nejde spustit vyhodnocení (administrace to oznámí). `AUTOMATION_DEFAULT_MODE`
  musí být `observe`, `assisted` nebo `automatic` (jiná hodnota zastaví start aplikace); dokud admin
  neuloží první verzi pravidel, platí tento režim.
- **Ověření:** stránka Pravidla zobrazuje platnou verzi 0 a zvolený režim; po doplnění klíče
  a modelu doběhne vyhodnocení alespoň jednoho vzorku bez chyby.

## 0.3.0

- **Týká se:** všech instancí
- **Kdy:** v krocích 6, 7 a po kroku 10
- **Akce:**
  - krok 6: spustit `deploy/bootstrap-environment.sh` (založí `data/redis/`, nic nepřepíše);
  - krok 7: `docker compose build --pull php` (image nově obsahuje `phpredis`), pak
    `docker compose up -d --wait web`; tím se poprvé spustí nová služba `redis` a znovu vytvoří
    `php` (PHP nově běží v UTC, `deploy/php/php.ini`);
  - po kroku 10: pokud v tabulce `operators` ještě nikdo není, založit prvního administrátora
    `docker compose exec php bin/console operator:create <e-mail> "<jméno>"` (heslo se zadává
    interaktivně). Do té doby se do administrace nikdo nepřihlásí.
- **Ověření:** `docker compose ps redis` je `healthy`; `/` přesměruje na `/sign/in`; po přihlášení
  se zobrazí přehled a `docker compose exec redis redis-cli --scan --pattern 'socialai:session:*'`
  vypíše klíč session.

## 0.1.0

- **Týká se:** všech instancí
- **Kdy:** v kroku 6 (lokální konfigurace)
- **Akce:** spustit `deploy/bootstrap-environment.sh` (vytvoří nové `config/nginx/trusted-proxies.inc`
  a `config/nginx/maintenance-allowlist.geo`). Do `trusted-proxies.inc` zapsat přesnou adresu
  reverzní proxy (stejnou jako `http.proxy` v `config/local.neon`). V `.env` nastavit `APP_GID`
  na GID skupiny `socialai`. Spustit `./update-permissions`.
- **Ověření:** `docker compose up -d --wait` projde, `bin/maintenance on` vrací veřejně 503
  a `bin/console translations:import --check` hlásí `translations up to date`.
