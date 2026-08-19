<?php
/**
 * Settings storage: a single `rwdpwa_settings` option, with defaults,
 * sanitization, and small getter helpers used throughout the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default settings shape. Every other function treats this as the source of truth for keys.
 *
 * @return array
 */
function rwdpwa_get_default_settings() {
	return array(
		'dealer_source'       => 'rw_dealer_portal', // 'rw_dealer_portal' | 'custom_post_type' | 'user_role'
		'radius_miles'        => 50,
		'google_maps_api_key' => '',
		'custom_post_type'    => array(
			'post_type'    => '',
			'lat_meta'     => '',
			'lng_meta'     => '',
			'address_meta' => '',
			'email_meta'   => '',
			'active_meta'  => '',
			'active_value' => '1',
		),
		'user_role'           => array(
			'role'         => '',
			'lat_meta'     => '',
			'lng_meta'     => '',
			'address_meta' => '',
			'email_source' => 'user_email', // 'user_email' | a user meta key
		),
		'wc_overrides'        => array(
			'text_overrides' => true,
			'custom_text'    => 'quote request',
			'hide_prices'    => true,
			'pewc_accordion' => true,
		),
		'email_messages'      => array(
			'admin_message'  => '',
			'dealer_message' => '',
		),
	);
}

/**
 * Recursively merge saved values over defaults, keeping the default shape intact
 * even if the saved option is missing keys (e.g. after an upgrade adds new fields).
 *
 * @param array $defaults  Default values.
 * @param mixed $overrides Saved values.
 * @return array
 */
function rwdpwa_merge_settings_recursive( $defaults, $overrides ) {
	if ( ! is_array( $overrides ) ) {
		return $defaults;
	}

	foreach ( $defaults as $key => $default_value ) {
		if ( ! array_key_exists( $key, $overrides ) ) {
			continue;
		}

		if ( is_array( $default_value ) ) {
			$defaults[ $key ] = rwdpwa_merge_settings_recursive( $default_value, $overrides[ $key ] );
		} else {
			$defaults[ $key ] = $overrides[ $key ];
		}
	}

	return $defaults;
}

/**
 * Get the current settings, merged over defaults.
 *
 * Falls back to the plugin's own pre-rename option (`rwdpcr_settings`, saved
 * back when this plugin was named "RW Dealer Portal Checkout Routing") when
 * no `rwdpwa_settings` option has been saved yet, and further back to the
 * original `rwdp_cr_radius_miles` option for just the radius if neither newer
 * option exists — so a site that was already configured under either older
 * name doesn't lose its settings across the rename.
 *
 * @return array
 */
function rwdpwa_get_settings() {
	$defaults = rwdpwa_get_default_settings();
	$saved    = get_option( 'rwdpwa_settings', array() );

	if ( empty( $saved ) ) {
		$saved = get_option( 'rwdpcr_settings', array() );
	}

	if ( empty( $saved ) ) {
		$legacy_radius = get_option( 'rwdp_cr_radius_miles' );
		if ( false !== $legacy_radius ) {
			$defaults['radius_miles'] = absint( $legacy_radius );
		}
	}

	return rwdpwa_merge_settings_recursive( $defaults, $saved );
}

/**
 * Sanitize callback for the `rwdpwa_settings` option.
 *
 * @param mixed $input Raw posted value.
 * @return array
 */
function rwdpwa_sanitize_settings( $input ) {
	$defaults = rwdpwa_get_default_settings();
	$input    = is_array( $input ) ? $input : array();

	$clean = array();

	$clean['dealer_source'] = in_array( $input['dealer_source'] ?? '', array( 'rw_dealer_portal', 'custom_post_type', 'user_role' ), true )
		? $input['dealer_source']
		: $defaults['dealer_source'];

	$radius                 = absint( $input['radius_miles'] ?? 0 );
	$clean['radius_miles']  = $radius > 0 ? $radius : $defaults['radius_miles'];
	$clean['google_maps_api_key'] = sanitize_text_field( $input['google_maps_api_key'] ?? '' );

	$cpt                       = is_array( $input['custom_post_type'] ?? null ) ? $input['custom_post_type'] : array();
	$clean['custom_post_type'] = array(
		'post_type'    => sanitize_key( $cpt['post_type'] ?? '' ),
		'lat_meta'     => sanitize_text_field( $cpt['lat_meta'] ?? '' ),
		'lng_meta'     => sanitize_text_field( $cpt['lng_meta'] ?? '' ),
		'address_meta' => sanitize_text_field( $cpt['address_meta'] ?? '' ),
		'email_meta'   => sanitize_text_field( $cpt['email_meta'] ?? '' ),
		'active_meta'  => sanitize_text_field( $cpt['active_meta'] ?? '' ),
		'active_value' => sanitize_text_field( $cpt['active_value'] ?? '1' ),
	);

	$role                = is_array( $input['user_role'] ?? null ) ? $input['user_role'] : array();
	$clean['user_role']  = array(
		'role'         => sanitize_key( $role['role'] ?? '' ),
		'lat_meta'     => sanitize_text_field( $role['lat_meta'] ?? '' ),
		'lng_meta'     => sanitize_text_field( $role['lng_meta'] ?? '' ),
		'address_meta' => sanitize_text_field( $role['address_meta'] ?? '' ),
		'email_source' => sanitize_text_field( $role['email_source'] ?? 'user_email' ),
	);

	$overrides         = is_array( $input['wc_overrides'] ?? null ) ? $input['wc_overrides'] : array();
	$custom_text       = sanitize_text_field( $overrides['custom_text'] ?? '' );
	$clean['wc_overrides'] = array(
		'text_overrides' => ! empty( $overrides['text_overrides'] ),
		'custom_text'    => '' !== $custom_text ? $custom_text : $defaults['wc_overrides']['custom_text'],
		'hide_prices'    => ! empty( $overrides['hide_prices'] ),
		'pewc_accordion' => ! empty( $overrides['pewc_accordion'] ),
	);

	$messages               = is_array( $input['email_messages'] ?? null ) ? $input['email_messages'] : array();
	$clean['email_messages'] = array(
		'admin_message'  => sanitize_textarea_field( $messages['admin_message'] ?? '' ),
		'dealer_message' => sanitize_textarea_field( $messages['dealer_message'] ?? '' ),
	);

	return $clean;
}

/**
 * @return int
 */
function rwdpwa_get_radius_miles() {
	$settings = rwdpwa_get_settings();
	return absint( $settings['radius_miles'] );
}

/**
 * @return string One of 'rw_dealer_portal', 'custom_post_type', 'user_role'.
 */
function rwdpwa_get_dealer_source() {
	$settings = rwdpwa_get_settings();
	return $settings['dealer_source'];
}

/**
 * @param string $key One of 'text_overrides', 'hide_prices', 'pewc_accordion'.
 * @return bool
 */
function rwdpwa_wc_override_enabled( $key ) {
	$settings = rwdpwa_get_settings();
	return ! empty( $settings['wc_overrides'][ $key ] );
}

/**
 * The custom replacement term used by the text overrides (e.g. "quote request").
 *
 * @return string
 */
function rwdpwa_get_custom_text() {
	$settings = rwdpwa_get_settings();
	$text     = trim( (string) $settings['wc_overrides']['custom_text'] );
	return '' !== $text ? $text : 'quote request';
}

/**
 * Custom message shown in the configured recipient's copy of the New Order email.
 *
 * @return string
 */
function rwdpwa_get_admin_email_message() {
	$settings = rwdpwa_get_settings();
	return trim( (string) $settings['email_messages']['admin_message'] );
}

/**
 * Custom message shown in the nearest dealer's separate notification email.
 *
 * @return string
 */
function rwdpwa_get_dealer_email_message() {
	$settings = rwdpwa_get_settings();
	return trim( (string) $settings['email_messages']['dealer_message'] );
}

/**
 * Resolve the Google Maps API key to use for geocoding: this plugin's own
 * setting first, falling back to RW Dealer Portal's key when that plugin is
 * active and no override has been set here.
 *
 * @return string
 */
function rwdpwa_get_google_maps_api_key() {
	$settings = rwdpwa_get_settings();
	if ( ! empty( $settings['google_maps_api_key'] ) ) {
		return $settings['google_maps_api_key'];
	}

	if ( defined( 'RWDP_VERSION' ) ) {
		$rwdp_settings = get_option( 'rwdp_settings', array() );
		if ( ! empty( $rwdp_settings['google_maps_server_key'] ) ) {
			return $rwdp_settings['google_maps_server_key'];
		}
		if ( ! empty( $rwdp_settings['google_maps_api_key'] ) ) {
			return $rwdp_settings['google_maps_api_key'];
		}
	}

	return '';
}
