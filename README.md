# SheetNester

2D nesting obdélníkových dílců na skladové formáty desek (polykarbonát apod.):
guillotine řez, Best Area Fit, lookahead výběr formátu, eliminace a redukce desek,
značení použitelných zbytků. Výstupem je pole s rozmístěním, SVG náhled desky
a volitelně PDF nářezový plán (mPDF).

- `Multiplast\SheetNester\SheetNester` – výpočet + `toSvg()`, bez závislostí
- `Multiplast\SheetNester\SheetNesterPdf` – PDF plán, vyžaduje `mpdf/mpdf`

Požadavky: PHP ≥ 8.0 (determinismus spoléhá na stabilní `usort`, které garantuje PHP od verze 8.0).

## Instalace

Repozitář je soukromý, Composer ho stahuje přímo z GitHubu. Do `composer.json`
projektu přidejte zdroj:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Multiplast/SheetNester.git" }
]
```

Na stroji, kde se spouští Composer, jednou nastavte GitHub token
(fine-grained token s oprávněním *Contents: Read-only* jen na tento repozitář):

```sh
composer config --global --auth github-oauth.github.com <token>
```

Pak:

```sh
composer require multiplast/sheet-nester:^1.0
```

PDF potřebuje navíc `composer require mpdf/mpdf` (v Bridge už je).

## Použití

```php
use Multiplast\SheetNester\SheetNester;
use Multiplast\SheetNester\SheetNesterPdf;

$nester = new SheetNester(
    kerf: 0,
    grainDirection: 'vertical',
    formatStrategy: SheetNester::STRATEGY_MIN_WASTE,
    restarts: 300,
    seed: 42,
    timeBudgetMs: 8000,
    remnantMode: SheetNester::REMNANT_AUTO,
    minRemnantSide: 200,
);

$nester->addStockFormat(6000, 2100, 100);   // šířka, výška [mm], počet kusů skladem
$nester->addPiece(2200, 900, 22);           // šířka, výška [mm], počet

$result = $nester->calculate();
$svg    = $nester->toSvg(0);                // náhled první desky

$pdf = new SheetNesterPdf($result, ['title' => 'Nářezový plán č. 123', 'material' => 'PC 10 mm']);
$pdf->save('/cesta/plan.pdf');              // nebo generate() / download() / inline()
```

Spustitelné ukázky jsou v `examples/` (`composer install && php examples/basic.php`).

### Parametry konstruktoru

| Parametr | Výchozí | Význam |
|---|---|---|
| `kerf` | – | Prořez pily v mm, přičítá se ke stopě dílce |
| `grainDirection` | `'none'` | `'horizontal'`, `'vertical'` = zákaz otáčení, `'none'` = dílce lze otočit |
| `formatStrategy` | `smallest_fit` | viz Strategie |
| `restarts` | 20 | Počet náhodných pořadí navíc (vícepr. strategie) |
| `seed` | `null` | Seed RNG; se seedem je výsledek reprodukovatelný |
| `timeBudgetMs` | `null` | Měkký časový limit; po vyčerpání se další průchody přeskočí |
| `remnantMode` | `'none'` | `'auto'` (největší volné obdélníky), `'defined'` (formáty z `addRemnantFormat()`) |
| `minRemnantSide` | 200 | Min. kratší strana zbytku v mm (režim `auto`) |
| `cutCostM2` | 0.05 | Jen `min_cuts`: kolik m² materiálu „stojí" jeden průjezd pily |

### Strategie

- `smallest_fit` – jeden průchod FFD, nejrychlejší.
- `min_waste` – kanonické FFD + deterministické portfolio řazení + `restarts` náhodných pořadí; vyhrává nejméně neumístěných, pak nejméně materiálu (m²).
- `min_cuts` – minimalizuje `materiál_m2 + cutCostM2 × počet_řezů`; může vyměnit trochu materiálu za méně průjezdů pily.
- `best_remnant` – jako `min_waste`, při shodě materiálu preferuje největší jednolitý zbytek.

### Výsledek `calculate()`

Hlavní klíče: `sheets` (pro každou desku `stock_width`, `stock_height`,
`pieces[x, y, width, height, rotated]`, `used_area_pct`, `cuts`, příp. `remnants`),
`stock_usage`, `unplaced_pieces` (s `reason`), `total_sheets`, `total_material_m2`,
`utilization_pct`, `total_cuts_count`, `total_cuts_mm`, `total_seal_mm`,
`total_remnant_m2`, `strategy`, `permutations_tested`, `time_budget_hit`,
`calculation_time_ms`. Úplný tvar popisuje docblock `SheetNester::calculate()`.

Desky jsou ve výsledku seřazené od největšího formátu, stejné formáty jdou za sebou.

## Determinismus a hosting

Se zadaným `seed` a bez `timeBudgetMs` dává stejný vstup vždy stejný výsledek.
`timeBudgetMs` chrání před `max_execution_time` na hostingu bez `set_time_limit`;
pokud se limit vyčerpá (`time_budget_hit: true`), výsledek závisí na rychlosti stroje.

## Vydání nové verze

Verze se řídí [SemVer](https://semver.org/lang/cs/) a vydávají se git tagem:

```sh
git tag v1.0.1 && git push origin v1.0.1
```

V projektu pak `composer update multiplast/sheet-nester`. Veřejné API tvoří
`SheetNester`, `SheetNesterPdf` a tvar výsledného pole; pomocné třídy
(`Piece`, `StockFormat`, `Sheet`, `FreeRect`, `PlacedPiece`) jsou `@internal`.

**Kontrola před vydáním:** změna, která nemá měnit chování, musí na golden datasetu
dát identické rozmístění. Golden dataset je výchozí předvyplnění v Bridge
`/admin/nesting/` (30 formátů, seed 42); `min_waste` s `restarts: 200` bez časového
limitu dává 18 desek / 101,92 m².
