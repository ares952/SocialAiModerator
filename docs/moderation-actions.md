# Zásahy u komentářů a outbox

Skrývání a odkrývání komentářů (specifikace §2.1, §6, Milestone 3). Stav: zásahy, schvalování
a odesílání přes outbox jsou hotové, **zápis do Mety zatím zapojený není** (`UnavailableMetaClient`).
Konkrétní Graph endpointy se implementují až po potvrzení oprávnění (`docs/meta-checklist.md`).

## Tok

1. AI rozhodnutí `hide` u komentáře vytvoří zásah (`moderation_actions`):
   - pravidla dovolují automatické skrytí (režim `automatic`, zapnuté automatické skrytí, bez
     pauzy, aktivní účet) → zásah je rovnou `approved` a dostane záznam v `outbox`;
   - jinak čeká jako `pending` na moderátora.
2. Moderátor v detailu události (vedle obsahu komentáře) zásah schválí nebo zamítne, případně
   sám vyžádá skrytí nebo odkrytí komentáře. Vše se zapisuje do auditu
   (`action.approve`, `action.reject`, `action.request`).
3. Schválený zásah dostane v `outbox` idempotenční klíč (`hide:<id>`) a úlohu `action.send`.
4. `ActionDispatcher` je jediná část aplikace, která smí zapisovat do Mety. Těsně před odesláním
   znovu ověří přes `PolicyEngine::blocks()` aktuální stav; když něco neplatí, zásah skončí jako
   `blocked` s důvodem.

## Co zastaví odeslání

| Důvod | Kdy |
| --- | --- |
| `mode_observe` | pravidla jsou v režimu pozorování – neodejde nic, ani po schválení |
| `mode_assisted`, `auto_hide_disabled` | zásah bez člověka, ale pravidla automatiku nedovolují |
| `unhide_needs_human` | odkrytí vždy jen člověkem |
| `automation_paused` | panic switch (globální nebo účtu) – zásah **čeká** a ověří se znovu po `outbox.pauseRetrySeconds` |
| `account_inactive` | účet není aktivní |
| `comment_removed`, `already_hidden`, `already_visible` | komentář je smazaný nebo už v požadovaném stavu |
| `own_comment` | vlastní komentář účtu se neskrývá |
| `comment_changed` | text komentáře se změnil po vytvoření zásahu (jen u skrytí) |
| `token_unavailable` | účet nemá platný token |
| `meta_write_unavailable` | zápis do Mety ještě není zapojený |
| `rejected` | zamítnuto moderátorem |

## Výsledek odeslání

- `sent` – Meta potvrdila; komentář se v aplikaci označí jako skrytý/viditelný.
- `failed` – Meta odmítla; nic se nezměnilo. Chyba, která jistě nic nezměnila a může pominout
  (např. HTTP 429), se zopakuje frontou s backoffem; po posledním pokusu je `failed`.
- `unknown` – výsledek není jistý (timeout po odeslání, 5xx, přerušený worker). **Nikdy se
  automaticky neopakuje**; dořeší se rekonciliací, až bude čtecí Graph klient.

## Oprávnění

- `viewer` vidí přehled **Zásahy**.
- `moderator` a `admin` schvalují, zamítají a vyžadují zásahy v detailu události.
- V režimu pozorování lze zásahy jen zamítnout.

## Data

- Zásah patří ke komentáři a maže se s ním (retence komentářů). Neobsahuje text komentáře, jen
  jeho externí ID.
- `result_json` obsahuje jen HTTP status a kód chyby Mety, nikdy token ani obsah.
