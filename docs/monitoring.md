# Upozornění a přehled provozu

Podle specifikace §4.3 a §8.4. Upozornění a čísla se počítají při zobrazení přehledu (Přehled)
a v příkazu `monitor:check`; nic se kvůli nim neukládá a neobsahují obsah od zákazníků.

## Upozornění

| Kód | Kdy | Práh (Upozornění) |
| --- | --- | --- |
| `token_missing`, `token_invalid`, `token_expired` | aktivní účet nemá platný token | vždy |
| `token_expiring`, `data_access_expiring` | token nebo jeho přístup k datům brzy vyprší | dny předem |
| `webhook_silence` | z aktivního účtu, ze kterého už události chodily, nic nepřišlo | hodiny |
| `queue_lag`, `worker_silent`, `worker_missing` | úloha čeká ve frontě nebo se worker neozval (heartbeat) | minuty |
| `circuit_open` | volání API je po opakovaných chybách pozastavené | vždy |
| `failed_jobs`, `failed_actions` | selhané úlohy nebo zásahy za 24 hodin (např. 429/5xx) | počet |
| `unknown_actions` | zásah s neznámým výsledkem čeká na ověření | vždy |
| `hidden_comments` | navržená nebo provedená skrytí za 24 hodin | počet |
| `stale_approvals` | zásah dlouho čeká na schválení (v režimu pozorování se nehlásí) | hodiny |

Prahy nastavuje administrátor na stránce **Upozornění**; prázdná hodnota upozornění vypíná.
Výchozí hodnoty přidává migrace `basic-data` (7 dní, 48 h, 15 min, 3 selhání, 20 skrytí, 24 h)
a nejde o kalibrované hodnoty. Každá změna se zapisuje do auditu (`alarms.update`).

## Externí monitoring

Aplikace zatím sama nikomu nic neposílá (kanál notifikací není rozhodnutý). Externí monitoring
nebo cron může spouštět:

```sh
docker compose exec -T php bin/console monitor:check
```

Bez upozornění vypíše `OK: no alarms` a skončí s kódem 0, jinak vypíše řádky `ALARM: …`
a skončí s kódem 1.

## Přehled provozu

Přehled ukazuje čísla a grafy za zvolené období: posledních 24 hodin po hodinách, nebo 7 či 30
kalendářních dnů (včetně dneška) po dnech v časovém pásmu `timezone`. Lze je zúžit na platformu
nebo jeden účet; upozornění, stavy událostí a fronta úloh platí vždy pro celý provoz a selhané
úlohy fronty se s filtrem nepočítají. Data každého grafu jdou stáhnout jako CSV.

- **Dlaždice:** přijaté události, zásahy čekající na schválení (nejstarší), předání člověku,
  navržená skrytí, návrhy odpovědí a selhání (zásahy selhané nebo s neznámým výsledkem a selhané
  úlohy). U každé je průběh za období a změna proti předchozímu stejně dlouhému období; dlaždice
  vede na filtrovaný seznam.
- **Provoz v čase:** komentáře, zprávy zákazníků a ostatní události jako sloupce, rozhodnutí AI
  jako čára; pod grafem podíl předaných člověku a průměrný čas od příjmu do rozhodnutí.
- **Rozhodnutí AI** podle akce a podle kategorie (včetně selhané klasifikace), **výsledky zásahů**
  podle typu (čeká, schváleno, provedeno, zamítnuto moderátorem, zastaveno pravidly, selhalo),
  **čas do rozhodnutí** (průměr a nejdelší), **události podle platformy**.
- **Spotřeba tokenů** po modelech (klasifikace a návrhy odpovědí, bez moderation endpointu a bez
  návrhů, které neskončily použitelnou odpovědí); cenu je třeba spočítat podle aktuálního ceníku
  OpenAI.

Přehled obsahuje jen souhrnná čísla, nikdy texty zákazníků. Technické podrobnosti grafů jsou
v `docs/admin-ui.md`.
