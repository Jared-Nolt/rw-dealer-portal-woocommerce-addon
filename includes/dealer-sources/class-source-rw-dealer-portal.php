<?php
/**
 * Dealer source: RW Dealer Portal's `rw_dealer` custom post type.
 *
 * Reads that plugin's own post type and `_rwdp_*` meta directly (RW Dealer
 * Portal exposes no custom PHP hooks to depend on instead) — this is a
 * read-only relationship, identical to what the original sample code did.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWDPWA_Source_RW_Dealer_Portal implements RWDPWA_Dealer_Source {

	/**
	 * @return array<int, array{lat: float, lng: float, emails: array<string>, label: string}>
	 */
	public function get_candidates() {
		$dealer_ids = get_posts( array(
			'post_type'      => 'rw_dealer',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_rwdp_lat',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_rwdp_lng',
					'compare' => 'EXISTS',
				),
			),
		) );

		$candidates = array();

		foreach ( $dealer_ids as $dealer_id ) {
			$lat = (float) get_post_meta( $dealer_id, '_rwdp_lat', true );
			$lng = (float) get_post_meta( $dealer_id, '_rwdp_lng', true );
			if ( ! $lat || ! $lng ) {
				continue;
			}

			$valid_flag = get_post_meta( $dealer_id, '_rwdp_address_valid', true );
			if ( '1' !== (string) $valid_flag ) {
				continue;
			}

			$emails = rwdpwa_split_emails( get_post_meta( $dealer_id, '_rwdp_contact_emails', true ) );
			if ( empty( $emails ) ) {
				continue;
			}

			$candidates[] = array(
				'lat'    => $lat,
				'lng'    => $lng,
				'emails' => $emails,
				'label'  => get_the_title( $dealer_id ),
			);
		}

		return $candidates;
	}
}
