# Changelog

## Nevydáno

- Testovací sada (PHPUnit 9.6–12, PHP 8.0+, `composer test`): golden fixtures referenčního
  zadání pro všechny strategie, prořez, volnou rotaci, režimy zbytků a neumístěné dílce;
  property-based invarianty na náhodných zadáních; testy jednotlivých funkcí; smoke test PDF.
- CI přes GitHub Actions na PHP 8.0–8.5.
- Vnitřní rozdělení třídy `SheetNester` (1 800 řádků) na `Internal\GuillotinePacker`
  (umísťování + lookahead), `SheetEliminator`, `FormatReducer`, `Solver` (smyčka průchodů),
  `Objective` (cílová funkce strategie), `CutCalculator`, `RemnantFinder`, `ResultBuilder`
  a `Render\SvgRenderer` (kreslí z pole výsledku); pomocné třídy přesunuty do `Internal\`.
  Veřejné API ani výstup se nemění – golden fixtures, SVG i PDF HTML jsou identické.
- Nové API 2.0: `NestingJob::create(material, objective, search, remnants)` s `Options\Material`
  (`Grain::NONE | ALONG_WIDTH | ALONG_HEIGHT`), `Options\Objective` (`minWaste()` / `minCuts()` /
  `bestRemnant()`), `Options\Search` (`singlePass()` ≙ dřívější `smallest_fit`) a
  `Options\Remnants` (`none()` / `auto()` / `defined()`); `SheetNester::solve(NestingJob)` vrací
  typovaný `Result\NestingResult` (`SheetPlan`, `Placement`, `CutPlan`, `Cut`, `Remnant`,
  `StockUsage`, `UnplacedPieces`). `NestingResult::toLegacyArray()` dává pole v tvaru 1.x.
  Konstruktor 1.x, `addStockFormat()`, `addPiece()`, `addRemnantFormat()`, `calculate()`
  a `toSvg()` zůstávají jako deprecated adaptér nad `solve()` (`'horizontal'` = `ALONG_WIDTH`,
  `'vertical'` = `ALONG_HEIGHT`); výstup beze změny.
- **Změna hodnot:** délka pásky (`seal_mm`, `total_seal_mm`) se počítá podle osy komůrek –
  na dílec dvě hrany kolmé ke komůrkám: `Grain::ALONG_WIDTH` → 2 × výška, `Grain::ALONG_HEIGHT`
  → 2 × šířka, `Grain::NONE` → `null` (v legacy poli `0.0`). Verze 1.x počítala vždy Σ výška × 2
  bez ohledu na směr, což odpovídá jen komůrkám podél šířky; pro `'vertical'` (= `ALONG_HEIGHT`,
  konvence e-shopu: délka podél komůrek je `height`) se tedy hodnota mění na Σ šířka × 2.
  Rozmístění, řezy ani SVG se nemění. Páska je nově na úrovni desky (`SheetPlan::$sealMm`),
  v legacy poli zůstává v `cuts.seal_mm`.
- Štítky dílců: `NestingJob::addPiece(..., label: 'okno A')` – štítek nese každý `Placement`
  i skupina `UnplacedPieces` (neumístěné se seskupují podle rozměru a štítku).
- Strojové důvody neumístění: `UnplacedPieces::$reasonCode` (`stock` / `too_large`) a anglická
  `reason()`; legacy pole dál vrací české texty 1.x.
- Neomezený sklad: `NestingJob::addStock(width, height)` bez `quantity` (nebo `null`);
  `StockUsage::$remaining` je pak `null`.
- `NestingResult::toArray()` – čistý tvar pro JSON (`totals`, `sheets[].pieces[].label`,
  `cuts.count`, `unplaced[].reason_code`); `toLegacyArray()` zůstává pro tvar 1.x.
- `Render\SvgRenderer` je veřejný: kreslí `SheetPlan`, texty přes mapu (`LABELS_EN` výchozí,
  `LABELS_CS`), zobrazuje štítky dílců, a výstup je validní XML – 1.x `toSvg()` vkládalo
  `&nbsp;`, takže samostatný `.svg` soubor se nedal otevřít (nyní `&#160;`).
- `Render\PdfReport` nahrazuje `SheetNesterPdf`: bere `NestingResult`, texty přes mapu
  (`LABELS_EN` / `LABELS_CS`), `html()` bez mPDF, sloupce rozměrů v pořadí zadání
  (šířka, výška – 1.x tisklo „Délka“ = `height` jako první), nové řádky řezy a páska,
  sloupec štítků, `∞` pro neomezený sklad, meta klíče `company_id` / `company_vat_id`.
  `SheetNesterPdf` zůstává jako deprecated obal (bere pole 1.x i `NestingResult`, mapuje
  `company_ico` / `company_dic`, používá české texty).
- `NestingResult::fromArray()` a `fromLegacyArray()` – obnovení výsledku z uloženého JSONu
  (např. pro pozdější PDF).

## 1.0.1 – 2026-10-27

- Minimální verze PHP snížena z 8.1 na 8.0. Kód nepoužívá žádnou syntaxi, funkci ani třídu
  novější než PHP 8.0 (ověřeno tokenovou kontrolou zdrojáků); stabilní `usort`, na kterém
  stojí determinismus výsledků, garantuje PHP právě od verze 8.0. Běh na PHP 8.0.30 (Linux)
  a 8.5.9 (Windows) dává pro všechny strategie, směry vláken a režimy zbytků identický
  výsledek, SVG i PDF HTML.

## 1.0.0 – 2026-10-26

- Vyčleněno z aplikace Bridge (`App\Model\Multiplast\Nesting`) do samostatného balíčku
  s namespace `Multiplast\SheetNester`. Algoritmus beze změny – na golden datasetu
  a dalších variantách (všechny strategie, kerf, směr vláken, režimy zbytků,
  neumístěné dílce) identický výsledek, SVG i PDF HTML.
- Pomocné třídy v samostatných souborech (PSR-4) a označené `@internal`.
- `SheetNesterPdf`: mPDF je volitelná závislost (`suggest`); bez něj srozumitelná výjimka.
