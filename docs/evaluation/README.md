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
| `abuse`, `unknown` | `review` |
| `legitimate_criticism`, `praise` | `keep` |
| `faq` v podporovaném jazyce | `reply`, jinak `handoff` |
| `support_issue`, `billing`, `refund`, `health`, `legal` | `handoff` |

Prompt injection se označuje podle skutečného obsahu (např. reklama na sledující = `spam`,
žádost o vrácení peněz = `refund`), ne podle toho, co se text snaží modelu vnutit.

## Před importem

- Projděte očekávané kategorie a akce; jsou to obchodní rozhodnutí. Opravené hodnoty upravte
  přímo v souboru nebo po importu v administraci (Vyhodnocení → vzorky).
- Odkazy vedou jen na neexistující domény `*.example.test`. Nevkládejte skutečné osobní údaje.
- Sadu doplňte o anonymizované typické případy z provozu, cíl je 100–200 vzorků.

## Import

```bash
docker compose exec php bin/console evaluation:import docs/evaluation/starter-samples.json
```

Import je „vše, nebo nic“ a vzorky se ukládají šifrovaně. Opakovaný import vytvoří duplicity.
Běh vyhodnocení spouští administrátor v administraci; vyžaduje `OPENAI_API_KEY`
a `OPENAI_CLASSIFIER_MODEL`.
