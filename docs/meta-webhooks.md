# Meta webhooky, účty a přístupové tokeny

Provozní postup pro příjem webhooků Facebook Page, Messengeru a Instagramu a pro správu
přístupových tokenů Meta (specifikace §3, §6, §8.2). Rozhodnutí o přihlašovací cestě a checklist
oprávnění jsou v `docs/meta-checklist.md`.

V dokumentu jsou jen placeholdery: `<APP_URL>` (veřejná adresa instance), `<PAGE_ID>`,
`<IG_ACCOUNT_ID>`, `<GRAPH_VERSION>` (`META_GRAPH_API_VERSION`). Konkrétní hodnoty patří do lokální
konfigurace instance, ne do repozitáře.

> Údaje o Meta API se mění. Body označené **ověřit** zkontrolovat v aktuální dokumentaci Meta pro
> pinovanou verzi Graph API a výsledek zapsat sem.

## 1. Architektura a tok událostí

```text
Meta ──HTTPS──> reverse proxy (TLS) ──> web (nginx) ──> php: MetaWebhookPresenter
                                                          │ 1. ověří X-Hub-Signature-256 nad raw body
                                                          │ 2. rozdělí doručení na jednotlivé události
                                                          │ 3. v jedné transakci uloží social_events
                                                          │    (šifrovaně, deduplikace) + queue_jobs
                                                          └ 4. odpoví 200 EVENT_RECEIVED
worker: event.process ──> zatím každou událost předá do stavu review (žádná automatická akce)
```

- Endpoint: `GET|POST <APP_URL>/webhooks/meta` (callback URL v Meta Dashboardu). Cesta
  `/api/meta/webhook` ze specifikace §7.1 zůstává jako alias se stejným chováním.
- `GET` ověří `hub.mode=subscribe` a `hub.verify_token` (konstantní čas) a vrátí přesně
  `hub.challenge`; jinak 403.
- `POST`:

  | Situace | Odpověď | Uloží se |
  | --- | --- | --- |
  | chybějící nebo neplatný podpis | 403 | nic |
  | podepsané, ale nevalidní JSON nebo struktura | 400 | nic |
  | podepsaný nepodporovaný `object` (např. `permissions`) | 200 | nic, jen řádek v logu |
  | `object` = `page` nebo `instagram` | 200 | každá položka `changes[]`, `messaging[]`, `standby[]` jako událost |
  | chyba databáze | 503 | nic (Meta doručení zopakuje) |
  | maintenance | 503 (nginx) | nic (Meta doručení zopakuje) |

- Události účtů, které nejsou v administraci (Účty) nebo nejsou aktivní, se uloží jako `ignored`
  a nezpracují se.
- Deduplikace: zprávy podle `mid`, přičemž echo (`is_echo`) a zrušená zpráva (`is_deleted`) jsou
  samostatné události. Ostatní položky podle hashe normalizované položky, takže každá změna
  komentáře (přidání, úprava, smazání) je vlastní událost a opakované doručení stejné změny se
  uloží jen jednou.
- Limit velikosti těla je 2 MB (`client_max_body_size` v `deploy/nginx/nginx.conf`). HTTPS
  zajišťuje reverzní proxy před kontejnerem `web` (`deploy/README.md`, oddíl Reverzní proxy);
  aplikace pro webhook nepotřebuje žádnou zvláštní konfiguraci proxy.
- V HTTP requestu se nevolá AI ani Meta. Automatické akce jsou vypnuté: `EventNormalizer`
  (rozlišení komentářů, zpráv, echa, úprav a smazání) navazuje po ověření skutečných payloadů
  a výchozí režim zůstává `observe`.

## 2. Konfigurace instance

`config/secrets/app.env` (prostředí kontejnerů `php` a `worker`):

| Proměnná | Význam |
| --- | --- |
| `META_APP_ID` | ID aplikace z App Dashboardu |
| `META_APP_SECRET` | App Secret, klíč podpisu webhooků; bez něj endpoint odpovídá 500 |
| `META_WEBHOOK_VERIFY_TOKEN` | generuje `deploy/bootstrap-environment.sh`; stejná hodnota do App Dashboardu |
| `META_GRAPH_API_VERSION` | pinovaná verze Graph API |
| `APP_ENCRYPTION_KEY` | klíč šifrování tokenů a událostí v DB (oddíl 5) |

ID Page a Instagram účtů ani tokeny se do prostředí nezapisují: účty se registrují v administraci
(Účty) a tokeny se ukládají šifrovaně do databáze (oddíl 4). Po změně `app.env` je potřeba znovu
vytvořit kontejnery `php` a `worker` (`docker compose up -d php worker`).

## 3. Meta Dashboard a odběr webhooků (ruční kroky)

Volání Graph API v tomto oddílu se provádějí ručně a jen po potvrzení. Aplikace je sama nevolá.

### 3.1 Callback URL

V App Dashboardu, produkt **Webhooks**:

1. Pro objekt **Instagram** a pro objekt **Page** zadat Callback URL `<APP_URL>/webhooks/meta`
   a Verify Token = hodnota `META_WEBHOOK_VERIFY_TOKEN`. Meta pošle `GET` a uloží URL až po
   odpovědi 200 s challenge.
2. Přihlásit pole:
   - Instagram: `comments`, `messages` (později podle potřeby `mentions`, `message_reactions`,
     `messaging_postbacks`, `messaging_seen`);
   - Page: `feed`, `messages`, `message_echoes`, `messaging_postbacks`.

### 3.2 Instagram účet (Unified Login, systémový uživatel)

Instagram API s přihlášením přes Facebook používá `graph.facebook.com`, nikoli
`graph.instagram.com`. Token je Business Integration System User Access Token, proto se místo
`/me` vždy používá `<IG_ACCOUNT_ID>`.

```bash
read -rs META_TOKEN   # token se nezobrazí a nezůstane v historii shellu
curl -sS -X POST -H "Authorization: Bearer $META_TOKEN" \
  "https://graph.facebook.com/<GRAPH_VERSION>/<IG_ACCOUNT_ID>/subscribed_apps?subscribed_fields=comments,messages"
curl -sS -H "Authorization: Bearer $META_TOKEN" \
  "https://graph.facebook.com/<GRAPH_VERSION>/<IG_ACCOUNT_ID>/subscribed_apps"
unset META_TOKEN
```

Očekávaná odpověď prvního volání je `{"success":true}`, druhé vypíše aplikaci a přihlášená pole.
**Ověřit:** pro IG účet propojený s Page Meta u některých verzí vyžaduje odběr na úrovni Page
(oddíl 3.3); pokud volání na `<IG_ACCOUNT_ID>` selže, přihlásit Page.

Zprávy z Instagramu chodí jen tehdy, když je v aplikaci Instagram zapnuto
**Nastavení → Zprávy → Připojené nástroje → Povolit přístup ke zprávám**.

### 3.3 Facebook Page a Messenger (Page Access Token)

```bash
read -rs META_TOKEN   # Page Access Token
curl -sS -X POST -H "Authorization: Bearer $META_TOKEN" \
  "https://graph.facebook.com/<GRAPH_VERSION>/<PAGE_ID>/subscribed_apps?subscribed_fields=feed,messages,message_echoes,messaging_postbacks"
curl -sS -H "Authorization: Bearer $META_TOKEN" \
  "https://graph.facebook.com/<GRAPH_VERSION>/<PAGE_ID>/subscribed_apps"
unset META_TOKEN
```

Potřebná oprávnění: `pages_manage_metadata` (odběr), `pages_read_engagement`,
`pages_read_user_content` (komentáře), `pages_messaging` (Messenger). Pokud Page souběžně používá
schránku Meta Business Suite, **ověřit** směrování konverzací, aby aplikace a lidé neodpovídali
dvakrát.

### 3.4 Ověření skutečného doručení

1. Účet je v administraci (Účty) aktivní a jeho ID je přesně `entry.id` z webhooku: číselné ID
   Page pro objekt `page`, ID Instagram professional účtu (ne ID Page) pro objekt `instagram`.
2. Napsat testovací komentář a DM z účtu, který má v aplikaci roli (ve vývojovém režimu Meta
   jiným uživatelům webhooky neposílá).
3. V administraci (Události) se objeví událost se správnou platformou, účtem a tématem
   (`comments`, `feed`, `messaging`). V `log/app.log` je řádek `Meta webhook received`
   s počtem událostí. Testovací tlačítko v App Dashboardu posílá ukázková data s neexistujícím
   účtem, takže se uloží jako `ignored`.
4. Echo vlastní zprávy (odpověď z Instagramu nebo Messengeru) se objeví jako další událost
   `messaging` a nespustí žádnou akci.

### 3.5 Typické chyby

| Příznak | Příčina a řešení |
| --- | --- |
| Dashboard hlásí, že callback nejde ověřit | Verify Token neodpovídá `META_WEBHOOK_VERIFY_TOKEN`; URL není veřejně dostupná přes HTTPS; běží maintenance (503). |
| Webhooky dostávají 403 | Jiný App Secret, než má aplikace v Dashboardu; proxy mění tělo požadavku (podpis se počítá nad nezměněným raw body). |
| 500 na webhooku | Chybí `META_APP_SECRET` nebo `META_WEBHOOK_VERIFY_TOKEN` v `app.env`, nebo kontejner nebyl po změně znovu vytvořen. |
| Události jsou `ignored` | Účet není v administraci, je neaktivní, nebo má jiné ID než `entry.id` (častá záměna ID Page a ID IG účtu). |
| Nechodí nic | Aplikace není přihlášena k odběru (`subscribed_apps`), pole nejsou v Dashboardu zapnutá, autor nemá v aplikaci roli (vývojový režim), u IG zpráv je vypnutý přístup ke zprávám. |
| Občasné 503 | Maintenance nebo nedostupná DB; Meta doručení opakuje, nic se neztratí. Při dlouhém výpadku Meta odběr může pozastavit; zkontrolovat v Dashboardu. |

### 3.6 App Review, Live režim, ověření firmy

- Ve vývojovém režimu aplikace dostává data jen od uživatelů s rolí v aplikaci. Pro komentáře
  a zprávy veřejnosti je potřeba **Live** režim a **Advanced Access** k použitým oprávněním,
  které se získává v **App Review**. Meta k tomu obvykle vyžaduje **Business Verification**.
- Žádat jen o oprávnění pro funkce, které budou v provozu. Funkci neoznačovat za hotovou, dokud
  oprávnění nejsou udělena a ověřena na účtu bez role v aplikaci (specifikace §3.2).
- Přepnutí do Live režimu, odeslání App Review a publikace jsou ruční kroky s potvrzením
  vlastníka aplikace.

## 4. Účty a přístupové tokeny

- Účet (Page nebo IG) se zaregistruje v administraci (Účty) s číselným ID.
- Token se uloží nebo vymění konzolovým příkazem. Token se zadává skrytě, nikdy jako argument:

  ```bash
  docker compose exec php bin/console account:token <ID účtu v administraci> \
    --type=system_user \
    --scopes=instagram_basic,instagram_manage_comments,instagram_manage_messages \
    --expires=never
  ```

  - `--type`: `system_user` (token systémového uživatele pro Instagram) nebo `page`
    (Page Access Token).
  - `--scopes`: udělená oprávnění, jak je ukazuje Access Token Debugger.
  - `--expires`: datum expirace v UTC (`YYYY-MM-DD` nebo `"YYYY-MM-DD HH:MM"`) nebo `never`.
    I token bez expirace může Meta odvolat nebo zneplatnit.
  - `--data-access-expires` (volitelné): konec přístupu k datům v UTC.
- Výměna platí okamžitě bez restartu: aplikace čte token z databáze při každém použití
  (`App\Account\TokenProvider`). Výměnu zapíše audit (bez tokenu).
- Stav tokenu (typ, platnost, poslední výměna) je v administraci na stránce Účty. Token se nikdy
  nezobrazuje ani neloguje. Chybějící, neplatný nebo prošlý token je chyba a akce se neprovede
  (fail closed).
- Kontrola platnosti tokenu proti Meta (stav `valid` / `invalid`) přibude s klientem Graph API;
  do té doby má uložený token stav „Neověřený“.

## 5. Šifrovací klíč a jeho rotace

- `APP_ENCRYPTION_KEY` šifruje tokeny, payloady událostí a evaluační vzorky (XChaCha20-Poly1305,
  libsodium). Klíč je jen v `config/secrets/app.env`, nikdy v databázi ani v zálohách DB.
- Každá zašifrovaná hodnota nese ID klíče (otisk, ne klíč). `encryption:rotate` vypíše ID
  aktuálního klíče.
- Rotace:
  1. Vygenerovat nový klíč `openssl rand -base64 32`.
  2. V `app.env` nastavit `APP_ENCRYPTION_KEY=<nový>,<starý>`. První klíč šifruje, další jen
     dešifrují.
  3. `docker compose up -d php worker`.
  4. `docker compose exec php bin/console encryption:rotate` přešifruje vše novým klíčem. Výpis
     musí mít u všech tabulek `0 failed`; opakovaný běh hlásí jen `current`.
  5. Starý klíč z `APP_ENCRYPTION_KEY` odebrat a znovu `docker compose up -d php worker`.
  6. Starý klíč uchovat u záloh DB, které byly pořízeny před rotací (oddíl 6).

## 6. Záloha a obnova

- Záloha DB (`docs/release-lifecycle.md`, krok 4) obsahuje tokeny a události jen zašifrované. Bez
  klíče je nepoužitelná, s klíčem obsahuje platné přístupové tokeny.
- Klíč (všechny klíče, kterými jsou data v záloze zašifrovaná) uložit odděleně od záloh, např.
  ve správci hesel vlastníka instance, s datem, od kdy platí. Nikdy ne do `data/backups/`, do
  repozitáře nebo do stejného úložiště jako dumpy DB.
- Obnova:
  1. Vrátit do `config/secrets/app.env` klíč, kterým jsou data zálohy zašifrovaná (při obnově
     starší zálohy po rotaci `APP_ENCRYPTION_KEY=<současný>,<starý>`).
  2. Obnovit DB podle `docs/release-lifecycle.md` (rollback) a znovu vytvořit `php` a `worker`.
  3. `docker compose exec php bin/console encryption:rotate --dry-run`: všechny tabulky musí mít
     `0 failed`. Pokud byl použit i starý klíč, spustit rotaci bez `--dry-run` (oddíl 5).
- Ztráta klíče: uložené tokeny a payloady událostí nejdou dešifrovat. Nový klíč vygenerovat,
  tokeny znovu zadat příkazem `account:token` a staré události považovat za ztracené.
- Únik klíče spolu se zálohou: tokeny v Meta odvolat a vygenerovat nové, klíč rotovat (oddíl 5).

## 7. Testování

- `docker compose exec php composer test`: unit a integrační testy endpointu (podpis, challenge,
  multi-entry, Instagram komentář, zpráva, echo a zrušená zpráva, Facebook komentář a Messenger,
  opakované doručení, nepodporovaný objekt, chyba DB), tokenů a rotace klíče. Syntetické
  payloady jsou v `tests/fixtures/meta/`.
- `bin/e2e`: webhook přes nginx na obou cestách, uložení, fronta a worker v izolovaném stacku.
- Ruční pokus s podepsaným požadavkem jen v e2e stacku (`bin/e2e run --keep`), nikdy proti
  instanci (`docs/testing.md`).
