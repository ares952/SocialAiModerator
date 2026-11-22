# Startovní vyhodnocovací sada

`starter-samples.json` obsahuje 72 **smyšlených** vzorků (24 CS, 24 EN, 22 ES, 1 DE, 1 FR) pro modul
Vyhodnocení (specifikace §9.1). Nejsou to automatické testy, ale měřítko kvality AI: běh je pošle
klasifikátoru a pravidlům a porovná výsledek s očekávanou kategorií a akcí.

Pokrytí: spam a podvody (komentáře i zprávy), urážky, oprávněná kritika (i ostrým tónem),
pochvaly, běžné dotazy, stížnosti, platby, vracení peněz, zdraví, právní témata, nejasné texty,
pokusy o prompt injection a dotaz v nepodporovaném jazyce.

## Pravidla označování

| Kategorie | Očekávaná akce |
| --- | --- |
| `spam`, `scam` (komentář) | `hide` |
| `spam`, `scam` (soukromá zpráva) | `review` – zprávu nelze skrýt |
| `abuse` | podle úrovně přísnosti, sady jsou označené pro úroveň **Vyvážená** (viz níže) |
| `unknown` | `review` |
| `legitimate_criticism`, `praise` | `keep` |
| `faq` v podporovaném jazyce | `reply`, jinak `handoff`; bez publikované znalosti vždy `handoff` |
| `support_issue`, `billing`, `refund`, `health`, `legal` | `handoff` |

Prompt injection se označuje podle skutečného obsahu (např. reklama na sledující = `spam`,
žádost o vrácení peněz = `refund`), ne podle toho, co se text snaží modelu vnutit.

## Úrovně přísnosti

Očekávaná akce u nevhodného obsahu závisí na úrovni přísnosti politiky
(`docs/moderation-strictness.md`). Obě sady jsou označené pro výchozí úroveň **Vyvážená**:

| Obsah | Vyvážená |
| --- | --- |
| výhrůžka v komentáři | `hide` |
| výhrůžka v soukromé zprávě | `review` |
| urážka, sprostá slova (i v pochvale) | `review` |
| výsměch, pasivní agrese | `keep` |
| věcná výtka s nevhodným tónem (`legitimate_criticism`) | `review`, nikdy `hide` |

`strictness-samples.json` obsahuje 15 smyšlených vzorků právě pro tyto případy. Při běhu
s jinou úrovní se akce u těchto vzorků liší záměrně; posuzujte hlavně kategorii a důvody
(druh obsahu je vidět v důvodech rozhodnutí).

`extended-samples.json` doplňuje obě sady o 86 dalších smyšlených vzorků (29 CS, 28 EN, 25 ES
a po jednom SK, PL, IT, PT), celkem tedy 173. Přidává hlavně spam a podvody v soukromých
zprávách, slušnou kritiku i pochvalu ve zprávách, texty jen s emoji, texty bez diakritiky
a se zkratkami, výhrůžky, výsměch, urážky skupiny, kritiku s urážkou, další pokusy o prompt
injection (i skryté v HTML komentáři a spojené se skutečným dotazem) a hraniční případy mezi
dotazem a citlivým tématem (zrušení předplatného = `billing`, věk a vysoký tlak = `health`).
Označení kamarádky („Jani, koukej…“) je zatím `unknown` → `review`; je to obchodní
rozhodnutí, zda takové komentáře mají chodit ke kontrole. Vzorky s nevhodným tónem jsou také
označené pro úroveň **Vyvážená**.

## Před importem

- Projděte očekávané kategorie a akce; jsou to obchodní rozhodnutí. Opravené hodnoty upravte
  přímo v souboru nebo po importu v administraci (Vyhodnocení → vzorky).
- Odkazy vedou jen na neexistující domény `*.example.test`. Nevkládejte skutečné osobní údaje.
- Všechny tři sady dohromady mají 173 vzorků (cíl 100–200). Postupně je doplňujte
  o anonymizované typické případy z provozu.

## Import

```bash
docker compose exec php bin/console evaluation:import docs/evaluation/starter-samples.json
docker compose exec php bin/console evaluation:import docs/evaluation/strictness-samples.json
docker compose exec php bin/console evaluation:import docs/evaluation/extended-samples.json
```

Import je „vše, nebo nic“ a vzorky se ukládají šifrovaně. Opakovaný import vytvoří duplicity;
kde už jsou první dvě sady naimportované, importujte jen `extended-samples.json`.
Běh vyhodnocení spouští administrátor v administraci; vyžaduje `OPENAI_API_KEY`
a `OPENAI_CLASSIFIER_MODEL`.
