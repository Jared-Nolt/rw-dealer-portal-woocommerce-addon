<?php
/**
 * Dealer source: a site-configured custom post type.
 *
 * Meta key names (lat/lng, address, email, active flag) are all set on the
 * settings page. If lat/lng meta keys are configured, they're read directly
 * (already-geocoded data). Otherwise this relies on the cached
 * `_rwdpwa_lat`/`_rwdpwa_lng` meta populated by the geocode-on-save hook in
 * includes/geocoding.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWDPWA_Source_Custom_Post_Type implements RWDPWA_Dealer_Source {

	/**
	 * @return array<int, array{lat: float, lng: float, emails: array<string>, label: string}>
	 */
	public function get_candidates() {
		$settings = rwdpwa_get_settings();
		$config   = $settings['custom_post_type'];

		if ( empty( $config['post_type'] ) || ! post_type_exists( $config['post_type'] ) ) {
			return array();
		}

		$query_args = array(
			'post_type'      => $config['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		if ( ! empty( $config['active_meta'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => $config['active_meta'],
					'value' => $config['active_value'],
				),
			);
		}

		$post_ids   = get_posts( $query_args );
		$candidates = array();

		foreach ( $post_ids as $post_id ) {
			$coords = rwdpwa_get_object_coordinates( 'post', $post_id, $config['lat_meta'], $config['lng_meta'] );
			if ( ! $coords ) {
				continue;
			}

			$emails = empty( $config['email_meta'] ) ? array() : rwdpwa_split_emails( get_post_meta( $post_id, $config['email_meta'], true ) );
			if ( empty( $emails ) ) {
				continue;
			}

			$candidates[] = array(
				'lat'    => $coords['lat'],
				'lng'    => $coords['lng'],
				'emails' => $emails,
				'label'  => get_the_title( $post_id ),
			);
		}

		return $candidates;
	}
}
