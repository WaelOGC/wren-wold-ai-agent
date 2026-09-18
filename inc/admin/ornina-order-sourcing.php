<?php
/**
 * Ornina Order Sourcing — custom status, line-item snapshots, metabox, admin list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta: admin sourcing progress (independent of WC order status). */
const ORNINA_SOURCING_STATUS_META = '_ornina_sourcing_status';

/** Order meta: supplier/customer tracking number. */
const ORNINA_TRACKING_NUMBER_META = '_ornina_tracking_number';

/** Order meta: internal sourcing notes. */
const ORNINA_SOURCING_NOTES_META = '_ornina_sourcing_notes';

/** Order meta: flag that this order needs sourcing (has at least one snapshot). */
const ORNINA_HAS_SOURCING_META = '_ornina_has_sourcing';

/** Order item meta: immutable Matterhorn original name at purchase time. */
const ORNINA_SOURCE_NAME_SNAPSHOT = '_ornina_source_name_snapshot';

/** Order item meta: immutable model number / style at purchase time. */
const ORNINA_SOURCE_MODEL_SNAPSHOT = '_ornina_source_model_snapshot';

/**
 * Allowed sourcing status values and labels.
 *
 * @return array<string, string>
 */
function ornina_sourcing_status_options() {
	return array(
		'new'       => __( 'New', 'wren-wold-ai-agent' ),
		'purchased' => __( 'Purchased from Supplier', 'wren-wold-ai-agent' ),
		'shipped'   => __( 'Shipped to Customer', 'wren-wold-ai-agent' ),
	);
}

/**
 * Sanitize a sourcing status value.
 *
 * @param string $status Raw status.
 * @return string
 */
function ornina_sanitize_sourcing_status( $status ) {
	$status  = sanitize_key( (string) $status );
	$allowed = ornina_sourcing_status_options();
	return isset( $allowed[ $status ] ) ? $status : 'new';
}

/**
 * Human label for a sourcing status slug.
 *
 * @param string $status Status slug.
 * @return string
 */
function ornina_sourcing_status_label( $status ) {
	$options = ornina_sourcing_status_options();
	$status  = ornina_sanitize_sourcing_status( $status );
	return $options[ $status ];
}

/* --------------------------------------------------------------------------
 * Part A — Custom order status
 * -------------------------------------------------------------------------- */

/**
 * Register the "Awaiting Supplier Purchase" order status.
 */
function ornina_register_sourcing_order_status() {
	register_post_status(
		'wc-sourcing-pending',
		array(
			'label'                     => _x( 'Awaiting Supplier Purchase', 'Order status', 'wren-wold-ai-agent' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop(
				'Awaiting Supplier Purchase <span class="count">(%s)</span>',
				'Awaiting Supplier Purchase <span class="count">(%s)</span>',
				'wren-wold-ai-agent'
			),
		)
	);
}
add_action( 'init', 'ornina_register_sourcing_order_status' );

/**
 * Add custom status to WooCommerce order statuses list.
 *
 * @param array<string, string> $statuses Existing statuses.
 * @return array<string, string>
 */
function ornina_add_sourcing_to_order_statuses( $statuses ) {
	$new = array();
	foreach ( $statuses as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'wc-processing' === $key ) {
			$new['wc-sourcing-pending'] = _x( 'Awaiting Supplier Purchase', 'Order status', 'wren-wold-ai-agent' );
		}
	}
	if ( ! isset( $new['wc-sourcing-pending'] ) ) {
		$new['wc-sourcing-pending'] = _x( 'Awaiting Supplier Purchase', 'Order status', 'wren-wold-ai-agent' );
	}
	return $new;
}
add_filter( 'wc_order_statuses', 'ornina_add_sourcing_to_order_statuses' );

/**
 * Whether an order has any line item with a source name snapshot.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function ornina_order_has_source_snapshot( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}
	foreach ( $order->get_items() as $item ) {
		$name = $item->get_meta( ORNINA_SOURCE_NAME_SNAPSHOT, true );
		if ( '' !== (string) $name ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a product (or its parent) has Matterhorn original name meta.
 *
 * @param int $product_id Product or variation ID.
 * @return string Original name or empty string.
 */
function ornina_get_product_matterhorn_original_name( $product_id ) {
	$product_id = absint( $product_id );
	if ( $product_id <= 0 ) {
		return '';
	}

	$name = (string) get_post_meta( $product_id, '_ornina_matterhorn_original_name', true );
	if ( '' !== $name ) {
		return $name;
	}

	$parent_id = wp_get_post_parent_id( $product_id );
	if ( $parent_id > 0 ) {
		return (string) get_post_meta( $parent_id, '_ornina_matterhorn_original_name', true );
	}

	return '';
}

/**
 * Resolve model snapshot from product (prefer model number, fall back to style key).
 *
 * @param int $product_id Product or variation ID.
 * @return string
 */
function ornina_get_product_source_model( $product_id ) {
	$product_id = absint( $product_id );
	if ( $product_id <= 0 ) {
		return '';
	}

	$ids = array( $product_id );
	$parent_id = wp_get_post_parent_id( $product_id );
	if ( $parent_id > 0 ) {
		$ids[] = $parent_id;
	}

	foreach ( $ids as $id ) {
		$model_no = (string) get_post_meta( $id, '_ornina_matterhorn_model_number', true );
		if ( '' !== $model_no ) {
			return $model_no;
		}
	}
	foreach ( $ids as $id ) {
		$style = (string) get_post_meta( $id, '_ornina_model', true );
		if ( '' !== $style ) {
			return $style;
		}
	}

	return '';
}

/**
 * After payment / when moving to processing: set sourcing-pending if order needs sourcing.
 *
 * @param int $order_id Order ID.
 */
function ornina_maybe_set_sourcing_pending_status( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	// Prefer snapshot (set at checkout); fall back to live product meta for older orders.
	$needs_sourcing = ornina_order_has_source_snapshot( $order );
	if ( ! $needs_sourcing ) {
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$check_id = $variation_id > 0 ? $variation_id : $product_id;
			if ( '' !== ornina_get_product_matterhorn_original_name( $check_id ) || '' !== ornina_get_product_matterhorn_original_name( $product_id ) ) {
				$needs_sourcing = true;
				break;
			}
		}
	}

	if ( ! $needs_sourcing ) {
		return;
	}

	if ( 'yes' !== (string) $order->get_meta( ORNINA_HAS_SOURCING_META, true ) ) {
		$order->update_meta_data( ORNINA_HAS_SOURCING_META, 'yes' );
	}
	if ( '' === (string) $order->get_meta( ORNINA_SOURCING_STATUS_META, true ) ) {
		$order->update_meta_data( ORNINA_SOURCING_STATUS_META, 'new' );
	}

	$current = $order->get_status();
	// Do not override completed / cancelled / refunded / failed.
	$skip = array( 'completed', 'cancelled', 'refunded', 'failed', 'sourcing-pending' );
	if ( in_array( $current, $skip, true ) ) {
		$order->save();
		return;
	}

	$order->update_status(
		'sourcing-pending',
		__( 'Order contains Matterhorn-sourced products and awaits supplier purchase.', 'wren-wold-ai-agent' ),
		true
	);
}
add_action( 'woocommerce_payment_complete', 'ornina_maybe_set_sourcing_pending_status', 20 );
add_action( 'woocommerce_order_status_processing', 'ornina_maybe_set_sourcing_pending_status', 20 );

/* --------------------------------------------------------------------------
 * Part B — Immutable line-item snapshots
 * -------------------------------------------------------------------------- */

/**
 * Copy Matterhorn original name + model onto the order line item at checkout.
 *
 * @param WC_Order_Item_Product $item          Line item.
 * @param string                $cart_item_key Cart key.
 * @param array                 $values        Cart item values.
 * @param WC_Order              $order         Order.
 */
function ornina_snapshot_source_meta_on_line_item( $item, $cart_item_key, $values, $order ) {
	unset( $cart_item_key, $order );

	$product = isset( $values['data'] ) && $values['data'] instanceof WC_Product
		? $values['data']
		: null;

	$product_id   = $product ? $product->get_id() : 0;
	$parent_id    = $product && $product->get_parent_id() ? $product->get_parent_id() : 0;
	$check_ids    = array_filter( array( $product_id, $parent_id ) );

	$original = '';
	$model    = '';
	foreach ( $check_ids as $id ) {
		if ( '' === $original ) {
			$original = ornina_get_product_matterhorn_original_name( $id );
		}
		if ( '' === $model ) {
			$model = ornina_get_product_source_model( $id );
		}
	}

	if ( '' === $original ) {
		return;
	}

	// Set once — never overwrite if somehow already present.
	if ( '' === (string) $item->get_meta( ORNINA_SOURCE_NAME_SNAPSHOT, true ) ) {
		$item->add_meta_data( ORNINA_SOURCE_NAME_SNAPSHOT, $original, true );
	}
	if ( '' !== $model && '' === (string) $item->get_meta( ORNINA_SOURCE_MODEL_SNAPSHOT, true ) ) {
		$item->add_meta_data( ORNINA_SOURCE_MODEL_SNAPSHOT, $model, true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'ornina_snapshot_source_meta_on_line_item', 20, 4 );

/**
 * Keep snapshot keys out of the visible order-item meta list in admin/emails.
 *
 * @param string[] $hidden Hidden meta keys.
 * @return string[]
 */
function ornina_hide_source_snapshot_itemmeta( $hidden ) {
	$hidden[] = ORNINA_SOURCE_NAME_SNAPSHOT;
	$hidden[] = ORNINA_SOURCE_MODEL_SNAPSHOT;
	return $hidden;
}
add_filter( 'woocommerce_hidden_order_itemmeta', 'ornina_hide_source_snapshot_itemmeta' );

/**
 * After order is created, flag it for sourcing when snapshots exist.
 *
 * @param int|WC_Order $order_id_or_order Order ID or order object.
 */
function ornina_flag_order_for_sourcing_after_checkout( $order_id_or_order ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = $order_id_or_order instanceof WC_Order
		? $order_id_or_order
		: wc_get_order( $order_id_or_order );

	if ( ! $order || ! ornina_order_has_source_snapshot( $order ) ) {
		return;
	}
	$order->update_meta_data( ORNINA_HAS_SOURCING_META, 'yes' );
	if ( '' === (string) $order->get_meta( ORNINA_SOURCING_STATUS_META, true ) ) {
		$order->update_meta_data( ORNINA_SOURCING_STATUS_META, 'new' );
	}
	$order->save();
}
add_action( 'woocommerce_checkout_order_processed', 'ornina_flag_order_for_sourcing_after_checkout', 20 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'ornina_flag_order_for_sourcing_after_checkout', 20 );

/* --------------------------------------------------------------------------
 * Part C — Order edit metabox
 * -------------------------------------------------------------------------- */

/**
 * Register the Order Sourcing metabox (classic + HPOS screens).
 *
 * @param WP_Post|WC_Order|null $post_or_order_object Post or order.
 */
function ornina_register_order_sourcing_metabox( $post_or_order_object = null ) {
	unset( $post_or_order_object );

	$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
	foreach ( $screens as $screen ) {
		add_meta_box(
			'ornina_order_sourcing',
			__( 'Order Sourcing (Matterhorn)', 'wren-wold-ai-agent' ),
			'ornina_render_order_sourcing_metabox',
			$screen,
			'normal',
			'high'
		);
	}
}
add_action( 'add_meta_boxes', 'ornina_register_order_sourcing_metabox', 30 );
add_action( 'add_meta_boxes_shop_order', 'ornina_register_order_sourcing_metabox', 30 );
add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'ornina_register_order_sourcing_metabox', 30 );

/**
 * Resolve WC_Order from metabox context (HPOS or classic).
 *
 * @param WP_Post|WC_Order $post_or_order Post or order.
 * @return WC_Order|null
 */
function ornina_resolve_order_from_metabox( $post_or_order ) {
	if ( $post_or_order instanceof WC_Order ) {
		return $post_or_order;
	}
	if ( $post_or_order instanceof WP_Post ) {
		return wc_get_order( $post_or_order->ID );
	}
	return null;
}

/**
 * Collect color/size (and other) variation attributes from a line item.
 *
 * @param WC_Order_Item_Product $item Line item.
 * @return array{color: string, size: string, other: string}
 */
function ornina_get_line_item_variation_bits( $item ) {
	$color = '';
	$size  = '';
	$other = array();

	if ( ! $item instanceof WC_Order_Item_Product ) {
		return array(
			'color' => '',
			'size'  => '',
			'other' => '',
		);
	}

	$meta_data = $item->get_formatted_meta_data( '' );
	foreach ( $meta_data as $meta ) {
		$key   = strtolower( wp_strip_all_tags( (string) $meta->display_key ) );
		$value = wp_strip_all_tags( (string) $meta->display_value );
		if ( '' === $value ) {
			continue;
		}
		// Skip our private snapshot keys if they ever surface.
		if ( 0 === strpos( (string) $meta->key, '_ornina_' ) ) {
			continue;
		}
		if ( false !== strpos( $key, 'color' ) || false !== strpos( $key, 'colour' ) ) {
			$color = $value;
		} elseif ( false !== strpos( $key, 'size' ) ) {
			$size = $value;
		} else {
			$other[] = $key . ': ' . $value;
		}
	}

	return array(
		'color' => $color,
		'size'  => $size,
		'other' => implode( ', ', $other ),
	);
}

/**
 * Render the Order Sourcing metabox.
 *
 * @param WP_Post|WC_Order $post_or_order Post or order.
 */
function ornina_render_order_sourcing_metabox( $post_or_order ) {
	$order = ornina_resolve_order_from_metabox( $post_or_order );
	if ( ! $order ) {
		echo '<p>' . esc_html__( 'Order not found.', 'wren-wold-ai-agent' ) . '</p>';
		return;
	}

	wp_nonce_field( 'ornina_save_order_sourcing', 'ornina_order_sourcing_nonce' );

	$sourcing_status = ornina_sanitize_sourcing_status(
		(string) $order->get_meta( ORNINA_SOURCING_STATUS_META, true )
	);
	$tracking = (string) $order->get_meta( ORNINA_TRACKING_NUMBER_META, true );
	$notes    = (string) $order->get_meta( ORNINA_SOURCING_NOTES_META, true );

	$snapshot_items = array();
	foreach ( $order->get_items() as $item_id => $item ) {
		$snap_name = (string) $item->get_meta( ORNINA_SOURCE_NAME_SNAPSHOT, true );
		if ( '' === $snap_name ) {
			continue;
		}
		$bits = ornina_get_line_item_variation_bits( $item );
		$snapshot_items[] = array(
			'item_id'  => $item_id,
			'name'     => $item->get_name(),
			'snapshot' => $snap_name,
			'model'    => (string) $item->get_meta( ORNINA_SOURCE_MODEL_SNAPSHOT, true ),
			'qty'      => $item->get_quantity(),
			'color'    => $bits['color'],
			'size'     => $bits['size'],
			'other'    => $bits['other'],
		);
	}
	?>
	<style>
		.ornina-sourcing-table{width:100%;border-collapse:collapse;margin:0 0 16px}
		.ornina-sourcing-table th,.ornina-sourcing-table td{border:1px solid #c3c4c7;padding:8px 10px;text-align:left;vertical-align:top}
		.ornina-sourcing-table th{background:#f0f0f1}
		.ornina-sourcing-fields{display:grid;gap:12px;max-width:640px}
		.ornina-sourcing-fields label{display:block;font-weight:600;margin-bottom:4px}
		.ornina-source-snap{font-family:Menlo,Consolas,monospace;background:#fff;cursor:text}
	</style>

	<?php if ( empty( $snapshot_items ) ) : ?>
		<p><?php esc_html_e( 'No Matterhorn-sourced line items on this order.', 'wren-wold-ai-agent' ); ?></p>
	<?php else : ?>
		<table class="ornina-sourcing-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product (as sold)', 'wren-wold-ai-agent' ); ?></th>
					<th><?php esc_html_e( 'Original Matterhorn Name', 'wren-wold-ai-agent' ); ?></th>
					<th><?php esc_html_e( 'Model', 'wren-wold-ai-agent' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'wren-wold-ai-agent' ); ?></th>
					<th><?php esc_html_e( 'Color / Size', 'wren-wold-ai-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $snapshot_items as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td>
							<input
								type="text"
								class="widefat ornina-source-snap"
								value="<?php echo esc_attr( $row['snapshot'] ); ?>"
								readonly
								onclick="this.select();"
							/>
						</td>
						<td><?php echo esc_html( $row['model'] ); ?></td>
						<td><?php echo esc_html( (string) $row['qty'] ); ?></td>
						<td>
							<?php
							$variation = array_filter( array( $row['color'], $row['size'] ) );
							echo esc_html( implode( ' / ', $variation ) );
							if ( '' !== $row['other'] ) {
								echo '<br><span class="description">' . esc_html( $row['other'] ) . '</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<div class="ornina-sourcing-fields">
		<p>
			<label for="ornina_sourcing_status"><?php esc_html_e( 'Sourcing Status', 'wren-wold-ai-agent' ); ?></label>
			<select name="ornina_sourcing_status" id="ornina_sourcing_status">
				<?php foreach ( ornina_sourcing_status_options() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sourcing_status, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Internal only — does not change the customer-facing WooCommerce order status.', 'wren-wold-ai-agent' ); ?></span>
		</p>
		<p>
			<label for="ornina_tracking_number"><?php esc_html_e( 'Tracking Number', 'wren-wold-ai-agent' ); ?></label>
			<input type="text" class="widefat" name="ornina_tracking_number" id="ornina_tracking_number" value="<?php echo esc_attr( $tracking ); ?>" />
		</p>
		<p>
			<label for="ornina_sourcing_notes"><?php esc_html_e( 'Internal Notes', 'wren-wold-ai-agent' ); ?></label>
			<textarea class="widefat" name="ornina_sourcing_notes" id="ornina_sourcing_notes" rows="4"><?php echo esc_textarea( $notes ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Sourcing-only notes — separate from WooCommerce order notes.', 'wren-wold-ai-agent' ); ?></span>
		</p>
	</div>
	<?php
}

/**
 * Save Order Sourcing metabox fields.
 *
 * @param int            $order_id Order ID.
 * @param WC_Order|null  $order    Order object (HPOS may pass it).
 */
function ornina_save_order_sourcing_metabox( $order_id, $order = null ) {
	if ( ! isset( $_POST['ornina_order_sourcing_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ornina_order_sourcing_nonce'] ) ), 'ornina_save_order_sourcing' )
	) {
		return;
	}

	if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order ) {
		return;
	}

	if ( isset( $_POST['ornina_sourcing_status'] ) ) {
		$order->update_meta_data(
			ORNINA_SOURCING_STATUS_META,
			ornina_sanitize_sourcing_status( wp_unslash( $_POST['ornina_sourcing_status'] ) )
		);
	}
	if ( isset( $_POST['ornina_tracking_number'] ) ) {
		$order->update_meta_data(
			ORNINA_TRACKING_NUMBER_META,
			sanitize_text_field( wp_unslash( $_POST['ornina_tracking_number'] ) )
		);
	}
	if ( isset( $_POST['ornina_sourcing_notes'] ) ) {
		$order->update_meta_data(
			ORNINA_SOURCING_NOTES_META,
			sanitize_textarea_field( wp_unslash( $_POST['ornina_sourcing_notes'] ) )
		);
	}

	$order->save();
}
add_action( 'woocommerce_process_shop_order_meta', 'ornina_save_order_sourcing_metabox', 50, 2 );

/* --------------------------------------------------------------------------
 * Part D — Central Order Sourcing admin page
 * -------------------------------------------------------------------------- */

/**
 * Register Order Sourcing under Ornina Settings.
 */
function ornina_register_order_sourcing_menu() {
	add_submenu_page(
		'ornina-settings',
		__( 'Order Sourcing', 'wren-wold-ai-agent' ),
		__( 'Order Sourcing', 'wren-wold-ai-agent' ),
		'manage_options',
		'ornina-order-sourcing',
		'ornina_render_order_sourcing_page'
	);
}
add_action( 'admin_menu', 'ornina_register_order_sourcing_menu', 25 );

/**
 * Enqueue assets on the Order Sourcing admin page.
 *
 * @param string $hook Current admin hook.
 */
function ornina_order_sourcing_admin_assets( $hook ) {
	if ( 'ornina-settings_page_ornina-order-sourcing' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'ornina-order-sourcing-admin',
		ORNINA_PLUGIN_URI . 'assets/js/admin/ornina-order-sourcing.js',
		array(),
		ORNINA_PLUGIN_VERSION,
		true
	);

	wp_localize_script(
		'ornina-order-sourcing-admin',
		'orninaOrderSourcing',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ornina_order_sourcing' ),
			'i18n'    => array(
				'saved'  => __( 'Saved', 'wren-wold-ai-agent' ),
				'error'  => __( 'Could not save status.', 'wren-wold-ai-agent' ),
				'copied' => __( 'Copied', 'wren-wold-ai-agent' ),
			),
			'statuses' => ornina_sourcing_status_options(),
		)
	);

	wp_register_style( 'ornina-order-sourcing-admin', false, array(), ORNINA_PLUGIN_VERSION );
	wp_enqueue_style( 'ornina-order-sourcing-admin' );
	wp_add_inline_style(
		'ornina-order-sourcing-admin',
		'
		.ornina-sourcing-wrap .ornina-sourcing-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:16px 0}
		.ornina-sourcing-wrap .ornina-sourcing-filters label{display:block;font-weight:600;margin-bottom:4px}
		.ornina-sourcing-wrap .ornina-copy-btn{margin-left:6px;vertical-align:middle}
		.ornina-sourcing-wrap .ornina-source-name{font-family:Menlo,Consolas,monospace;font-size:12px}
		.ornina-sourcing-wrap .ornina-status-msg{margin-left:8px;color:#00a32a;font-size:12px}
		.ornina-sourcing-wrap .ornina-status-msg.is-error{color:#d63638}
		.ornina-sourcing-wrap .tablenav .displaying-num{margin-right:8px}
		'
	);
}
add_action( 'admin_enqueue_scripts', 'ornina_order_sourcing_admin_assets' );

/**
 * Query orders that need sourcing, with optional filters.
 *
 * @param array $args {
 *     @type string $sourcing_status Status slug or 'all'.
 *     @type string $search          Order number or customer name.
 *     @type int    $paged           Page number.
 *     @type int    $per_page        Rows per page.
 * }
 * @return array{orders: WC_Order[], total: int}
 */
function ornina_query_sourcing_orders( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'sourcing_status' => 'all',
			'search'          => '',
			'paged'           => 1,
			'per_page'        => 20,
		)
	);

	$query_args = array(
		'limit'      => -1,
		'return'     => 'ids',
		'orderby'    => 'date',
		'order'      => 'DESC',
		'meta_query' => array(
			array(
				'key'   => ORNINA_HAS_SOURCING_META,
				'value' => 'yes',
			),
		),
	);

	$status = sanitize_key( $args['sourcing_status'] );
	if ( 'all' !== $status && isset( ornina_sourcing_status_options()[ $status ] ) ) {
		$query_args['meta_query'][] = array(
			'key'   => ORNINA_SOURCING_STATUS_META,
			'value' => $status,
		);
	}

	$search = trim( (string) $args['search'] );
	if ( '' !== $search ) {
		// Exact order ID / order number match first.
		if ( ctype_digit( $search ) ) {
			$query_args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'   => ORNINA_HAS_SOURCING_META,
					'value' => 'yes',
				),
			);
			if ( 'all' !== $status && isset( ornina_sourcing_status_options()[ $status ] ) ) {
				$query_args['meta_query'][] = array(
					'key'   => ORNINA_SOURCING_STATUS_META,
					'value' => $status,
				);
			}
			// Also allow searching by order ID directly among flagged orders.
			$query_args['include'] = array( absint( $search ) );
		}
	}

	$order_ids = wc_get_orders( $query_args );

	// Fallback: discover via line-item snapshot meta (covers orders missing the flag).
	if ( empty( $order_ids ) && '' === $search ) {
		$order_ids = ornina_find_order_ids_with_source_snapshot();
		if ( 'all' !== $status && isset( ornina_sourcing_status_options()[ $status ] ) ) {
			$order_ids = array_values(
				array_filter(
					$order_ids,
					static function ( $id ) use ( $status ) {
						$o = wc_get_order( $id );
						return $o && ornina_sanitize_sourcing_status( (string) $o->get_meta( ORNINA_SOURCING_STATUS_META, true ) ) === $status;
					}
				)
			);
		}
	}

	// Customer name / order number search filter in PHP (HPOS-safe).
	if ( '' !== $search && ! ctype_digit( $search ) ) {
		if ( empty( $order_ids ) ) {
			$base_args = array(
				'limit'      => -1,
				'return'     => 'ids',
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array(
					array(
						'key'   => ORNINA_HAS_SOURCING_META,
						'value' => 'yes',
					),
				),
			);
			if ( 'all' !== $status && isset( ornina_sourcing_status_options()[ $status ] ) ) {
				$base_args['meta_query'][] = array(
					'key'   => ORNINA_SOURCING_STATUS_META,
					'value' => $status,
				);
			}
			$order_ids = wc_get_orders( $base_args );
			if ( empty( $order_ids ) ) {
				$order_ids = ornina_find_order_ids_with_source_snapshot();
			}
		}

		$needle    = strtolower( $search );
		$order_ids = array_values(
			array_filter(
				$order_ids,
				static function ( $id ) use ( $needle ) {
					$o = wc_get_order( $id );
					if ( ! $o ) {
						return false;
					}
					$hay = strtolower(
						trim(
							$o->get_order_number() . ' ' .
							$o->get_formatted_billing_full_name() . ' ' .
							$o->get_billing_first_name() . ' ' .
							$o->get_billing_last_name() . ' ' .
							$o->get_billing_email()
						)
					);
					return false !== strpos( $hay, $needle );
				}
			)
		);
	} elseif ( '' !== $search && ctype_digit( $search ) ) {
		// Verify the digit search order actually has sourcing.
		$order_ids = array_values(
			array_filter(
				(array) $order_ids,
				static function ( $id ) {
					$o = wc_get_order( $id );
					return $o && ( 'yes' === (string) $o->get_meta( ORNINA_HAS_SOURCING_META, true ) || ornina_order_has_source_snapshot( $o ) );
				}
			)
		);
	}

	$total    = count( $order_ids );
	$paged    = max( 1, absint( $args['paged'] ) );
	$per_page = max( 1, absint( $args['per_page'] ) );
	$slice    = array_slice( $order_ids, ( $paged - 1 ) * $per_page, $per_page );

	$orders = array();
	foreach ( $slice as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$orders[] = $o;
		}
	}

	return array(
		'orders' => $orders,
		'total'  => $total,
	);
}

/**
 * Find order IDs that have at least one line item with a source name snapshot.
 *
 * @return int[]
 */
function ornina_find_order_ids_with_source_snapshot() {
	global $wpdb;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT oi.order_id
			FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				ON oi.order_item_id = oim.order_item_id
			WHERE oi.order_item_type = %s
				AND oim.meta_key = %s
				AND oim.meta_value <> ''
			ORDER BY oi.order_id DESC",
			'line_item',
			ORNINA_SOURCE_NAME_SNAPSHOT
		)
	);

	return array_map( 'absint', (array) $ids );
}

/**
 * Build one display row per snapshotted line item (orders may expand to multiple rows).
 *
 * @param WC_Order $order Order.
 * @return array<int, array<string, mixed>>
 */
function ornina_sourcing_rows_for_order( $order ) {
	$rows = array();
	foreach ( $order->get_items() as $item ) {
		$snap = (string) $item->get_meta( ORNINA_SOURCE_NAME_SNAPSHOT, true );
		if ( '' === $snap ) {
			continue;
		}
		$bits = ornina_get_line_item_variation_bits( $item );
		$rows[] = array(
			'order'     => $order,
			'product'   => $item->get_name(),
			'snapshot'  => $snap,
			'model'     => (string) $item->get_meta( ORNINA_SOURCE_MODEL_SNAPSHOT, true ),
			'qty'       => $item->get_quantity(),
			'color'     => $bits['color'],
			'size'      => $bits['size'],
			'variation' => implode( ' / ', array_filter( array( $bits['color'], $bits['size'] ) ) ),
		);
	}
	return $rows;
}

/**
 * Edit-order admin URL (HPOS-aware).
 *
 * @param WC_Order $order Order.
 * @return string
 */
function ornina_get_order_edit_url( $order ) {
	if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		return $order->get_edit_order_url();
	}
	return admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
}

/**
 * Render the Order Sourcing list page.
 */
function ornina_render_order_sourcing_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$status_filter = isset( $_GET['sourcing_status'] ) ? sanitize_key( wp_unslash( $_GET['sourcing_status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'all' !== $status_filter && ! isset( ornina_sourcing_status_options()[ $status_filter ] ) ) {
		$status_filter = 'all';
	}
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$result     = ornina_query_sourcing_orders(
		array(
			'sourcing_status' => $status_filter,
			'search'          => $search,
			'paged'           => $paged,
			'per_page'        => 20,
		)
	);
	$total      = $result['total'];
	$total_pages = max( 1, (int) ceil( $total / 20 ) );
	?>
	<div class="wrap ornina-sourcing-wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Orders that contain Matterhorn-imported products. Use the original name snapshot to purchase from the supplier.', 'wren-wold-ai-agent' ); ?></p>

		<form method="get" class="ornina-sourcing-filters">
			<input type="hidden" name="page" value="ornina-order-sourcing" />
			<div>
				<label for="ornina-filter-status"><?php esc_html_e( 'Sourcing Status', 'wren-wold-ai-agent' ); ?></label>
				<select name="sourcing_status" id="ornina-filter-status">
					<option value="all" <?php selected( $status_filter, 'all' ); ?>><?php esc_html_e( 'All', 'wren-wold-ai-agent' ); ?></option>
					<?php foreach ( ornina_sourcing_status_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status_filter, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="ornina-filter-search"><?php esc_html_e( 'Search', 'wren-wold-ai-agent' ); ?></label>
				<input type="search" name="s" id="ornina-filter-search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Order # or customer name…', 'wren-wold-ai-agent' ); ?>" />
			</div>
			<div>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wren-wold-ai-agent' ); ?></button>
			</div>
		</form>

		<div class="tablenav top">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: %s: number of orders */
					esc_html( _n( '%s order', '%s orders', $total, 'wren-wold-ai-agent' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</span>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			<?php endif; ?>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order #', 'wren-wold-ai-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Customer', 'wren-wold-ai-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product (as sold)', 'wren-wold-ai-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Original Matterhorn Name', 'wren-wold-ai-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Qty / Color / Size', 'wren-wold-ai-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Order date', 'wren-wold-ai-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sourcing Status', 'wren-wold-ai-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tracking Number', 'wren-wold-ai-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $result['orders'] ) ) : ?>
					<tr>
						<td colspan="8"><?php esc_html_e( 'No sourcing orders found.', 'wren-wold-ai-agent' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $result['orders'] as $order ) : ?>
						<?php
						$rows            = ornina_sourcing_rows_for_order( $order );
						$sourcing_status = ornina_sanitize_sourcing_status( (string) $order->get_meta( ORNINA_SOURCING_STATUS_META, true ) );
						$tracking        = (string) $order->get_meta( ORNINA_TRACKING_NUMBER_META, true );
						$edit_url        = ornina_get_order_edit_url( $order );
						$customer        = $order->get_formatted_billing_full_name();
						$date            = $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
						if ( empty( $rows ) ) {
							continue;
						}
						$first = true;
						foreach ( $rows as $row ) :
							?>
							<tr>
								<?php if ( $first ) : ?>
									<td rowspan="<?php echo esc_attr( (string) count( $rows ) ); ?>">
										<a href="<?php echo esc_url( $edit_url ); ?>">
											#<?php echo esc_html( $order->get_order_number() ); ?>
										</a>
									</td>
									<td rowspan="<?php echo esc_attr( (string) count( $rows ) ); ?>">
										<?php echo esc_html( $customer ); ?>
									</td>
								<?php endif; ?>
								<td><?php echo esc_html( $row['product'] ); ?></td>
								<td>
									<span class="ornina-source-name" data-copy="<?php echo esc_attr( $row['snapshot'] ); ?>">
										<?php echo esc_html( $row['snapshot'] ); ?>
									</span>
									<button type="button" class="button-link ornina-copy-btn" data-copy="<?php echo esc_attr( $row['snapshot'] ); ?>">
										<?php esc_html_e( 'Copy', 'wren-wold-ai-agent' ); ?>
									</button>
								</td>
								<td>
									<?php
									echo esc_html( (string) $row['qty'] );
									if ( '' !== $row['variation'] ) {
										echo ' · ' . esc_html( $row['variation'] );
									}
									?>
								</td>
								<?php if ( $first ) : ?>
									<td rowspan="<?php echo esc_attr( (string) count( $rows ) ); ?>">
										<?php echo esc_html( $date ); ?>
									</td>
									<td rowspan="<?php echo esc_attr( (string) count( $rows ) ); ?>">
										<select
											class="ornina-inline-sourcing-status"
											data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"
										>
											<?php foreach ( ornina_sourcing_status_options() as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sourcing_status, $value ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<span class="ornina-status-msg" aria-live="polite"></span>
									</td>
									<td rowspan="<?php echo esc_attr( (string) count( $rows ) ); ?>">
										<?php echo esc_html( $tracking ); ?>
									</td>
								<?php endif; ?>
							</tr>
							<?php
							$first = false;
						endforeach;
						?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * AJAX: update sourcing status from the list table.
 */
function ornina_ajax_update_sourcing_status() {
	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wren-wold-ai-agent' ) ), 403 );
	}

	check_ajax_referer( 'ornina_order_sourcing', 'nonce' );

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$status   = isset( $_POST['status'] ) ? ornina_sanitize_sourcing_status( wp_unslash( $_POST['status'] ) ) : 'new';

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'wren-wold-ai-agent' ) ), 404 );
	}

	$order->update_meta_data( ORNINA_SOURCING_STATUS_META, $status );
	$order->save();

	wp_send_json_success(
		array(
			'status' => $status,
			'label'  => ornina_sourcing_status_label( $status ),
		)
	);
}
add_action( 'wp_ajax_ornina_update_sourcing_status', 'ornina_ajax_update_sourcing_status' );
