<?php
/**
 * Ornina internal REST: AI content generation context + apply (title/description/SEO).
 *
 * Never modifies Matterhorn sourcing meta (_ornina_matterhorn_original_name, etc.).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register generate-content and apply-content REST routes.
 */
function ornina_register_product_content_routes() {
	register_rest_route(
		'ornina/v1',
		'/generate-content',
		array(
			'methods'             => 'POST',
			'callback'            => 'ornina_rest_generate_content_context',
			'permission_callback' => 'ornina_rest_verify_api_key',
		)
	);

	register_rest_route(
		'ornina/v1',
		'/apply-content',
		array(
			'methods'             => 'POST',
			'callback'            => 'ornina_rest_apply_content',
			'permission_callback' => 'ornina_rest_verify_api_key',
		)
	);
}
add_action( 'rest_api_init', 'ornina_register_product_content_routes' );

/**
 * Resolve product_id from a JSON body.
 *
 * @param WP_REST_Request $request Request.
 * @return int|WP_Error
 */
function ornina_rest_require_product_id( $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$product_id = isset( $body['product_id'] ) ? absint( $body['product_id'] ) : 0;
	if ( $product_id <= 0 ) {
		return new WP_Error(
			'ornina_missing_product_id',
			__( 'Request body must include a positive product_id.', 'wren-wold-ai-agent' ),
			array( 'status' => 400 )
		);
	}

	return $product_id;
}

/**
 * Build a product summary used by both endpoints.
 *
 * @param WC_Product $product Product.
 * @return array<string, mixed>
 */
function ornina_product_content_summary( $product ) {
	$product_id = $product->get_id();
	$image_id   = (int) $product->get_image_id();
	$image_url  = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'full' ) : '';

	$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
	if ( is_wp_error( $terms ) ) {
		$terms = array();
	}

	return array(
		'product_id'                 => $product_id,
		'name'                       => $product->get_name(),
		'description'                => $product->get_description(),
		'short_description'          => $product->get_short_description(),
		'status'                     => $product->get_status(),
		'category'                   => ! empty( $terms ) ? (string) $terms[0] : (string) $product->get_meta( '_ornina_source_category', true ),
		'categories'                 => array_values( array_map( 'strval', $terms ) ),
		'featured_image_url'         => $image_url,
		'cost'                       => (string) $product->get_meta( '_ornina_cost', true ),
		'model'                      => (string) $product->get_meta( '_ornina_model', true ),
		'model_number'               => (string) $product->get_meta( '_ornina_matterhorn_model_number', true ),
		'matterhorn_original_name'   => (string) $product->get_meta( '_ornina_matterhorn_original_name', true ),
		'seo_title'                  => (string) get_post_meta( $product_id, '_yoast_wpseo_title', true ),
		'seo_meta_description'       => (string) get_post_meta( $product_id, '_yoast_wpseo_metadesc', true ),
	);
}

/**
 * POST /wp-json/ornina/v1/generate-content
 *
 * Body: { "product_id": int }
 * Returns featured image URL + internal context for the Python Gemini vision step.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ornina_rest_generate_content_context( $request ) {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'ornina_woocommerce_missing',
			__( 'WooCommerce is not active.', 'wren-wold-ai-agent' ),
			array( 'status' => 503 )
		);
	}

	$product_id = ornina_rest_require_product_id( $request );
	if ( is_wp_error( $product_id ) ) {
		return $product_id;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return new WP_Error(
			'ornina_product_not_found',
			__( 'Product not found.', 'wren-wold-ai-agent' ),
			array( 'status' => 404 )
		);
	}

	return rest_ensure_response( ornina_product_content_summary( $product ) );
}

/**
 * POST /wp-json/ornina/v1/apply-content
 *
 * Body: {
 *   product_id, name, description, short_description,
 *   seo_title, seo_meta_description
 * }
 *
 * Updates title/content/excerpt (+ Yoast SEO when available).
 * Does NOT modify Matterhorn sourcing meta, SKU, price, or category.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ornina_rest_apply_content( $request ) {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'ornina_woocommerce_missing',
			__( 'WooCommerce is not active.', 'wren-wold-ai-agent' ),
			array( 'status' => 503 )
		);
	}

	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$product_id = ornina_rest_require_product_id( $request );
	if ( is_wp_error( $product_id ) ) {
		return $product_id;
	}

	$name               = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
	$description        = isset( $body['description'] ) ? wp_kses_post( (string) $body['description'] ) : '';
	$short_description  = isset( $body['short_description'] ) ? wp_kses_post( (string) $body['short_description'] ) : '';
	$seo_title          = isset( $body['seo_title'] ) ? sanitize_text_field( (string) $body['seo_title'] ) : '';
	$seo_meta_description = isset( $body['seo_meta_description'] ) ? sanitize_text_field( (string) $body['seo_meta_description'] ) : '';

	if ( '' === $name ) {
		return new WP_Error(
			'ornina_missing_name',
			__( 'Request body must include a non-empty name.', 'wren-wold-ai-agent' ),
			array( 'status' => 400 )
		);
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return new WP_Error(
			'ornina_product_not_found',
			__( 'Product not found.', 'wren-wold-ai-agent' ),
			array( 'status' => 404 )
		);
	}

	// Title / long description / short description only — never Matterhorn sourcing meta.
	$product->set_name( $name );
	$product->set_description( $description );
	$product->set_short_description( $short_description );
	$product->save();

	// Yoast SEO — skip gracefully when the plugin is inactive.
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options', false ) ) {
		if ( '' !== $seo_title ) {
			update_post_meta( $product_id, '_yoast_wpseo_title', $seo_title );
		}
		if ( '' !== $seo_meta_description ) {
			update_post_meta( $product_id, '_yoast_wpseo_metadesc', $seo_meta_description );
		}
	}

	// Reload so the summary reflects saved values.
	$product = wc_get_product( $product_id );

	return rest_ensure_response(
		array(
			'success' => true,
			'product' => ornina_product_content_summary( $product ),
		)
	);
}
