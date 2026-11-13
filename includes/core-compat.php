<?php
/**
 * RW Dealer Portal update watch (setting `core_watch`, off by default).
 *
 * Notes on the Plugins page when RW Dealer Portal has an update, or is newer
 * than the version this add-on was tested with, and a self-check of the RW
 * Dealer Portal functions, post type and meta keys this add-on relies on.
 * Results are cached per RW Dealer Portal version. Does nothing when RW Dealer
 * Portal isn't active (the other dealer sources don't need it).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** RW Dealer Portal version this add-on release was last tested with. Bump after testing a new one. */
define( 'RWDPWA_TESTED_CORE', '2.3.0' );

/**
 * Register the Plugins-page hooks.
 */
function rwdpwa_register_core_compat() {
	add_action( 'load-plugins.php', 'rwdpwa_core_compat_maybe_recheck' );
	add_action( 'after_plugin_row_' . plugin_basename( RWDPWA_PLUGIN_FILE ), 'rwdpwa_core_compat_plugin_row', 10, 2 );
	add_action( 'admin_init', 'rwdpwa_core_compat_register_core_update_message' );
}

/**
 * Whether the watch is on and RW Dealer Portal is active.
 *
 * @return bool
 */
function rwdpwa_core_compat_enabled() {
	return defined( 'RWDP_VERSION' ) && ! empty( rwdpwa_get_settings()['core_watch'] );
}

/**
 * Plugin basename of RW Dealer Portal.
 *
 * @return string
 */
function rwdpwa_core_basename() {
	return defined( 'RWDP_PLUGIN_DIR' ) ? plugin_basename( RWDP_PLUGIN_DIR . 'rw-dealer-portal.php' ) : '';
}

/**
 * Newer RW Dealer Portal version waiting in WordPress updates, or ''.
 *
 * @return string
 */
function rwdpwa_core_available_update() {
	$updates = get_site_transient( 'update_plugins' );
	$file    = rwdpwa_core_basename();
	if ( ! $file || ! is_object( $updates ) || empty( $updates->response[ $file ]->new_version ) ) {
		return '';
	}
	$version = (string) $updates->response[ $file ]->new_version;
	return version_compare( $version, RWDP_VERSION, '>' ) ? $version : '';
}

/**
 * What this add-on needs from RW Dealer Portal, grouped by feature.
 *
 * Each check: array( feature, description, type, value ). Types: `function`,
 * `post_type`, `page` (a portal page key), or `source` (a string that must
 * appear in RW Dealer Portal's PHP/JS/CSS).
 *
 * @return array<int,array{0:string,1:string,2:string,3:string}>
 */
function rwdpwa_core_compat_checks() {
	$routing = __( 'Dealer routing (RW Dealer Portal source)', 'rw-dealer-portal-woocommerce-addon' );
	$safety  = __( 'Skip routing for dealers\' own orders', 'rw-dealer-portal-woocommerce-addon' );
	$store   = __( 'Members-only store', 'rw-dealer-portal-woocommerce-addon' );
	$account = __( 'Dealer checkout pre-fill and My Account', 'rw-dealer-portal-woocommerce-addon' );

	return array(
		array( $routing, __( 'Dealer post type', 'rw-dealer-portal-woocommerce-addon' ), 'post_type', 'rw_dealer' ),
		array( $routing, __( 'Dealer latitude meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_lat' ),
		array( $routing, __( 'Dealer longitude meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_lng' ),
		array( $routing, __( 'Valid-address flag meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_address_valid' ),
		array( $routing, __( 'Dealer contact emails meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_contact_emails' ),
		array( $routing, 'rwdp_geocode_address()', 'function', 'rwdp_geocode_address' ),
		array( $routing, __( 'Google Maps key settings', 'rw-dealer-portal-woocommerce-addon' ), 'source', 'google_maps_api_key' ),
		array( $safety, 'rwdp_user_is_portal_member()', 'function', 'rwdp_user_is_portal_member' ),
		array( $safety, __( 'User–dealer link meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_dealer_ids' ),
		array( $store, 'rwdp_current_user_has_portal_access()', 'function', 'rwdp_current_user_has_portal_access' ),
		array( $store, 'rwdp_get_page_url()', 'function', 'rwdp_get_page_url' ),
		array( $store, 'rwdp_get_current_frontend_url()', 'function', 'rwdp_get_current_frontend_url' ),
		array( $store, __( 'Portal login page', 'rw-dealer-portal-woocommerce-addon' ), 'page', 'login' ),
		array( $store, __( 'Portal dashboard page', 'rw-dealer-portal-woocommerce-addon' ), 'page', 'dashboard' ),
		array( $account, 'rwdp_get_page_id()', 'function', 'rwdp_get_page_id' ),
		array( $account, __( 'Dealer address meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_address' ),
		array( $account, __( 'Dealer city meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_city' ),
		array( $account, __( 'Dealer state meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_state' ),
		array( $account, __( 'Dealer ZIP meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_zip' ),
		array( $account, __( 'Dealer phone meta', 'rw-dealer-portal-woocommerce-addon' ), 'source', '_rwdp_phone' ),
	);
}

/**
 * RW Dealer Portal's PHP/JS/CSS as one string (bundled libraries excluded).
 *
 * @return string
 */
function rwdpwa_core_compat_source() {
	if ( ! defined( 'RWDP_PLUGIN_DIR' ) || ! is_dir( RWDP_PLUGIN_DIR ) ) {
		return '';
	}
	$source = '';
	$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( RWDP_PLUGIN_DIR, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		$path = $file->getPathname();
		if ( ! preg_match( '/\.(php|js|css)$/', $path ) || preg_match( '#/(plugin-update-checker|vendor|node_modules)/#', $path ) ) {
			continue;
		}
		$source .= (string) file_get_contents( $path ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin files
	}
	return $source;
}

/**
 * Run every check and cache the result.
 *
 * @return array{core:string,checked:int,failed:array<int,array{feature:string,label:string}>,total:int}
 */
function rwdpwa_core_compat_run() {
	$source = rwdpwa_core_compat_source();
	$pages  = function_exists( 'rwdp_get_portal_page_definitions' ) ? (array) rwdp_get_portal_page_definitions() : array();
	$checks = rwdpwa_core_compat_checks();
	$failed = array();

	foreach ( $checks as list( $feature, $label, $type, $value ) ) {
		switch ( $type ) {
			case 'function':
				$ok = function_exists( $value );
				break;
			case 'post_type':
				$ok = post_type_exists( $value );
				break;
			case 'page':
				$ok = isset( $pages[ $value ] );
				break;
			default:
				$ok = '' !== $source && false !== strpos( $source, $value );
		}
		if ( ! $ok ) {
			$failed[] = array(
				'feature' => $feature,
				'label'   => $label,
			);
		}
	}

	$result = array(
		'core'    => RWDP_VERSION,
		'checked' => time(),
		'failed'  => $failed,
		'total'   => count( $checks ),
	);
	update_option( 'rwdpwa_core_compat', $result, false );
	return $result;
}

/**
 * Latest results, re-running when RW Dealer Portal's version changed since the last run.
 *
 * @return array{core:string,checked:int,failed:array,total:int}
 */
function rwdpwa_core_compat_results() {
	$saved = get_option( 'rwdpwa_core_compat', array() );
	if ( ! is_array( $saved ) || ( $saved['core'] ?? '' ) !== RWDP_VERSION ) {
		$saved = rwdpwa_core_compat_run();
	}
	return $saved;
}

/**
 * Handle the "Run check again" link on the Plugins page.
 */
function rwdpwa_core_compat_maybe_recheck() {
	if ( empty( $_GET['rwdpwa_core_check'] ) || ! rwdpwa_core_compat_enabled() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	check_admin_referer( 'rwdpwa_core_check' );
	rwdpwa_core_compat_run();
	wp_safe_redirect( remove_query_arg( array( 'rwdpwa_core_check', '_wpnonce' ) ) );
	exit;
}

/**
 * Notice row under this add-on on the Plugins page.
 *
 * @param string $file        Plugin basename.
 * @param array  $plugin_data Plugin data.
 */
function rwdpwa_core_compat_plugin_row( $file, $plugin_data ) {
	if ( ! rwdpwa_core_compat_enabled() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$results  = rwdpwa_core_compat_results();
	$update   = rwdpwa_core_available_update();
	$messages = array();
	$type     = 'success';

	if ( $update && version_compare( $update, RWDPWA_TESTED_CORE, '>' ) ) {
		$type       = 'warning';
		$messages[] = sprintf(
			/* translators: 1: new RW Dealer Portal version, 2: tested version */
			esc_html__( 'RW Dealer Portal %1$s is available. This add-on was tested with %2$s: update on a staging site first and check the store, checkout and dealer routing still work.', 'rw-dealer-portal-woocommerce-addon' ),
			esc_html( $update ),
			esc_html( RWDPWA_TESTED_CORE )
		);
	}
	if ( version_compare( RWDP_VERSION, RWDPWA_TESTED_CORE, '>' ) ) {
		$type       = 'warning';
		$messages[] = sprintf(
			/* translators: 1: installed RW Dealer Portal version, 2: tested version */
			esc_html__( 'RW Dealer Portal was updated to %1$s. This add-on was tested with %2$s.', 'rw-dealer-portal-woocommerce-addon' ),
			esc_html( RWDP_VERSION ),
			esc_html( RWDPWA_TESTED_CORE )
		);
	}

	if ( $results['failed'] ) {
		$type  = 'error';
		$items = '';
		foreach ( $results['failed'] as $fail ) {
			$items .= '<li><strong>' . esc_html( $fail['feature'] ) . ':</strong> ' . esc_html( $fail['label'] ) . '</li>';
		}
		$messages[] = sprintf(
			/* translators: 1: number of failed checks, 2: number of checks, 3: RW Dealer Portal version */
			esc_html__( 'Compatibility check: %1$d of %2$d things this add-on needs were not found in RW Dealer Portal %3$s. These features may not work:', 'rw-dealer-portal-woocommerce-addon' ),
			count( $results['failed'] ),
			(int) $results['total'],
			esc_html( $results['core'] )
		) . '<ul style="list-style:disc;margin:4px 0 0 20px;">' . $items . '</ul>';
	} else {
		$messages[] = sprintf(
			/* translators: 1: number of checks, 2: RW Dealer Portal version */
			esc_html__( 'Compatibility check passed: all %1$d things this add-on needs were found in RW Dealer Portal %2$s.', 'rw-dealer-portal-woocommerce-addon' ),
			(int) $results['total'],
			esc_html( $results['core'] )
		);
	}

	$recheck = wp_nonce_url( add_query_arg( 'rwdpwa_core_check', 1, self_admin_url( 'plugins.php' ) ), 'rwdpwa_core_check' );
	$active  = is_plugin_active( $file ) ? ' active' : '';
	?>
	<tr class="plugin-update-tr<?php echo esc_attr( $active ); ?> rwdpwa-core-compat-row">
		<td colspan="<?php echo esc_attr( (string) ( isset( $GLOBALS['wp_list_table'] ) ? $GLOBALS['wp_list_table']->get_column_count() : 4 ) ); ?>" class="plugin-update colspanchange">
			<div class="notice inline notice-<?php echo esc_attr( $type ); ?> notice-alt">
				<?php foreach ( $messages as $message ) : ?>
					<p><?php echo wp_kses( $message, array( 'strong' => array(), 'ul' => array( 'style' => array() ), 'li' => array() ) ); ?></p>
				<?php endforeach; ?>
				<p>
					<?php
					printf(
						/* translators: %s: date and time of the last check */
						esc_html__( 'Last checked %s.', 'rw-dealer-portal-woocommerce-addon' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $results['checked'] ) )
					);
					?>
					<a href="<?php echo esc_url( $recheck ); ?>"><?php esc_html_e( 'Run check again', 'rw-dealer-portal-woocommerce-addon' ); ?></a>
				</p>
			</div>
		</td>
	</tr>
	<?php
}

/**
 * Add a line to RW Dealer Portal's own "update available" message on the Plugins page.
 */
function rwdpwa_core_compat_register_core_update_message() {
	if ( rwdpwa_core_compat_enabled() && rwdpwa_core_basename() ) {
		add_action( 'in_plugin_update_message-' . rwdpwa_core_basename(), 'rwdpwa_core_compat_core_update_message', 11, 2 );
	}
}

/**
 * @param array  $plugin_data Plugin data.
 * @param object $response    Update response.
 */
function rwdpwa_core_compat_core_update_message( $plugin_data, $response ) {
	$version = (string) ( $response->new_version ?? '' );
	if ( ! $version || ! version_compare( $version, RWDPWA_TESTED_CORE, '>' ) ) {
		return;
	}
	echo '<br /><strong>' . esc_html(
		sprintf(
			/* translators: %s: tested RW Dealer Portal version */
			__( 'RW Dealer Portal WooCommerce Addon was tested with %s. Update on a staging site first.', 'rw-dealer-portal-woocommerce-addon' ),
			RWDPWA_TESTED_CORE
		)
	) . '</strong>';
}
