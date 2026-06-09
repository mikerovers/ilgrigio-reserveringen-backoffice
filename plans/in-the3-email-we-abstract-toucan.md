# Show coupon/discount in the order confirmation email (consistent all-gross totals)

## Context

The customer order confirmation email did not show any coupon/discount line even
when a coupon was applied at checkout. The discount data already exists end-to-end
(`order_data.coupon_lines`, written in `TicketingController.php:571-585`, each entry
shaped `["code", "discount", "discount_tax"]`, all already passed to the templates as
`order_data` in `SendOrderEmailMessageHandler.php:94`), so this is a template change
plus a preview-data tweak — no business-logic PHP changes.

A first pass added a Korting line, but it exposed a pre-existing inconsistency in the
totals column: the **line item is shown incl. BTW (gross, e.g. €15.00)** while
**Subtotaal was computed ex. BTW (`order_total − total_tax`, e.g. €9.17)**. Mixing
gross and net in one column made the figures fail to add up once a discount row was
present.

**Decision:** make the whole totals column **all-gross (incl. BTW)** so it matches the
line item and reads as a clean running sum. BTW becomes an informational
"waarvan … BTW" note rather than a summed line.

Target layout (preview data: €15 item, €5 coupon, €10 total, €0.83 BTW):

```
Subtotaal:          €15,00     ← pre-discount gross, matches line item
Korting (WELKOM10): -€5,00
Betaalmethode:       iDEAL
————————————————————————————
Totaal:             €10,00     ← 15 − 5 ✓
(waarvan €0,83 BTW)
```

## Approach

All amounts are gross (incl. BTW):

- **Subtotaal (gross)** = `order_total + Σ(coupon.discount + coupon.discount_tax)`
  (i.e. the pre-discount gross; with no coupon this equals `order_total`).
- **Korting (gross)** = `Σ(coupon.discount + coupon.discount_tax)` per coupon line
  (already what the current template computes).
- **Totaal** = `order_total`.
- **BTW** = `total_tax`, shown as an informational note under Totaal, not a summed row.

### Files to change

1. `templates/email/customer_order_confirmation.html.twig`
   (totals block, ~lines 220-244)

   - Change **Subtotaal** to pre-discount gross. Compute the gross discount sum into a
     variable, then add it back:
     ```twig
     {% set coupon_total = 0 %}
     {% if order_data.coupon_lines is defined and order_data.coupon_lines %}
         {% for coupon in order_data.coupon_lines %}
             {% set coupon_total = coupon_total + (coupon.discount|default(0)) + (coupon.discount_tax|default(0)) %}
         {% endfor %}
     {% endif %}

     <tr>
         <td colspan="2"><strong>Subtotaal:</strong></td>
         <td class="text-right"><strong>€{{ (order_total + coupon_total)|number_format(2, '.', '') }}</strong></td>
     </tr>
     ```
   - Keep the **Korting** loop as-is (one row per coupon, `-€` of
     `discount + discount_tax`).
   - **Remove** the standalone `BTW (9%)` row.
   - Keep **Betaalmethode** and the **Totaal** (`order_total`) row.
   - After the Totaal row, add an informational BTW note (only when `total_tax`):
     ```twig
     {% if total_tax %}
     <tr>
         <td colspan="3" class="text-right"><small style="color: #6c757d;">(waarvan €{{ total_tax|number_format(2, '.', '') }} BTW)</small></td>
     </tr>
     {% endif %}
     ```

2. `templates/email/customer_order_confirmation.txt.twig`
   (totals block, lines ~36-39)

   Mirror the same logic in plain text:
   ```twig
   {% set coupon_total = 0 %}
   {% if order_data.coupon_lines is defined and order_data.coupon_lines %}{% for coupon in order_data.coupon_lines %}{% set coupon_total = coupon_total + (coupon.discount|default(0)) + (coupon.discount_tax|default(0)) %}{% endfor %}{% endif %}
   Subtotaal: €{{ (order_total + coupon_total)|number_format(2, '.', '') }}
   {% if order_data.coupon_lines is defined and order_data.coupon_lines %}{% for coupon in order_data.coupon_lines %}Korting ({{ coupon.code }}): -€{{ ((coupon.discount|default(0)) + (coupon.discount_tax|default(0)))|number_format(2, '.', '') }}
   {% endfor %}{% endif %}Betaalmethode: {{ order_data.payment_method_title|default('iDEAL') }}
   Totaal: €{{ order_total }}
   {% if total_tax %}(waarvan €{{ total_tax|number_format(2, '.', '') }} BTW){% endif %}
   ```
   (Remove the existing standalone `BTW (9%):` line.)

3. `src/Controller/TestController.php` — `testEmailPreview()` sample data
   (already partly done in this session)

   Keep the consistent preview figures so the column adds up:
   `total = "10.00"`, `total_tax = "0.83"`, line item `total = "15.00"`,
   and `coupon_lines = [{code: "WELKOM10", discount: "4.59", discount_tax: "0.41"}]`.
   (15.00 line item − 5.00 gross discount = 10.00 total; €0.83 BTW on the €10 gross.)

### Notes / decisions

- No-coupon orders are unaffected: `coupon_total` is 0, so Subtotaal == Totaal == `order_total`,
  the Korting loop renders nothing, and only the "waarvan … BTW" note shows — same numbers as before, just gross.
- Label stays Dutch ("Subtotaal", "Korting", "Betaalmethode", "Totaal") to match the existing
  hardcoded totals labels; no new translation keys.
- No changes to `SendOrderEmailMessageHandler` (`templateVars` already carries `order_total`,
  `total_tax`, and full `order_data`).

## Verification

1. Templates compile:
   ```bash
   bin/console cache:clear && bin/console lint:twig templates/email/
   ```
2. Visual: `symfony server:start`, open `/test/email-preview` (dev-only `TestController`).
   Confirm the column reads Subtotaal €15,00 → Korting −€5,00 → Betaalmethode → Totaal €10,00,
   with "(waarvan €0,83 BTW)" under the total, and that 15 − 5 = 10.
3. No-coupon regression: temporarily drop `coupon_lines` from the preview data (or rely on a
   real order without a coupon) and confirm Subtotaal == Totaal and no empty Korting row.
4. Run the suite to ensure nothing regressed:
   ```bash
   ./bin/phpunit
   ```
