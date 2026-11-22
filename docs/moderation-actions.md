# Zásahy, odpovědi a outbox

Skrývání, odkrývání a odpovědi na komentáře a soukromé zprávy (specifikace §2.1, §3.5, §5.3, §6,
Milestone 3 a 4). Stav: zásahy, návrhy odpovědí, jednotný inbox, schvalování a odesílání přes
outbox jsou hotové a zápis do Mety je zapojený (`HttpMetaClient`, endpointy v
`docs/meta-checklist.md` oddíl 8). V režimu pozorování se nezapisuje nic; v ostatních režimech
odchází jen to, co projde kontrolou `PolicyEngine` v okamžiku odeslání.

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
5. Bez člověka odejde odpověď jen jako **automatická odpověď** (oddíl níže); na jeden komentář
   jde nejvýš jedna odpověď.

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
- **Automatické převzetí:** když účet zákazníkovi odpoví mimo aplikaci (Meta Business Suite,
  aplikace Instagram), konverzace se převezme sama, bez přiřazeného operátora („převzato –
  odpověď mimo aplikaci“, audit `conversation.takeover` bez operátora). AI pak neodpovídá,
  dokud konverzaci někdo výslovně neuvolní. Vlastní zprávy aplikace se poznají podle `app_id`
  v echu (Messenger, `META_APP_ID`), podle idempotenčního klíče, který aplikace posílá jako
  `metadata` zprávy, nebo podle ID odeslané zprávy. Když se echo nepozná, konverzace se raději
  převezme – AI se tím jen zastaví. Odpověď starší než poslední uvolnění konverzaci znovu
  nepřevezme. **Ověřit** s reálným Graph klientem, že echo Instagramu nese `metadata`.
- **Vlastní odpověď operátora** se píše v detailu konverzace, platí pro ni stejné kontroly
  (okno, panic switch, režim) a v režimu pozorování ji nelze odeslat.
- **Jedna odpověď na zprávu:** když po zprávě zákazníka přišlo echo odpovědi účtu (např. odpověď
  z Meta Business Suite) nebo už odešla jiná odpověď, další se neodešle (`already_replied`).
  Echo vlastních zpráv nikdy nespouští návrh odpovědi.

## Automatické odpovědi

Odpověď na komentář nebo zprávu odejde bez schválení člověkem, jen když platí všechno:

- pravidla jsou v režimu `automatic`, automatika není pozastavená a účet je aktivní;
- kategorie je v pravidlech povolená pro automatické odpovědi (citlivá témata povolit nejde);
- návrh prošel kontrolou faktů vůči publikovaným znalostem;
- nejsou vyčerpané limity automatických odpovědí za 24 hodin: do jedné konverzace
  (`autoRepliesPerConversation`, výchozí 2, odpovídá `MAX_AUTO_REPLIES_PER_CONVERSATION_PER_DAY`
  ze specifikace §11) a celkem za účet (`autoRepliesPerDay`, výchozí 20). Limity jsou součástí
  verzovaných pravidel a mění se v administraci bez nasazení;
- platí všechny ostatní kontroly (okno, převzetí, echo, stav komentáře, jedna odpověď).

Když automatickou odpověď zastaví jen pravidla pro jednání bez člověka (jiný režim, kategorie,
limit), nezahodí se: vrátí se do fronty ke schválení s důvodem a moderátor ji může odeslat.
Ostatní důvody (např. převzetí, vypršelé okno) ji zastaví úplně.

## Fronta ke kontrole

Stránka **Ke kontrole** (specifikace §4.3) ukazuje komentáře a zprávy, které čekají na člověka,
od nejstarších:

- AI předala událost člověku (`review`, `handoff`) – sem patří i selhaná klasifikace;
- událost zatím nemá rozhodnutí AI (fail closed);
- zásah k události čeká na schválení.

Fronta ukazuje jen metadata a souhrn AI (akce, kategorie, jistota, důvody). Obsah, zásahy
a vyřízení jsou na detailu události, kde se zobrazení obsahu audituje. Na detailu:

- **Vyřízeno, další** – událost opustí frontu a otevře se další (audit `event.resolve`);
  **Vrátit do fronty** ji vrátí (`event.reopen`);
- **Předat** – přiřazení aktivnímu moderátorovi nebo administrátorovi (`event.assign`); filtr
  fronty „Předané mně“;
- **Další ve frontě** – nejbližší další otevřená událost.

Událost, u které AI navrhla zásah (např. odpověď) a moderátor ho schválí nebo zamítne, opustí
frontu sama. Vyřizovat a předávat smí moderátor a administrátor, číst frontu i viewer.

## Co zastaví odeslání

| Důvod | Kdy |
| --- | --- |
| `mode_observe` | pravidla jsou v režimu pozorování – neodejde nic, ani po schválení |
| `mode_assisted`, `auto_hide_disabled`, `auto_reply_not_allowed` | zásah bez člověka, ale pravidla automatiku nedovolují (vrátí se ke schválení) |
| `auto_reply_limit_conversation`, `auto_reply_limit_day` | vyčerpaný limit automatických odpovědí (vrátí se ke schválení) |
| `unhide_needs_human` | odkrytí vždy jen člověkem |
| `automation_paused` | panic switch (globální nebo účtu) – zásah **čeká** a ověří se znovu po `outbox.pauseRetrySeconds` |
| `account_inactive` | účet není aktivní |
| `comment_removed`, `already_hidden`, `already_visible` | komentář je smazaný nebo už v požadovaném stavu |
| `own_comment` | vlastní komentář účtu se neskrývá ani se na něj neodpovídá |
| `comment_changed` | text komentáře se změnil po vytvoření zásahu (skrytí, odpověď) |
| `comment_hidden` | na skrytý komentář se neodpovídá |
| `already_replied` | komentář už odpověď má (nebo možná má, stav `unknown`) |
| `token_unavailable` | účet nemá platný token |
| `meta_write_unavailable` | jen starší záznamy (do 0.17.1): zápis do Mety tehdy nebyl zapojený |
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
