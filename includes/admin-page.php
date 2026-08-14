<?php
/**
 * Settings UI: dealer source picker with dynamic fields, radius/API key,
 * a "Test Address" preview panel, and the WooCommerce overrides toggles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function rwdpwa_register_admin_page() {
	add_action( 'admin_menu', 'rwdpwa_register_admin_menu' );
	add_action( 'admin_init', 'rwdpwa_register_settings_field' );
	add_action( 'admin_enqueue_scripts', 'rwdpwa_admin_enqueue_assets' );
	add_action( 'wp_ajax_rwdpwa_test_address', 'rwdpwa_ajax_test_address' );
	add_action( 'admin_notices', 'rwdpwa_maybe_render_jsn_overlap_notice' );
}

function rwdpwa_register_settings_field() {
	register_setting( 'rwdpwa_settings_group', 'rwdpwa_settings', array(
		'sanitize_callback' => 'rwdpwa_sanitize_settings',
		'default'           => rwdpwa_get_default_settings(),
	) );
}

function rwdpwa_register_admin_menu() {
	if ( function_exists( 'wc_get_page_id' ) ) {
		add_submenu_page(
			'woocommerce',
			__( 'Dealer Routing', 'rw-dealer-portal-woocommerce-addon' ),
			__( 'Dealer Routing', 'rw-dealer-portal-woocommerce-addon' ),
			'manage_woocommerce',
			'rw-dealer-portal-woocommerce-addon',
			'rwdpwa_render_settings_page'
		);
	} else {
		add_options_page(
			__( 'Dealer Routing', 'rw-dealer-portal-woocommerce-addon' ),
			__( 'Dealer Routing', 'rw-dealer-portal-woocommerce-addon' ),
			'manage_options',
			'rw-dealer-portal-woocommerce-addon',
			'rwdpwa_render_settings_page'
		);
	}
}

/**
 * @param string $hook Current admin page hook suffix.
 */
function rwdpwa_admin_enqueue_assets( $hook ) {
	if ( false === strpos( (string) $hook, 'rw-dealer-portal-woocommerce-addon' ) ) {
		return;
	}

	wp_enqueue_style( 'rwdpwa-admin', RWDPWA_PLUGIN_URL . 'assets/css/admin.css', array(), RWDPWA_VERSION );
	wp_enqueue_script( 'rwdpwa-admin', RWDPWA_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), RWDPWA_VERSION, true );
	wp_localize_script( 'rwdpwa-admin', 'rwdpwaAdmin', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'rwdpwa_test_address' ),
		'i18n'    => array(
			'testing'    => __( 'Searching…', 'rw-dealer-portal-woocommerce-addon' ),
			'noMatches'  => __( 'No dealers matched within the configured radius.', 'rw-dealer-portal-woocommerce-addon' ),
			'errorGeneric' => __( 'Something went wrong. Please try again.', 'rw-dealer-portal-woocommerce-addon' ),
		),
	) );
}

/**
 * AJAX handler backing the "Test Address" panel: geocodes a sample address
 * and reports which dealers from the currently *saved* configuration would
 * receive the order-routing email, and at what distance.
 */
function rwdpwa_ajax_test_address() {
	check_ajax_referer( 'rwdpwa_test_address', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'rw-dealer-portal-woocommerce-addon' ) ) );
	}

	$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
	if ( empty( $address ) ) {
		wp_send_json_error( array( 'message' => __( 'Enter an address to test.', 'rw-dealer-portal-woocommerce-addon' ) ) );
	}

	$coords = rwdpwa_geocode_address_preferred( $address );
	if ( empty( $coords ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not geocode that address. Check the API key and try a more complete address.', 'rw-dealer-portal-woocommerce-addon' ) ) );
	}

	$radius     = rwdpwa_get_radius_miles();
	$source     = RWDPWA_Source_Factory::get_active_source();
	$candidates = $source->get_candidates();

	$matches = array();
	foreach ( $candidates as $candidate ) {
		$distance = rwdpwa_haversine_distance( $coords['lat'], $coords['lng'], $candidate['lat'], $candidate['lng'] );
		if ( $distance > $radius ) {
			continue;
		}

		$matches[] = array(
			'label'    => $candidate['label'],
			'emails'   => $candidate['emails'],
			'distance' => round( $distance, 1 ),
		);
	}

	usort( $matches, function ( $a, $b ) {
		return $a['distance'] <=> $b['distance'];
	} );

	wp_send_json_success( array(
		'matches'      => $matches,
		'radius'       => $radius,
		'totalDealers' => count( $candidates ),
	) );
}

/**
 * Warn if jsn-woocommerce-overrides is still active alongside the merged overrides here.
 */
function rwdpwa_maybe_render_jsn_overlap_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'jsn-woocommerce-overrides/jsn-woocommerce-overrides.php' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || false === strpos( (string) $screen->id, 'rw-dealer-portal-woocommerce-addon' ) ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>
			<?php esc_html_e( 'JSN WooCommerce Overrides is still active. This plugin now includes the same text and pricing overrides (see the WooCommerce Overrides section below) — deactivate JSN WooCommerce Overrides once you have confirmed the settings here match what you need, to avoid the two plugins duplicating each other.', 'rw-dealer-portal-woocommerce-addon' ); ?>
		</p>
	</div>
	<?php
}

/**
 * @return array<string,string> post type slug => label, limited to types with an admin UI.
 */
function rwdpwa_get_selectable_post_types() {
	$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
	unset( $post_types['attachment'] );

	$options = array();
	foreach ( $post_types as $post_type ) {
		$options[ $post_type->name ] = $post_type->labels->singular_name . ' (' . $post_type->name . ')';
	}

	return $options;
}

/**
 * @return array<string,string> role slug => label.
 */
function rwdpwa_get_selectable_roles() {
	if ( ! function_exists( 'get_editable_roles' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	$roles   = get_editable_roles();
	$options = array();
	foreach ( $roles as $slug => $role ) {
		$options[ $slug ] = translate_user_role( $role['name'] );
	}

	return $options;
}

function rwdpwa_render_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'rw-dealer-portal-woocommerce-addon' ) );
	}

	$settings   = rwdpwa_get_settings();
	$cpt        = $settings['custom_post_type'];
	$user_role  = $settings['user_role'];
	$overrides  = $settings['wc_overrides'];
	$post_types = rwdpwa_get_selectable_post_types();
	$roles      = rwdpwa_get_selectable_roles();
	?>
	<div class="wrap rwdpwa-settings">
		<h1><?php esc_html_e( 'RW Dealer Portal WooCommerce Addon', 'rw-dealer-portal-woocommerce-addon' ); ?></h1>
		<p class="rwdpwa-intro">
			<?php esc_html_e( 'When a WooCommerce order is created, this add-on looks up the customer address, finds nearby dealers, and sends the order notification email to their contact address(es) instead of the site admin.', 'rw-dealer-portal-woocommerce-addon' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'rwdpwa_settings_group' ); ?>

			<div class="rwdpwa-card">
				<h2><?php esc_html_e( 'Dealer Source', 'rw-dealer-portal-woocommerce-addon' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose where this plugin should look for dealers.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>

				<p>
					<select id="rwdpwa-dealer-source" name="rwdpwa_settings[dealer_source]">
						<option value="rw_dealer_portal" <?php selected( $settings['dealer_source'], 'rw_dealer_portal' ); ?>>
							<?php esc_html_e( 'RW Dealer Portal', 'rw-dealer-portal-woocommerce-addon' ); ?>
						</option>
						<option value="custom_post_type" <?php selected( $settings['dealer_source'], 'custom_post_type' ); ?>>
							<?php esc_html_e( 'Custom Post Type', 'rw-dealer-portal-woocommerce-addon' ); ?>
						</option>
						<option value="user_role" <?php selected( $settings['dealer_source'], 'user_role' ); ?>>
							<?php esc_html_e( 'WordPress Users by Role', 'rw-dealer-portal-woocommerce-addon' ); ?>
						</option>
					</select>
				</p>

				<div class="rwdpwa-source-fields" data-source="rw_dealer_portal">
					<?php if ( ! defined( 'RWDP_VERSION' ) ) : ?>
						<p class="rwdpwa-warning">
							<?php esc_html_e( 'RW Dealer Portal is not currently active. Activate it, or choose a different dealer source above.', 'rw-dealer-portal-woocommerce-addon' ); ?>
						</p>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'Uses RW Dealer Portal\'s dealer listings directly — no configuration needed here.', 'rw-dealer-portal-woocommerce-addon' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<div class="rwdpwa-source-fields" data-source="custom_post_type">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="rwdpwa-cpt-post-type"><?php esc_html_e( 'Post Type', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<select id="rwdpwa-cpt-post-type" name="rwdpwa_settings[custom_post_type][post_type]">
									<option value=""><?php esc_html_e( '— Select a post type —', 'rw-dealer-portal-woocommerce-addon' ); ?></option>
									<?php foreach ( $post_types as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cpt['post_type'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-cpt-lat-meta"><?php esc_html_e( 'Latitude Meta Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<input type="text" id="rwdpwa-cpt-lat-meta" name="rwdpwa_settings[custom_post_type][lat_meta]" value="<?php echo esc_attr( $cpt['lat_meta'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Leave blank if this post type does not already store coordinates — set an Address Meta Key below instead and this plugin will geocode it for you.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-cpt-lng-meta"><?php esc_html_e( 'Longitude Meta Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td><input type="text" id="rwdpwa-cpt-lng-meta" name="rwdpwa_settings[custom_post_type][lng_meta]" value="<?php echo esc_attr( $cpt['lng_meta'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-cpt-address-meta"><?php esc_html_e( 'Address Meta Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<input type="text" id="rwdpwa-cpt-address-meta" name="rwdpwa_settings[custom_post_type][address_meta]" value="<?php echo esc_attr( $cpt['address_meta'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'A meta key holding a full, geocodable address string. Only used when Latitude/Longitude Meta Key above are left blank — this plugin will geocode and cache coordinates automatically whenever the post is saved.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-cpt-email-meta"><?php esc_html_e( 'Email Meta Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<input type="text" id="rwdpwa-cpt-email-meta" name="rwdpwa_settings[custom_post_type][email_meta]" value="<?php echo esc_attr( $cpt['email_meta'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'A meta key holding one or more email addresses, separated by commas or semicolons.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-cpt-active-meta"><?php esc_html_e( 'Active Flag Meta Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<input type="text" id="rwdpwa-cpt-active-meta" name="rwdpwa_settings[custom_post_type][active_meta]" value="<?php echo esc_attr( $cpt['active_meta'] ); ?>" class="regular-text" />
								<input type="text" name="rwdpwa_settings[custom_post_type][active_value]" value="<?php echo esc_attr( $cpt['active_value'] ); ?>" class="small-text" placeholder="1" />
								<p class="description"><?php esc_html_e( 'Optional. If set, only posts whose meta key equals the given value are included. Leave the meta key blank to always include published posts of this type.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="rwdpwa-source-fields" data-source="user_role">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="rwdpwa-role-role"><?php esc_html_e( 'User Role', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<select id="rwdpwa-role-role" name="rwdpwa_settings[user_role][role]">
									<option value=""><?php esc_html_e( '— Select a role —', 'rw-dealer-portal-woocommerce-addon' ); ?></option>
									<?php foreach ( $roles as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $user_role['role'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-role-lat-meta"><?php esc_html_e( 'Latitude Meta Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<input type="text" id="rwdpwa-role-lat-meta" name="rwdpwa_settings[user_role][lat_meta]" value="<?php echo esc_attr( $user_role['lat_meta'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Leave blank if users do not already have stored coordinates — set an Address Meta Key below instead and this plugin will geocode it for you.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-role-lng-meta"><?php esc_html_e( 'Longitude Meta Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td><input type="text" id="rwdpwa-role-lng-meta" name="rwdpwa_settings[user_role][lng_meta]" value="<?php echo esc_attr( $user_role['lng_meta'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-role-address-meta"><?php esc_html_e( 'Address Meta Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<input type="text" id="rwdpwa-role-address-meta" name="rwdpwa_settings[user_role][address_meta]" value="<?php echo esc_attr( $user_role['address_meta'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'A user meta key holding a full, geocodable address string. Only used when Latitude/Longitude Meta Key above are left blank — coordinates are geocoded and cached automatically whenever the user profile is saved.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rwdpwa-role-email-source"><?php esc_html_e( 'Email Source', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
							<td>
								<input type="text" id="rwdpwa-role-email-source" name="rwdpwa_settings[user_role][email_source]" value="<?php echo esc_attr( $user_role['email_source'] ); ?>" class="regular-text" placeholder="user_email" />
								<p class="description"><?php esc_html_e( 'Enter "user_email" to use the WordPress account email, or a user meta key holding one or more emails separated by commas or semicolons.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<div class="rwdpwa-card">
				<h2><?php esc_html_e( 'Routing', 'rw-dealer-portal-woocommerce-addon' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rwdpwa-radius-miles"><?php esc_html_e( 'Search Radius', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
						<td>
							<input type="number" id="rwdpwa-radius-miles" name="rwdpwa_settings[radius_miles]" value="<?php echo esc_attr( $settings['radius_miles'] ); ?>" min="1" step="1" class="small-text" />
							<?php esc_html_e( 'miles', 'rw-dealer-portal-woocommerce-addon' ); ?>
							<p class="description"><?php esc_html_e( 'Maximum distance from the customer address to a dealer for routing to occur.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rwdpwa-maps-api-key"><?php esc_html_e( 'Google Maps API Key', 'rw-dealer-portal-woocommerce-addon' ); ?></label></th>
						<td>
							<input type="text" id="rwdpwa-maps-api-key" name="rwdpwa_settings[google_maps_api_key]" value="<?php echo esc_attr( $settings['google_maps_api_key'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Uses RW Dealer Portal\'s key if left blank and that plugin is active', 'rw-dealer-portal-woocommerce-addon' ); ?>" />
							<p class="description"><?php esc_html_e( 'Used to geocode checkout addresses (and dealer addresses, for the Custom Post Type / User Role sources).', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="rwdpwa-card">
				<h2><?php esc_html_e( 'WooCommerce Overrides', 'rw-dealer-portal-woocommerce-addon' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Optional WooCommerce text and pricing customizations.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Text Overrides', 'rw-dealer-portal-woocommerce-addon' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="rwdpwa-text-overrides-toggle" name="rwdpwa_settings[wc_overrides][text_overrides]" value="1" <?php checked( $overrides['text_overrides'] ); ?> />
								<?php esc_html_e( 'Rename "Order" to a custom term throughout WooCommerce (checkout, emails, buttons).', 'rw-dealer-portal-woocommerce-addon' ); ?>
							</label>
							<p class="rwdpwa-custom-text-field">
								<label for="rwdpwa-custom-text">
									<?php esc_html_e( 'Replacement term:', 'rw-dealer-portal-woocommerce-addon' ); ?>
								</label>
								<input type="text" id="rwdpwa-custom-text" name="rwdpwa_settings[wc_overrides][custom_text]" value="<?php echo esc_attr( $overrides['custom_text'] ); ?>" class="regular-text" placeholder="quote request" />
								<span class="description"><?php esc_html_e( 'e.g. "quote request" or "estimate" — used in place of "order" throughout checkout, buttons, and emails.', 'rw-dealer-portal-woocommerce-addon' ); ?></span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide Prices & Totals', 'rw-dealer-portal-woocommerce-addon' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rwdpwa_settings[wc_overrides][hide_prices]" value="1" <?php checked( $overrides['hide_prices'] ); ?> />
								<?php esc_html_e( 'Hide product prices, cart/order subtotals, totals, and payment method throughout the site and in emails.', 'rw-dealer-portal-woocommerce-addon' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'PEWC Accordion', 'rw-dealer-portal-woocommerce-addon' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rwdpwa_settings[wc_overrides][pewc_accordion]" value="1" <?php checked( $overrides['pewc_accordion'] ); ?> />
								<?php esc_html_e( 'If Product Add-ons (PEWC) is active, display its groups as closed accordions.', 'rw-dealer-portal-woocommerce-addon' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button(); ?>
		</form>

		<div class="rwdpwa-card">
			<h2><?php esc_html_e( 'Test Address', 'rw-dealer-portal-woocommerce-addon' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Enter a sample address to see which dealers (from the saved configuration above) would receive the order-routing email, and at what distance.', 'rw-dealer-portal-woocommerce-addon' ); ?></p>
			<p>
				<input type="text" id="rwdpwa-test-address" class="regular-text" placeholder="<?php esc_attr_e( '123 Main St, Springfield, IL 62704', 'rw-dealer-portal-woocommerce-addon' ); ?>" />
				<button type="button" class="button button-secondary" id="rwdpwa-test-address-button"><?php esc_html_e( 'Test Address', 'rw-dealer-portal-woocommerce-addon' ); ?></button>
			</p>
			<div id="rwdpwa-test-address-results"></div>
		</div>
	</div>
	<?php
}
