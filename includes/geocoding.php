<?php
/**
 * Address building, geocoding, distance calculation, and the generic
 * geocode-and-cache flow used by the Custom Post Type / User Role dealer
 * sources (RW Dealer Portal maintains its own lat/lng, so it doesn't need this).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a geocodable address string from the order shipping or billing address.
 *
 * @param WC_Order $order WooCommerce order object.
 * @return string
 */
function rwdpwa_get_order_address_string( $order ) {
	$address_fields = array();

	if ( method_exists( $order, 'has_shipping_address' ) && $order->has_shipping_address() ) {
		$address_fields[] = $order->get_shipping_address_1();
		$address_fields[] = $order->get_shipping_address_2();
		$address_fields[] = $order->get_shipping_city();
		$address_fields[] = $order->get_shipping_state();
		$address_fields[] = $order->get_shipping_postcode();
		$address_fields[] = $order->get_shipping_country();
	} else {
		$address_fields[] = $order->get_billing_address_1();
		$address_fields[] = $order->get_billing_address_2();
		$address_fields[] = $order->get_billing_city();
		$address_fields[] = $order->get_billing_state();
		$address_fields[] = $order->get_billing_postcode();
		$address_fields[] = $order->get_billing_country();
	}

	$address_fields = array_filter( array_map( 'trim', $address_fields ) );
	return implode( ', ', $address_fields );
}

/**
 * Geocode the order's address.
 *
 * @param WC_Order $order WooCommerce order object.
 * @return array{lat:float,lng:float}|false
 */
function rwdpwa_get_order_coordinates( $order ) {
	return rwdpwa_geocode_address_preferred( rwdpwa_get_order_address_string( $order ) );
}

/**
 * Geocode an address, preferring RW Dealer Portal's own geocoder when that
 * plugin is active and selected as the dealer source (keeps behavior/quota
 * usage identical to core in that case), falling back to this plugin's own
 * Google Maps call otherwise.
 *
 * @param string $address Address string.
 * @return array{lat:float,lng:float}|false
 */
function rwdpwa_geocode_address_preferred( $address ) {
	if ( empty( $address ) ) {
		return false;
	}

	if ( 'rw_dealer_portal' === rwdpwa_get_dealer_source() && function_exists( 'rwdp_geocode_address' ) ) {
		$result = rwdp_geocode_address( $address );
	} else {
		$result = rwdpwa_geocode_address( $address );
	}

	if ( empty( $result['lat'] ) || empty( $result['lng'] ) ) {
		return false;
	}

	return array(
		'lat' => (float) $result['lat'],
		'lng' => (float) $result['lng'],
	);
}

/**
 * Geocode an address using the Google Maps Geocoding API.
 *
 * @param string $address Address string.
 * @return array{lat:float,lng:float}|false
 */
function rwdpwa_geocode_address( $address ) {
	$api_key = rwdpwa_get_google_maps_api_key();
	if ( empty( $api_key ) || empty( $address ) ) {
		return false;
	}

	$url = add_query_arg( array(
		'address' => $address,
		'key'     => $api_key,
	), 'https://maps.googleapis.com/maps/api/geocode/json' );

	// Kept short since an order-address geocode can run synchronously during
	// checkout (see includes/woocommerce/order-routing.php) — this bounds the
	// worst case rather than letting a slow API response stall the customer.
	$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
	if ( is_wp_error( $response ) ) {
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['results'][0]['geometry']['location'] ) ) {
		return false;
	}

	$location = $body['results'][0]['geometry']['location'];
	return array(
		'lat' => (float) $location['lat'],
		'lng' => (float) $location['lng'],
	);
}

/**
 * Calculate the great-circle distance between two coordinates in miles.
 *
 * @param float $lat1 Latitude of point 1.
 * @param float $lng1 Longitude of point 1.
 * @param float $lat2 Latitude of point 2.
 * @param float $lng2 Longitude of point 2.
 * @return float
 */
function rwdpwa_haversine_distance( $lat1, $lng1, $lat2, $lng2 ) {
	$earth_radius_miles = 3958.8;
	$lat1_rad           = deg2rad( $lat1 );
	$lat2_rad           = deg2rad( $lat2 );
	$delta_lat          = deg2rad( $lat2 - $lat1 );
	$delta_lng          = deg2rad( $lng2 - $lng1 );

	$a = sin( $delta_lat / 2 ) * sin( $delta_lat / 2 ) + cos( $lat1_rad ) * cos( $lat2_rad ) * sin( $delta_lng / 2 ) * sin( $delta_lng / 2 );
	$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

	return $earth_radius_miles * $c;
}

/**
 * Split a comma/semicolon separated string of emails into a clean, validated list.
 *
 * @param mixed $raw Raw meta value.
 * @return array<string>
 */
function rwdpwa_split_emails( $raw ) {
	if ( empty( $raw ) ) {
		return array();
	}

	$parts  = array_filter( array_map( 'trim', preg_split( '/[;,]/', (string) $raw ) ) );
	$emails = array();
	foreach ( $parts as $part ) {
		$email = sanitize_email( $part );
		if ( is_email( $email ) ) {
			$emails[] = $email;
		}
	}

	return $emails;
}

/**
 * Resolve lat/lng for a post or user: either from directly-configured meta
 * keys (already-geocoded data), or from this plugin's own cached
 * `_rwdpwa_lat`/`_rwdpwa_lng` meta populated by the geocode-on-save hooks below.
 *
 * @param string $object_type  'post' or 'user'.
 * @param int    $object_id    Post or user ID.
 * @param string $lat_meta_key Configured lat meta key, or '' to use the cache.
 * @param string $lng_meta_key Configured lng meta key, or '' to use the cache.
 * @return array{lat:float,lng:float}|false
 */
function rwdpwa_get_object_coordinates( $object_type, $object_id, $lat_meta_key, $lng_meta_key ) {
	$is_user = ( 'user' === $object_type );

	if ( ! empty( $lat_meta_key ) && ! empty( $lng_meta_key ) ) {
		$lat = $is_user ? get_user_meta( $object_id, $lat_meta_key, true ) : get_post_meta( $object_id, $lat_meta_key, true );
		$lng = $is_user ? get_user_meta( $object_id, $lng_meta_key, true ) : get_post_meta( $object_id, $lng_meta_key, true );
	} else {
		$lat = $is_user ? get_user_meta( $object_id, '_rwdpwa_lat', true ) : get_post_meta( $object_id, '_rwdpwa_lat', true );
		$lng = $is_user ? get_user_meta( $object_id, '_rwdpwa_lng', true ) : get_post_meta( $object_id, '_rwdpwa_lng', true );
	}

	$lat = (float) $lat;
	$lng = (float) $lng;

	if ( ! $lat || ! $lng ) {
		return false;
	}

	return array(
		'lat' => $lat,
		'lng' => $lng,
	);
}

/**
 * Geocode an address and cache the result on a post or user, but only when the
 * address has actually changed since the last geocode (tracked via a hash),
 * and only invalidate previously-good coordinates on a definitive failure —
 * mirrors RW Dealer Portal core's own change-detection approach so this
 * doesn't burn API quota per save or hide a dealer over a transient error.
 *
 * @param string $object_type 'post' or 'user'.
 * @param int    $object_id   Post or user ID.
 * @param string $address     Composed address string to geocode.
 */
function rwdpwa_maybe_geocode_and_cache( $object_type, $object_id, $address ) {
	$is_user = ( 'user' === $object_type );
	$address = trim( (string) $address );
	$hash    = $address ? md5( $address ) : '';

	$existing_hash = $is_user ? get_user_meta( $object_id, '_rwdpwa_geo_address_hash', true ) : get_post_meta( $object_id, '_rwdpwa_geo_address_hash', true );
	if ( $hash === $existing_hash ) {
		return;
	}

	if ( empty( $address ) ) {
		if ( $is_user ) {
			update_user_meta( $object_id, '_rwdpwa_geo_valid', '0' );
			update_user_meta( $object_id, '_rwdpwa_geo_address_hash', '' );
		} else {
			update_post_meta( $object_id, '_rwdpwa_geo_valid', '0' );
			update_post_meta( $object_id, '_rwdpwa_geo_address_hash', '' );
		}
		return;
	}

	$result = rwdpwa_geocode_address( $address );

	if ( empty( $result['lat'] ) || empty( $result['lng'] ) ) {
		$has_existing_coords = $is_user ? get_user_meta( $object_id, '_rwdpwa_lat', true ) : get_post_meta( $object_id, '_rwdpwa_lat', true );
		if ( ! $has_existing_coords ) {
			if ( $is_user ) {
				update_user_meta( $object_id, '_rwdpwa_geo_valid', '0' );
			} else {
				update_post_meta( $object_id, '_rwdpwa_geo_valid', '0' );
			}
		}
		return;
	}

	if ( $is_user ) {
		update_user_meta( $object_id, '_rwdpwa_lat', (float) $result['lat'] );
		update_user_meta( $object_id, '_rwdpwa_lng', (float) $result['lng'] );
		update_user_meta( $object_id, '_rwdpwa_geo_valid', '1' );
		update_user_meta( $object_id, '_rwdpwa_geo_address_hash', $hash );
	} else {
		update_post_meta( $object_id, '_rwdpwa_lat', (float) $result['lat'] );
		update_post_meta( $object_id, '_rwdpwa_lng', (float) $result['lng'] );
		update_post_meta( $object_id, '_rwdpwa_geo_valid', '1' );
		update_post_meta( $object_id, '_rwdpwa_geo_address_hash', $hash );
	}
}

/**
 * Register save-time geocode-and-cache hooks for the currently configured
 * Custom Post Type / User Role source, but only when it's set up with an
 * address field rather than already-geocoded lat/lng meta.
 */
function rwdpwa_register_geocode_cache_hooks() {
	$settings = rwdpwa_get_settings();

	if ( 'custom_post_type' === $settings['dealer_source'] ) {
		$config = $settings['custom_post_type'];
		if ( ! empty( $config['post_type'] ) && ! empty( $config['address_meta'] ) && ( empty( $config['lat_meta'] ) || empty( $config['lng_meta'] ) ) ) {
			add_action( 'save_post_' . $config['post_type'], 'rwdpwa_geocode_cache_on_post_save', 20 );
		}
	}

	if ( 'user_role' === $settings['dealer_source'] ) {
		$config = $settings['user_role'];
		if ( ! empty( $config['address_meta'] ) && ( empty( $config['lat_meta'] ) || empty( $config['lng_meta'] ) ) ) {
			add_action( 'profile_update', 'rwdpwa_geocode_cache_on_user_save' );
			add_action( 'user_register', 'rwdpwa_geocode_cache_on_user_save' );
		}
	}
}

/**
 * @param int $post_id Post ID being saved.
 */
function rwdpwa_geocode_cache_on_post_save( $post_id ) {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	$settings = rwdpwa_get_settings();
	$address  = get_post_meta( $post_id, $settings['custom_post_type']['address_meta'], true );
	rwdpwa_maybe_geocode_and_cache( 'post', $post_id, $address );
}

/**
 * @param int $user_id User ID being saved.
 */
function rwdpwa_geocode_cache_on_user_save( $user_id ) {
	$settings = rwdpwa_get_settings();
	$address  = get_user_meta( $user_id, $settings['user_role']['address_meta'], true );
	rwdpwa_maybe_geocode_and_cache( 'user', $user_id, $address );
}
