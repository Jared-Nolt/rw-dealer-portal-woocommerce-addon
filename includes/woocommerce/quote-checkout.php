<?php
/**
 * Quote checkout: customers go all the way through checkout, but no payment
 * is taken online. Orders are placed "On hold" for the office, which adds
 * shipping, sends an invoice and collects payment outside the site.
 * Gated by the `quote_checkout.enabled` setting; requires the classic
 * (shortcode) checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the quote checkout hooks when the feature is on.
 */
function rwdpwa_register_quote_checkout() {
	if ( ! rwdpwa_quote_checkout_enabled() ) {
		return;
	}

	$quote = rwdpwa_get_quote_settings();

	add_filter( 'woocommerce_payment_gateways', 'rwdpwa_add_quote_gateway' );
	add_filter( 'woocommerce_available_payment_gateways', 'rwdpwa_only_quote_gateway', 100 );
	// Send every order, including $0 ones, through the quote gateway so they all land "On hold".
	add_filter( 'woocommerce_cart_needs_payment', '__return_true', 100 );
	add_filter( 'woocommerce_order_needs_payment', 'rwdpwa_quote_order_needs_payment', 100, 2 );
	add_filter( 'woocommerce_get_order_item_totals', 'rwdpwa_quote_order_item_totals', 20, 2 );

	if ( ! empty( $quote['defer_shipping'] ) ) {
		// No shipping methods or rates at checkout, but still collect the ship-to address.
		add_filter( 'woocommerce_cart_needs_shipping', '__return_false', 100 );
		add_filter( 'woocommerce_cart_needs_shipping_address', 'rwdpwa_quote_needs_shipping_address', 100 );
		add_action( 'woocommerce_cart_totals_before_order_total', 'rwdpwa_quote_render_shipping_note_row' );
		add_action( 'woocommerce_review_order_before_order_total', 'rwdpwa_quote_render_shipping_note_row' );
	}

	if ( ! empty( $quote['po_field'] ) ) {
		add_filter( 'woocommerce_checkout_fields', 'rwdpwa_quote_add_po_field' );
		add_action( 'woocommerce_checkout_create_order', 'rwdpwa_quote_save_po_number', 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', 'rwdpwa_quote_admin_po_number' );
	}
}

/**
 * @param array $gateways Gateway class names.
 * @return array
 */
function rwdpwa_add_quote_gateway( $gateways ) {
	require_once RWDPWA_PLUGIN_DIR . 'includes/woocommerce/class-quote-gateway.php';
	$gateways[] = 'RWDPWA_Quote_Gateway';
	return $gateways;
}

/**
 * The quote gateway is the only way to check out while the feature is on.
 *
 * @param array $gateways Available gateways keyed by ID.
 * @return array
 */
function rwdpwa_only_quote_gateway( $gateways ) {
	if ( isset( $gateways['rwdpwa_quote'] ) ) {
		return array( 'rwdpwa_quote' => $gateways['rwdpwa_quote'] );
	}
	return $gateways;
}

/**
 * Quote orders never ask the customer to pay on the site (e.g. no "Pay"
 * button in My Account), even after the office adds shipping.
 *
 * @param bool     $needs_payment Whether the order needs payment.
 * @param WC_Order $order         Order.
 * @return bool
 */
function rwdpwa_quote_order_needs_payment( $needs_payment, $order ) {
	if ( $order instanceof WC_Order && 'rwdpwa_quote' === $order->get_payment_method() ) {
		return false;
	}
	return $needs_payment;
}

/**
 * Keep the ship-to address fields at checkout while shipping rates are off.
 *
 * @return bool
 */
function rwdpwa_quote_needs_shipping_address() {
	return function_exists( 'wc_ship_to_billing_address_only' ) ? ! wc_ship_to_billing_address_only() : true;
}

/**
 * "Shipping: added by the office" row in the cart and checkout totals.
 */
function rwdpwa_quote_render_shipping_note_row() {
	$note = trim( (string) rwdpwa_get_quote_settings()['shipping_note'] );
	if ( '' === $note ) {
		return;
	}
	?>
	<tr class="rwdpwa-shipping-note">
		<th><?php esc_html_e( 'Shipping', 'woocommerce' ); ?></th>
		<td data-title="<?php esc_attr_e( 'Shipping', 'woocommerce' ); ?>"><?php echo esc_html( $note ); ?></td>
	</tr>
	<?php
}

/**
 * Add the PO number and shipping note to the order totals shown on the
 * thank-you page, My Account → View order, and order emails.
 *
 * @param array    $rows  Totals rows.
 * @param WC_Order $order Order.
 * @return array
 */
function rwdpwa_quote_order_item_totals( $rows, $order ) {
	if ( ! $order instanceof WC_Order || 'rwdpwa_quote' !== $order->get_payment_method() ) {
		return $rows;
	}

	$quote = rwdpwa_get_quote_settings();
	$extra = array();

	$po = (string) $order->get_meta( '_rwdpwa_po_number' );
	if ( '' !== $po ) {
		$extra['rwdpwa_po_number'] = array(
			'label' => $quote['po_label'] . ':',
			'value' => esc_html( $po ),
		);
	}

	$note = trim( (string) $quote['shipping_note'] );
	if ( ! empty( $quote['defer_shipping'] ) && '' !== $note && ! $order->get_shipping_methods() ) {
		$extra['rwdpwa_shipping_note'] = array(
			'label' => __( 'Shipping:', 'woocommerce' ),
			'value' => esc_html( $note ),
		);
	}

	if ( ! $extra ) {
		return $rows;
	}

	// Show the extra rows just before the total.
	$out = array();
	foreach ( $rows as $key => $row ) {
		if ( 'order_total' === $key ) {
			$out += $extra;
			$extra = array();
		}
		$out[ $key ] = $row;
	}
	return $out + $extra;
}

/**
 * @param array $fields Checkout fields.
 * @return array
 */
function rwdpwa_quote_add_po_field( $fields ) {
	$quote = rwdpwa_get_quote_settings();

	$fields['order'] = array(
		'rwdpwa_po_number' => array(
			'type'        => 'text',
			'label'       => $quote['po_label'],
			'required'    => ! empty( $quote['po_required'] ),
			'class'       => array( 'form-row-wide' ),
			'priority'    => 5,
			'maxlength'   => 60,
			'autocomplete' => 'off',
		),
	) + ( $fields['order'] ?? array() );

	return $fields;
}

/**
 * @param WC_Order $order Order being created.
 * @param array    $data  Posted checkout data.
 */
function rwdpwa_quote_save_po_number( $order, $data ) {
	$po = isset( $data['rwdpwa_po_number'] ) ? sanitize_text_field( wp_unslash( $data['rwdpwa_po_number'] ) ) : '';
	if ( '' !== $po ) {
		$order->update_meta_data( '_rwdpwa_po_number', $po );
	}
}

/**
 * @param WC_Order $order Order shown on the admin edit screen.
 */
function rwdpwa_quote_admin_po_number( $order ) {
	$po = (string) $order->get_meta( '_rwdpwa_po_number' );
	if ( '' === $po ) {
		return;
	}
	printf(
		'<p class="rwdpwa-po-number"><strong>%1$s:</strong> %2$s</p>',
		esc_html( rwdpwa_get_quote_settings()['po_label'] ),
		esc_html( $po )
	);
}
