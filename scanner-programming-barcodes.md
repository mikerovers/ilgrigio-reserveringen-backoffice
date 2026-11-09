# Scanner programming barcodes

Implementation plan for a scanbalie page that shows Code 128 configuration barcodes, used to program USB barcode scanners. The catalog is managed per scanner type in the backoffice.

`picqer/php-barcode-generator` `^3.3` is already required. Do not add it again. `endroid/qr-code` cannot generate Code 128.

## Split of responsibilities

| Surface | Who | What |
|---|---|---|
| EasyAdmin on the admin host | `ROLE_ADMIN` | Create scanner types and add the Code 128 payloads for each type |
| New page on the scanner host | `ROLE_CHECK_IN` | Pick a type and scan the generated symbols |

Door staff should not edit the catalog from the kiosk. No firewall change is needed: `^/` on `%scanner_host%` already requires `ROLE_CHECK_IN`, and admin CRUD stays behind `ROLE_ADMIN` like the other EasyAdmin controllers.

Do not seed real manufacturer command strings. A wrong programming symbol can drop the Enter suffix the scan screen depends on (`Scanner typt de code en sluit af met Enter.`). Admins paste the payload from the scanner's own manual.

## Doctrine model

Two entities, same style as `Event` / `TicketType`: public properties, UUID v7, Gedmo timestamps, `sortOrder` for scan order.

### `BarcodeScannerType` (`barcode_scanner_type`)

- `id` UUID
- `name` — what staff see, e.g. "Netum NT-1228BL"
- `slug` — Gedmo slug from `name`, unique, used in the page URL
- `manufacturer` — optional
- `notes` — optional text shown above the symbols: enter setup, scan in order, exit setup, hold 10–20 cm from the screen
- `sortOrder` — order of types on the page
- `active` — inactive types stay in the backoffice and are hidden on the scanbalie
- `createdAt` / `updatedAt`
- `barcodes` — `OneToMany`, ordered by `sortOrder`

### `ScannerProgrammingBarcode` (`scanner_programming_barcode`)

- `id` UUID
- `scannerType` — `ManyToOne`, required, `onDelete: CASCADE`, Gedmo sortable group
- `label` — e.g. "Suffix: Enter"
- `description` — optional, what this scan does
- `payload` — the exact Code 128 data, max 80 characters. Longer symbols are unreliable from a screen.
- `codeSet` — enum `Code128Set`: `auto` (default), `A`, `B`, `C`
- `sortOrder` — scan order within the type. Order matters.
- `active`
- `createdAt` / `updatedAt`

No image column. The payload is the source of truth; the symbol is derived on each request.

`auto` is Code 128 with the encoder choosing the set. `A` is for control characters (ASCII 0–95), `B` for printable ASCII (32–127), `C` for an even run of digits. Programming manuals almost always decode to a printable command (`~CR`, a setup string), so `auto` is the right default. The code set does not change the decoded string a normal imager returns; it only matters when a manual insists on a specific set, or when the payload contains bytes that set B cannot hold.

PostgreSQL `text` / `varchar` cannot store a NUL byte. Reject `\x00`. Other control bytes (CR, TAB, GS) are fine.

## CODE-128 generation

`App\Service\Code128BarcodeGenerator`, using the already installed Picqer v3 API:

1. Reject an empty payload, anything outside the chosen set, and Code 128C that is not an even digit string. Raise a domain exception; do not render a broken symbol.
2. Encode with `Picqer\Barcode\Types\TypeCode128` (`auto`), `TypeCode128A`, `TypeCode128B`, or `TypeCode128C`, via `getBarcode($payload)`.
3. Render with `Picqer\Barcode\Renderers\SvgRenderer`:
   - `setSvgType(SvgRenderer::TYPE_SVG_INLINE)` so the result has no XML declaration and can sit in the HTML page
   - black bars, white background
   - width = `$barcode->getWidth() * 3`, height about 100px
   - integer module width avoids fractional bars, which imagers miss on a screen
4. Do not draw the human-readable text inside the SVG. Picqer only draws bars, plus a `<desc>` of the payload (already `htmlspecialchars`'d). Keep it that way.

Charset rules, matching Picqer:

| Set | Accepted |
|---|---|
| `auto` | ASCII 0–127 |
| `A` | ASCII 0–95 |
| `B` | ASCII 32–127 |
| `C` | even-length digits only |

Control bytes, if a manual needs them, are entered as `\xNN` (for example `\x0D`). A form data transformer unescapes that on save and escapes it again on edit. Printable text is stored as typed. `\\` is a literal backslash. A lone `\` that is not `\\` or `\xNN` is a form error. The generator never interprets `~CR` or similar vendor syntax; that string is the payload.

Share the charset and length checks between the entity constraint and the generator (`Code128Payload::assertEncodable()`), so a bad row is rejected in EasyAdmin and still cannot 500 the scanbalie.

FNC1–FNC4 are out of scope. Picqer accepts bytes 241–244 as FNC internally, but this feature does not expose them. If a model turns out to need FNC3 to enter programming mode, that is a follow-up.

A bad stored payload must not take down the page. The controller catches the generator exception, logs the barcode id, and shows an error card instead of an SVG.

## Scanner page

`GET /scanners` (`scanner_setup`) on `ScannerController`, template `templates/scanner/scanners.html.twig`.

- Screen dropdown gains **Instellen**, next to Scan / Alle scans / Orders.
- Hide the show picker on this screen. A voorstelling has nothing to do with programming a scanner.
- F4 in `assets/controllers/scanner/kiosk_controller.js` opens this page. F1–F3 stay as they are. Pass `data-scanner--kiosk-setup-path-value` from `templates/scanner/layout.html.twig`.
- `?type={slug}` selects the model. Default is the first active type by `sortOrder`. Unknown or inactive slug falls back the same way `selectEvent()` does.
- Still load the scannable events so the screen dropdown can link back to Scan / Alle scans / Orders with the current show.

Layout, in the existing scanbalie chrome:

1. Short instruction: scan these codes to program the USB scanner; the scanner must end each ticket scan with Enter; if the screen will not read, print the page.
2. Type switcher, same select pattern as the show picker.
3. The type's `notes`.
4. Active barcodes in `sortOrder`, each as a numbered white card: label, description, then the SVG in a white box with at least 10 modules of quiet zone on each side (about 30px at module factor 3). CSS must not scale the SVG (`shape-rendering: crispEdges`, no `width: 100%`). Allow horizontal scroll if a symbol is wider than the card. The payload is shown in monospace well below the bars, so it is not in the scan path.
5. Print button. Print CSS hides the masthead, forces a white background, and page-breaks between symbols.

Empty catalog: "Er zijn nog geen scannerconfiguraties. Voeg ze toe in het backoffice."

There is no scan input on this page, so a scanner that is not yet in programming mode cannot dump a payload into the check-in field.

The controller builds a small view model and passes pre-rendered SVG strings. Twig prints them with `|raw`. That is safe only because the SVG comes from the generator, never from concatenated user text. The label, description, and payload are escaped as normal text.

Do not add a PWA shortcut. `tests/Controller/Scanner/ScannerTest.php` asserts the manifest shortcuts stay `/`, `/scans`, `/orders`.

## Backoffice

New menu section **Scanbalie** on `DashboardController`, same pattern as Events → Ticket types.

- `BarcodeScannerTypeCrudController` — name, manufacturer, notes, sort order, active. Slug is generated and read-only. An index action **Barcodes** links to the child CRUD with `?scannerType={id}`, the same way `EventCrudController` links to ticket types.
- `ScannerProgrammingBarcodeCrudController` — required type association (disabled on edit), label, description, payload, code set, sort order, active. Index query is filtered by `scannerType` when that query param is present. `createEntity()` reads that param so "new" from a type lands on the right model. Default sort is type, then `sortOrder`. Throw if no scanner type exists yet, same as ticket types without an event.

Payload field uses a custom form type (`Code128PayloadType`) whose model transformer does the `\xNN` round-trip. Help text states the 80-character limit, the `\xNN` rule, the NUL rejection, and that the value must be the decoded content from the manual, not a description of the setting.

Validate in the entity and again in the generator. A form error is better than a 500 on the scanbalie.

Two linked CRUDs, not an EasyAdmin `CollectionField`. This codebase has no nested collections, and `TicketTypeCrudController` is the pattern to copy.

## Tests and docs

- Unit-test the generator: SVG for a printable payload (`<svg`, no XML declaration), rejection of empty input, rejection of non-digits for set C, and the `\xNN` / `\\` round-trip.
- Extend `tests/Controller/Scanner/ScannerTest.php`: a check-in user sees the type name and an inline `<svg>`; an inactive type is absent; a logged-out request to `/scanners` redirects to the scanner login.
- Add a short note to the Core Workflows section of `AGENTS.md`: `/scanners` on the scanner host shows programming barcodes for the active `BarcodeScannerType`; payloads are edited in EasyAdmin; the scan screen still assumes the USB scanner types the code and closes with Enter. Do not seed manufacturer command strings.

## Out of scope

- Decoding a photographed manual barcode.
- Code 39, PDF417, or vendor 2D setup symbols.
- Storing PNG/SVG files.
- Letting `ROLE_CHECK_IN` edit payloads.
- Invented setup codes for a specific brand.
- FNC1–FNC4.

## Implementation order

1. Migration and entities (`BarcodeScannerType`, `ScannerProgrammingBarcode`, `Code128Set`), plus repositories.
2. `Code128Payload` and `Code128BarcodeGenerator`, plus unit tests.
3. EasyAdmin CRUDs and the Scanbalie menu section.
4. Scanner route, template, layout dropdown, and F4 wiring.
5. Functional test and the `AGENTS.md` note.
