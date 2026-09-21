<?php
/**
 * Ornina internal REST: create WooCommerce Draft products from priced feed items.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register create-products REST route.
 */
function ornina_register_create_products_route() {
	register_rest_route(
		'ornina/v1',
		'/create-products',
		array(
			'methods'             => 'POST',
			'callback'            => 'ornina_rest_create_products',
			'permission_callback' => 'ornina_rest_verify_api_key',
		)
	);
}
add_action( 'rest_api_init', 'ornina_register_create_products_route' );

/**
 * Polyfill-friendly list check for request bodies.
 *
 * @param array $arr Array.
 * @return bool
 */
function ornina_array_is_list( array $arr ) {
	if ( function_exists( 'array_is_list' ) ) {
		return array_is_list( $arr );
	}

	if ( array() === $arr ) {
		return true;
	}

	return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
}

/**
 * POST /wp-json/ornina/v1/create-products
 *
 * Body: { "items": [ { name, model, category, colors[], sizes[], cost, sale_price, profit, image_urls[] }, ... ] }
 * or a bare JSON array of items.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ornina_rest_create_products( $request ) {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'ornina_woocommerce_missing',
			__( 'WooCommerce is not active.', 'wren-wold-ai-agent' ),
			array( 'status' => 503 )
		);
	}

	$body  = $request->get_json_params();
	$items = array();

	if ( is_array( $body ) && isset( $body['items'] ) && is_array( $body['items'] ) ) {
		$items = $body['items'];
	} elseif ( is_array( $body ) && ornina_array_is_list( $body ) ) {
		$items = $body;
	}

	if ( empty( $items ) ) {
		return new WP_Error(
			'ornina_empty_items',
			__( 'Request body must include a non-empty items array.', 'wren-wold-ai-agent' ),
			array( 'status' => 400 )
		);
	}

	fashion_brand_theme_matterhorn_bootstrap_import();
	ornina_ensure_variation_attributes();

	$created     = 0;
	$failed      = 0;
	$product_ids = array();
	$errors      = array();

	foreach ( $items as $index => $item ) {
		if ( ! is_array( $item ) ) {
			++$failed;
			$errors[] = array(
				'index'   => $index,
				'message' => 'Item must be an object.',
			);
			continue;
		}

		$result = ornina_create_draft_product_from_item( $item );

		if ( is_wp_error( $result ) ) {
			++$failed;
			$errors[] = array(
				'index'   => $index,
				'name'    => isset( $item['name'] ) ? (string) $item['name'] : '',
				'message' => $result->get_error_message(),
			);
			continue;
		}

		++$created;
		$product_ids[] = (int) $result['product_id'];
		if ( ! empty( $result['image_error'] ) ) {
			$errors[] = array(
				'index'   => $index,
				'name'    => isset( $item['name'] ) ? (string) $item['name'] : '',
				'message' => (string) $result['image_error'],
				'warning' => true,
			);
		}
	}

	return rest_ensure_response(
		array(
			'created'     => $created,
			'failed'      => $failed,
			'product_ids' => $product_ids,
			'errors'      => $errors,
		)
	);
}

/**
 * Ensure pa_color and pa_size global attributes exist (theme may already create them).
 */
function ornina_ensure_variation_attributes() {
	if ( ! function_exists( 'wc_create_attribute' ) ) {
		return;
	}

	$needed = array(
		'color' => 'Color',
		'size'  => 'Size',
	);

	$existing = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_name' );

	foreach ( $needed as $slug => $label ) {
		if ( ! in_array( $slug, $existing, true ) ) {
			wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
			delete_transient( 'wc_attribute_taxonomies' );
		}

		$taxonomy = 'pa_' . $slug;
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				array( 'product' ),
				array(
					'hierarchical' => false,
					'label'        => $label,
					'query_var'    => true,
					'rewrite'      => false,
					'show_ui'      => false,
					'public'       => false,
				)
			);
		}
	}
}

/**
 * Ensure a product_cat term exists; return term ID.
 *
 * @param string $category Category slug or name.
 * @return int|WP_Error
 */
function ornina_ensure_product_category( $category ) {
	$category = trim( (string) $category );
	if ( '' === $category ) {
		return new WP_Error( 'ornina_missing_category', 'Category is required.' );
	}

	$slug = sanitize_title( $category );
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term && ! is_wp_error( $term ) ) {
		return (int) $term->term_id;
	}

	$term = get_term_by( 'name', $category, 'product_cat' );
	if ( $term && ! is_wp_error( $term ) ) {
		return (int) $term->term_id;
	}

	$label    = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	$inserted = wp_insert_term( $label, 'product_cat', array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		return $inserted;
	}

	return (int) $inserted['term_id'];
}

/**
 * Create one WooCommerce Draft product from a priced Ornina item.
 *
 * @param array<string,mixed> $item Item payload.
 * @return array{product_id:int,image_error?:string}|WP_Error
 */
function ornina_create_draft_product_from_item( array $item ) {
	$name = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';
	if ( '' === $name ) {
		return new WP_Error( 'ornina_missing_name', 'Missing required field: name.' );
	}

	if ( ! isset( $item['sale_price'] ) || '' === $item['sale_price'] || ! is_numeric( $item['sale_price'] ) ) {
		return new WP_Error( 'ornina_missing_sale_price', 'Missing required field: sale_price.' );
	}

	$sale_price = wc_format_decimal( (float) $item['sale_price'], wc_get_price_decimals() );
	$model      = isset( $item['model'] ) ? trim( (string) $item['model'] ) : '';
	$category   = isset( $item['category'] ) ? trim( (string) $item['category'] ) : '';
	$cost       = isset( $item['cost'] ) && is_numeric( $item['cost'] ) ? (float) $item['cost'] : null;
	$profit     = isset( $item['profit'] ) && is_numeric( $item['profit'] ) ? (float) $item['profit'] : null;

	$colors = array();
	if ( ! empty( $item['colors'] ) && is_array( $item['colors'] ) ) {
		foreach ( $item['colors'] as $color ) {
			$color = trim( (string) $color );
			if ( '' !== $color ) {
				$colors[] = $color;
			}
		}
		$colors = array_values( array_unique( $colors ) );
	}

	$sizes = array();
	if ( ! empty( $item['sizes'] ) && is_array( $item['sizes'] ) ) {
		foreach ( $item['sizes'] as $size ) {
			// Preview may send size names as strings, or legacy {name:..} objects.
			if ( is_array( $size ) && isset( $size['name'] ) ) {
				$size = $size['name'];
			}
			$size = trim( (string) $size );
			if ( '' !== $size ) {
				$sizes[] = $size;
			}
		}
		$sizes = array_values( array_unique( $sizes ) );
	}

	$image_urls = array();
	if ( ! empty( $item['image_urls'] ) && is_array( $item['image_urls'] ) ) {
		foreach ( $item['image_urls'] as $url ) {
			$url = trim( (string) $url );
			if ( '' !== $url ) {
				$image_urls[] = $url;
			}
		}
	}

	$color_images = array();
	if ( ! empty( $item['color_images'] ) && is_array( $item['color_images'] ) ) {
		foreach ( $item['color_images'] as $color => $url ) {
			$color = trim( (string) $color );
			$url   = trim( (string) $url );
			if ( '' !== $color && '' !== $url ) {
				$color_images[ $color ] = $url;
			}
		}
	}

	$category_term_id = ornina_ensure_product_category( $category );
	if ( is_wp_error( $category_term_id ) ) {
		return $category_term_id;
	}

	$display_name = $name;
	if ( '' !== $model && false === stripos( $name, $model ) ) {
		$display_name = $name . ' (' . $model . ')';
	}

	$is_variable = ( count( $colors ) > 0 || count( $sizes ) > 0 );

	try {
		if ( $is_variable ) {
			$product_id = ornina_create_draft_variable_product(
				$display_name,
				$model,
				$sale_price,
				$colors,
				$sizes,
				$color_images
			);
		} else {
			$product_id = ornina_create_draft_simple_product( $display_name, $model, $sale_price );
		}
	} catch ( Exception $exc ) {
		return new WP_Error( 'ornina_create_failed', $exc->getMessage() );
	}

	if ( $product_id <= 0 ) {
		return new WP_Error( 'ornina_create_failed', 'Product could not be created.' );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return new WP_Error( 'ornina_create_failed', 'Product created but could not be reloaded.' );
	}

	wp_set_object_terms( $product_id, array( (int) $category_term_id ), 'product_cat' );

	// Permanent Matterhorn reference: exact feed <name>, set once, never overwritten.
	$original_name = '';
	if ( isset( $item['matterhorn_original_name'] ) ) {
		$original_name = trim( (string) $item['matterhorn_original_name'] );
	}
	if ( '' === $original_name ) {
		// Fallback for older payloads that only sent the display name.
		$original_name = $name;
	}
	$existing_original = (string) $product->get_meta( '_ornina_matterhorn_original_name', true );
	if ( '' === $existing_original && '' !== $original_name ) {
		$product->update_meta_data( '_ornina_matterhorn_original_name', $original_name );
	}

	$product->update_meta_data( '_ornina_cost', null !== $cost ? wc_format_decimal( $cost, 2 ) : '' );
	$product->update_meta_data( '_ornina_profit', null !== $profit ? wc_format_decimal( $profit, 2 ) : '' );
	$product->update_meta_data( '_ornina_model', $model );

	// Model number embedded in feed <name> (e.g. "Avondjurk model 107269 Tessita"), distinct from style_key.
	$model_number = ornina_extract_matterhorn_model_number( $original_name );
	if ( '' !== $model_number ) {
		$existing_model_number = (string) $product->get_meta( '_ornina_matterhorn_model_number', true );
		if ( '' === $existing_model_number ) {
			$product->update_meta_data( '_ornina_matterhorn_model_number', $model_number );
		}
	}

	$product->update_meta_data( '_ornina_source_category', $category );

	$product->set_status( 'draft' );
	$product->save();

	$image_error = '';
	if ( ! empty( $image_urls[0] ) ) {
		$attachment_id = fashion_brand_theme_matterhorn_sideload_image( $image_urls[0], $product_id );
		if ( $attachment_id > 0 ) {
			$product->set_image_id( $attachment_id );
			$product->save();
		} else {
			$image_error = 'Featured image sideload failed for ' . $image_urls[0];
		}
	}

	$result = array( 'product_id' => $product_id );
	if ( '' !== $image_error ) {
		$result['image_error'] = $image_error;
	}

	return $result;
}

/**
 * Create a simple draft product.
 *
 * @param string $name       Product name.
 * @param string $model      Model / SKU.
 * @param string $sale_price Formatted regular price.
 * @return int Product ID.
 */
function ornina_create_draft_simple_product( $name, $model, $sale_price ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'draft' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( $sale_price );
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	if ( '' !== $model ) {
		$product->set_sku( ornina_unique_sku( $model ) );
	}

	return (int) $product->save();
}

/**
 * Create a variable draft product with color and/or size variations.
 *
 * Structure:
 * - sizes only  → variations on pa_size
 * - colors only → variations on pa_color
 * - both        → variations on pa_color × pa_size
 *
 * @param string             $name         Product name.
 * @param string             $model        Model / SKU base.
 * @param string             $sale_price   Formatted regular price for every variation.
 * @param array<int,string>  $colors       Color labels.
 * @param array<int,string>  $sizes        Size labels.
 * @param array<string,string> $color_images Optional map of color label => image URL.
 * @return int Parent product ID.
 */
function ornina_create_draft_variable_product( $name, $model, $sale_price, array $colors, array $sizes, array $color_images = array() ) {
	$product = new WC_Product_Variable();
	$product->set_name( $name );
	$product->set_status( 'draft' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_manage_stock( false );
	if ( '' !== $model ) {
		$product->set_sku( ornina_unique_sku( $model ) );
	}

	$color_slugs = array();
	foreach ( $colors as $color ) {
		$slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_color', $color );
		if ( '' !== $slug ) {
			$color_slugs[] = $slug;
		}
	}
	$color_slugs = array_values( array_unique( $color_slugs ) );

	$size_slugs = array();
	foreach ( $sizes as $size ) {
		$slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_size', $size );
		if ( '' !== $slug ) {
			$size_slugs[] = $slug;
		}
	}
	$size_slugs = array_values( array_unique( $size_slugs ) );

	if ( empty( $color_slugs ) && empty( $size_slugs ) ) {
		return ornina_create_draft_simple_product( $name, $model, $sale_price );
	}

	$attributes = array();

	if ( ! empty( $color_slugs ) && taxonomy_exists( 'pa_color' ) ) {
		$color_attr = new WC_Product_Attribute();
		$color_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_color' ) );
		$color_attr->set_name( 'pa_color' );
		$color_attr->set_options( $color_slugs );
		$color_attr->set_visible( true );
		$color_attr->set_variation( true );
		$attributes[] = $color_attr;
	}

	if ( ! empty( $size_slugs ) && taxonomy_exists( 'pa_size' ) ) {
		$size_attr = new WC_Product_Attribute();
		$size_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_size' ) );
		$size_attr->set_name( 'pa_size' );
		$size_attr->set_options( $size_slugs );
		$size_attr->set_visible( true );
		$size_attr->set_variation( true );
		$attributes[] = $size_attr;
	}

	$product->set_attributes( $attributes );
	$parent_id = (int) $product->save();

	if ( ! empty( $color_slugs ) ) {
		wp_set_object_terms( $parent_id, $color_slugs, 'pa_color' );
	}
	if ( ! empty( $size_slugs ) ) {
		wp_set_object_terms( $parent_id, $size_slugs, 'pa_size' );
	}

	// Sideload one image per color (keyed by pa_color slug) for variation assignment.
	$color_attachment_by_slug = array();
	foreach ( $color_images as $color_label => $image_url ) {
		$color_label = trim( (string) $color_label );
		$image_url   = trim( (string) $image_url );
		if ( '' === $color_label || '' === $image_url ) {
			continue;
		}
		$slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_color', $color_label );
		if ( '' === $slug || isset( $color_attachment_by_slug[ $slug ] ) ) {
			continue;
		}
		$attachment_id = fashion_brand_theme_matterhorn_sideload_image( $image_url, $parent_id );
		if ( $attachment_id > 0 ) {
			$color_attachment_by_slug[ $slug ] = $attachment_id;
		}
	}

	$color_axis = ! empty( $color_slugs ) ? $color_slugs : array( '' );
	$size_axis  = ! empty( $size_slugs ) ? $size_slugs : array( '' );

	foreach ( $color_axis as $color_slug ) {
		foreach ( $size_axis as $size_slug ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );

			$attrs = array();
			if ( '' !== $color_slug ) {
				$attrs['pa_color'] = $color_slug;
			}
			if ( '' !== $size_slug ) {
				$attrs['pa_size'] = $size_slug;
			}
			$variation->set_attributes( $attrs );
			$variation->set_regular_price( $sale_price );
			$variation->set_manage_stock( false );
			$variation->set_stock_status( 'instock' );
			$variation->set_status( 'publish' );

			if ( '' !== $color_slug && isset( $color_attachment_by_slug[ $color_slug ] ) ) {
				$variation->set_image_id( $color_attachment_by_slug[ $color_slug ] );
			}

			$variation->save();
		}
	}

	WC_Product_Variable::sync( $parent_id );

	return $parent_id;
}

/**
 * Build a unique SKU from a model key.
 *
 * @param string $model Model key.
 * @return string
 */
function ornina_unique_sku( $model ) {
	$base = sanitize_title( (string) $model );
	if ( '' === $base ) {
		$base = 'ornina';
	}

	$sku = $base;
	$i   = 1;
	while ( wc_get_product_id_by_sku( $sku ) ) {
		$sku = $base . '-' . $i;
		++$i;
	}

	return $sku;
}

/**
 * Extract a numeric model number from a Matterhorn feed <name> (e.g. "… model 107269 …").
 *
 * @param string $original_name Exact feed <name>.
 * @return string Digits only, or empty string.
 */
function ornina_extract_matterhorn_model_number( $original_name ) {
	if ( preg_match( '/\bmodel\s+(\d+)\b/iu', (string) $original_name, $matches ) ) {
		return (string) $matches[1];
	}

	return '';
}
