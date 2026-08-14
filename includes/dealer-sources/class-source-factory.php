<?php
/**
 * Returns the dealer source adapter matching the current settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWDPWA_Source_Factory {

	/**
	 * @return RWDPWA_Dealer_Source
	 */
	public static function get_active_source() {
		switch ( rwdpwa_get_dealer_source() ) {
			case 'custom_post_type':
				return new RWDPWA_Source_Custom_Post_Type();
			case 'user_role':
				return new RWDPWA_Source_User_Role();
			case 'rw_dealer_portal':
			default:
				return new RWDPWA_Source_RW_Dealer_Portal();
		}
	}
}
