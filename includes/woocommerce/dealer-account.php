<?php
/**
 * Dealer-facing account helpers for RW Dealer Portal stores:
 * - pre-fill empty checkout fields from the user's linked dealer record
 *   (`store_access.prefill_checkout`);
 * - a simplified My Account: Orders, Addresses, Dealer Portal, Log out
 *   (`store_access.simple_account`);
 * - [rwdpwa_store_links]: store page, cart (with a live item count) and
 *   My Orders links for portal pages.
 * The settings are off by default; the shortcode is inert until placed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the dealer account hooks.
 */
function rwdpwa_register_dealer_account() {
	$settings = rwdpwa_get_settings();
	$access   = $settings['store_access'];

	add_shortcode( 'rwdpwa_store_links', 'rwdpwa_store_links_shortcode' );
	add_filter( 'woocommerce_add_to_cart_fragments', 'rwdpwa_store_links_cart_fragment' );

	if ( ! empty( $access['prefill_checkout'] ) ) {
		add_filter( 'woocommerce_checkout_get_value', 'rwdpwa_prefill_checkout_value', 10, 2 );
	}

	if ( ! empty( $access['simple_account'] ) ) {
		add_filter( 'woocommerce_account_menu_items', 'rwdpwa_simple_account_menu', 100 );
		add_filter( 'woocommerce_get_endpoint_url', 'rwdpwa_simple_account_portal_url', 10, 2 );
		add_action( 'template_redirect', 'rwdpwa_simple_account_redirect_dashboard', 20 );
	}
}

/**
 * The first RW Dealer Portal dealer linked to a user, or 0.
 *
 * @param int $user_id User ID.
 * @return int
 */
function rwdpwa_get_user_dealer_id( $user_id ) {
	if ( function_exists( 'rwdpa_get_user_dealer_ids' ) ) {
		$ids = (array) rwdpa_get_user_dealer_ids( $user_id );
	} else {
		$ids = (array) get_user_meta( $user_id, '_rwdp_dealer_ids', true );
	}

	foreach ( array_map( 'absint', $ids ) as $id ) {
		if ( $id && 'rw_dealer' === get_post_type( $id ) ) {
			return $id;
		}
	}
	return 0;
}

/**
 * Checkout values taken from the user and their dealer record.
 *
 * @param int $user_id User ID.
 * @return array<string, string> Checkout field key => value.
 */
function rwdpwa_dealer_checkout_values( $user_id ) {
	static $cache = array();
	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$user   = get_userdata( $user_id );
	$values = array();
	if ( ! $user ) {
		return $cache[ $user_id ] = $values;
	}

	$values['billing_first_name'] = (string) $user->first_name;
	$values['billing_last_name']  = (string) $user->last_name;
	$values['billing_email']      = (string) $user->user_email;
	$values['billing_phone']      = (string) get_user_meta( $user_id, '_rwdpa_phone', true );

	$dealer_id = rwdpwa_get_user_dealer_id( $user_id );
	if ( $dealer_id ) {
		$state = strtoupper( trim( (string) get_post_meta( $dealer_id, '_rwdp_state', true ) ) );
		$values += array(
			'billing_company'   => html_entity_decode( get_the_title( $dealer_id ), ENT_QUOTES, 'UTF-8' ),
			'billing_address_1' => (string) get_post_meta( $dealer_id, '_rwdp_address', true ),
			'billing_city'      => (string) get_post_meta( $dealer_id, '_rwdp_city', true ),
			'billing_state'     => 2 === strlen( $state ) ? $state : '',
			'billing_postcode'  => (string) get_post_meta( $dealer_id, '_rwdp_zip', true ),
		);
		if ( '' === $values['billing_phone'] ) {
			$values['billing_phone'] = (string) get_post_meta( $dealer_id, '_rwdp_phone', true );
		}
		if ( $values['billing_address_1'] && $values['billing_state'] ) {
			$values['billing_country'] = 'US';
		}
	}

	// Ship to the same address unless the dealer changes it.
	foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'city', 'state', 'postcode', 'country', 'phone' ) as $field ) {
		if ( isset( $values[ 'billing_' . $field ] ) ) {
			$values[ 'shipping_' . $field ] = $values[ 'billing_' . $field ];
		}
	}

	/**
	 * Filters the checkout values pre-filled for a portal user.
	 *
	 * @param array $values    Checkout field key => value.
	 * @param int   $user_id   User ID.
	 * @param int   $dealer_id Linked dealer post ID (0 if none).
	 */
	$values = apply_filters( 'rwdpwa_dealer_checkout_values', array_filter( array_map( 'trim', $values ), 'strlen' ), $user_id, $dealer_id );

	return $cache[ $user_id ] = $values;
}

/**
 * Fill an empty checkout field from the dealer record. Values the customer
 * already saved (from an earlier order or My Account) always win.
 *
 * @param mixed  $value Value from earlier filters (null = not set).
 * @param string $input Checkout field key.
 * @return mixed
 */
function rwdpwa_prefill_checkout_value( $value, $input ) {
	if ( null !== $value || ! is_user_logged_in() ) {
		return $value;
	}

	$customer = function_exists( 'WC' ) ? WC()->customer : null;
	$getter   = 'get_' . $input;
	if ( $customer && is_callable( array( $customer, $getter ) ) && '' !== (string) $customer->{$getter}() ) {
		// A saved value; but replace the default country/state with the dealer's.
		if ( ! in_array( $input, array( 'billing_country', 'billing_state', 'shipping_country', 'shipping_state' ), true ) || $customer->get_billing_address_1() ) {
			return $value;
		}
	}

	$values = rwdpwa_dealer_checkout_values( get_current_user_id() );
	return $values[ $input ] ?? $value;
}

/**
 * Orders, Addresses, Dealer Portal and Log out only.
 *
 * @param array $items Menu endpoint => label.
 * @return array
 */
function rwdpwa_simple_account_menu( $items ) {
	$keep = array();
	foreach ( array( 'orders', 'edit-address' ) as $key ) {
		if ( isset( $items[ $key ] ) ) {
			$keep[ $key ] = $items[ $key ];
		}
	}
	if ( function_exists( 'rwdp_get_page_id' ) && rwdp_get_page_id( 'dashboard' ) ) {
		$keep['rwdpwa-portal'] = __( 'Dealer Portal', 'rw-dealer-portal-woocommerce-addon' );
	}
	if ( isset( $items['customer-logout'] ) ) {
		$keep['customer-logout'] = $items['customer-logout'];
	}
	return $keep;
}

/**
 * The "Dealer Portal" menu item links to the portal dashboard.
 *
 * @param string $url      Endpoint URL.
 * @param string $endpoint Endpoint key.
 * @return string
 */
function rwdpwa_simple_account_portal_url( $url, $endpoint ) {
	if ( 'rwdpwa-portal' === $endpoint && function_exists( 'rwdp_get_page_url' ) ) {
		return rwdp_get_page_url( 'dashboard' );
	}
	return $url;
}

/**
 * Open My Account on the Orders list instead of the dashboard.
 */
function rwdpwa_simple_account_redirect_dashboard() {
	if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() || is_wc_endpoint_url() ) {
		return;
	}
	wp_safe_redirect( wc_get_account_endpoint_url( 'orders' ) );
	exit;
}

/**
 * Wording for the cart link.
 *
 * @return string
 */
function rwdpwa_store_links_cart_label() {
	/* translators: %s: "quote request" or "cart" */
	return sprintf( __( 'View %s', 'rw-dealer-portal-woocommerce-addon' ), rwdpwa_wc_override_enabled( 'text_overrides' ) ? rwdpwa_get_custom_text() : __( 'cart', 'rw-dealer-portal-woocommerce-addon' ) );
}

/**
 * Cart count badge markup (also used as a cart fragment).
 *
 * @return string
 */
function rwdpwa_store_links_count_html() {
	$count = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
	return '<span class="rwdpwa-store-links__count" data-count="' . esc_attr( $count ) . '">' . esc_html( number_format_i18n( $count ) ) . '</span>';
}

/**
 * Keep the count current after AJAX add-to-cart / cart changes.
 *
 * @param array $fragments Selector => HTML.
 * @return array
 */
function rwdpwa_store_links_cart_fragment( $fragments ) {
	$fragments['span.rwdpwa-store-links__count'] = rwdpwa_store_links_count_html();
	return $fragments;
}

/**
 * [rwdpwa_store_links show="store,cart,orders" store_text="" orders_text=""]
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function rwdpwa_store_links_shortcode( $atts = array() ) {
	if ( ! function_exists( 'wc_get_cart_url' ) || ! is_user_logged_in() ) {
		return '';
	}
	if ( rwdpwa_members_only_store_enabled() && function_exists( 'rwdpwa_current_user_can_shop' ) && ! rwdpwa_current_user_can_shop() ) {
		return '';
	}

	$atts = shortcode_atts( array(
		'show'        => 'store,cart,orders',
		'store_text'  => __( 'Shop', 'rw-dealer-portal-woocommerce-addon' ),
		'cart_text'   => rwdpwa_store_links_cart_label(),
		'orders_text' => __( 'My Orders', 'rw-dealer-portal-woocommerce-addon' ),
	), $atts, 'rwdpwa_store_links' );

	$show  = array_map( 'trim', explode( ',', strtolower( $atts['show'] ) ) );
	$links = array();

	$store_id = rwdpwa_get_store_page_id();
	if ( in_array( 'store', $show, true ) && $store_id && get_queried_object_id() !== $store_id ) {
		$links[] = sprintf( '<a class="rwdpwa-store-links__link" href="%s">%s</a>', esc_url( get_permalink( $store_id ) ), esc_html( $atts['store_text'] ) );
	}
	if ( in_array( 'cart', $show, true ) ) {
		$links[] = sprintf( '<a class="rwdpwa-store-links__link rwdpwa-store-links__cart" href="%s">%s %s</a>', esc_url( wc_get_cart_url() ), esc_html( $atts['cart_text'] ), rwdpwa_store_links_count_html() );
	}
	if ( in_array( 'orders', $show, true ) && wc_get_page_id( 'myaccount' ) > 0 ) {
		$links[] = sprintf( '<a class="rwdpwa-store-links__link" href="%s">%s</a>', esc_url( wc_get_account_endpoint_url( 'orders' ) ), esc_html( $atts['orders_text'] ) );
	}

	if ( ! $links ) {
		return '';
	}

	wp_enqueue_style( 'rwdpwa-product-list' );
	wp_enqueue_script( 'wc-cart-fragments' );

	return '<nav class="rwdpwa-store-links" aria-label="' . esc_attr__( 'Store', 'rw-dealer-portal-woocommerce-addon' ) . '">' . implode( '', $links ) . '</nav>';
}
