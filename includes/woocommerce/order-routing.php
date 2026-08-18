<?php
/**
 * Routes the WooCommerce "New Order" admin notification to nearby dealer
 * contact emails, using whichever dealer source adapter is configured.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rwdpwa_register_order_routing() {
	add_filter( 'woocommerce_email_recipient_new_order', 'rwdpwa_route_new_order_email', 10, 2 );
}

/**
 * Route the WooCommerce new-order email recipient to nearby dealer emails,
 * in addition to whatever recipient(s) are configured on WooCommerce >
 * Settings > Emails > New Order.
 *
 * @param string   $recipient Configured recipient string.
 * @param WC_Order $order     WooCommerce order object.
 * @return string
 */
function rwdpwa_route_new_order_email( $recipient, $order ) {
	if ( ! is_object( $order ) ) {
		return $recipient;
	}

	$dealer_emails = rwdpwa_get_dealer_recipients_for_order( $order );
	if ( empty( $dealer_emails ) ) {
		return $recipient;
	}

	$configured_emails = array_filter( array_map( 'trim', explode( ',', (string) $recipient ) ), 'is_email' );

	return implode( ', ', array_unique( array_merge( $configured_emails, $dealer_emails ) ) );
}

/**
 * Resolve all dealer emails that are within the configured radius for the order address.
 *
 * @param WC_Order $order WooCommerce order object.
 * @return array<string>
 */
function rwdpwa_get_dealer_recipients_for_order( $order ) {
	$coords = rwdpwa_get_order_coordinates( $order );
	if ( empty( $coords ) ) {
		return array();
	}

	$radius     = rwdpwa_get_radius_miles();
	$source     = RWDPWA_Source_Factory::get_active_source();
	$candidates = $source->get_candidates();

	$recipients = array();
	foreach ( $candidates as $candidate ) {
		$distance = rwdpwa_haversine_distance( $coords['lat'], $coords['lng'], $candidate['lat'], $candidate['lng'] );
		if ( $distance > $radius ) {
			continue;
		}

		foreach ( $candidate['emails'] as $email ) {
			$recipients[] = $email;
		}
	}

	return array_values( array_unique( $recipients ) );
}
