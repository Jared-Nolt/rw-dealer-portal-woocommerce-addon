<?php
/**
 * Plugin Name: RW Dealer Portal WooCommerce Addon
 * Description: Routes WooCommerce order emails to nearby dealers based on the checkout address and a configurable radius. Dealers can come from RW Dealer Portal, a custom post type, or WordPress users with a chosen role. Also includes optional WooCommerce text/pricing overrides.
 * Version: 1.1.11
 * Author: Rosewood Marketing
 * License: GPLv2 or later
 * Requires Plugins: woocommerce
 * Text Domain: rw-dealer-portal-woocommerce-addon
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'RWDPWA_VERSION' ) ) {
	define( 'RWDPWA_VERSION', '1.1.11' );
}
define( 'RWDPWA_PLUGIN_FILE', __FILE__ );
define( 'RWDPWA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RWDPWA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, 'rwdpwa_activate' );
add_action( 'plugins_loaded', 'rwdpwa_bootstrap' );

/**
 * Set default options on activation, migrating settings saved under either
 * older plugin name (`rwdpcr_settings`, or the original `rwdp_cr_radius_miles`
 * radius-only option) if present.
 */
function rwdpwa_activate() {
	if ( false !== get_option( 'rwdpwa_settings', false ) ) {
		return;
	}

	require_once RWDPWA_PLUGIN_DIR . 'includes/settings.php';
	$defaults = rwdpwa_get_default_settings();

	$legacy_settings = get_option( 'rwdpcr_settings', array() );
	if ( ! empty( $legacy_settings ) ) {
		$defaults = rwdpwa_merge_settings_recursive( $defaults, $legacy_settings );
	} else {
		$legacy_radius = get_option( 'rwdp_cr_radius_miles' );
		if ( false !== $legacy_radius ) {
			$defaults['radius_miles'] = absint( $legacy_radius );
		}
	}

	add_option( 'rwdpwa_settings', $defaults );
}

/**
 * Bootstrap the add-on once WooCommerce is available.
 */
function rwdpwa_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
		return;
	}

	require_once RWDPWA_PLUGIN_DIR . 'includes/settings.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/geocoding.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/dealer-sources/interface-dealer-source.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/dealer-sources/class-source-rw-dealer-portal.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/dealer-sources/class-source-custom-post-type.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/dealer-sources/class-source-user-role.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/dealer-sources/class-source-factory.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/woocommerce/order-routing.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/woocommerce/text-overrides.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/woocommerce/price-display.php';
	require_once RWDPWA_PLUGIN_DIR . 'includes/woocommerce/pewc-integration.php';

	rwdpwa_register_order_routing();
	rwdpwa_register_text_overrides();
	rwdpwa_register_price_display();
	rwdpwa_register_pewc_integration();
	rwdpwa_register_geocode_cache_hooks();

	if ( is_admin() ) {
		require_once RWDPWA_PLUGIN_DIR . 'includes/admin-page.php';
		rwdpwa_register_admin_page();
	}
}
