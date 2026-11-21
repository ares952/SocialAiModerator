# Meta: přístupy, oprávnění a checklist (Milestone 0)

Podklad pro rozhodnutí a nastavení přístupu k Facebook Page a Instagram Professional účtu
(specifikace §3, §10 – Milestone 0). Aplikace zatím **nevolá žádné Graph API**. Provozní postup
pro webhooky, tokeny a odběr je v `docs/meta-webhooks.md`.

> Údaje o Meta API se často mění. Každý bod označený **ověřit** zkontrolovat v aktuální
> dokumentaci (odkazy ve specifikaci §15) a výsledek zapsat sem. Funkce se nesmí označit za hotovou,
> dokud oprávnění nejsou skutečně udělena a ověřena (§3.2).

## 1. Rozhodnutí: způsob přihlášení

| | Facebook Login for Business | Instagram API s Instagram Login |
| --- | --- | --- |
| Pokrývá | Facebook Page **i** Instagram propojený s Page | jen Instagram Professional účet |
| Podmínka pro IG | IG Business/Creator účet propojený s Facebook Page | žádná Page není potřeba |
| API host | `graph.facebook.com` | `graph.instagram.com` |
| Tokeny | uživatelský token → tokeny Page (`/me/accounts`), případně systémový uživatel Business portfolia | token IG uživatele |
| Webhooky | objekty `page` a `instagram` | objekt `instagram` |

**Doporučení:** Facebook Login for Business (preferuje ho i specifikace §3.1), protože jedním
nastavením pokryje Page i Instagram. Platí jen tehdy, když je Instagram Harmony Face Yoga
Business/Creator účet propojený s Page (otevřená otázka §13.1). Jinak Instagram Login jen pro
Instagram a Facebook Login pro Page; aplikace drží obě cesty oddělené (`InstagramConnector`,
oddělené hosty – AGENTS.md).

Rozhodnutí (21. 11. 2026, testovací účty): ☑ přihlášení přes Facebook (Instagram API with Unified
Login), host `graph.facebook.com` pro Page i Instagram. Instagram používá Business Integration
System User Access Token a vždy ID IG účtu místo `/me`; Page používá Page Access Token. Tokeny se
zadávají ručně (`bin/console account:token`), OAuth tok v administraci v1 nepotřebuje. Pro účty
Harmony Face Yoga zůstávají otázky §13.1 a §13.2 otevřené.

## 2. Oprávnění podle funkcí (ověřit)

| Funkce | Facebook Login for Business | Instagram Login |
| --- | --- | --- |
| Seznam a výběr Page | `pages_show_list`, `business_management` (u Business portfolia) | – |
| Webhooky Page (přihlášení k odběru) | `pages_manage_metadata` | – |
| Čtení komentářů a příspěvků | `pages_read_engagement`, `pages_read_user_content` | – |
| Odpověď, skrytí, smazání komentáře na FB | `pages_manage_engagement` | – |
| Messenger | `pages_messaging` | – |
| IG profil a média | `instagram_basic` | `instagram_business_basic` |
| IG komentáře (čtení, odpověď, skrytí) | `instagram_manage_comments` | `instagram_business_manage_comments` |
| IG Direct | `instagram_manage_messages` | `instagram_business_manage_messages` |

- Ve vývojovém režimu aplikace dostává data jen od uživatelů s rolí v aplikaci (admin, developer,
  tester). Pro komentáře a zprávy veřejnosti je potřeba Live režim, **Advanced Access** k výše
  uvedeným oprávněním (App Review) a obvykle **ověření firmy** (Business Verification). **Ověřit**
  aktuální požadavky pro každé oprávnění.
- Žádat jen o oprávnění pro funkce, které budou v provozu; mazání komentářů (`FR-C08`) je P1.

## 3. Webhooky (ověřit názvy polí)

Aplikace má hotový endpoint `GET/POST <APP_URL>/webhooks/meta` (alias `/api/meta/webhook`):
ověří verify token a HMAC-SHA256 podpis nad raw body, událost uloží šifrovaně, deduplikuje
a zařadí do fronty; události neaktivních nebo nezaregistrovaných účtů jen uloží jako ignorované,
podepsané nepodporované objekty potvrdí bez uložení.

| Objekt | Pole k odběru | Účel |
| --- | --- | --- |
| `page` | `feed` | nové, upravené a smazané komentáře pod příspěvky Page |
| `page` | `messages`, `message_echoes` | příchozí zprávy Messengeru; echo vlastních zpráv (nesmí spustit odpověď) |
| `page` | `messaging_postbacks` | tlačítka a postbacky v Messengeru |
| `instagram` | `comments` | komentáře pod příspěvky a Reels |
| `instagram` | `messages` | zprávy IG Direct (včetně echa vlastních zpráv – **ověřit**) |

- V App Dashboardu (produkt Webhooks) zadat Callback URL `<APP_URL>/webhooks/meta`
  a Verify Token = `META_WEBHOOK_VERIFY_TOKEN` z `config/secrets/app.env`.
- Page i IG účet musí aplikaci přihlásit k odběru (`POST /{id}/subscribed_apps`), postup
  a ověření doručení jsou v `docs/meta-webhooks.md` oddíl 3.
- Pokud Page souběžně používá schránku Meta Business Suite, **ověřit** směrování konverzací
  (Conversation Routing / dříve handover protocol), aby aplikace a lidé neodpovídali dvakrát.

## 4. Pravidla zpráv (§3.5, ověřit)

- Běžné okno pro odpověď je 24 hodin od poslední zprávy zákazníka; aplikace ho ověří znovu
  v okamžiku odeslání.
- Tag `HUMAN_AGENT` (delší okno) jen pro odpovědi člověka, nikdy pro automatické odpovědi.
- Výjimky pro reklamní vstupy aplikace nepředpokládá bez doloženého typu události.

## 5. Nastavení aplikace (instance)

| Proměnná (`config/secrets/app.env`) | Hodnota |
| --- | --- |
| `META_APP_ID` | ID aplikace z App Dashboardu |
| `META_APP_SECRET` | App Secret (podpis webhooků); bez něj endpoint odpovídá 500 |
| `META_WEBHOOK_VERIFY_TOKEN` | generuje `deploy/bootstrap-environment.sh`; stejná hodnota do App Dashboardu |
| `META_GRAPH_API_VERSION` | pinovaná verze, **ověřit** aktuální podporovanou (`v27.0` je příklad ze specifikace) |

Účty (Page, IG) se registrují ručně v administraci (Účty) podle číselného ID, které Meta
posílá ve webhooku jako `entry.id`. Tokeny se ukládají šifrovaně do databáze příkazem
`bin/console account:token` (`docs/meta-webhooks.md` oddíl 4); do `app.env` se nezapisují.

## 6. Checklist Milestone 0

Přístupy a účty:

- [ ] Kdo je správcem Facebook Page; Page je v Meta Business portfoliu (§13.2).
- [ ] Instagram je Business/Creator a je propojený s Page (§13.1).
- [ ] Ověření firmy v Business portfoliu (stav, kdo ho provádí).
- [ ] Vytvořená Meta aplikace typu Business; role admin/developer/tester pro testovací osoby.
- [x] Testovací Page a testovací IG účet (ne produkční Harmony Face Yoga); propojené, ruční čtení
  a skrytí komentářů ověřeno, Messenger webhook zatím neověřen.

Rozhodnutí:

- [x] Způsob přihlášení (oddíl 1).
- [ ] Seznam oprávnění, o která se bude žádat (oddíl 2), podle funkcí v1.

Technické ověření na testovacích účtech:

- [ ] Webhook ověřen (verify token) a přihlášen k odběru polí z oddílu 3.
- [ ] Testovací komentář na Page a na IG se objeví v administraci (Události) se správnou
  platformou a účtem.
- [ ] Testovací DM (Messenger, IG Direct) se objeví v administraci.
- [ ] Echo vlastní zprávy dorazí a je rozpoznatelné.
- [ ] Zjištěno, jak se chová úprava a smazání komentáře (pole a ID v payloadu).

App Review:

- [ ] Screenshoty a video průchodu aplikací pro každé oprávnění.
- [ ] Popis použití dat, zásady ochrany osobních údajů (§8.3) a URL pro smazání dat.
- [ ] Odesláno, schváleno; oprávnění ověřena na účtu bez role v aplikaci.

## 7. Co následuje po rozhodnutí (implementace)

1. ✓ Uložení tokenů (`account_credentials`, šifrovaně s ID klíče a rotací, sledování expirace
   a rozsahů, ruční výměna bez restartu) – 0.6.0.
2. Rozhraní Graph klienta s produkční a fake implementací, oddělené pro `graph.facebook.com`
   a `graph.instagram.com`, s pinovanou verzí.
3. `EventNormalizer` pro skutečné payloady (komentáře, zprávy, echo, úpravy, smazání) –
   teprve potom se přijaté události posílají klasifikátoru a do rozhodování.
4. Až poté (Milestone 3 a 4) `ActionDispatcher` a outbox pro zápisy na Metu; výchozí režim zůstává
   `observe`.

Produkční Page ani skutečné účty se během vývoje nepřipojují bez výslovného pokynu.
