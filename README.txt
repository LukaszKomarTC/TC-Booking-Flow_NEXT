TC — Booking Flow NEXT (Refactored Architecture)

This is a refactored version of TC Booking Flow with improved code organization.
The old repo remains unchanged in production.

What this plugin consolidates:
- Gravity for of a settings-driven ID after submission → add booking(s) to Woo cart
- Early Booking Discount (EB) calculation and GF field population (field 172 inputName early_booking_discount_pct)
- EB SNAPSHOT stored per cart item and applied from snapshot (no recalculation drift)
- Partner coupon remains Woo-native (applied by WC()->cart->add_discount), partner commission ledger stored on order

Per-event meta (sc_event):
- tc_ebd_enabled: yes/no
- tc_ebd_rules_json: [{"days":90,"pct":15},{"days":30,"pct":5}]
- tc_ebd_cap: optional
- tc_ebd_participation_enabled: yes/no
- tc_ebd_rental_enabled: yes/no

Product scheme:
- Participation product ID: set on event meta tc_participation_product_id (or tc_product_id fallback)
- Rental product ID: comes from GF bike choice (PRODUCTID_RESOURCEID). If rental product is bookable, plugin adds it as a separate cart item.

IMPORTANT:
- Until you fully rearrange product scheme, participation pricing is still using GF total as _custom_cost (legacy).
  Once participation and rental pricing are separate, remove/replace that part with component pricing.

