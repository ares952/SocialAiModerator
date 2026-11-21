# Úrovně přísnosti moderace

Stav: **návrh k odsouhlasení**, zatím neimplementováno. Body v části „Rozhodnutí“ potřebují
potvrzení.

## Cíl

Administrátor volí jednu ze tří úrovní: **Benevolentní**, **Vyvážená**, **Přísná**. Úroveň
určuje, které druhy nevhodného obsahu se řeší, s jakými prahy a zda komentář skončí rovnou
skrytý („koš“), nebo jde ke kontrole člověku (`review`). Administrace u každé úrovně vždy
zobrazí kritéria, kategorie, prahy a co se děje nad a pod prahem.

„Koš“ = **skrytí** (`hide`), které lze vrátit (FR-C07). Úroveň přísnosti nikdy nemaže; mazání
zůstává jen ručním nebo explicitním pravidlem (FR-C08).

## Co se nemění

- Úroveň mění jen **rozhodnutí** `PolicyEngine`. Zda se skrytí opravdu provede, dál určuje
  režim (`observe` / `suggest` / `automatic`), přepínač `auto_hide`, panic switch a stav účtu.
  Přísná úroveň sama o sobě automatický režim nezapíná.
- Stížnosti, platby, vracení peněz, zdraví a právní témata jdou vždy člověku (`handoff`).
- Soukromou zprávu nelze skrýt; cokoli, co by se u komentáře skrylo, jde u zprávy na `review`.
- **Vždy zůstává** na všech úrovních: věcná kritika (i ostrým tónem) a nesouhlas.

## Klasifikace: druh nevhodného obsahu

Kategorie ze specifikace §5.2 zůstávají. Kategorie `abuse` dostane podrobnější druh. Prompt
`classifier-v2` a schéma `classification-v2` přidají tři pole:

| Pole | Hodnoty | Význam |
| --- | --- | --- |
| `abuse_type` | `none`, `threat`, `profanity`, `insult`, `ridicule`, `passive_aggression` | Nejzávažnější druh nevhodného obsahu v textu |
| `abuse_score` | 0–1 | Jak jednoznačně text tento druh obsahuje |
| `has_substance` | `true` / `false` | Text obsahuje věcnou výtku k produktu nebo službě |

| Druh | Kritérium | Ukázka (smyšlená) |
| --- | --- | --- |
| Spam a podvody | kategorie `spam`/`scam`, `spam_probability` | „Vydělej 5 000 denně z domu, napiš mi do zpráv.“ |
| Výhrůžky a násilí | `threat` | „Počkej, až tě potkám na ulici.“ |
| Sprostá slova | `profanity` | „To je ale zas*aná pí*ovina.“ |
| Urážky a osobní útoky | `insult` | „Ty jsi fakt ubožák, běž se léčit.“ |
| Výsměch | `ridicule` | „No jasně, náš velký expert zase promluvil 😂“ |
| Pasivní agrese | `passive_aggression` | „Hlavně že ty tomu rozumíš nejlíp, viď?“ |

**Smíšený text:** když `has_substance = true` (např. „Kurz je předražený, jste banda
zlodějů“), komentář se na žádné úrovni neskryje automaticky. Při zásahu prahem jde na
`review`, jinak zůstane. Tím se chrání oprávněná kritika s nevhodným tónem (FR-C03).

## Jak se rozhoduje

Každý druh má na každé úrovni dva prahy:

- **Práh skrytí** (S): `skóre ≥ S` → `hide`.
- **Práh kontroly** (K): `K ≤ skóre < S` → `review`.
- `skóre < K` → `keep`, komentář zůstane.
- `—` = práh je vypnutý. Bez S se druh nikdy neskryje; bez S i K se ponechá.

Skóre je `abuse_score`, u spamu a podvodů `spam_probability`. Navíc musí platit stávající
podmínka `confidence ≥ hide_min_confidence` (jistota kategorie); jinak `review`.

## Úrovně (počáteční hodnoty)

| Druh | 1 · Benevolentní | 2 · Vyvážená (výchozí) | 3 · Přísná |
| --- | --- | --- | --- |
| Spam a podvody | S 0,95 · K 0,50 | S 0,95 · K 0,50 | S 0,90 · K 0,50 |
| Výhrůžky a násilí | S 0,90 · K 0,50 | S 0,85 · K 0,50 | S 0,80 · K 0,50 |
| Sprostá slova | — · — | — · K 0,70 | S 0,85 · K 0,60 |
| Urážky a osobní útoky | — · K 0,90 | — · K 0,70 | S 0,85 · K 0,60 |
| Výsměch | — · — | — · — | S 0,95 · K 0,70 |
| Pasivní agrese | — · — | — · — | S 0,95 · K 0,80 |

Čtení po úrovních:

- **Benevolentní** skrývá jen spam, podvody a výhrůžky. Hrubé osobní útoky (skóre ≥ 0,90)
  jdou ke kontrole, ostatní zůstává.
- **Vyvážená** skrývá spam, podvody a výhrůžky. Sprostá slova a urážky jdou ke kontrole
  s návrhem skrýt; moderátor potvrdí jedním klikem. Výsměch a pasivní agrese zůstávají.
- **Přísná** skrývá i sprostá slova, urážky, výsměch a pasivní agresi; méně jednoznačné
  případy jdou ke kontrole.

Výhrůžka se po skrytí vždy objeví i ve frontě ke kontrole (`reasons`: `threat_hidden`),
protože může vyžadovat další kroky mimo aplikaci.

Hodnoty jsou počáteční návrh jako prahy v §5.4, ne kalibrované. Ladí se vyhodnocovací sadou.

## Uložení

- `PolicySettings` (verzovaná politika, `moderation_policies.settings_json`) dostane
  `strictness` (`benevolent` / `balanced` / `strict` / `custom`) a `abuse_rules`:
  `{"insult": {"hide_min": null, "review_min": 0.70}, …}`.
- Volba úrovně v administraci předvyplní prahy. Ruční úprava kteréhokoli prahu přepne úroveň
  na `custom`. Prahy se tak dál mění bez nasazení a každé rozhodnutí nese `policy_version`.
- Validace: prahy 0,50–1,00 a `review_min ≤ hide_min`.
- `PolicyEngine` při `requires_human` od modelu u `abuse`, `spam` a `unknown` zvolí `review`,
  ne `handoff`. `handoff` zůstává pro kategorie, které ho vyžadují (dnes tam končí urážky,
  protože model u nich často nastaví `requires_human`).
- Nejdřív globální politika; úroveň per účet až s politikami per účet.

## Administrace

Stránka politiky ukáže tři karty úrovní (název, krátký popis) a pod vybranou úrovní:

1. **Skrývá / Ke kontrole / Ponechává:** štítky druhů obsahu podle úrovně.
2. **Vždy zůstává:** věcná kritika, nesouhlas.
3. **Tabulka prahů:** pro každý druh S a K a věta „nad S se skryje, mezi K a S jde ke
   kontrole, pod K zůstane“.
4. **Ukázky:** smyšlené příklady z tabulky výše s výsledkem na zvolené úrovni.
5. Upozornění, zda se skrytí opravdu provede (režim, `auto_hide`, panic switch).

Všechny texty přes překladové klíče (`policy.strictness.*`) v `cs` i `en`.

## Vyhodnocení

- Vzorky dostanou `expected_abuse_type` (nová migrace, MINOR verze); běh vypíše přesnost
  druhu zvlášť od přesnosti kategorie.
- Očekávaná akce u `abuse` závisí na úrovni. Běh se proto spouští proti konkrétní verzi
  politiky a vzorky `abuse` se označí pro výchozí úroveň (Vyvážená).
- Doplnit vzorky: výsměch, pasivní agrese, sarkastická věcná kritika, kritika s urážkou,
  sprostá slova bez útoku na osobu („to je ku*va dobrý cvičení“), výhrůžky ve zprávě.

## Kalibrace

Model hlásí jistotu převážně 0,91–0,99, takže rozdíl mezi prahy 0,85 a 0,90 rozhoduje málo.
Úroveň proto působí hlavně tím, **které druhy** řeší a zda končí skrytím, nebo kontrolou.
Jemnější skóre umí moderační endpoint OpenAI (`category_scores` pro `harassment`,
`harassment/threatening`, `hate`, `violence`). Možné rozšíření: skrýt urážku jen tehdy, když
model i moderační endpoint překročí práh. Rozhodne se podle dat z vyhodnocení.

## Rozhodnutí (k potvrzení)

1. „Koš“ = vratné skrytí, nikdy smazání.
2. Automatické skrývání urážek, sprostých slov, výsměchu a pasivní agrese (Přísná) rozšiřuje
   §5.4 a FR-C04, kde se automaticky skrývá jen spam. Rozšíření je vědomé rozhodnutí firmy.
3. Výchozí úroveň je Vyvážená.
4. Výhrůžky se skrývají na všech úrovních a navíc jdou do fronty ke kontrole.
5. Smíšený text (věcná výtka + nevhodný tón) se automaticky neskryje nikdy.
