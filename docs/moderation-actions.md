# Zásahy u komentářů a outbox

Skrývání, odkrývání a odpovědi na komentáře (specifikace §2.1, §5.3, §6, Milestone 3). Stav:
zásahy, návrhy odpovědí, schvalování a odesílání přes outbox jsou hotové, **zápis do Mety zatím
zapojený není** (`UnavailableMetaClient`).
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

## Odpovědi na komentáře

1. AI rozhodnutí `reply` u komentáře (dotaz v podporovaném jazyce s publikovanou znalostí nad
   prahem) vytvoří zásah `reply` ve stavu `pending` a úlohu `reply.draft`.
2. `ReplyGenerator` (model `OPENAI_REPLY_MODEL`, prompt `app/Ai/prompts/reply-v1.md`) dostane
   jen aktuální publikované znalosti, které klasifikace označila, v jazyce komentáře. Komentář
   jde do modelu jako data, s nahrazenými kontakty.
3. Návrh se kontroluje: každé číslo, odkaz a e-mail v odpovědi musí být v použitých znalostech
   (vymyšlená cena, sleva ani kontakt neprojdou). Text se ukládá šifrovaně spolu s použitými
   zdroji (klíč a verze), modelem a verzí promptu.
4. Moderátor v detailu události návrh zkontroluje, případně upraví a schválí, nebo zamítne.
   Úprava se pozná podle štítku „upraveno moderátorem“ a v auditu (`action.approve`, `edited`).
5. Odpověď se **nikdy neodesílá bez člověka**; na jeden komentář jde nejvýš jedna odpověď.

Když znalost chybí, model odpovědět neumí, návrh neprojde kontrolou nebo volání selže, zásah
skončí jako `blocked` s důvodem (`knowledge_missing`, `reply_not_answerable`, `reply_invalid`,
`reply_unavailable`) a komentář zůstává člověku.

Označení automatické AI komunikace v odpovědích (specifikace §13.9) zatím není rozhodnuté,
odpovědi ho proto neobsahují.

## Co zastaví odeslání

| Důvod | Kdy |
| --- | --- |
| `mode_observe` | pravidla jsou v režimu pozorování – neodejde nic, ani po schválení |
| `mode_assisted`, `auto_hide_disabled` | zásah bez člověka, ale pravidla automatiku nedovolují |
| `unhide_needs_human`, `reply_needs_human` | odkrytí a odpověď vždy jen člověkem |
| `automation_paused` | panic switch (globální nebo účtu) – zásah **čeká** a ověří se znovu po `outbox.pauseRetrySeconds` |
| `account_inactive` | účet není aktivní |
| `comment_removed`, `already_hidden`, `already_visible` | komentář je smazaný nebo už v požadovaném stavu |
| `own_comment` | vlastní komentář účtu se neskrývá ani se na něj neodpovídá |
| `comment_changed` | text komentáře se změnil po vytvoření zásahu (skrytí, odpověď) |
| `comment_hidden` | na skrytý komentář se neodpovídá |
| `already_replied` | komentář už odpověď má (nebo možná má, stav `unknown`) |
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
  jeho externí ID. Text odpovědi je šifrovaný (`moderation_actions.text`, zahrnuto
  v `encryption:rotate`) a vidí ho jen moderátor a administrátor.
- `result_json` obsahuje jen HTTP status a kód chyby Mety, případně ID odeslané odpovědi, nikdy
  token ani obsah.
