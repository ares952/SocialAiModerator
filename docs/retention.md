# Retence dat

Lhůty uchovávání nastavuje administrátor v administraci (**Retence**). Výchozí lhůta není
stanovena (specifikace §13, bod 8), proto se bez nastavení nic nemaže.

| Data | Co se maže |
| --- | --- |
| Komentáře | události `comment.*` včetně rozhodnutí AI a úloh workeru; komentáře beze změny po dobu lhůty a příspěvky, kterým nezůstal žádný komentář |
| Soukromé zprávy | `message.*` a `postback` včetně rozhodnutí AI a úloh workeru; zprávy starší než lhůta a konverzace, kterým nezůstala žádná zpráva |
| Ostatní události | reakce, potvrzení přečtení, nepodporované, ignorované a nenormalizované události |
| Audit | záznamy `admin_audit` |

- Lhůta se počítá od přijetí události (`received_at`), u komentáře od poslední promítnuté
  změny, u zprávy od jejího uložení, u auditu od vzniku záznamu. Přešifrování
  (`encryption:rotate`) lhůtu neprodlužuje.
- Worker lhůty uplatňuje jednou za hodinu (`worker.retentionSeconds` v `config/common.neon`)
  po dávkách po 500 řádcích; okamžitě `docker compose exec php bin/console retention:run`.
- Každá změna lhůt se zapisuje do auditu (`retention.update`).
- Smazání nelze vrátit; data zůstávají jen v zálohách v `data/backups/` (ty retence nemaže,
  jejich promazání je ruční provozní krok).
- Lhůty musí odpovídat zveřejněným zásadám ochrany osobních údajů.
