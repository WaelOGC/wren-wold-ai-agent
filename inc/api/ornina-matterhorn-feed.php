<?php
/**
 * Ornina internal REST: read-only Matterhorn feed preview for the Python backend.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Ornina REST routes.
 */
function ornina_register_rest_routes() {
	register_rest_route(
		'ornina/v1',
		'/matterhorn/feed',
		array(
			'methods'             => 'GET',
			'callback'            => 'ornina_rest_matterhorn_feed_preview',
			'permission_callback' => 'ornina_rest_verify_api_key',
			'args'                => array(
				'category' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'quantity' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ornina_register_rest_routes' );

/**
 * Verify X-API-Key matches the saved Ornina API key (same shared secret as the Python backend).
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function ornina_rest_verify_api_key( $request ) {
	$provided = $request->get_header( 'X-API-Key' );
	$expected = (string) get_option( 'ornina_api_key', '' );

	if ( '' === $expected || ! is_string( $provided ) || ! hash_equals( $expected, $provided ) ) {
		return new WP_Error(
			'ornina_unauthorized',
			__( 'Unauthorized', 'wren-wold-ai-agent' ),
			array( 'status' => 401 )
		);
	}

	return true;
}

/**
 * GET /wp-json/ornina/v1/matterhorn/feed?category=dresses&quantity=10
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ornina_rest_matterhorn_feed_preview( $request ) {
	$category = (string) $request->get_param( 'category' );
	$quantity = (int) $request->get_param( 'quantity' );

	$result = fashion_brand_theme_matterhorn_preview_category_items( $category, $quantity );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			$result->get_error_code(),
			$result->get_error_message(),
			array( 'status' => 404 )
		);
	}

	return rest_ensure_response( $result );
}
