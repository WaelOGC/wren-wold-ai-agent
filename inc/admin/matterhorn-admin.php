<?php
/**
 * Matterhorn admin browser / importer UI.
 *
 * Products → Import from Matterhorn
 *
 * @package Wren_Wold_AI_Agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register submenu under Products.
 *
 * @return void
 */
function fashion_brand_theme_matterhorn_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=product',
		__( 'Import from Matterhorn', 'fashion-brand-theme' ),
		__( 'Import from Matterhorn', 'fashion-brand-theme' ),
		'manage_woocommerce',
		'fashion-brand-matterhorn-import',
		'fashion_brand_theme_matterhorn_admin_page'
	);
}
add_action( 'admin_menu', 'fashion_brand_theme_matterhorn_admin_menu' );

/**
 * Enqueue admin assets on the Matterhorn page only.
 *
 * @param string $hook Current admin hook.
 * @return void
 */
function fashion_brand_theme_matterhorn_admin_assets( $hook ) {
	if ( 'product_page_fashion-brand-matterhorn-import' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'fashion-brand-theme-matterhorn-admin',
		ORNINA_PLUGIN_URI . 'assets/js/admin/matterhorn-import-admin.js',
		array(),
		ORNINA_PLUGIN_VERSION,
		true
	);

	wp_localize_script(
		'fashion-brand-theme-matterhorn-admin',
		'fashionBrandMatterhornAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'fashion_brand_matterhorn_import' ),
			'batch'   => 10,
			'i18n'    => array(
				'selectNone'   => __( 'No products selected.', 'fashion-brand-theme' ),
				'importing'    => __( 'Importing…', 'fashion-brand-theme' ),
				'done'         => __( 'Import complete.', 'fashion-brand-theme' ),
				'error'        => __( 'Import request failed.', 'fashion-brand-theme' ),
				'selected'     => __( 'selected', 'fashion-brand-theme' ),
			),
			'productsUrl' => admin_url( 'edit.php?post_type=product' ),
		)
	);

	wp_register_style( 'fashion-brand-theme-matterhorn-admin', false, array(), ORNINA_PLUGIN_VERSION );
	wp_enqueue_style( 'fashion-brand-theme-matterhorn-admin' );
	wp_add_inline_style(
		'fashion-brand-theme-matterhorn-admin',
		'
		.matterhorn-admin .matterhorn-thumb{width:48px;height:48px;object-fit:cover;border-radius:4px;background:#f0f0f0}
		.matterhorn-admin .matterhorn-thumb--empty{display:inline-block;width:48px;height:48px;background:#e2e2e2;border-radius:4px}
		.matterhorn-admin .matterhorn-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#d1e4dd;color:#1e5c45}
		.matterhorn-admin .matterhorn-badge--new{background:#f0f0f1;color:#50575e}
		.matterhorn-admin .matterhorn-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:16px 0}
		.matterhorn-admin .matterhorn-progress{display:none;margin:16px 0;padding:16px;background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 1px rgba(0,0,0,.04)}
		.matterhorn-admin .matterhorn-progress.is-active{display:block}
		.matterhorn-admin .matterhorn-progress__bar{height:10px;background:#dcdcde;border-radius:999px;overflow:hidden;margin:8px 0 12px}
		.matterhorn-admin .matterhorn-progress__fill{height:100%;width:0;background:#2271b1;transition:width .2s ease}
		.matterhorn-admin .matterhorn-progress__log{max-height:180px;overflow:auto;font-family:Consolas,Monaco,monospace;font-size:12px;background:#f6f7f7;padding:10px;border:1px solid #dcdcde}
		.matterhorn-admin .matterhorn-actions{display:flex;gap:12px;align-items:center;margin:12px 0}
		.matterhorn-admin .matterhorn-selection-count{font-weight:600}
		.matterhorn-admin .matterhorn-index-meta{color:#646970;margin:8px 0 0}
		'
	);
}
add_action( 'admin_enqueue_scripts', 'fashion_brand_theme_matterhorn_admin_assets' );

/**
 * Handle Build / Refresh Index (admin-post).
 *
 * @return void
 */
function fashion_brand_theme_matterhorn_admin_build_index() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'fashion-brand-theme' ) );
	}

	check_admin_referer( 'fashion_brand_matterhorn_build_index' );

	$result = fashion_brand_theme_matterhorn_build_index();

	$redirect = add_query_arg(
		array(
			'post_type' => 'product',
			'page'      => 'fashion-brand-matterhorn-import',
		),
		admin_url( 'edit.php' )
	);

	if ( is_wp_error( $result ) ) {
		$redirect = add_query_arg(
			array(
				'matterhorn_index' => 'error',
				'matterhorn_msg'   => rawurlencode( $result->get_error_message() ),
			),
			$redirect
		);
	} else {
		$redirect = add_query_arg(
			array(
				'matterhorn_index' => 'ok',
				'mapped'           => (int) $result['mapped_count'],
				'unmapped'         => (int) $result['unmapped_count'],
				'unrecog'          => (int) ( $result['unrecognized_color_count'] ?? 0 ),
			),
			$redirect
		);
	}

	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_post_fashion_brand_matterhorn_build_index', 'fashion_brand_theme_matterhorn_admin_build_index' );

/**
 * AJAX: import a batch of selected Matterhorn product IDs.
 *
 * @return void
 */
function fashion_brand_theme_matterhorn_ajax_import_batch() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}

	check_ajax_referer( 'fashion_brand_matterhorn_import', 'nonce' );

	$ids = isset( $_POST['product_ids'] ) ? wp_unslash( $_POST['product_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	if ( ! is_array( $ids ) ) {
		$ids = array();
	}

	$ids = array_values(
		array_filter(
			array_map(
				static function ( $id ) {
					return sanitize_text_field( (string) $id );
				},
				$ids
			)
		)
	);

	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => 'No product IDs provided.' ), 400 );
	}

	// Hard cap per request (client sends 10).
	$ids = array_slice( $ids, 0, 10 );

	@set_time_limit( 120 );

	$payload = fashion_brand_theme_matterhorn_import_product_ids( $ids );

	wp_send_json_success( $payload );
}
add_action( 'wp_ajax_fashion_brand_matterhorn_import_batch', 'fashion_brand_theme_matterhorn_ajax_import_batch' );

/**
 * Render the Matterhorn admin page.
 *
 * @return void
 */
function fashion_brand_theme_matterhorn_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$index   = fashion_brand_theme_matterhorn_load_index();
	$per_page = 50;
	$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$category = isset( $_GET['matterhorn_cat'] ) ? sanitize_title( wp_unslash( $_GET['matterhorn_cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$canonical = function_exists( 'fashion_brand_theme_get_product_category_slugs' )
		? fashion_brand_theme_get_product_category_slugs()
		: array(
			't-shirts' => 'T-Shirts',
			'hoodies'  => 'Hoodies',
			'knitwear' => 'Knitwear',
			'shirts'   => 'Shirts',
			'pants'    => 'Pants',
			'dresses'  => 'Dresses',
		);

	$notice = '';
	if ( isset( $_GET['matterhorn_index'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'ok' === $_GET['matterhorn_index'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = sprintf(
				/* translators: 1: mapped count, 2: unmapped count, 3: unrecognized color count */
				__( 'Index built. %1$d style groups indexed; %2$d unmapped category; %3$d skipped (unrecognized color).', 'fashion-brand-theme' ),
				isset( $_GET['mapped'] ) ? (int) $_GET['mapped'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				isset( $_GET['unmapped'] ) ? (int) $_GET['unmapped'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				isset( $_GET['unrecog'] ) ? (int) $_GET['unrecog'] : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		} elseif ( 'error' === $_GET['matterhorn_index'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = isset( $_GET['matterhorn_msg'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? sanitize_text_field( wp_unslash( $_GET['matterhorn_msg'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				: __( 'Index build failed.', 'fashion-brand-theme' );
		}
	}

	$products = array();
	if ( $index && ! empty( $index['products'] ) && is_array( $index['products'] ) ) {
		$products = $index['products'];
	}

	if ( '' !== $category ) {
		$products = array_values(
			array_filter(
				$products,
				static function ( $row ) use ( $category ) {
					return isset( $row['category'] ) && $row['category'] === $category;
				}
			)
		);
	}

	if ( '' !== $search ) {
		$needle = strtolower( $search );
		$products = array_values(
			array_filter(
				$products,
				static function ( $row ) use ( $needle ) {
					$name = isset( $row['name'] ) ? strtolower( (string) $row['name'] ) : '';
					return false !== strpos( $name, $needle );
				}
			)
		);
	}

	$total_filtered = count( $products );
	$total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
	$page           = min( $page, $total_pages );
	$offset         = ( $page - 1 ) * $per_page;
	$page_rows      = array_slice( $products, $offset, $per_page );

	$status_map = fashion_brand_theme_matterhorn_group_status_map( $page_rows );

	$markup = defined( 'MATTERHORN_PRICE_MARKUP_MULTIPLIER' ) ? (float) MATTERHORN_PRICE_MARKUP_MULTIPLIER : 2.0;
	$base_url = add_query_arg(
		array(
			'post_type' => 'product',
			'page'      => 'fashion-brand-matterhorn-import',
		),
		admin_url( 'edit.php' )
	);

	?>
	<div class="wrap matterhorn-admin">
		<h1><?php esc_html_e( 'Import from Matterhorn', 'fashion-brand-theme' ); ?></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo ( isset( $_GET['matterhorn_index'] ) && 'ok' === $_GET['matterhorn_index'] ) ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?> is-dismissible">
				<p><?php echo esc_html( $notice ); ?></p>
			</div>
		<?php endif; ?>

		<div class="card" style="max-width:100%;padding:16px 20px;margin-top:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Product Index', 'fashion-brand-theme' ); ?></h2>
			<p><?php esc_html_e( 'Build a lightweight metadata index from the XML feed. No images are downloaded and no products are created.', 'fashion-brand-theme' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fashion_brand_matterhorn_build_index">
				<?php wp_nonce_field( 'fashion_brand_matterhorn_build_index' ); ?>
				<?php submit_button( __( 'Build / Refresh Index', 'fashion-brand-theme' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $index ) : ?>
				<p class="matterhorn-index-meta">
					<?php
					printf(
						/* translators: 1: datetime, 2: group count, 3: unmapped count, 4: unrecognized color count, 5: total in feed */
						esc_html__( 'Last built: %1$s — %2$d style groups · %3$d unmapped category · %4$d skipped (unrecognized color) · %5$d total in feed', 'fashion-brand-theme' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $index['built_at'] ) ),
						(int) $index['mapped_count'],
						(int) $index['unmapped_count'],
						(int) ( $index['unrecognized_color_count'] ?? 0 ),
						(int) $index['total_in_feed']
					);
					?>
				</p>
			<?php else : ?>
				<p class="matterhorn-index-meta"><?php esc_html_e( 'No index yet. Upload the feed to uploads/matterhorn/feed-woocommerce.xml, then build the index.', 'fashion-brand-theme' ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $index ) : ?>
			<form class="matterhorn-filters" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
				<input type="hidden" name="post_type" value="product">
				<input type="hidden" name="page" value="fashion-brand-matterhorn-import">
				<div>
					<label for="matterhorn_cat"><strong><?php esc_html_e( 'Category', 'fashion-brand-theme' ); ?></strong></label><br>
					<select name="matterhorn_cat" id="matterhorn_cat">
						<option value=""><?php esc_html_e( 'All mapped categories', 'fashion-brand-theme' ); ?></option>
						<?php foreach ( $canonical as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $category, $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label for="matterhorn_s"><strong><?php esc_html_e( 'Search name', 'fashion-brand-theme' ); ?></strong></label><br>
					<input type="search" name="s" id="matterhorn_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Product name…', 'fashion-brand-theme' ); ?>">
				</div>
				<div>
					<?php submit_button( __( 'Filter', 'fashion-brand-theme' ), 'secondary', '', false ); ?>
				</div>
				<p class="description" style="flex-basis:100%;margin:0">
					<?php
					printf(
						/* translators: 1: unmapped count, 2: unrecognized color count */
						esc_html__( 'Unmapped category (not importable): %1$d · Skipped (unrecognized color): %2$d', 'fashion-brand-theme' ),
						(int) ( $index['unmapped_count'] ?? 0 ),
						(int) ( $index['unrecognized_color_count'] ?? 0 )
					);
					?>
				</p>
			</form>

			<div class="matterhorn-actions">
				<button type="button" class="button button-primary" id="matterhorn-import-selected" disabled>
					<?php esc_html_e( 'Import Selected', 'fashion-brand-theme' ); ?>
				</button>
				<span class="matterhorn-selection-count" id="matterhorn-selection-count">0 <?php esc_html_e( 'selected', 'fashion-brand-theme' ); ?></span>
			</div>

			<div class="matterhorn-progress" id="matterhorn-progress" aria-live="polite">
				<strong id="matterhorn-progress-status"><?php esc_html_e( 'Ready', 'fashion-brand-theme' ); ?></strong>
				<div class="matterhorn-progress__bar"><div class="matterhorn-progress__fill" id="matterhorn-progress-fill"></div></div>
				<div class="matterhorn-progress__log" id="matterhorn-progress-log"></div>
				<p id="matterhorn-progress-summary"></p>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="matterhorn-select-page" title="<?php esc_attr_e( 'Select all on this page', 'fashion-brand-theme' ); ?>">
						</td>
						<th scope="col" style="width:64px"><?php esc_html_e( 'Image', 'fashion-brand-theme' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'fashion-brand-theme' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Category', 'fashion-brand-theme' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Store price', 'fashion-brand-theme' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sizes', 'fashion-brand-theme' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'fashion-brand-theme' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $page_rows ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No mapped products match these filters.', 'fashion-brand-theme' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $page_rows as $row ) : ?>
							<?php
							$gid         = isset( $row['id'] ) ? (string) $row['id'] : '';
							$on_sale     = ( '1' === (string) ( $row['sale'] ?? '' ) ) && (float) ( $row['sale_price_netto'] ?? 0 ) > 0;
							$display     = $on_sale
								? ( (float) $row['sale_price_netto'] * $markup )
								: ( (float) ( $row['price_netto'] ?? 0 ) * $markup );
							$status      = isset( $status_map[ $gid ] ) ? $status_map[ $gid ] : 'new';
							$cat_label   = isset( $canonical[ $row['category'] ] ) ? $canonical[ $row['category'] ] : $row['category'];
							$color_count = isset( $row['color_count'] ) ? (int) $row['color_count'] : 1;
							$size_text   = '';
							if ( ! empty( $row['sizes'] ) && is_array( $row['sizes'] ) ) {
								$names = array();
								foreach ( $row['sizes'] as $size ) {
									$names[] = isset( $size['name'] ) ? (string) $size['name'] : '';
								}
								$size_text = implode( ', ', array_filter( array_unique( $names ) ) );
							}
							?>
							<tr>
								<th scope="row" class="check-column">
									<input
										type="checkbox"
										class="matterhorn-row-check"
										value="<?php echo esc_attr( $gid ); ?>"
										data-category="<?php echo esc_attr( $row['category'] ?? '' ); ?>"
									>
								</th>
								<td>
									<?php if ( ! empty( $row['photo'] ) ) : ?>
										<img class="matterhorn-thumb" src="<?php echo esc_url( $row['photo'] ); ?>" alt="" loading="lazy" width="48" height="48">
									<?php else : ?>
										<span class="matterhorn-thumb--empty" aria-hidden="true"></span>
									<?php endif; ?>
								</td>
								<td>
									<strong><?php echo esc_html( $row['name'] ?? '' ); ?></strong>
									<?php if ( $color_count > 1 ) : ?>
										<span class="matterhorn-badge" style="margin-left:6px"><?php echo esc_html( sprintf( _n( '%d color', '%d colors', $color_count, 'fashion-brand-theme' ), $color_count ) ); ?></span>
									<?php endif; ?>
									<br>
									<code title="<?php echo esc_attr( $gid ); ?>"><?php echo esc_html( $row['style_key'] ?? $gid ); ?></code>
									<?php if ( ! empty( $row['colors'] ) && is_array( $row['colors'] ) ) : ?>
										<br><small><?php echo esc_html( implode( ', ', $row['colors'] ) ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $cat_label ); ?></td>
								<td>
									<?php
									echo esc_html(
										function_exists( 'wc_price' )
											? wp_strip_all_tags( wc_price( $display ) )
											: number_format( $display, 2 )
									);
									?>
									<?php if ( $on_sale ) : ?>
										<br><small><?php esc_html_e( 'Sale', 'fashion-brand-theme' ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $size_text ); ?></td>
								<td>
									<?php if ( 'imported' === $status ) : ?>
										<span class="matterhorn-badge"><?php esc_html_e( 'Already imported', 'fashion-brand-theme' ); ?></span>
									<?php elseif ( 'partial' === $status ) : ?>
										<span class="matterhorn-badge" style="background:#fcf0e3;color:#9a5b1a"><?php esc_html_e( 'Partial', 'fashion-brand-theme' ); ?></span>
									<?php else : ?>
										<span class="matterhorn-badge matterhorn-badge--new"><?php esc_html_e( 'New', 'fashion-brand-theme' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %d: number of items */
								esc_html( _n( '%d item', '%d items', $total_filtered, 'fashion-brand-theme' ) ),
								(int) $total_filtered
							);
							?>
						</span>
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%', $base_url ),
									'format'    => '',
									'current'   => $page,
									'total'     => $total_pages,
									'add_args'  => array_filter(
										array(
											'matterhorn_cat' => $category,
											's'              => $search,
										)
									),
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
