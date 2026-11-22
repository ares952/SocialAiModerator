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

Přehled ukazuje za 24 hodin a 7 dní přijaté události, rozhodnutí AI s průměrným časem od příjmu
do rozhodnutí, předání člověku, navržená a provedená skrytí, návrhy a odeslané odpovědi,
zamítnuté a pravidly zastavené zásahy a selhání. Spotřeba tokenů je po modelech za 7 dní
(klasifikace a návrhy odpovědí, bez moderation endpointu a bez návrhů, které neskončily
použitelnou odpovědí); cenu je třeba spočítat podle aktuálního ceníku OpenAI.
