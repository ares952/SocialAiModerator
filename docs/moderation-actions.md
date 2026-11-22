# Zásahy, odpovědi a outbox

Skrývání, odkrývání a odpovědi na komentáře a soukromé zprávy (specifikace §2.1, §3.5, §5.3, §6,
Milestone 3 a 4). Stav: zásahy, návrhy odpovědí, jednotný inbox, schvalování a odesílání přes
outbox jsou hotové, **zápis do Mety zatím zapojený není** (`UnavailableMetaClient`).
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
2. `ReplyGenerator` (model `OPENAI_REPLY_MODEL`, prompt `app/Ai/prompts/reply-v2.md`) dostane
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

## Soukromé zprávy (jednotný inbox)

Stránka **Zprávy** ukazuje konverzace Messengeru a Instagram Direct: účet, zákazníka (jen jeho
ID), poslední zprávu, okno pro odpověď, kdo konverzaci řeší a počet odpovědí ke schválení.
Obsah zpráv vidí jen moderátor a administrátor a každé zobrazení se zapisuje do auditu
(`conversation.content.view`).

- AI rozhodnutí `reply` u zprávy zákazníka vytvoří návrh odpovědi stejně jako u komentáře;
  `ReplyGenerator` navíc dostane posledních několik zpráv konverzace jako kontext
  (`Conversations::HistoryLimit`, s nahrazenými kontakty).
- **Okno pro odpověď** je 24 hodin od poslední zprávy zákazníka s časem ověřeným Metou. Ověřuje se
  při přípravě návrhu i **v okamžiku odeslání**; po vypršení zásah skončí `window_expired`.
  Tag `HUMAN_AGENT` aplikace nepoužívá vůbec.
- **Převzetí člověkem:** moderátor konverzaci převezme a později ji výslovně uvolní (audit
  `conversation.takeover`, `conversation.release`). Během převzetí AI nepřipravuje návrhy
  a dříve připravené AI odpovědi se neodešlou (`human_takeover`); vlastní odpověď operátora
  odejít může.
- **Vlastní odpověď operátora** se píše v detailu konverzace, platí pro ni stejné kontroly
  (okno, panic switch, režim) a v režimu pozorování ji nelze odeslat.
- **Jedna odpověď na zprávu:** když po zprávě zákazníka přišlo echo odpovědi účtu (např. odpověď
  z Meta Business Suite) nebo už odešla jiná odpověď, další se neodešle (`already_replied`).
  Echo vlastních zpráv nikdy nespouští návrh odpovědi.

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
| `window_expired` | u soukromé zprávy vypršelo okno pro odpověď |
| `human_takeover` | konverzaci převzal člověk (jen odpovědi napsané AI) |
| `message_deleted` | zákazník zprávu zrušil |
| `rejected` | zamítnuto moderátorem |

## Výsledek odeslání

- `sent` – Meta potvrdila; komentář se v aplikaci označí jako skrytý/viditelný.
- `failed` – Meta odmítla; nic se nezměnilo. Chyba, která jistě nic nezměnila a může pominout
  (např. HTTP 429), se zopakuje frontou s backoffem; po posledním pokusu je `failed`.
- `unknown` – výsledek není jistý (timeout po odeslání, 5xx, přerušený worker). **Nikdy se
  automaticky neopakuje**; dořeší se rekonciliací, až bude čtecí Graph klient.

## Oprávnění

- `viewer` vidí přehledy **Zásahy** a **Zprávy** bez obsahu.
- `moderator` a `admin` schvalují, zamítají a vyžadují zásahy v detailu události nebo
  konverzace, čtou zprávy, píší vlastní odpovědi a konverzace přebírají a uvolňují.
- V režimu pozorování lze zásahy jen zamítnout.

## Data

- Zásah patří ke komentáři nebo ke zprávě a maže se s nimi (retence komentářů a zpráv). Neobsahuje text komentáře, jen
  jeho externí ID. Text odpovědi je šifrovaný (`moderation_actions.text`, zahrnuto
  v `encryption:rotate`) a vidí ho jen moderátor a administrátor.
- `result_json` obsahuje jen HTTP status a kód chyby Mety, případně ID odeslané odpovědi, nikdy
  token ani obsah.
