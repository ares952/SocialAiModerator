# Úrovně přísnosti moderace

Stav: **implementováno** (prompt `classifier-v2`, schéma `classification-v2`). Rozhodnutí
odsouhlasil provozovatel 2026-11-21 (viz „Rozhodnutí“).

## Cíl

Administrátor volí úroveň **Benevolentní**, **Vyvážená** (výchozí), **Přísná** nebo
**Vlastní (danger zone)**. Úroveň určuje, které druhy nevhodného obsahu se řeší, s jakými prahy
a zda komentář skončí skrytý („koš“), nebo jde ke kontrole člověku (`review`). Administrace
(Pravidla moderace → Úroveň přísnosti) u každé úrovně zobrazí kritéria, druhy obsahu, ukázky,
prahy a co se děje nad a pod prahem.

„Koš“ = **vratné skrytí** (`hide`, FR-C07). Úroveň přísnosti nikdy nemaže; mazání zůstává jen
ručním nebo explicitním pravidlem (FR-C08).

## Odchylka od specifikace

Specifikace §5.4 a FR-C04 počítají s automatickým skrytím jen u jednoznačného spamu a podvodů;
nevhodný obsah má jít na `review`. Úrovně přísnosti to **vědomě rozšiřují** (rozhodnutí
provozovatele 2026-11-21): výhrůžky se skrývají na všech úrovních, Přísná skrývá i sprostá
slova, urážky, výsměch a pasivní agresi. Ochrany zůstávají: skrytí je vratné, věcná kritika se
na předvolených úrovních neskryje nikdy a o tom, zda se skrytí opravdu provede, dál rozhoduje
režim politiky.

## Co se nemění

- Úroveň mění jen **rozhodnutí** `PolicyEngine`. Zda se skrytí opravdu provede, dál určuje
  režim (`observe` / `assisted` / `automatic`), přepínač automatického skrytí, panic switch
  a stav účtu. Přísná úroveň sama o sobě automatický režim nezapíná.
- Stížnosti, platby, vracení peněz, zdraví a právní témata jdou vždy člověku (`handoff`),
  i když obsahují výhrůžku nebo urážku.
- Soukromou zprávu nelze skrýt; co by se u komentáře skrylo, jde u zprávy na `review`.
- **Vždy zůstává** na předvolených úrovních: věcná kritika (i ostrým tónem) a nesouhlas.

## Klasifikace: druh nevhodného obsahu

Kategorie ze specifikace §5.2 zůstávají. Klasifikace navíc vrací:

| Pole | Hodnoty | Význam |
| --- | --- | --- |
| `abuse_type` | `none`, `threat`, `profanity`, `insult`, `ridicule`, `passive_aggression` | Nejzávažnější druh nevhodného obsahu v textu |
| `abuse_score` | 0–1 | Jak jednoznačně text tento druh obsahuje |
| `has_substance` | `true` / `false` | Text obsahuje věcnou výtku, dotaz nebo názor na produkt či službu |

| Druh | Kritérium | Ukázka (smyšlená) |
| --- | --- | --- |
| Spam a podvody | kategorie `spam`/`scam`, skóre = `spam_probability` | „Vydělej 5 000 denně z domu, napiš mi do zpráv.“ |
| Výhrůžky a násilí | `threat` | „Počkej, až tě potkám na ulici.“ |
| Sprostá slova | `profanity` | „To je ale zas*aná pí*ovina.“ |
| Urážky a osobní útoky | `insult` | „Ty jsi fakt ubožák, běž se léčit.“ |
| Výsměch | `ridicule` | „No jasně, náš velký expert zase promluvil 😂“ |
| Pasivní agrese | `passive_aggression` | „Hlavně že ty tomu rozumíš nejlíp, viď?“ |
| Věcná výtka s nevhodným tónem | `has_substance`, nebo kategorie `legitimate_criticism` či `faq` | „Kurz je předražený, jste banda zlodějů.“ |

Pravidla tónu se uplatní u kategorií `abuse`, `praise`, `legitimate_criticism` a `faq`. Spam,
podvody a `unknown` mají vlastní zpracování; citlivá témata jdou vždy člověku.

## Jak se rozhoduje

Každý druh má dva prahy:

- **Práh skrytí** (S): `skóre ≥ S` → `hide`.
- **Práh kontroly** (K): `K ≤ skóre < S` → `review`.
- `skóre < K` → původní akce kategorie (urážka bez kategorie zůstane, dotaz dostane odpověď
  podle pravidel odpovědí).
- `—` = práh je vypnutý. Bez S se druh nikdy neskryje; bez S i K se ponechá.

Spam a podvody mají jen S; pod ním jdou vždy ke kontrole.

Skrytí navíc vyžaduje:

- jistotu kategorie `confidence ≥` „Skrytí: minimální jistota kategorie“, jinak `review`;
- doporučení modelu `hide`; jinak `review` (model může rozhodnutí jen zpřísnit);
- u věcné výtky s nevhodným tónem práh pro smíšený text, který jde nastavit **jen na úrovni
  Vlastní**. Na předvolených úrovních jde takový text místo skrytí nejvýše na `review`.

Skrytá výhrůžka dostane v důvodech `threat_hidden`, aby ji zkontroloval člověk.

Když model u nevhodného obsahu, spamu nebo nejasného textu nastaví `requires_human`, výsledek
je `review` (moderátor), ne `handoff` (odpovědná osoba firmy).

## Úrovně (počáteční hodnoty)

| Druh | 1 · Benevolentní | 2 · Vyvážená (výchozí) | 3 · Přísná |
| --- | --- | --- | --- |
| Spam a podvody | S 0,95 | S 0,95 | S 0,90 |
| Výhrůžky a násilí | S 0,90 · K 0,50 | S 0,85 · K 0,50 | S 0,80 · K 0,50 |
| Sprostá slova | — · — | — · K 0,70 | S 0,85 · K 0,60 |
| Urážky a osobní útoky | — · K 0,90 | — · K 0,70 | S 0,85 · K 0,60 |
| Výsměch | — · — | — · — | S 0,95 · K 0,70 |
| Pasivní agrese | — · — | — · — | S 0,95 · K 0,80 |
| Věcná výtka s nevhodným tónem | nikdy neskrýt | nikdy neskrýt | nikdy neskrýt |

Čtení po úrovních:

- **Benevolentní** skrývá jen spam, podvody a výhrůžky. Hrubé osobní útoky (skóre ≥ 0,90)
  jdou ke kontrole, ostatní zůstává.
- **Vyvážená** skrývá spam, podvody a výhrůžky. Sprostá slova a urážky jdou ke kontrole;
  moderátor rozhodne. Výsměch a pasivní agrese zůstávají.
- **Přísná** skrývá i sprostá slova, urážky, výsměch a pasivní agresi; méně jednoznačné
  případy jdou ke kontrole.
- **Vlastní (danger zone)** má volné prahy pro každý druh i pro věcnou výtku s nevhodným
  tónem. Jen tato úroveň může automaticky skrýt kritiku s nevhodným tónem.

Hodnoty jsou počáteční návrh jako prahy v §5.4, ne kalibrované. Ladí se vyhodnocovací sadou
(`docs/evaluation/strictness-samples.json`).

## Uložení

- `PolicySettings` (verzovaná politika, `moderation_policies.settings_json`):
  `strictness` (`benevolent` / `balanced` / `strict` / `custom`), `abuse_rules`
  (`{"insult": {"hide_min": null, "review_min": 0.70}, …}`), `mixed_hide_min`
  a `hide_min_spam_probability`.
- Volba předvolené úrovně zkopíruje její prahy do nové verze politiky. Uložená verze nese
  hodnoty samotné, takže pozdější změna předvolby v kódu nezmění pravidla existující verze;
  každé rozhodnutí je dohledatelné přes `policy_version`.
- Validace: prahy 0,50–1,00, `review_min ≤ hide_min`, pravidla pro všech pět druhů,
  `mixed_hide_min` jen u úrovně `custom`.
- Starší verze politiky bez těchto klíčů se čtou jako Vyvážená.
- Zatím jen globální politika; úroveň per účet až s politikami per účet.

## Vyhodnocení

Druh nevhodného obsahu je vidět v důvodech rozhodnutí (`abuse_insult`, `abuse_review`,
`mixed_content`, …) v běhu vyhodnocení i v detailu události. Vzorky v obou sadách jsou
označené pro úroveň Vyvážená; při jiné úrovni se akce u nevhodného obsahu liší záměrně.

## Kalibrace

Model hlásí jistotu převážně 0,91–0,99, takže rozdíl mezi prahy 0,85 a 0,90 rozhoduje málo.
Úroveň proto působí hlavně tím, **které druhy** řeší a zda končí skrytím, nebo kontrolou.
Jemnější skóre umí moderační endpoint OpenAI (`category_scores` pro `harassment`,
`harassment/threatening`, `hate`, `violence`). Možné rozšíření: skrýt urážku jen tehdy, když
model i moderační endpoint překročí práh. Rozhodne se podle dat z vyhodnocení.

## Rozhodnutí (2026-11-21)

1. „Koš“ = vratné skrytí, nikdy smazání.
2. Automatické skrývání nevhodného obsahu nad rámec spamu je vědomé rozšíření §5.4 a FR-C04.
3. Výchozí úroveň je Vyvážená; k dispozici je i Vlastní (danger zone).
4. Výhrůžky se skrývají na všech předvolených úrovních a navíc se označí ke kontrole.
5. Věcná výtka s nevhodným tónem se na předvolených úrovních automaticky neskryje nikdy;
   na úrovni Vlastní lze nastavit práh, od kterého se skryje, jinak jde ke kontrole.
