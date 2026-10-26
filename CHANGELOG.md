# Changelog

## 1.0.0 – 2026-10-26

- Vyčleněno z aplikace Bridge (`App\Model\Multiplast\Nesting`) do samostatného balíčku
  s namespace `Multiplast\SheetNester`. Algoritmus beze změny – na golden datasetu
  a dalších variantách (všechny strategie, kerf, směr vláken, režimy zbytků,
  neumístěné dílce) identický výsledek, SVG i PDF HTML.
- Pomocné třídy v samostatných souborech (PSR-4) a označené `@internal`.
- `SheetNesterPdf`: mPDF je volitelná závislost (`suggest`); bez něj srozumitelná výjimka.
