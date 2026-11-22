# Formát souboru znalostní báze (JSON)

Stav: import je implementovaný v administraci (Znalosti → Import ze souboru, role moderátor
a vyšší), export zatím ne.

Znalostní báze obsahuje schválené firemní informace, ze kterých AI smí odpovídat (specifikace
§5.3). Dnes se zadává ručně v administraci (Znalosti). Tento formát slouží k hromadnému importu,
například z exportu jiné aplikace, a ke zpětnému exportu z moderátoru. Je to částečná odpověď
na otevřenou otázku §13 bod 6 (odkud se berou autoritativní FAQ, ceník a podmínky).

## Ukázka

```json
{
	"format": "social-ai-moderator.knowledge",
	"format_version": 1,
	"exported_at": "2026-11-21T18:00:00Z",
	"source": "eshop",
	"sources": [
		{
			"key": "pricing_current",
			"type": "pricing",
			"valid_from": "2026-12-01",
			"valid_to": "2027-06-30",
			"locales": {
				"cs": {
					"title": "Ceník online programů",
					"content": "Měsíční členství stojí 490 Kč, roční 3 900 Kč.\nCeny jsou včetně DPH.\nPlatit lze kartou nebo převodem v e-shopu."
				},
				"en": {
					"title": "Online program pricing",
					"content": "Monthly membership costs EUR 20, yearly EUR 160.\nPrices include VAT.\nPayment by card or bank transfer in the shop."
				}
			}
		},
		{
			"key": "access_login",
			"type": "faq",
			"valid_from": null,
			"valid_to": null,
			"locales": {
				"cs": {
					"title": "Přístup do kurzu a přihlášení",
					"content": "Přístup přijde e-mailem do 15 minut po zaplacení.\nPřihlášení: shop.example.test/login.\nZapomenuté heslo lze obnovit na stejné stránce."
				}
			}
		}
	]
}
```

## Pole

### Hlavička

| Pole | Povinné | Význam |
| --- | --- | --- |
| `format` | ano | Vždy `social-ai-moderator.knowledge`. Jiná hodnota = soubor se odmítne. |
| `format_version` | ano | Celé číslo, nyní `1`. Nekompatibilní změna formátu zvýší číslo. |
| `exported_at` | ne | Čas vzniku exportu v UTC (ISO 8601). Jen informativní, ukládá se do auditu. |
| `source` | ne | Krátký název zdrojové aplikace (`[a-z0-9_-]`, max. 50 znaků). Jen informativní. |
| `sources` | ano | Seznam znalostí, alespoň jedna položka. |

### Znalost (`sources[]`)

| Pole | Povinné | Význam |
| --- | --- | --- |
| `key` | ano | Stabilní název tématu, `^[a-z0-9_]{1,100}$`, např. `pricing_current`. V souboru je unikátní. AI se na znalost odkazuje tímto klíčem a každá odpověď ukládá klíč a verzi. |
| `type` | ano | `faq`, `pricing`, `product`, `terms` nebo `other` (`App\Knowledge\SourceType`). |
| `valid_from` | ano | Datum `YYYY-MM-DD` v časové zóně firmy (parametr `timezone`), nebo `null` = bez omezení. |
| `valid_to` | ano | Poslední platný den včetně, nebo `null`. Musí být `>= valid_from`. |
| `locales` | ano | Objekt jazyk → text. Povolené jsou jen jazyky z konfigurace (nyní `cs`, `en`, `es`), aspoň jeden. |

### Text v jazyce (`locales.<jazyk>`)

| Pole | Povinné | Význam |
| --- | --- | --- |
| `title` | ano | Výstižný název, max. 200 znaků. **Klasifikátor vybírá znalosti jen podle klíče a názvu**, proto musí název jasně říkat, na jaké dotazy znalost odpovídá. |
| `content` | ano | Fakta pro odpověď, prostý text v UTF-8, řádky odděleny `\n`. Doporučeno nejvýše 2 000 znaků. |

Platnost a typ jsou společné pro všechny jazyky jednoho klíče. Každý jazyk se v databázi uloží
jako samostatný řádek `knowledge_sources` (klíč + jazyk + verze).

## Pravidla pro obsah

- **Jen fakta, žádné pokyny pro AI.** Ne „Odpověz, že…“ ani „Vždy nabídni slevu“. Obsah znalosti
  se do promptu vkládá jako data; pokyny v něm model ignoruje a validace je může odmítnout.
- Jedno téma = jeden klíč. Raději více kratších znalostí než jeden dlouhý dokument.
- Žádné osobní údaje, interní poznámky, hesla ani odkazy mimo oficiální domény firmy.
- Žádná zdravotní doporučení, příslib výsledků ani individuální podmínky. Dotazy na zdraví,
  platby konkrétního zákazníka, reklamace a vracení peněz AI vždy předá člověku, ať je ve
  znalostech cokoli.
- Ceny vždy s měnou a informací o DPH.
- Stejný klíč ve všech jazycích nese stejná fakta.

## Plánovaná změna (např. nový ceník)

Pro jeden klíč a jazyk je publikovaná nejvýše jedna verze. Budoucí změnu proto připravte pod
**novým klíčem** s omezenou platností, například `pricing_2026` (`valid_to: 2026-12-31`)
a `pricing_2027` (`valid_from: 2027-01-01`). Obě mohou být publikované najednou; AI vidí jen
tu, která je dnes platná.

## Chování importu

Administrace → Znalosti → **Import ze souboru** (`App\Knowledge\KnowledgeImport`), soubor
nejvýše 1 MB.

1. **Vše, nebo nic.** Soubor se zpracuje v jedné transakci; při jakékoli chybě se nezapíše nic
   a formulář uvede pole s chybou, např. `sources[3].locales.cs` (bez obsahu textů).
2. **Jen nové verze jako koncepty.** Pro každý klíč a jazyk se obsah (typ, název, text,
   platnost) porovná s poslední verzí. Shoda = nic se nestane, takže opakovaný import stejného
   souboru nic nezmění. Rozdíl = nová verze ve stavu `draft`.
3. **Publikuje člověk.** Import nikdy nepublikuje. Administrátor koncepty zkontroluje
   a publikuje v administraci (Znalosti); předchozí publikovaná verze se archivuje.
4. **Nic nemaže.** Klíče, které v souboru chybí, zůstanou beze změny. Stažení znalosti je
   vědomý krok v administraci (zrušit publikování).
5. Import se zapisuje do auditu (`knowledge.import`) s ID operátora, počtem vytvořených
   a přeskočených verzí a hodnotami `source` a `exported_at`. Každý koncept má navíc vlastní
   záznam `knowledge.create`.

## Export

Plánovaný příkaz: `bin/console knowledge:export <soubor>`. Vytvoří soubor ve stejném formátu
se všemi **publikovanými** verzemi (i s budoucí nebo prošlou platností). Koncepty ani archiv
neobsahuje. Export znovu naimportovaný do jiné instance vytvoří stejné koncepty.

Pokud se platnost nebo typ jednoho klíče mezi jazyky liší, export skončí chybou s názvem
klíče, aby se nesoulad opravil v administraci.

## Doporučená počáteční témata

Klíče jsou návrh; obsah musí dodat firma.

| Klíč | Typ | Na co odpovídá |
| --- | --- | --- |
| `program_overview` | `product` | Co je face yoga program, pro koho je, co obsahuje |
| `how_it_works` | `product` | Formát lekcí, délka, jak často cvičit, potřebné vybavení |
| `pricing_current` | `pricing` | Ceny členství a kurzů, měna, DPH |
| `payment_methods` | `pricing` | Způsoby platby (obecně, ne stav platby zákazníka) |
| `access_login` | `faq` | Jak získat přístup, přihlášení, obnova hesla |
| `devices_requirements` | `faq` | Na čem kurz běží (mobil, počítač, aplikace) |
| `languages_available` | `faq` | V jakých jazycích je obsah |
| `live_sessions_schedule` | `faq` | Termíny živých lekcí, pokud existují |
| `gift_vouchers` | `product` | Dárkové poukazy |
| `cancellation_terms` | `terms` | Obecná pravidla zrušení předplatného (odkaz na podmínky) |
| `contact_support` | `other` | Jak kontaktovat podporu a kdy odpovídá |
