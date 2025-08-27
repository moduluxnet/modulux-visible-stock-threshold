=== Modulux Visible Stock Threshold ===
Contributors: modulux, sgeray
Donate link: https://modulux.net
Tags: woocommerce, threshold, out of stock, availability, role based
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a capped “visible stock” and limit purchase quantity using global / category / role / per-product thresholds. Strict Mode can auto-mark items as Out of Stock when real stock ≤ threshold.

== Description ==

**Modulux Visible Stock Threshold** lets you control what customers *see* and *can buy*:

- **Visible stock cap:** If real stock is above the threshold, only the threshold amount is shown/purchasable.
- **Strict Mode:** If real stock ≤ threshold, the product is Out of Stock (not purchasable).
- **Multiple levels:** Per-product, per-category, role-based, or global default.
- **Custom message:** “Only {qty} left in stock” (fully editable).
- **Secure limits:** Frontend input is capped and server-side validations prevent bypassing limits.
- **Variation support**, **i18n-ready**, clean code, no templates overridden.

**Precedence:** Product > Role > Highest Category > Global.

### Why this plugin?
Urgency marketing without lying: keep internal buffer stock while honestly limiting visible/purchasable quantity.

### Works with
WooCommerce stock-managed products (simple & variations). If stock management is off, plugin stays out of the way.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via **Plugins → Add New**.
2. Activate **Modulux Visible Stock Threshold**.
3. Go to **WooCommerce → Visible Stock Threshold** to configure:
   - Enable on storefront
   - Strict Mode
   - Global default threshold
   - Availability text (use `{qty}`)
   - Role-based overrides
4. (Optional) Set thresholds per **Product** (Inventory tab) and **Product Category**.

== Frequently Asked Questions ==

= Does it change real stock? =
No. It only changes what’s **visible** and **purchasable** to the customer.

= How does Strict Mode work? =
If real stock ≤ threshold, the product is Out of Stock. If real stock > threshold, visible/purchasable qty = threshold.

= Which level wins if multiple thresholds are set? =
Product > Role override > Highest category threshold > Global default.

= Does it support variations? =
Yes—each variation’s stock is treated independently.

= Is it compatible with backorders? =
If a product allows backorders (and stock management is enabled), the plugin still enforces the visible cap unless another plugin bypasses WC’s checks. Test your backorder flow.

== Screenshots ==

1. Settings page under WooCommerce menu
2. Product Inventory meta box
3. Product Category term field
4. Frontend “Only {qty} left” text example

== Changelog ==

= 1.0.0 =
* Initial public release: visible stock cap, Strict Mode, per-product/category/role/global, server validations, i18n.

== Upgrade Notice ==

= 1.0.0 =
First release.
