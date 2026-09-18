<?php
/**
 * Ornina product admin UI — read-only Matterhorn reference fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register a product metabox for Matterhorn original name.
 */
function ornina_register_matterhorn_name_metabox() {
	add_meta_box(
		'ornina_matterhorn_original_name',
		__( 'Matterhorn Original Name (for sourcing/reorder)', 'wren-wold-ai-agent' ),
		'ornina_render_matterhorn_name_metabox',
		'product',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'ornina_register_matterhorn_name_metabox' );

/**
 * Render read-only Matterhorn original name (+ model number when present).
 *
 * @param WP_Post $post Product post.
 */
function ornina_render_matterhorn_name_metabox( $post ) {
	$original = get_post_meta( $post->ID, '_ornina_matterhorn_original_name', true );
	$model_no = get_post_meta( $post->ID, '_ornina_matterhorn_model_number', true );
	$style    = get_post_meta( $post->ID, '_ornina_model', true );

	if ( '' === (string) $original ) {
		echo '<p>' . esc_html__( 'No Matterhorn original name stored for this product.', 'wren-wold-ai-agent' ) . '</p>';
		return;
	}
	?>
	<p>
		<label for="ornina_matterhorn_original_name_field">
			<strong><?php esc_html_e( 'Matterhorn Original Name (for sourcing/reorder)', 'wren-wold-ai-agent' ); ?></strong>
		</label>
	</p>
	<input
		type="text"
		id="ornina_matterhorn_original_name_field"
		class="widefat"
		value="<?php echo esc_attr( (string) $original ); ?>"
		readonly
		onclick="this.select();"
	/>
	<p class="description">
		<?php esc_html_e( 'Exact feed name — use this string when searching Matterhorn. It is not changed if you rename the product title.', 'wren-wold-ai-agent' ); ?>
	</p>
	<?php if ( '' !== (string) $model_no ) : ?>
		<p>
			<label for="ornina_matterhorn_model_number_field">
				<strong><?php esc_html_e( 'Matterhorn model number', 'wren-wold-ai-agent' ); ?></strong>
			</label>
		</p>
		<input
			type="text"
			id="ornina_matterhorn_model_number_field"
			class="widefat"
			value="<?php echo esc_attr( (string) $model_no ); ?>"
			readonly
			onclick="this.select();"
		/>
	<?php endif; ?>
	<?php if ( '' !== (string) $style ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: style key from feed code */
				esc_html__( 'Feed style/code key (_ornina_model): %s', 'wren-wold-ai-agent' ),
				esc_html( (string) $style )
			);
			?>
		</p>
	<?php endif; ?>
	<?php
}
