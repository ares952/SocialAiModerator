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

## 0.1.0

- **Týká se:** všech instancí
- **Kdy:** v kroku 6 (lokální konfigurace)
- **Akce:** spustit `deploy/bootstrap-environment.sh` (vytvoří nové `config/nginx/trusted-proxies.inc`
  a `config/nginx/maintenance-allowlist.geo`). Do `trusted-proxies.inc` zapsat přesnou adresu
  reverzní proxy (stejnou jako `http.proxy` v `config/local.neon`). V `.env` nastavit `APP_GID`
  na GID skupiny `socialai`. Spustit `./update-permissions`.
- **Ověření:** `docker compose up -d --wait` projde, `bin/maintenance on` vrací veřejně 503
  a `bin/console translations:import --check` hlásí `translations up to date`.
