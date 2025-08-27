<?php
/**
 * Plugin Name:  Modulux Visible Stock Threshold
 * Description:  Show a capped "visible stock" and limit purchase quantity using global / category / role / per-product thresholds. Strict Mode can mark stock ≤ threshold as Out of Stock. Includes customizable "Only {qty} left" message.
 * Version:      1.0.0
 * Author:       Modulux
 * Author URI:   https://modulux.net
 * License:      GPLv2 or later
 * Text Domain:  modulux-visible-stock-threshold
 * Domain Path:  /languages
 */

if (!defined('ABSPATH')) exit;

final class Modulux_VST {
    const OPT_KEY       = 'modulux_vst_options';
    const META_KEY      = '_mvisth_threshold';
    const TERM_META_KEY = 'mvisth_threshold';

    private static $instance = null;
    public  $opts = [];

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Load text domain and defaults at the right time (avoid _load_textdomain_just_in_time warning)
        //add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'init_defaults']);

        // Woo check bootstrap
        add_action('plugins_loaded', [$this, 'maybe_boot']);

        // Uninstall handler
        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);

        // Inject CSS for settings page table header
        add_action('admin_footer', function () {
            $screen = get_current_screen();
            if ($screen && $screen->id === 'woocommerce_page_modulux-vst') {
            echo '<style>
                .modulux-vst-table {
                    max-width:680px
                }
                .modulux-vst-table th {
                    display: table-cell!important;
                    padding: 15px 10px!important;
                    vertical-align: top;
                    line-height: 1.75em;
                }
                .modulux-vst-about .hndle { padding: 0 12px; cursor: default; }
                .modulux-vst-about code, .modulux-vst-about pre { background: #f6f7f7; }
                .modulux-vst-about pre { padding: 12px; overflow: auto; }
                .modulux-vst-about .hooks-list code { display: inline-block; margin-bottom: 6px; }                    
            </style>';
            }
        });        
    }

    public static function uninstall() {
        delete_option(self::OPT_KEY);
        // keep post/term meta by design
    }

    /*public function load_textdomain() {
        load_plugin_textdomain('modulux-visible-stock-threshold', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }*/

    public function init_defaults() {
        $defaults = [
            'enabled'           => 1,
            'strict_mode'       => 1,  // If real stock ≤ threshold => Out of Stock
            'default_threshold' => '',
            // Store raw text; translate when displaying
            'availability_tpl'  => 'Only {qty} left in stock',
            'role_overrides'    => [],
        ];

        $saved      = get_option(self::OPT_KEY, []);
        $this->opts = wp_parse_args($saved, $defaults);
    }

    public function maybe_boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>Modulux Visible Stock Threshold</strong> ' .
                    esc_html__('requires WooCommerce to be active.', 'modulux-visible-stock-threshold') . '</p></div>';
            });
            return;
        }

        if (is_admin()) {
            $this->admin();
        }
        $this->frontend();
    }

    /* ---------------------------
     * Admin area
     * --------------------------*/
    private function admin() {
        // Settings page
        add_action('admin_menu', function(){
            add_submenu_page(
                'woocommerce',
                __('Visible Stock Threshold', 'modulux-visible-stock-threshold'),
                __('Visible Stock Threshold', 'modulux-visible-stock-threshold'),
                'manage_woocommerce',
                'modulux-vst',
                [$this, 'render_settings']
            );
        });

        add_action('admin_init', function(){            

            register_setting('modulux_vst_group', self::OPT_KEY, [$this, 'sanitize_options']);

            add_settings_section('modulux_vst_main', __('General Settings', 'modulux-visible-stock-threshold'), '__return_false', 'modulux_vst');

            add_settings_field('enabled', __('Enable', 'modulux-visible-stock-threshold'), function(){
                $o = $this->opts;
                echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_KEY).'[enabled]" value="1" '.checked(!empty($o['enabled']), true, false).'> '.
                     esc_html__('Activate threshold logic on storefront', 'modulux-visible-stock-threshold').'</label>';
            }, 'modulux_vst', 'modulux_vst_main');

            add_settings_field('strict_mode', __('Strict Mode', 'modulux-visible-stock-threshold'), function(){
                $o = $this->opts;
                echo '<label><input type="checkbox" name="'.esc_attr(self::OPT_KEY).'[strict_mode]" value="1" '.checked(!empty($o['strict_mode']), true, false).'> '.
                     esc_html__('If real stock ≤ threshold, mark product out of stock (not purchasable).', 'modulux-visible-stock-threshold').'</label>';
            }, 'modulux_vst', 'modulux_vst_main');

            add_settings_field('default_threshold', __('Default Threshold', 'modulux-visible-stock-threshold'), function(){
                $o = $this->opts;
                echo '<input type="number" min="0" step="1" style="width:120px" name="'.esc_attr(self::OPT_KEY).'[default_threshold]" value="'.esc_attr($o['default_threshold']).'"> ';
                echo '<p class="description">'.esc_html__('Used if no product / category / role override is set.', 'modulux-visible-stock-threshold').'</p>';
            }, 'modulux_vst', 'modulux_vst_main');

            add_settings_field('availability_tpl', __('Availability Text', 'modulux-visible-stock-threshold'), function(){
                $o = $this->opts;
                echo '<input type="text" style="width:400px" name="'.esc_attr(self::OPT_KEY).'[availability_tpl]" value="'.esc_attr($o['availability_tpl'] ?: __('Only {qty} left in stock', 'modulux-visible-stock-threshold')).'"> ';
                echo '<p class="description">'.esc_html__('Use {qty} placeholder. Example: "Only {qty} left in stock"', 'modulux-visible-stock-threshold').'</p>';
            }, 'modulux_vst', 'modulux_vst_main');

            add_settings_field('role_overrides', __('Role Overrides', 'modulux-visible-stock-threshold'), function(){
                $o = $this->opts;
                $roles = wp_roles()->roles;
                echo '<table class="widefat striped modulux-vst-table"><thead><tr><th>'.esc_html__('Role','modulux-visible-stock-threshold').'</th><th>'.esc_html__('Threshold','modulux-visible-stock-threshold').'</th></tr></thead><tbody>';
                foreach ($roles as $key => $role) {
                    $val = isset($o['role_overrides'][$key]) ? (int)$o['role_overrides'][$key] : '';
                    echo '<tr><td>'.esc_html(translate_user_role($role['name'])).' ('.esc_html($key).')</td><td>'.
                         '<input type="number" min="0" step="1" name="'.esc_attr(self::OPT_KEY).'[role_overrides]['.esc_attr($key).']" value="'.esc_attr($val).'"></td></tr>';
                }
                echo '</tbody></table>';
                echo '<p class="description">'.esc_html__('If set, applies when current user has that role (product override wins, then role, then category, then global).', 'modulux-visible-stock-threshold').'</p>';
            }, 'modulux_vst', 'modulux_vst_main');
        });

        // Product meta box
        add_action('woocommerce_product_options_inventory_product_data', function(){
            echo '<div class="options_group">';
            woocommerce_wp_text_input([
                'id'                => self::META_KEY,
                'label'             => __('Visible Stock Threshold', 'modulux-visible-stock-threshold'),
                'description'       => __('If set: when real stock ≤ threshold, product is out of stock (strict mode). When real stock > threshold, only threshold is visible & purchasable.', 'modulux-visible-stock-threshold'),
                'desc_tip'          => true,
                'type'              => 'number',
                'custom_attributes' => ['min' => '0', 'step' => '1'],
            ]);
            echo '</div>';

            wp_nonce_field('modulux_vst_term_save', '_modulux_vst_term_nonce'); // just in case
        });

        add_action('woocommerce_process_product_meta', function($post_id){
            if ( isset( $_POST[self::META_KEY] ) && isset( $_POST['_modulux_vst_nonce'] ) && wp_verify_nonce( sanitize_text_field(wp_unslash($_POST['_modulux_vst_nonce'])), 'modulux_vst_save' ) ) {
                update_post_meta($post_id, self::META_KEY, sanitize_text_field(wp_unslash($_POST[self::META_KEY])));
            }
        });

        // Category (product_cat) term meta
        add_action('product_cat_add_form_fields', function(){
            ?>
            <div class="form-field">
                <label for="<?php echo esc_attr(self::TERM_META_KEY); ?>"><?php esc_html_e('Visible Stock Threshold', 'modulux-visible-stock-threshold'); ?></label>
                <input name="<?php echo esc_attr(self::TERM_META_KEY); ?>" id="<?php echo esc_attr(self::TERM_META_KEY); ?>" type="number" min="0" step="1">
                <p class="description"><?php esc_html_e('Used if product has no override; role can still override; falls back to global default.', 'modulux-visible-stock-threshold'); ?></p>
            </div>
            <?php
            wp_nonce_field('modulux_vst_term_save', '_modulux_vst_term_nonce'); // just in case
        });

        add_action('product_cat_edit_form_fields', function($term){
            $value = get_term_meta($term->term_id, self::TERM_META_KEY, true);
            ?>
            <tr class="form-field">
                <th scope="row"><label for="<?php echo esc_attr(self::TERM_META_KEY); ?>"><?php esc_html_e('Visible Stock Threshold', 'modulux-visible-stock-threshold'); ?></label></th>
                <td>
                    <input name="<?php echo esc_attr(self::TERM_META_KEY); ?>" id="<?php echo esc_attr(self::TERM_META_KEY); ?>" type="number" min="0" step="1" value="<?php echo esc_attr($value); ?>">
                    <p class="description"><?php esc_html_e('Leave empty to not set at category level.', 'modulux-visible-stock-threshold'); ?></p>
                </td>
            </tr>
            <?php
            wp_nonce_field('modulux_vst_term_save', '_modulux_vst_term_nonce'); // just in case
        });

        add_action('created_product_cat', [$this, 'save_term_meta']);
        add_action('edited_product_cat',  [$this, 'save_term_meta']);
    } 

    public function save_term_meta($term_id) {
        if (isset($_POST[self::TERM_META_KEY]) && isset( $_POST['_modulux_vst_nonce'] ) && wp_verify_nonce( sanitize_text_field(wp_unslash($_POST['_modulux_vst_nonce'])), 'modulux_vst_save' )) {
            $val = sanitize_text_field(wp_unslash($_POST[self::TERM_META_KEY]));
            if ($val === '' || $val === null) {
                delete_term_meta($term_id, self::TERM_META_KEY);
            } else {
                update_term_meta($term_id, self::TERM_META_KEY, $val);
            }
        }
    }

    public function sanitize_options($input) {
        $out = $this->opts;
        $out['enabled']           = empty($input['enabled']) ? 0 : 1;
        $out['strict_mode']       = empty($input['strict_mode']) ? 0 : 1;
        $out['default_threshold'] = isset($input['default_threshold']) && $input['default_threshold'] !== '' ? max(0, (int)$input['default_threshold']) : '';
        $out['availability_tpl']  = isset($input['availability_tpl']) ? wp_kses_post($input['availability_tpl']) : $out['availability_tpl'];
        $out['role_overrides']    = [];
        if (!empty($input['role_overrides']) && is_array($input['role_overrides'])) {
            foreach ($input['role_overrides'] as $role => $val) {
                if ($val === '' || $val === null) continue;
                $out['role_overrides'][$role] = max(0, (int)$val);
            }
        }
        return $out;
    }

    /*public function render_settings() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Visible Stock Threshold', 'modulux-visible-stock-threshold'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('modulux_vst_group');
                do_settings_sections('modulux_vst');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }*/

    public function render_settings() {
        $active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $base   = admin_url('admin.php?page=modulux-vst');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Visible Stock Threshold', 'modulux-visible-stock-threshold'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'general', $base)); ?>"
                class="nav-tab <?php echo $active === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'modulux-visible-stock-threshold'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'about', $base)); ?>"
                class="nav-tab <?php echo $active === 'about' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('About', 'modulux-visible-stock-threshold'); ?>
                </a>
            </h2>

            <?php if ($active === 'about'): ?>

                <div class="postbox modulux-vst-about">
                    <h2 class="hndle"><?php esc_html_e('About Modulux Visible Stock Threshold', 'modulux-visible-stock-threshold'); ?></h2>
                    <div class="inside">
                        <p>
                            <?php esc_html_e('Cap the visible stock and max purchasable quantity using global, category, role, or per-product thresholds. In Strict Mode, products become Out of Stock when real stock is less than or equal to the threshold.', 'modulux-visible-stock-threshold'); ?>
                        </p>

                        <h3><?php esc_html_e('How it works', 'modulux-visible-stock-threshold'); ?></h3>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><?php esc_html_e('If real stock is greater than the threshold, customers see and can buy at most the threshold.', 'modulux-visible-stock-threshold'); ?></li>
                            <li><?php esc_html_e('If Strict Mode is enabled and real stock is less than or equal to the threshold, the product is Out of Stock.', 'modulux-visible-stock-threshold'); ?></li>
                            <li><?php esc_html_e('Priority: Product > Role override > Highest Category threshold > Global default.', 'modulux-visible-stock-threshold'); ?></li>
                        </ul>

                        <h3><?php esc_html_e('Filters (Hooks)', 'modulux-visible-stock-threshold'); ?></h3>
                        <div class="hooks-list">
                            <code>modulux_vst_should_apply( bool $apply, WC_Product $product )</code><br>
                            <code>modulux_vst_resolved_threshold( int|null $resolved, WC_Product $product )</code><br>
                            <code>modulux_vst_out_of_stock( bool $oos, WC_Product $product, ?int $raw, int $threshold, bool $strict )</code><br>
                            <code>modulux_vst_visible_quantity( int $visible, WC_Product $product, ?int $raw, int $threshold, bool $strict )</code><br>
                            <code>modulux_vst_max_purchase_cap( int $cap, WC_Product $product, ?int $raw, int $threshold, bool $strict )</code><br>
                            <code>modulux_vst_availability_text( string $text, int $visible, WC_Product $product, ?int $raw, int $threshold, bool $strict )</code><br>
                            <code>modulux_vst_cart_notice_out_of_stock( string $message, WC_Product $product )</code><br>
                            <code>modulux_vst_cart_notice_cap( string $message, WC_Product $product, int $cap )</code><br>
                        </div>

                        <h3><?php esc_html_e('Example: 3 for guests, 10 for customers', 'modulux-visible-stock-threshold'); ?></h3>
    <pre><code>add_filter('modulux_vst_resolved_threshold', function($resolved, $product){
        if (!is_user_logged_in()) return 3; // guests
        $u = wp_get_current_user();
        if (in_array('customer', (array)$u->roles, true)) {
            return max((int)$resolved, 10);
        }
        return $resolved;
    }, 10, 2);</code></pre>

                        <p>
                            <strong><?php esc_html_e('Availability Text', 'modulux-visible-stock-threshold'); ?>:</strong>
                            <?php esc_html_e('Use the {qty} placeholder, e.g., "Only {qty} left in stock".', 'modulux-visible-stock-threshold'); ?>
                        </p>

                        <p>
                            <a href="https://wordpress.org/support/plugin/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Support', 'modulux-visible-stock-threshold'); ?></a>
                            &nbsp;|&nbsp;
                            <a href="https://modulux.net" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Author', 'modulux-visible-stock-threshold'); ?></a>
                        </p>
                    </div>
                </div>

            <?php else: // general tab ?>

                <form method="post" action="options.php">
                    <?php
                    settings_fields('modulux_vst_group');
                    do_settings_sections('modulux_vst');
                    submit_button();
                    ?>
                </form>

            <?php endif; ?>
        </div>
        <?php
    }        

    /* ---------------------------
     * Frontend logic
     * --------------------------*/
    private function frontend() {
        if (!is_admin()) {
            add_action('init', function(){
                $o = $this->opts;
                if (empty($o['enabled'])) {
                    return; // disabled → no filters
                }

                // Stock status
                add_filter('woocommerce_product_get_stock_status', [$this, 'flt_stock_status'], 10, 2);
                add_filter('woocommerce_product_variation_get_stock_status', [$this, 'flt_stock_status'], 10, 2);

                // Visible quantity
                add_filter('woocommerce_product_get_stock_quantity', [$this, 'flt_stock_qty'], 10, 2);
                add_filter('woocommerce_product_variation_get_stock_quantity', [$this, 'flt_stock_qty'], 10, 2);

                // Max qty input
                add_filter('woocommerce_quantity_input_max', [$this, 'flt_qty_input_max'], 10, 2);

                // Validation
                add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 5);
                add_filter('woocommerce_update_cart_validation', [$this, 'validate_update_cart'], 10, 4);

                // Availability text
                add_filter('woocommerce_get_availability_text', [$this, 'flt_availability_text'], 10, 2);
            });
        }
    }

    /* ---------------------------
     * Threshold resolution order:
     * 1) Per product meta
     * 2) Role override (current user)
     * 3) Highest category threshold among product_cat terms
     * 4) Global default
     * Returns int threshold or null if none set
     * --------------------------*/
    private function resolve_threshold($product) {
        if (!$product) return apply_filters('modulux_vst_resolved_threshold', null, $product);

        // 1) Per product
        $p = (int) $product->get_meta(self::META_KEY, true);
        if ($p > 0 || $p === 0 && $product->meta_exists(self::META_KEY)) {
            return (int) apply_filters('modulux_vst_resolved_threshold', $p, $product);
        }

        // 2) Role override
        $roles = is_user_logged_in() ? (array) wp_get_current_user()->roles : [];
        foreach ($roles as $role) {
            if (isset($this->opts['role_overrides'][$role])) {
                return (int) apply_filters('modulux_vst_resolved_threshold', (int)$this->opts['role_overrides'][$role], $product);
            }
        }

        // 3) Category (take the maximum set among product categories)
        $term_ids = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']);
        if (!is_wp_error($term_ids) && $term_ids) {
            $max = null;
            foreach ($term_ids as $tid) {
                $val = get_term_meta($tid, self::TERM_META_KEY, true);
                if ($val !== '' && $val !== null) {
                    $val = (int) $val;
                    $max = ($max === null) ? $val : max($max, $val);
                }
            }
            if ($max !== null) return (int) apply_filters('modulux_vst_resolved_threshold', $max, $product);
        }

        // 4) Global default
        if ($this->opts['default_threshold'] !== '' && $this->opts['default_threshold'] !== null) {
            return (int) apply_filters('modulux_vst_resolved_threshold', (int)$this->opts['default_threshold'], $product);
        }

        return apply_filters('modulux_vst_resolved_threshold', null, $product);
    }

    /* raw db quantity helper */
    private function get_raw_qty($product) {
        $q = $product->get_stock_quantity('edit'); // raw DB value, avoids recursion
        return ($q === null) ? null : (int) $q;
    }

    /* Stock status filter (strict mode only) */
    public function flt_stock_status($status, $product) {
        if (is_admin() && !wp_doing_ajax()) return $status;

        $threshold = $this->resolve_threshold($product);
        if ($threshold === null) return $status;

        $raw = $this->get_raw_qty($product);
        if ($raw === null) return $status; // unmanaged stock

        // Master gate
        $apply = apply_filters('modulux_vst_should_apply', true, $product);
        if (!$apply) return $status;

        $strict = !empty($this->opts['strict_mode']);
        $oos    = ($strict && $raw <= $threshold);

        // Allow overrides
        $oos = apply_filters('modulux_vst_out_of_stock', $oos, $product, $raw, (int)$threshold, $strict);

        if ($oos) return 'outofstock';
        if ($strict) return 'instock';

        // Non-strict: obey original status (but qty/limits still capped)
        return $status;
    }

    /* Displayed stock quantity */
    public function flt_stock_qty($qty, $product) {
        if (is_admin() && !wp_doing_ajax()) return $qty;

        $threshold = $this->resolve_threshold($product);
        if ($threshold === null || $qty === null) return $qty;

        // Master gate
        $apply = apply_filters('modulux_vst_should_apply', true, $product);
        if (!$apply) return $qty;

        $raw    = (int) $qty; // already raw here
        $strict = !empty($this->opts['strict_mode']);

        if ($strict) {
            $visible = ($raw <= $threshold) ? 0 : $threshold;
        } else {
            $visible = ($raw > $threshold) ? $threshold : $raw;
        }

        // Allow overrides
        $visible = (int) apply_filters('modulux_vst_visible_quantity', $visible, $product, $raw, (int)$threshold, $strict);

        return $visible;
    }

    /* Max quantity input */
    public function flt_qty_input_max($max, $product) {
        if (is_admin() && !wp_doing_ajax()) return $max;

        $threshold = $this->resolve_threshold($product);
        $raw = $this->get_raw_qty($product);
        if ($threshold === null || $raw === null) return $max;

        // Master gate
        $apply = apply_filters('modulux_vst_should_apply', true, $product);
        if (!$apply) return $max;

        $strict = !empty($this->opts['strict_mode']);
        if ($strict && $raw <= $threshold) return 0;

        $cap = $strict ? $threshold : min($raw, $threshold);

        // Allow overrides
        $cap = (int) apply_filters('modulux_vst_max_purchase_cap', $cap, $product, $raw, (int)$threshold, $strict);

        return ($max === '' || (int)$max > $cap) ? $cap : $max;
    }

    /* Add to cart validation */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = []) {
        if (!$passed) return $passed;
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) return $passed;

        $threshold = $this->resolve_threshold($product);
        $raw = $this->get_raw_qty($product);
        if ($threshold === null || $raw === null) return $passed;

        // Master gate
        $apply = apply_filters('modulux_vst_should_apply', true, $product);
        if (!$apply) return $passed;

        $strict = !empty($this->opts['strict_mode']);
        if ($strict && $raw <= $threshold) {
            $notice = __('This product is currently out of stock.', 'modulux-visible-stock-threshold');
            $notice = apply_filters('modulux_vst_cart_notice_out_of_stock', $notice, $product);
            wc_add_notice($notice, 'error');
            return false;
        }

        $cap = $strict ? $threshold : min($raw, $threshold);

        // count already in cart
        $in_cart = 0;
        foreach (WC()->cart->get_cart() as $item) {
            $cid = $item['variation_id'] ?: $item['product_id'];
            if ((int)$cid === (int)($variation_id ?: $product_id)) {
                $in_cart += (int)$item['quantity'];
            }
        }

        if ($in_cart + (int)$quantity > $cap) {
            /* translators: %d: maximum quantity allowed for this product */
            $notice = sprintf(__('You can purchase a maximum of %d for this product.', 'modulux-visible-stock-threshold'), $cap);
            $notice = apply_filters('modulux_vst_cart_notice_cap', $notice, $product, $cap);
            wc_add_notice($notice, 'error');
            return false;
        }

        return $passed;
    }

    /* Update cart validation */
    public function validate_update_cart($passed, $cart_item_key, $values, $quantity) {
        if (!$passed) return $passed;

        $product = $values['data'] ?? null;
        if (!$product) return $passed;

        $threshold = $this->resolve_threshold($product);
        $raw = $this->get_raw_qty($product);
        if ($threshold === null || $raw === null) return $passed;

        // Master gate
        $apply = apply_filters('modulux_vst_should_apply', true, $product);
        if (!$apply) return $passed;

        $strict = !empty($this->opts['strict_mode']);
        if ($strict && $raw <= $threshold) {
            $notice = __('This product is currently out of stock.', 'modulux-visible-stock-threshold');
            $notice = apply_filters('modulux_vst_cart_notice_out_of_stock', $notice, $product);
            wc_add_notice($notice, 'error');
            return false;
        }

        $cap = $strict ? $threshold : min($raw, $threshold);

        // other lines of same product in cart
        $target_id = $product->get_id();
        $other_qty = 0;
        foreach (WC()->cart->get_cart() as $key => $item) {
            if ($key === $cart_item_key) continue;
            $cid = $item['variation_id'] ?: $item['product_id'];
            if ((int)$cid === (int)$target_id) {
                $other_qty += (int)$item['quantity'];
            }
        }

        if ($other_qty + (int)$quantity > $cap) {
            /* translators: %d: maximum quantity allowed for this product */
            $notice = sprintf(__('You can purchase a maximum of %d for this product.', 'modulux-visible-stock-threshold'), $cap);
            $notice = apply_filters('modulux_vst_cart_notice_cap', $notice, $product, $cap);
            wc_add_notice($notice, 'error');
            return false;
        }

        return $passed;
    }

    /* Availability text filter */
    public function flt_availability_text($text, $product) {
        if (is_admin() && !wp_doing_ajax()) return $text;

        $threshold = $this->resolve_threshold($product);
        if ($threshold === null) return $text;

        // Master gate
        $apply = apply_filters('modulux_vst_should_apply', true, $product);
        if (!$apply) return $text;

        $raw = $this->get_raw_qty($product);
        if ($raw === null) return $text;

        // If strict & raw <= threshold => Out of stock text (use WC domain for consistency)
        if (!empty($this->opts['strict_mode']) && $raw <= $threshold) {
            return __('Out of stock', 'modulux-visible-stock-threshold');
        }

        // Visible qty:
        $strict  = !empty($this->opts['strict_mode']);
        $visible = $strict ? $threshold : (($raw > $threshold) ? $threshold : $raw);

        $tpl = $this->opts['availability_tpl'] ?: __( 'Only {qty} left in stock', 'modulux-visible-stock-threshold' );
        if (strpos($tpl, '{qty}') === false) {
            $tpl .= ' ({qty})';
        }

        $msg = str_replace('{qty}', (string)$visible, wp_kses_post($tpl));

        // Allow overrides
        return (string) apply_filters('modulux_vst_availability_text', $msg, (int)$visible, $product, $raw, (int)$threshold, $strict);
    }
}

// Boot
Modulux_VST::instance();
