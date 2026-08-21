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
	add_action( 'rwdpwa_send_dealer_notification', 'rwdpwa_handle_scheduled_dealer_notification' );
}

/**
 * Hooked to `woocommerce_email_recipient_new_order` purely as a reliable
 * signal that WooCommerce is actually about to send its native New Order
 * email (this filter only ever runs from inside that send flow, after
 * WooCommerce's own enabled/resend guards have already passed). The
 * recipient itself is left untouched; as a side effect, this schedules a
 * separate notification to the nearest dealer (when one is within radius)
 * to run moments later in the background, rather than building and sending
 * that whole extra email synchronously inline with the checkout request.
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
	if ( $dealer && rwdpwa_claim_dealer_notification( $order->get_id() ) ) {
		$args = array( $order->get_id() );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), 'rwdpwa_send_dealer_notification', $args );
		} else {
			wp_schedule_single_event( time(), 'rwdpwa_send_dealer_notification', $args );
		}
	}

	return $recipient;
}

/**
 * Atomically claim the right to notify this order's dealer, so a burst of
 * near-simultaneous requests for the same order (e.g. checkout retries
 * hitting multiple PHP-FPM workers at once) can only ever result in ONE
 * notification being scheduled, no matter how many arrive at the same time.
 *
 * Uses a plain `wp_options` row rather than order meta because
 * `option_name` carries a real database UNIQUE KEY constraint, making the
 * claim atomic at the database level — order meta (postmeta or WooCommerce's
 * HPOS order-meta table) does not guarantee that, since checking "already
 * claimed?" and writing the claim are separate steps there, leaving a race
 * window under concurrent requests.
 *
 * @param int $order_id Order ID.
 * @return bool True if this call won the claim (proceed to schedule); false if another request already claimed it.
 */
function rwdpwa_claim_dealer_notification( $order_id ) {
	global $wpdb;

	$inserted = $wpdb->query( $wpdb->prepare(
		"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')",
		'rwdpwa_dealer_notified_' . absint( $order_id )
	) );

	return 1 === (int) $inserted;
}

/**
 * Scheduled-action handler: re-resolves the nearest dealer (fresh — a
 * scheduled action runs in its own request, so nothing from the checkout
 * request's per-request cache carries over) and sends its notification email.
 *
 * @param int $order_id Order ID.
 */
function rwdpwa_handle_scheduled_dealer_notification( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$dealer = rwdpwa_get_nearest_dealer_for_order( $order );
	if ( $dealer ) {
		rwdpwa_send_dealer_notification_email( $order, $dealer );
	}
}

/**
 * Resolve the single nearest dealer to the order address, if within the
 * configured radius. Cached per order ID for the lifetime of the current
 * request, since this plugin computes it more than once per checkout (once
 * to decide whether to schedule a dealer notification, again when the
 * admin's own email content is built) and each lookup costs a live geocode
 * HTTP call.
 *
 * @param WC_Order $order WooCommerce order object.
 * @return array{label: string, emails: array<string>, distance: float}|null
 */
function rwdpwa_get_nearest_dealer_for_order( $order ) {
	static $cache = array();

	$order_id = $order->get_id();
	if ( array_key_exists( $order_id, $cache ) ) {
		return $cache[ $order_id ];
	}

	$coords = rwdpwa_get_order_coordinates( $order );
	if ( empty( $coords ) ) {
		return $cache[ $order_id ] = null;
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
		return $cache[ $order_id ] = null;
	}

	return $cache[ $order_id ] = $nearest;
}

/**
 * Build and send a standalone email to the nearest dealer, reusing
 * WooCommerce's own New Order email template/styling so it looks
 * consistent with the native admin email, just addressed differently and
 * carrying its own configured message (via rwdpwa_render_email_custom_message()).
 *
 * Duplicate-send prevention is layered:
 * - rwdpwa_claim_dealer_notification() (at scheduling time) is the real
 *   guard against a BURST of near-simultaneous requests for the same order
 *   — an atomic DB-level claim, immune to concurrency.
 * - The `_rwdpwa_dealer_notified` order-meta check below is a second,
 *   sequential-only safety net: it only matters if this function somehow
 *   runs a second time for the same order minutes apart (e.g. Action
 *   Scheduler reclaiming a stalled action after a PHP timeout/OOM killed
 *   a prior attempt mid-send) — not a concurrency guard by itself, since a
 *   check-then-set gap of microseconds within one execution is irrelevant
 *   once the scheduling-time claim has already ruled out concurrent starts.
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

	// Required before get_subject()/get_heading()/get_additional_content() below:
	// those substitute placeholders like {order_number} using this instance's
	// own $object property, which a freshly-fetched instance (this runs in its
	// own request, via the scheduled action) has never had set to our order.
	$new_order_email->set_object( $order );

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
