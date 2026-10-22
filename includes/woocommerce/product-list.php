<?php
/**
 * [rwdpwa_product_list] — a catalog-style product section: an optional
 * header band, then cards with the product image, description, one
 * "SKU ........ price" row per orderable item (each size of a variable
 * product gets its own row) with a quantity box, and one add-to-cart button
 * per card. Adds every row with a quantity in one go, with or without JS.
 * Inert until the shortcode is placed on a page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the shortcode and its add-to-cart handlers.
 */
function rwdpwa_register_product_list() {
	add_shortcode( 'rwdpwa_product_list', 'rwdpwa_product_list_shortcode' );
	add_action( 'wc_ajax_rwdpwa_add_items', 'rwdpwa_product_list_ajax_add' );
	add_action( 'wp_loaded', 'rwdpwa_product_list_form_add', 20 );
	add_action( 'wp_enqueue_scripts', 'rwdpwa_product_list_register_assets' );

	$settings = rwdpwa_get_settings();
	if ( ! empty( $settings['store_access']['style_pages'] ) ) {
		add_filter( 'body_class', 'rwdpwa_store_pages_body_class' );
		add_action( 'wp_enqueue_scripts', 'rwdpwa_store_pages_enqueue', 20 );
	}

	if ( rwdpwa_get_store_page_id() ) {
		add_filter( 'woocommerce_return_to_shop_redirect', 'rwdpwa_store_page_url' );
		add_filter( 'woocommerce_continue_shopping_redirect', 'rwdpwa_store_page_url' );
		add_action( 'template_redirect', 'rwdpwa_redirect_shop_to_store_page', 20 );
	}
}

/**
 * Whether the current page is a WooCommerce cart, checkout or account page.
 *
 * @return bool
 */
function rwdpwa_is_store_page() {
	return function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );
}

/**
 * @param string[] $classes Body classes.
 * @return string[]
 */
function rwdpwa_store_pages_body_class( $classes ) {
	if ( rwdpwa_is_store_page() ) {
		$classes[] = 'rwdpwa-store';
	}
	return $classes;
}

/**
 * Load the cart/checkout/account styles on those pages.
 */
function rwdpwa_store_pages_enqueue() {
	if ( rwdpwa_is_store_page() ) {
		wp_enqueue_style( 'rwdpwa-store-pages', RWDPWA_PLUGIN_URL . 'assets/css/store-pages.css', array( 'woocommerce-general' ), RWDPWA_VERSION );
	}
}

/**
 * @return string
 */
function rwdpwa_store_page_url() {
	return get_permalink( rwdpwa_get_store_page_id() );
}

/**
 * Send the WooCommerce shop archive to the chosen store page.
 */
function rwdpwa_redirect_shop_to_store_page() {
	if ( function_exists( 'is_shop' ) && is_shop() && ! is_search() ) {
		wp_safe_redirect( rwdpwa_store_page_url() );
		exit;
	}
}

/**
 * Register (not enqueue) the front-end assets; the shortcode enqueues them.
 */
function rwdpwa_product_list_register_assets() {
	wp_register_style( 'rwdpwa-product-list', RWDPWA_PLUGIN_URL . 'assets/css/product-list.css', array(), RWDPWA_VERSION );
	wp_register_script( 'rwdpwa-product-list', RWDPWA_PLUGIN_URL . 'assets/js/product-list.js', array(), RWDPWA_VERSION, true );
	wp_localize_script( 'rwdpwa-product-list', 'rwdpwaProductList', array(
		'ajaxUrl' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'rwdpwa_add_items' ) : '',
		'i18n'    => array(
			'adding' => __( 'Adding…', 'rw-dealer-portal-woocommerce-addon' ),
			'error'  => __( 'Something went wrong. Please try again.', 'rw-dealer-portal-woocommerce-addon' ),
		),
	) );
}

/**
 * Wording for the cart: "quote request" when the text overrides are on.
 *
 * @return array{button: string, cart: string}
 */
function rwdpwa_product_list_wording() {
	if ( rwdpwa_wc_override_enabled( 'text_overrides' ) ) {
		$term = rwdpwa_get_custom_text();
		return array(
			/* translators: %s: custom term, e.g. "quote request" */
			'button' => sprintf( __( 'Add to %s', 'rw-dealer-portal-woocommerce-addon' ), $term ),
			'cart'   => $term,
		);
	}
	return array(
		'button' => __( 'Add to cart', 'rw-dealer-portal-woocommerce-addon' ),
		'cart'   => __( 'cart', 'rw-dealer-portal-woocommerce-addon' ),
	);
}

/**
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function rwdpwa_product_list_shortcode( $atts = array() ) {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return '';
	}
	if ( function_exists( 'rwdpwa_current_user_can_shop' ) && rwdpwa_members_only_store_enabled() && ! rwdpwa_current_user_can_shop() ) {
		return '';
	}

	$wording = rwdpwa_product_list_wording();
	$atts    = shortcode_atts( array(
		'category'      => '',
		'tag'           => '',
		'heading'       => '',
		'eyebrow'       => '',
		'accent'        => '',
		'accent_text'   => '',
		'heading_color' => '',
		'title_color'   => '',
		'media_bg'      => '',
		'card_bg'       => '',
		'style'         => 'band',   // 'band' (header band, side-by-side cards) | 'catalog' (plain heading, title-on-top cards).
		'background'    => '',       // Full-width section background color.
		'panel'         => '',       // 'yes' = cards sit on a white rounded panel.
		'columns'       => 2,
		'limit'         => -1,
		'button_text'   => $wording['button'],
		'empty_text'    => '',
		'class'         => '',
	), $atts, 'rwdpwa_product_list' );

	$list = static function ( $value ) {
		return array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $value ) ) ) );
	};

	$args = array(
		'status'     => 'publish',
		'visibility' => 'catalog',
		'limit'      => (int) $atts['limit'],
		'orderby'    => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
	);
	if ( $list( $atts['category'] ) ) {
		$args['category'] = $list( $atts['category'] );
	}
	if ( $list( $atts['tag'] ) ) {
		$args['tag'] = $list( $atts['tag'] );
	}
	$products = wc_get_products( $args );

	if ( ! $products && '' === $atts['empty_text'] ) {
		return '';
	}

	wp_enqueue_style( 'rwdpwa-product-list' );
	wp_enqueue_script( 'rwdpwa-product-list' );

	$columns = max( 1, min( 3, (int) $atts['columns'] ) );
	$style   = array( '--rwdpwa-pl-columns:' . $columns );
	$catalog = 'catalog' === $atts['style'];
	$panel   = in_array( strtolower( (string) $atts['panel'] ), array( 'yes', '1', 'true' ), true );
	$classes = array( 'rwdpwa-pl', 'rwdpwa-pl--cols-' . $columns, $catalog ? 'rwdpwa-pl--catalog' : 'rwdpwa-pl--band' );
	$bg      = sanitize_hex_color( $atts['background'] );
	if ( $bg ) {
		$classes[] = 'rwdpwa-pl--bg';
		$style[]   = '--rwdpwa-pl-section-bg:' . $bg;
	}
	if ( $panel ) {
		$classes[] = 'rwdpwa-pl--panel';
	}
	foreach ( preg_split( '/\s+/', (string) $atts['class'] ) as $extra ) {
		if ( '' !== $extra ) {
			$classes[] = sanitize_html_class( $extra );
		}
	}
	foreach ( array( 'accent', 'accent_text', 'heading_color', 'title_color', 'media_bg', 'card_bg' ) as $key ) {
		$color = sanitize_hex_color( $atts[ $key ] );
		if ( $color ) {
			$style[] = '--rwdpwa-pl-' . str_replace( '_', '-', $key ) . ':' . $color;
		}
	}

	static $printed_notices = false;

	ob_start();
	?>
	<section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( implode( ';', $style ) ); ?>">
		<?php
		if ( ! $printed_notices && function_exists( 'wc_print_notices' ) && WC()->session ) {
			$printed_notices = true;
			echo '<div class="rwdpwa-pl__notices">';
			wc_print_notices();
			echo '</div>';
		}
		?>
		<?php if ( '' !== $atts['heading'] || '' !== $atts['eyebrow'] ) : ?>
			<header class="rwdpwa-pl__header">
				<?php if ( '' !== $atts['eyebrow'] ) : ?>
					<p class="rwdpwa-pl__eyebrow"><?php echo esc_html( $atts['eyebrow'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $atts['heading'] ) : ?>
					<h2 class="rwdpwa-pl__heading"><?php echo esc_html( $atts['heading'] ); ?></h2>
				<?php endif; ?>
			</header>
		<?php endif; ?>

		<?php if ( ! $products ) : ?>
			<p class="rwdpwa-pl__empty"><?php echo esc_html( $atts['empty_text'] ); ?></p>
		<?php else : ?>
			<?php echo $panel ? '<div class="rwdpwa-pl__panel">' : ''; ?>
			<div class="rwdpwa-pl__grid">
				<?php foreach ( $products as $product ) : ?>
					<?php echo rwdpwa_product_list_card( $product, $atts['button_text'], $catalog ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				<?php endforeach; ?>
			</div>
			<?php echo $panel ? '</div>' : ''; ?>
		<?php endif; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Orderable rows for a product: itself, or each purchasable variation.
 *
 * @param WC_Product $product Product.
 * @return array<int, array{product: WC_Product, label: string}>
 */
function rwdpwa_product_list_rows( $product ) {
	$rows = array();

	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation || ! $variation->is_purchasable() || ! $variation->is_in_stock() || 'publish' !== $variation->get_status() ) {
				continue;
			}
			$values = array_filter( array_map( 'strval', $variation->get_variation_attributes() ) );
			$label  = trim( $variation->get_sku() . ( $values ? ' (' . implode( ', ', $values ) . ')' : '' ) );
			$rows[] = array(
				'product' => $variation,
				'label'   => '' !== $label ? $label : $variation->get_name(),
			);
		}
	} elseif ( $product->is_purchasable() && $product->is_in_stock() ) {
		$rows[] = array(
			'product' => $product,
			'label'   => $product->get_sku(),
		);
	}

	return $rows;
}

/**
 * One product card.
 *
 * @param WC_Product $product     Product.
 * @param string     $button_text Button label.
 * @param bool       $catalog     Catalog style: title above the image.
 * @return string
 */
function rwdpwa_product_list_card( $product, $button_text, $catalog = false ) {
	$rows        = rwdpwa_product_list_rows( $product );
	$single      = 1 === count( $rows );
	$description = $product->get_description() ?: $product->get_short_description();
	$image       = $product->get_image_id()
		? wp_get_attachment_image( $product->get_image_id(), 'woocommerce_single', false, array( 'class' => 'rwdpwa-pl__image', 'loading' => 'lazy' ) )
		: wc_placeholder_img( 'woocommerce_single', array( 'class' => 'rwdpwa-pl__image' ) );
	$id          = 'rwdpwa-pl-' . $product->get_id();

	ob_start();
	?>
	<article class="rwdpwa-pl__card" id="<?php echo esc_attr( $id ); ?>">
		<?php if ( $catalog ) : ?>
			<h3 class="rwdpwa-pl__title"><?php echo esc_html( $product->get_name() ); ?></h3>
		<?php endif; ?>
		<div class="rwdpwa-pl__media"><?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></div>
		<div class="rwdpwa-pl__body">
			<?php if ( ! $catalog ) : ?>
				<h3 class="rwdpwa-pl__title"><?php echo esc_html( $product->get_name() ); ?></h3>
			<?php endif; ?>
			<?php if ( $description ) : ?>
				<div class="rwdpwa-pl__description"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
			<?php endif; ?>

			<?php if ( $rows ) : ?>
				<form class="rwdpwa-pl__form" method="post" action="<?php echo esc_url( '#' . $id ); ?>">
					<div class="rwdpwa-pl__rows">
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$item     = $row['product'];
							$input_id = 'rwdpwa-qty-' . $item->get_id();
							$max      = $item->get_max_purchase_quantity();
							?>
							<div class="rwdpwa-pl__row">
								<label class="rwdpwa-pl__sku" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $row['label'] ); ?></label>
								<span class="rwdpwa-pl__leader" aria-hidden="true"></span>
								<span class="rwdpwa-pl__price"><?php echo wp_kses_post( $item->get_price_html() ); ?></span>
								<input class="rwdpwa-pl__qty" type="number" id="<?php echo esc_attr( $input_id ); ?>" name="rwdpwa_items[<?php echo esc_attr( $item->get_id() ); ?>]" value="<?php echo esc_attr( $single ? 1 : 0 ); ?>" min="0" <?php echo $max > 0 ? 'max="' . esc_attr( $max ) . '"' : ''; ?> step="1" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item label */ __( 'Quantity for %s', 'rw-dealer-portal-woocommerce-addon' ), $row['label'] ) ); ?>" />
							</div>
						<?php endforeach; ?>
					</div>
					<?php wp_nonce_field( 'rwdpwa_add_items', 'rwdpwa_add_items_nonce' ); ?>
					<button type="submit" class="rwdpwa-pl__button"><?php echo esc_html( $button_text ); ?></button>
					<div class="rwdpwa-pl__message" role="status" aria-live="polite"></div>
				</form>
			<?php endif; ?>
		</div>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * Add the posted rows to the cart.
 *
 * @param array $items Map of product/variation ID => quantity.
 * @return array{added: int, names: string[], errors: string[]}
 */
function rwdpwa_product_list_add_items( $items ) {
	$result = array( 'added' => 0, 'names' => array(), 'errors' => array() );

	foreach ( (array) $items as $item_id => $qty ) {
		$item_id = absint( $item_id );
		$qty     = absint( $qty );
		$item    = $item_id && $qty ? wc_get_product( $item_id ) : null;
		if ( ! $item ) {
			continue;
		}

		if ( $item->is_type( 'variation' ) ) {
			$key = WC()->cart->add_to_cart( $item->get_parent_id(), $qty, $item_id, $item->get_variation_attributes() );
		} elseif ( $item->is_type( 'simple' ) ) {
			$key = WC()->cart->add_to_cart( $item_id, $qty );
		} else {
			continue;
		}

		if ( $key ) {
			$result['added'] += $qty;
			$result['names'][] = ( $qty > 1 ? $qty . ' × ' : '' ) . $item->get_name();
		}
	}

	// add_to_cart() reports problems as WooCommerce error notices.
	foreach ( wc_get_notices( 'error' ) as $notice ) {
		$result['errors'][] = wp_strip_all_tags( is_array( $notice ) ? $notice['notice'] : $notice );
	}
	wc_clear_notices();

	return $result;
}

/**
 * Message after adding, with a link to the cart.
 *
 * @param array $result Result of rwdpwa_product_list_add_items().
 * @return string HTML.
 */
function rwdpwa_product_list_result_message( $result ) {
	$wording = rwdpwa_product_list_wording();

	if ( $result['added'] ) {
		return sprintf(
			/* translators: 1: items added, 2: "quote request" or "cart", 3: cart URL, 4: link text */
			esc_html__( '%1$s added to your %2$s.', 'rw-dealer-portal-woocommerce-addon' ),
			esc_html( implode( ', ', $result['names'] ) ),
			esc_html( $wording['cart'] )
		) . sprintf(
			' <a class="rwdpwa-pl__view" href="%1$s">%2$s</a>',
			esc_url( wc_get_cart_url() ),
			/* translators: %s: "quote request" or "cart" */
			esc_html( sprintf( __( 'View %s', 'rw-dealer-portal-woocommerce-addon' ), $wording['cart'] ) )
		);
	}

	if ( $result['errors'] ) {
		return esc_html( implode( ' ', $result['errors'] ) );
	}

	return esc_html__( 'Enter a quantity for at least one item.', 'rw-dealer-portal-woocommerce-addon' );
}

/**
 * Whether the posted form is a valid add request.
 *
 * @return bool
 */
function rwdpwa_product_list_valid_request() {
	return isset( $_POST['rwdpwa_items'], $_POST['rwdpwa_add_items_nonce'] )
		&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rwdpwa_add_items_nonce'] ) ), 'rwdpwa_add_items' )
		&& function_exists( 'WC' ) && WC()->cart;
}

/**
 * AJAX: ?wc-ajax=rwdpwa_add_items.
 */
function rwdpwa_product_list_ajax_add() {
	if ( ! rwdpwa_product_list_valid_request() ) {
		wp_send_json_error( array( 'message' => esc_html__( 'Your session expired. Please reload the page and try again.', 'rw-dealer-portal-woocommerce-addon' ) ), 400 );
	}

	$items  = wp_unslash( $_POST['rwdpwa_items'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item.
	$result = rwdpwa_product_list_add_items( is_array( $items ) ? $items : array() );

	wp_send_json( array(
		'success'    => $result['added'] > 0,
		'message'    => rwdpwa_product_list_result_message( $result ),
		'cart_count' => WC()->cart->get_cart_contents_count(),
	) );
}

/**
 * No-JS fallback: handle the posted form, then return to the card.
 */
function rwdpwa_product_list_form_add() {
	if ( wp_doing_ajax() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! rwdpwa_product_list_valid_request() ) {
		return;
	}

	$items  = wp_unslash( $_POST['rwdpwa_items'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item.
	$result = rwdpwa_product_list_add_items( is_array( $items ) ? $items : array() );

	wc_add_notice( rwdpwa_product_list_result_message( $result ), $result['added'] ? 'success' : 'error' );

	// wp_get_referer() is empty when the form posts back to the same page, so
	// validate the raw referer ourselves and fall back to the current URL.
	$back = wp_validate_redirect( wp_get_raw_referer(), '' );
	if ( ! $back ) {
		$back = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by wp_safe_redirect().
	}

	wp_safe_redirect( $back );
	exit;
}
