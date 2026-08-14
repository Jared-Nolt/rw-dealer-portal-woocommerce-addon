<?php
/**
 * PEWC (Product Add-ons) accordion display, ported from jsn-woocommerce-overrides.
 * No-ops harmlessly if PEWC isn't active. Gated by `wc_overrides.pewc_accordion`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rwdpwa_register_pewc_integration() {
	if ( ! rwdpwa_wc_override_enabled( 'pewc_accordion' ) ) {
		return;
	}

	add_filter( 'pewc_group_display', 'rwdpwa_pewc_group_display' );
	add_filter( 'pewc_filter_initial_accordion_states', 'rwdpwa_pewc_initial_accordion_state', 10, 2 );
}

/**
 * @return string
 */
function rwdpwa_pewc_group_display() {
	return 'accordion';
}

/**
 * @param string $state   Current state.
 * @param int    $post_id Product ID.
 * @return string
 */
function rwdpwa_pewc_initial_accordion_state( $state, $post_id ) {
	return 'closed';
}
