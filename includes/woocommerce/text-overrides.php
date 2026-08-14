<?php
/**
 * "Order" -> custom text wording overrides (e.g. "Quote Request"), ported
 * from jsn-woocommerce-overrides. Gated by the `wc_overrides.text_overrides`
 * setting; the replacement term itself comes from `wc_overrides.custom_text`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rwdpwa_register_text_overrides() {
	if ( ! rwdpwa_wc_override_enabled( 'text_overrides' ) ) {
		return;
	}

	add_filter( 'woocommerce_product_single_add_to_cart_text', 'rwdpwa_single_add_to_cart_text' );
	add_filter( 'woocommerce_product_add_to_cart_text', 'rwdpwa_add_to_cart_text', 20, 2 );
	add_filter( 'woocommerce_product_single_add_to_cart_text', 'rwdpwa_add_to_cart_text', 20, 2 );
	add_filter( 'gettext', 'rwdpwa_gettext_overrides', 20, 3 );
	add_filter( 'ngettext', 'rwdpwa_gettext_overrides', 20, 3 );
	add_filter( 'wc_add_to_cart_message_html', 'rwdpwa_add_to_cart_message_html', 10, 3 );
}

/**
 * @return string
 */
function rwdpwa_single_add_to_cart_text() {
	return 'Add to ' . rwdpwa_get_custom_text();
}

/**
 * @param string     $text    Original button text.
 * @param WC_Product $product Product object.
 * @return string
 */
function rwdpwa_add_to_cart_text( $text, $product ) {
	if ( in_array( $text, array( 'Add to cart', 'Select options', 'Read more', 'Out of stock' ), true ) ) {
		return 'Add to ' . rwdpwa_get_custom_text();
	}

	return $text;
}

/**
 * Consolidated "Order" -> custom text string table, scoped to the WooCommerce
 * text domain so it never touches unrelated strings.
 *
 * @param string $translated_text Translated text.
 * @param string $text            Original text.
 * @param string $domain          Text domain.
 * @return string
 */
function rwdpwa_gettext_overrides( $translated_text, $text, $domain ) {
	if ( 'woocommerce' !== $domain ) {
		return $translated_text;
	}

	$custom       = rwdpwa_get_custom_text();
	$custom_title = ucwords( $custom );
	$first_word   = ucfirst( strtok( $custom, ' ' ) );

	$overrides = array(
		'Checkout'                                       => 'Order Summary',
		'View cart'                                       => 'View ' . $custom,
		'Proceed to checkout'                             => 'Proceed to Order Summary',
		'has been added to your cart.'                    => 'has been added to your ' . $custom . '.',
		'Place order'                                      => 'Place ' . $custom,
		'Order details'                                    => $first_word . ' details',
		'Thank you. Your order has been received.'         => 'Thank you. Your ' . $custom . ' has been received.',
		'Billing address'                                  => 'Address',
		'Order'                                            => $custom_title,
		'New order'                                         => 'New ' . $custom,
		'You’ve received the following order from %s:'     => 'You’ve received the following ' . $custom . ' from %s:',
		'You’ve received a new order from %s:'              => 'You’ve received a new ' . $custom . ' from %s:',
		'Just to let you know — we’ve received your order, and it is now being processed.' => 'Just to let you know — we’ve received your ' . $custom . ', and it is now being processed.',
		'Here’s a reminder of what you’ve ordered:'         => 'Here’s a reminder of what you had on the ' . $custom . ':',
	);

	return $overrides[ $text ] ?? $translated_text;
}

/**
 * Rewrite the "added to cart" flash message to reference the custom text.
 * Ported from businessbloomer.com/woocommerce-customization.
 *
 * @param string $message  Original message HTML.
 * @param mixed  $products Product ID, or array of product_id => qty.
 * @param bool   $show_qty Whether to show quantities.
 * @return string
 */
function rwdpwa_add_to_cart_message_html( $message, $products = array(), $show_qty = false ) {
	$titles = array();

	if ( ! is_array( $products ) ) {
		$products = array( $products => 1 );
		$show_qty = false;
	}

	if ( ! $show_qty ) {
		$products = array_fill_keys( array_keys( $products ), 1 );
	}

	foreach ( $products as $product_id => $qty ) {
		$titles[] = apply_filters( 'woocommerce_add_to_cart_qty_html', ( $qty > 1 ? absint( $qty ) . ' &times; ' : '' ), $product_id )
			. apply_filters( 'woocommerce_add_to_cart_item_name_in_quotes', sprintf( _x( '&ldquo;%s&rdquo;', 'Item name in quotes', 'woocommerce' ), strip_tags( get_the_title( $product_id ) ) ), $product_id );
	}

	$titles     = array_filter( $titles );
	$added_text = sprintf( ' %s is added to your %s.', wc_format_list_of_items( $titles ), rwdpwa_get_custom_text() );

	$wp_button_class = wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '';

	if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
		$return_to = apply_filters( 'woocommerce_continue_shopping_redirect', wc_get_raw_referer() ? wp_validate_redirect( wc_get_raw_referer(), false ) : wc_get_page_permalink( 'shop' ) );
		return sprintf( '%s <a href="%s" class="button wc-forward%s">%s</a>', esc_html( $added_text ), esc_url( $return_to ), esc_attr( $wp_button_class ), esc_html__( 'Continue shopping', 'woocommerce' ) );
	}

	return sprintf( '%s <a href="%s" class="button wc-forward%s">%s</a>', esc_html( $added_text ), esc_url( wc_get_cart_url() ), esc_attr( $wp_button_class ), esc_html__( 'View cart', 'woocommerce' ) );
}
