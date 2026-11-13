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
		// The free label only applies when prices are shown.
		if ( rwdpwa_wc_override_enabled( 'free_label' ) ) {
			add_filter( 'woocommerce_get_price_html', 'rwdpwa_free_price_html', 20, 2 );
			add_filter( 'woocommerce_cart_item_price', 'rwdpwa_free_cart_item_price', 20, 2 );
			add_filter( 'woocommerce_cart_item_subtotal', 'rwdpwa_free_cart_item_price', 20, 2 );
			add_filter( 'woocommerce_order_formatted_line_subtotal', 'rwdpwa_free_order_line_subtotal', 20, 2 );
		}
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
 * Markup for the free label.
 *
 * @return string
 */
function rwdpwa_free_label_html() {
	return '<span class="rwdpwa-free-price">' . esc_html( rwdpwa_get_free_text() ) . '</span>';
}

/**
 * Whether a product is priced at exactly zero. Variable products count only
 * when every variation is zero; an empty price (not purchasable) never counts.
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function rwdpwa_is_free_product( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	if ( $product->is_type( 'variable' ) ) {
		$min = $product->get_variation_price( 'min' );
		$max = $product->get_variation_price( 'max' );
		return '' !== $min && 0.0 === (float) $min && 0.0 === (float) $max;
	}

	$price = $product->get_price();
	return '' !== $price && 0.0 === (float) $price;
}

/**
 * Replace a $0.00 product price with the free label.
 *
 * @param string     $price_html Price HTML.
 * @param WC_Product $product    Product.
 * @return string
 */
function rwdpwa_free_price_html( $price_html, $product ) {
	return rwdpwa_is_free_product( $product ) ? rwdpwa_free_label_html() : $price_html;
}

/**
 * Replace a $0.00 cart item price/subtotal with the free label.
 *
 * @param string $price_html Price HTML.
 * @param array  $cart_item  Cart item data.
 * @return string
 */
function rwdpwa_free_cart_item_price( $price_html, $cart_item ) {
	$product = $cart_item['data'] ?? null;
	return rwdpwa_is_free_product( $product ) ? rwdpwa_free_label_html() : $price_html;
}

/**
 * Replace a $0.00 order line (thank-you page, My Account, emails) with the
 * free label. Only lines that were free before any discount count.
 *
 * @param string        $subtotal Formatted line subtotal.
 * @param WC_Order_Item $item     Order line item.
 * @return string
 */
function rwdpwa_free_order_line_subtotal( $subtotal, $item ) {
	if ( $item instanceof WC_Order_Item_Product && 0.0 === (float) $item->get_subtotal() && 0.0 === (float) $item->get_total() ) {
		return rwdpwa_free_label_html();
	}
	return $subtotal;
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
