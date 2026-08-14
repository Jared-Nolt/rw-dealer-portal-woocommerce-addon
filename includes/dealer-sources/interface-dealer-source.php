<?php
/**
 * Contract every dealer source adapter implements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface RWDPWA_Dealer_Source {

	/**
	 * Return every candidate dealer with a resolvable location and at least one email.
	 *
	 * @return array<int, array{lat: float, lng: float, emails: array<string>, label: string}>
	 */
	public function get_candidates();
}
