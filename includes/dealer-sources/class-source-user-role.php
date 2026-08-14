<?php
/**
 * Dealer source: WordPress users carrying a site-configured role.
 *
 * Email comes from either the user's account email or a named user meta key;
 * location comes from configured lat/lng meta if set, otherwise the cached
 * `_rwdpwa_lat`/`_rwdpwa_lng` user meta populated by the geocode-on-save hook
 * in includes/geocoding.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWDPWA_Source_User_Role implements RWDPWA_Dealer_Source {

	/**
	 * @return array<int, array{lat: float, lng: float, emails: array<string>, label: string}>
	 */
	public function get_candidates() {
		$settings = rwdpwa_get_settings();
		$config   = $settings['user_role'];

		if ( empty( $config['role'] ) ) {
			return array();
		}

		$users = get_users( array(
			'role'   => $config['role'],
			'fields' => array( 'ID', 'display_name', 'user_email' ),
		) );

		$candidates = array();

		foreach ( $users as $user ) {
			$coords = rwdpwa_get_object_coordinates( 'user', $user->ID, $config['lat_meta'], $config['lng_meta'] );
			if ( ! $coords ) {
				continue;
			}

			if ( empty( $config['email_source'] ) || 'user_email' === $config['email_source'] ) {
				$emails = rwdpwa_split_emails( $user->user_email );
			} else {
				$emails = rwdpwa_split_emails( get_user_meta( $user->ID, $config['email_source'], true ) );
			}

			if ( empty( $emails ) ) {
				continue;
			}

			$candidates[] = array(
				'lat'    => $coords['lat'],
				'lng'    => $coords['lng'],
				'emails' => $emails,
				'label'  => $user->display_name,
			);
		}

		return $candidates;
	}
}
