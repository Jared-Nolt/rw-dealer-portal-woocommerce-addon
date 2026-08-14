<?php
/**
 * Price/subtotal/total/payment-method hiding, ported from jsn-woocommerce-overrides.
 * Gated by the `wc_overrides.hide_prices` setting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rwdpwa_register_price_display() {
	if ( ! rwdpwa_wc_override_enabled( 'hide_prices' ) ) {
		return;
	}

	add_filter( 'woocommerce_get_price_html', 'rwdpwa_hide_all_prices' );
	add_filter( 'woocommerce_cart_item_price', 'rwdpwa_hide_all_prices' );
	add_filter( 'woocommerce_cart_item_subtotal', 'rwdpwa_hide_all_prices' );
	add_filter( 'woocommerce_order_formatted_line_subtotal', 'rwdpwa_hide_all_prices' );
	add_filter( 'woocommerce_email_styles', 'rwdpwa_add_email_styles_to_hide_price_column', 99 );
	add_filter( 'woocommerce_get_order_item_totals', 'rwdpwa_remove_order_totals_rows', 99 );
	add_action( 'wp_head', 'rwdpwa_hide_elements_with_css' );
}

/**
 * @return string
 */
function rwdpwa_hide_all_prices() {
	return '';
}

/**
 * Hide the "Price" column in WooCommerce email item tables via CSS, since the
 * table headers aren't otherwise filterable with PHP.
 *
 * @param string $css Original email CSS.
 * @return string
 */
function rwdpwa_add_email_styles_to_hide_price_column( $css ) {
	$css .= '
		.td:nth-child(3), .th:nth-child(3) {
			display: none !important;
		}
	';
	return $css;
}

/**
 * Remove Subtotal, Total, and Payment Method rows from order totals tables
 * (Thank You page, My Account -> View Order, and customer emails).
 *
 * @param array $total_rows Totals rows.
 * @return array
 */
function rwdpwa_remove_order_totals_rows( $total_rows ) {
	unset( $total_rows['cart_subtotal'] );
	unset( $total_rows['order_total'] );
	unset( $total_rows['payment_method'] );
	return $total_rows;
}

/**
 * CSS-only hiding of totals on the cart page and payment method selection on
 * checkout (keeps the "Place Order" button visible).
 */
function rwdpwa_hide_elements_with_css() {
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		echo '<style type="text/css">tr.cart-subtotal, tr.order-total { display: none !important; }</style>';
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
		echo '<style type="text/css">#payment .payment_methods { display: none !important; }</style>';
	}
}
