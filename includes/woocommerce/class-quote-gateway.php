<?php
/**
 * "Submit to office" payment method used by quote checkout. Takes no
 * payment: the order is placed On hold for the office to review.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWDPWA_Quote_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$quote = rwdpwa_get_quote_settings();

		$this->id                 = 'rwdpwa_quote';
		$this->has_fields         = false;
		$this->method_title       = __( 'Quote checkout (no payment)', 'rw-dealer-portal-woocommerce-addon' );
		$this->method_description = __( 'Added by RW Dealer Portal WooCommerce Addon. Orders are placed On hold and paid outside the site. Configure it under WooCommerce → Dealer Routing.', 'rw-dealer-portal-woocommerce-addon' );
		$this->title              = $quote['title'];
		$this->description        = $quote['description'];
		$this->enabled            = 'yes';
		$this->supports           = array( 'products' );
	}

	/**
	 * Settings live on the add-on's own page, not in WooCommerce → Payments.
	 */
	public function init_form_fields() {
		$this->form_fields = array();
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		return rwdpwa_quote_checkout_enabled();
	}

	/**
	 * Place the order On hold without taking payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$order->update_status( 'on-hold', __( 'Submitted through quote checkout. No payment was taken online.', 'rw-dealer-portal-woocommerce-addon' ) );

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
