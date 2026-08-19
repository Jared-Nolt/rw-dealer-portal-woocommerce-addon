<?php
/**
 * Sends a separate notification email to the single nearest dealer (if one
 * is within the configured radius) whenever WooCommerce is about to send
 * its native "New Order" admin email — which itself continues going,
 * untouched, to whatever recipient is configured on WooCommerce > Settings
 * > Emails > New Order. Each email carries its own configurable message.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rwdpwa_register_order_routing() {
	add_filter( 'woocommerce_email_recipient_new_order', 'rwdpwa_trigger_dealer_notification', 10, 2 );
	add_action( 'woocommerce_email_order_meta', 'rwdpwa_render_email_custom_message', 20, 4 );
}

/**
 * Hooked to `woocommerce_email_recipient_new_order` purely as a reliable
 * signal that WooCommerce is actually about to send its native New Order
 * email (this filter only ever runs from inside that send flow, after
 * WooCommerce's own enabled/resend guards have already passed). The
 * recipient itself is left untouched; as a side effect, this fires a
 * separate notification to the nearest dealer when one is within radius.
 *
 * @param string   $recipient Configured recipient string.
 * @param WC_Order $order     WooCommerce order object.
 * @return string
 */
function rwdpwa_trigger_dealer_notification( $recipient, $order ) {
	if ( ! is_object( $order ) ) {
		return $recipient;
	}

	$dealer = rwdpwa_get_nearest_dealer_for_order( $order );
	if ( $dealer ) {
		rwdpwa_send_dealer_notification_email( $order, $dealer );
	}

	return $recipient;
}

/**
 * Resolve the single nearest dealer to the order address, if within the
 * configured radius.
 *
 * @param WC_Order $order WooCommerce order object.
 * @return array{label: string, emails: array<string>, distance: float}|null
 */
function rwdpwa_get_nearest_dealer_for_order( $order ) {
	$coords = rwdpwa_get_order_coordinates( $order );
	if ( empty( $coords ) ) {
		return null;
	}

	$radius     = rwdpwa_get_radius_miles();
	$source     = RWDPWA_Source_Factory::get_active_source();
	$candidates = $source->get_candidates();

	$nearest = null;
	foreach ( $candidates as $candidate ) {
		$distance = rwdpwa_haversine_distance( $coords['lat'], $coords['lng'], $candidate['lat'], $candidate['lng'] );
		if ( null === $nearest || $distance < $nearest['distance'] ) {
			$nearest = array(
				'label'    => $candidate['label'],
				'emails'   => $candidate['emails'],
				'distance' => $distance,
			);
		}
	}

	if ( null === $nearest || $nearest['distance'] > $radius ) {
		return null;
	}

	return $nearest;
}

/**
 * Build and send a standalone email to the nearest dealer, reusing
 * WooCommerce's own New Order email template/styling so it looks
 * consistent with the native admin email, just addressed differently and
 * carrying its own configured message (via rwdpwa_render_email_custom_message()).
 *
 * @param WC_Order $order  WooCommerce order object.
 * @param array    $dealer {label, emails, distance} as returned by rwdpwa_get_nearest_dealer_for_order().
 * @return bool Whether the email was sent.
 */
function rwdpwa_send_dealer_notification_email( $order, $dealer ) {
	if ( $order->get_meta( '_rwdpwa_dealer_notified' ) ) {
		return false;
	}

	$to = implode( ', ', array_filter( array_map( 'sanitize_email', $dealer['emails'] ), 'is_email' ) );
	if ( ! $to ) {
		return false;
	}

	$emails = WC()->mailer()->get_emails();
	if ( empty( $emails['WC_Email_New_Order'] ) ) {
		return false;
	}
	$new_order_email = $emails['WC_Email_New_Order'];

	$html = wc_get_template_html(
		'emails/admin-new-order.php',
		array(
			'order'              => $order,
			'email_heading'      => $new_order_email->get_heading(),
			'additional_content' => $new_order_email->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => false,
			'email'              => $new_order_email,
		)
	);

	$sent = $new_order_email->send(
		$to,
		$new_order_email->get_subject(),
		$html,
		$new_order_email->get_headers(),
		$new_order_email->get_attachments()
	);

	if ( $sent ) {
		$order->update_meta_data( '_rwdpwa_dealer_notified', $dealer['label'] );
		$order->save_meta_data();
	}

	return $sent;
}

/**
 * Replace the {dealer_name}, {dealer_email}, and {dealer_distance} tokens in
 * a configured email message with the matched dealer's actual info. If no
 * dealer was matched for this order, tokens are replaced with an empty
 * string rather than left in place.
 *
 * @param string     $message Raw message, possibly containing tokens.
 * @param array|null $dealer  {label, emails, distance} from rwdpwa_get_nearest_dealer_for_order(), or null.
 * @return string
 */
function rwdpwa_replace_email_message_tokens( $message, $dealer ) {
	$replacements = array(
		'{dealer_name}'     => $dealer ? $dealer['label'] : '',
		'{dealer_email}'    => $dealer ? implode( ', ', $dealer['emails'] ) : '',
		'{dealer_distance}' => $dealer ? number_format_i18n( $dealer['distance'], 1 ) : '',
	);

	return strtr( (string) $message, $replacements );
}

/**
 * Inject each email's own configured custom message into the New Order
 * email body — the admin/configured-recipient's copy gets the "admin
 * message" (plus a short note naming the nearest dealer, if one was
 * notified separately); the dealer's own separate copy gets the "dealer
 * message" instead. Distinguished via $sent_to_admin, which is only ever
 * false for our own dealer-copy render (see rwdpwa_send_dealer_notification_email())
 * — every real WooCommerce-native render of the `new_order` email type
 * always passes sent_to_admin = true, so there's no collision with other
 * WooCommerce emails sharing this same hook.
 *
 * @param WC_Order $order         WooCommerce order object.
 * @param bool     $sent_to_admin Whether this render is the admin's copy.
 * @param bool     $plain_text    Whether this is the plain-text email variant.
 * @param WC_Email $email         The email instance rendering this template.
 */
function rwdpwa_render_email_custom_message( $order, $sent_to_admin, $plain_text, $email ) {
	if ( ! is_a( $email, 'WC_Email' ) || 'new_order' !== $email->id ) {
		return;
	}

	$dealer = rwdpwa_get_nearest_dealer_for_order( $order );
	$note   = '';

	if ( $sent_to_admin ) {
		$message = rwdpwa_replace_email_message_tokens( rwdpwa_get_admin_email_message(), $dealer );
		if ( $dealer ) {
			$note = sprintf(
				/* translators: 1: dealer name, 2: dealer email address(es) */
				__( 'This order was also sent separately to the nearest dealer: %1$s (%2$s).', 'rw-dealer-portal-woocommerce-addon' ),
				$dealer['label'],
				implode( ', ', $dealer['emails'] )
			);
		}
	} else {
		$message = rwdpwa_replace_email_message_tokens( rwdpwa_get_dealer_email_message(), $dealer );
	}

	$message = trim( (string) $message );
	if ( '' === $message && '' === $note ) {
		return;
	}

	if ( $plain_text ) {
		if ( '' !== $message ) {
			echo esc_html( $message ) . "\n\n";
		}
		if ( '' !== $note ) {
			echo esc_html( $note ) . "\n\n";
		}
		return;
	}
	?>
	<?php if ( '' !== $message ) : ?>
		<p style="margin-bottom: 16px;"><?php echo nl2br( esc_html( $message ) ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $note ) : ?>
		<p style="margin-bottom: 16px; font-style: italic; color: #636363;"><?php echo esc_html( $note ); ?></p>
	<?php endif; ?>
	<?php
}
