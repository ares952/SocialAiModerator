# Uživatelské rozhraní administrace

Administrace používá **CoreUI for Bootstrap 5** (free edice, MIT) a na přehledu grafy
**Chart.js 4** (MIT). Obě knihovny jsou lokální kopie v `www/assets/vendor/<balíček>-<verze>/`,
bez CDN a bez build kroku. Verze v cestě slouží i k obnovení cache prohlížeče.

## Knihovny

| Adresář | Balíček (npm) | Licence | Soubory a SHA-256 |
| --- | --- | --- | --- |
| `coreui-5.9.1/` | `@coreui/coreui` 5.9.1 | MIT | `css/coreui.min.css` `554fa0be…68bb`, `js/coreui.bundle.min.js` `910f33f2…242e` (včetně Popperu) |
| `chartjs-4.6.1/` | `chart.js` 4.6.1 | MIT | `chart.umd.min.js` `87364cc2…25d2` |

Ikony jsou vybrané symboly z **Bootstrap Icons 1.13.2** (MIT) ve spritu
`www/assets/admin/icons.svg`; id symbolu je role v aplikaci (`dashboard`, `events`, …), název
původní ikony je v komentáři.

Vědomě nepoužité:

- `@coreui/chartjs` – jen vlastní HTML tooltip, který se připojuje k prvnímu `div` vedle grafu;
  nativní tooltip Chart.js stačí.
- `@coreui/icons` – SVG ikony mají licenci CC BY 4.0 s povinným uvedením autora; Bootstrap Icons
  (MIT) aplikace používala už dřív.
- PRO komponenty CoreUI (vyžadují komerční licenci).

### Aktualizace knihovny

1. Stáhnout tarball z npm registry (`https://registry.npmjs.org/<balíček>/-/<název>-<verze>.tgz`)
   do `temp/tests/` a ověřit `dist.integrity` (sha512) z metadat registry.
2. Zkopírovat minifikované soubory s `.map` a licencí do nového adresáře `<balíček>-<nová verze>/`,
   starý adresář smazat.
3. Upravit cesty v `app/Presentation/@layout.latte`, případně v
   `app/Presentation/Admin/Dashboard/default.latte` a v `tests/E2e/`.
4. Doplnit tabulku výše, projít obrazovky ve světlém i tmavém režimu a spustit `bin/e2e`.

## Rozvržení a konvence

- Layout: pevný tmavý sidebar (skupiny **Provoz**, **Nastavení**, **Správa**; položky podle rolí)
  a horní lišta s jazykem, motivem a operátorem. Na užších obrazovkách se sidebar otevírá
  tlačítkem v liště (`data-sidebar-toggle` v `admin.js`).
- Barevný režim nastavuje `theme.js` atributem `data-coreui-theme` na `<html>`; po změně vyšle
  událost `socialai:theme`.
- Atributy komponent jsou `data-coreui-*` (např. `data-coreui-toggle="dropdown"`), CSS proměnné
  `--cui-*`. Vlastní styly jsou jen v `www/assets/admin/admin.css`.
- `|number` v šablonách formátuje podle jazyka rozhraní (Latte locale = jazyk překladače).

## Grafy

| Stránka | Grafy | Filtry |
| --- | --- | --- |
| Přehled | dlaždice s průběhem, provoz v čase, rozhodnutí AI podle akce a kategorie, výsledky zásahů, čas do rozhodnutí, tokeny, platformy | období (24 h / 7 / 30 dní), platforma, účet |
| Zásahy | výsledky za 30 dní po dnech, důvody zastavení a vrácení člověku | stav, typ, účet (typ a účet platí i pro grafy) |
| Události | – | stav, typ, účet |
| Účty | přijaté události podle účtu za 30 dní | – |
| Vyhodnocení | přesnost posledních 20 běhů (kategorie, akce v %); u běhu správně a chybně zařazené vzorky podle očekávané kategorie | – |

- Data počítá `App\Monitoring` (`Timeline`, `MetricSeries`, `Metrics`, `Outcome`) pro část provozu
  danou `Scope` (vše, platforma, účet). Rozhodnutí patří k účtu své události, zásahy k účtu svého
  komentáře nebo konverzace; úlohy fronty k žádnému účtu nepatří a počítají se jen bez filtru.
- Definice grafů sestaví `App\Presentation\Admin\Charts` včetně přeložených popisků (dashboard
  přes `Dashboard\DashboardData`). Šablona je předá jako JSON v atributu `data-chart` na
  `<canvas>` (`{include chart, $definice, export: název}` z `Admin/@chart.latte`, skripty
  `{block scripts}{include chartScripts}{/block}`); `www/assets/admin/charts.js` graf jen vykreslí.
  Žádný inline skript ani JSON API.
- Databáze seskupuje po hodinách v UTC, PHP je skládá do hodin (24 h) nebo místních dnů
  (7 a 30 dní, i přes změnu času). `CONVERT_TZ` se nepoužívá.
- Barva datové řady je klíč `--sa-chart-<klíč>` z `admin.css` (sémantické barvy CoreUI, značkové
  barvy Facebooku a Instagramu); `current` je barva textu dlaždice. Barvy akcí a výsledků jsou
  v `Charts::ActionColors` a `Charts::OutcomeColors`. Barvy se čtou při vykreslení, takže grafy
  sledují světlý i tmavý režim.
- Přístupnost: `<canvas role="img">` s textovým souhrnem v `aria-label` a pod každým grafem
  (kromě průběhů v dlaždicích) tabulka hodnot „Zobrazit data“; při `prefers-reduced-motion` bez
  animací.

### Export CSV

- Pod tabulkou grafu je „Stáhnout CSV“ (signál `export` z traitu `ChartExport`). Export obsahuje
  přesně data grafu pro aktuální parametry stránky (období, filtry) a stejná oprávnění jako stránka.
- Formát podle jazyka rozhraní: čeština `;` a desetinná čárka, angličtina `,` a tečka; UTF-8 s BOM
  (Excel), hodnoty s mezerou v uvozovkách, buňka začínající `=`, `+`, `-`, `@` (kromě čísel)
  dostane apostrof, aby ji tabulkový procesor nespustil jako vzorec.

### Filtry

- Filtry jsou GET parametry; neplatná hodnota vrací 404. Prázdná hodnota z formuláře („vše“)
  přesměruje na čistou URL (`ListFilters::cleanFilters`), filtr účtu ověřuje
  `ListFilters::accountFilter`. Výběr účtu v seznamech je `Admin/@accountFilter.latte`.
- Dlaždice dashboardu s filtrem účtu vedou na seznamy se stejným filtrem.

### Nový graf

Přidat data do `Metrics`/`MetricSeries` (s `Scope`, pokud jde o provoz), definici přes `Charts`
do `exportCharts()` presenteru s `ChartExport`, kartu do šablony s `{include chart, …, export: …}`
a překlady do obou `data/language_*.json`.
