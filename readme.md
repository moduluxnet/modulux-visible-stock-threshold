# Modulux Visible Stock Threshold for WooCommerce

> WooCommerce addon to cap **visible stock** and **max purchasable quantity** using global / category / role / per-product thresholds. Optional **Strict Mode** marks items Out of Stock when real stock ≤ threshold.

- **Honest urgency**: show “Only `{qty}` left” while keeping an internal buffer
- **Multiple levels**: product > role > category > global
- **Secure**: UI caps + server-side cart validations
- **Variations** supported, **i18n-ready**, no template overrides

## Features
- Visible stock cap (customers see/buy at most the threshold)
- Strict Mode (real ≤ threshold ⇒ out of stock)
- Per-product, per-category, role overrides, global default
- Customizable availability text (use `{qty}`)
- Robust validation on add/update cart
- WooCommerce + PHP 7.4+

## Installation
1. Copy to `wp-content/plugins/modulux-visible-stock-threshold/`
2. Activate in **Plugins**
3. Configure at **WooCommerce → Visible Stock Threshold**

## Priority (who wins?)
`Product > Role > Highest Category > Global`

## Example: Force 3 for guests, 10 for customers
```php
add_filter('modulux_vst_resolved_threshold', function($threshold, $product){
  if (is_user_logged_in()) return $threshold;
  return 3; // guest cap
}, 10, 2);

add_filter('modulux_vst_resolved_threshold', function($threshold, $product){
  if (is_user_logged_in() && in_array('customer', wp_get_current_user()->roles, true)) {
    return max((int)$threshold, 10);
  }
  return $threshold;
}, 20, 2);
