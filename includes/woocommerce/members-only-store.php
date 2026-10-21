<?php
/**
 * Members-only store: the shop, products, cart and checkout are only open to
 * approved RW Dealer Portal members. Everyone else is sent to the portal
 * login, products can't be added to a cart, and products stay out of
 * search, sitemaps and the public Store API.
 * Gated by the `store_access.members_only` setting; needs RW Dealer Portal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the members-only hooks when the feature is on.
 */
function rwdpwa_register_members_only_store() {
	if ( ! rwdpwa_members_only_store_enabled() || ! function_exists( 'rwdp_current_user_has_portal_access' ) ) {
		return;
	}

	add_action( 'template_redirect', 'rwdpwa_members_only_redirect', 5 );
	add_filter( 'woocommerce_is_purchasable', 'rwdpwa_members_only_purchasable', 100 );
	add_filter( 'woocommerce_variation_is_purchasable', 'rwdpwa_members_only_purchasable', 100 );
	add_action( 'pre_get_posts', 'rwdpwa_members_only_hide_from_search' );
	add_filter( 'rest_pre_dispatch', 'rwdpwa_members_only_block_store_api', 10, 3 );
	add_filter( 'wp_sitemaps_post_types', 'rwdpwa_members_only_core_sitemap_post_types' );
	add_filter( 'wp_sitemaps_taxonomies', 'rwdpwa_members_only_core_sitemap_taxonomies' );
	add_filter( 'wpseo_sitemap_exclude_post_type', 'rwdpwa_members_only_yoast_exclude_post_type', 10, 2 );
	add_filter( 'wpseo_sitemap_exclude_taxonomy', 'rwdpwa_members_only_yoast_exclude_taxonomy', 10, 2 );
}

/**
 * Whether the current visitor may use the store.
 *
 * @return bool
 */
function rwdpwa_current_user_can_shop() {
	$can = function_exists( 'rwdp_current_user_has_portal_access' ) && rwdp_current_user_has_portal_access();

	/**
	 * Filters whether the current visitor may use the members-only store.
	 *
	 * @param bool $can Whether the visitor is an approved portal member (or staff).
	 */
	return (bool) apply_filters( 'rwdpwa_current_user_can_shop', $can );
}

/**
 * Store taxonomies hidden from non-members.
 *
 * @return string[]
 */
function rwdpwa_store_taxonomies() {
	return array( 'product_cat', 'product_tag', 'product_brand' );
}

/**
 * Send non-members away from store pages: to the portal login when logged
 * out (returning here afterwards), to the portal home when logged in
 * without access (e.g. awaiting approval).
 */
function rwdpwa_members_only_redirect() {
	if ( rwdpwa_current_user_can_shop() ) {
		return;
	}

	$is_store = is_shop() || is_product() || is_product_taxonomy() || is_cart() || is_checkout() || is_tax( rwdpwa_store_taxonomies() );
	if ( ! $is_store ) {
		return;
	}

	if ( is_user_logged_in() ) {
		$target = function_exists( 'rwdp_get_page_url' ) ? rwdp_get_page_url( 'dashboard' ) : home_url( '/' );
	} else {
		$login  = function_exists( 'rwdp_get_page_url' ) ? rwdp_get_page_url( 'login' ) : wp_login_url();
		$here   = function_exists( 'rwdp_get_current_frontend_url' ) ? rwdp_get_current_frontend_url() : home_url( add_query_arg( array() ) );
		$target = add_query_arg( 'redirect_to', rawurlencode( $here ), $login );
	}

	nocache_headers();
	wp_safe_redirect( $target );
	exit;
}

/**
 * Non-members can't add anything to a cart (covers ?add-to-cart= links and AJAX).
 *
 * @param bool $purchasable Whether the product is purchasable.
 * @return bool
 */
function rwdpwa_members_only_purchasable( $purchasable ) {
	return $purchasable && rwdpwa_current_user_can_shop();
}

/**
 * Leave products out of front-end search results for non-members.
 *
 * @param WP_Query $query Query.
 */
function rwdpwa_members_only_hide_from_search( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || rwdpwa_current_user_can_shop() ) {
		return;
	}

	$types = $query->get( 'post_type' );
	if ( empty( $types ) || 'any' === $types ) {
		$types = get_post_types( array( 'exclude_from_search' => false ) );
	}
	$query->set( 'post_type', array_values( array_diff( (array) $types, array( 'product', 'product_variation' ) ) ) );
}

/**
 * Block WooCommerce's public Store API (product listings, cart) for non-members.
 *
 * @param mixed           $result  Response to replace the request with, or null.
 * @param WP_REST_Server  $server  Server.
 * @param WP_REST_Request $request Request.
 * @return mixed
 */
function rwdpwa_members_only_block_store_api( $result, $server, $request ) {
	if ( null !== $result || 0 !== strpos( $request->get_route(), '/wc/store' ) || rwdpwa_current_user_can_shop() ) {
		return $result;
	}

	return new WP_Error( 'rwdpwa_members_only', __( 'The store is available to approved dealers only.', 'rw-dealer-portal-woocommerce-addon' ), array( 'status' => 401 ) );
}

/**
 * @param array $post_types Post types in the core sitemap.
 * @return array
 */
function rwdpwa_members_only_core_sitemap_post_types( $post_types ) {
	unset( $post_types['product'] );
	return $post_types;
}

/**
 * @param array $taxonomies Taxonomies in the core sitemap.
 * @return array
 */
function rwdpwa_members_only_core_sitemap_taxonomies( $taxonomies ) {
	foreach ( rwdpwa_store_taxonomies() as $taxonomy ) {
		unset( $taxonomies[ $taxonomy ] );
	}
	return $taxonomies;
}

/**
 * @param bool   $exclude   Whether to exclude.
 * @param string $post_type Post type.
 * @return bool
 */
function rwdpwa_members_only_yoast_exclude_post_type( $exclude, $post_type ) {
	return 'product' === $post_type ? true : $exclude;
}

/**
 * @param bool   $exclude  Whether to exclude.
 * @param string $taxonomy Taxonomy.
 * @return bool
 */
function rwdpwa_members_only_yoast_exclude_taxonomy( $exclude, $taxonomy ) {
	return in_array( $taxonomy, rwdpwa_store_taxonomies(), true ) ? true : $exclude;
}
