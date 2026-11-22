# Ochrana osobních údajů: žádosti zákazníků

Podle specifikace §8.3. Stránka **Ochrana údajů** (jen administrátor) vyřizuje žádosti zákazníků
o přístup k údajům a o výmaz. Týká se jen dat uložených v této aplikaci; komentáře a zprávy
u Mety (Facebook, Instagram) zůstávají a řeší se tam.

## Postup

1. Najít ID zákazníka s prefixem platformy: autor na detailu události (`fb:…`, `ig:…`) nebo
   zákazník na detailu konverzace. Facebook používá pro komentáře a pro Messenger různá ID –
   každé se vyhledá zvlášť.
2. **Vyhledat** – počty uložených komentářů, konverzací, zpráv a přijatých událostí.
3. **Stáhnout export (JSON)** – komentáře a zprávy (rozšifrované texty), rozhodnutí AI
   (kategorie, akce). Soubor obsahuje osobní údaje: předat zákazníkovi bezpečně a nenechávat ho
   ležet.
4. **Smazat data zákazníka** – po opětovném zadání ID smaže komentáře, konverzace se zprávami,
   přijaté události, rozhodnutí AI, zásahy, jejich outbox a čekající úlohy. Nevratné.

## Co platí

- ID zákazníka se posílá jen v těle formuláře (POST), nikdy v adrese, takže nekončí v access logu.
- Audit (`privacy.export`, `privacy.delete`) ukládá jen otisk ID (`sha256:` + 16 znaků) a počty.
- Zálohy DB (`bin/backup`) obsahují smazaná data, dokud nevyprší jejich rotace (výchozí 14 dní);
  při obnově ze zálohy je nutné výmaz zopakovat.
- Vzorky pro vyhodnocení AI (Vyhodnocení) jsou syntetické; pokud do nich někdo zkopíroval text
  zákazníka, je potřeba je projít ručně.
- Retenční lhůty pro všechna data nastavuje stránka Retence (`docs/retention.md`); výmaz na žádost
  je doplněk, ne náhrada.
