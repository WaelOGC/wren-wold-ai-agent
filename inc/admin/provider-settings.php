<?php
/**
 * Ornina — AI provider API key settings (WordPress admin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Ornina Settings admin menu and AI Providers submenu.
 */
function ornina_register_provider_settings_menu() {
	add_menu_page(
		__( 'Ornina Settings', 'wren-wold-ai-agent' ),
		__( 'Ornina Settings', 'wren-wold-ai-agent' ),
		'manage_options',
		'ornina-settings',
		'ornina_render_provider_settings_page',
		'dashicons-admin-generic'
	);

	add_submenu_page(
		'ornina-settings',
		__( 'AI Providers', 'wren-wold-ai-agent' ),
		__( 'AI Providers', 'wren-wold-ai-agent' ),
		'manage_options',
		'ornina-settings',
		'ornina_render_provider_settings_page'
	);
}
add_action( 'admin_menu', 'ornina_register_provider_settings_menu' );

/**
 * Register settings, section, and fields via the Settings API.
 */
function ornina_register_provider_settings() {
	$providers = ornina_get_provider_definitions();

	register_setting(
		'ornina_provider_settings',
		'ornina_active_provider',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	foreach ( $providers as $slug => $label ) {
		register_setting(
			'ornina_provider_settings',
			'ornina_api_key_' . $slug,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	add_settings_section(
		'ornina_providers_section',
		__( 'AI Providers', 'wren-wold-ai-agent' ),
		'__return_false',
		'ornina-settings'
	);

	add_settings_field(
		'ornina_active_provider',
		__( 'Active Provider', 'wren-wold-ai-agent' ),
		'ornina_render_active_provider_field',
		'ornina-settings',
		'ornina_providers_section'
	);

	foreach ( $providers as $slug => $label ) {
		add_settings_field(
			'ornina_api_key_' . $slug,
			$label,
			'ornina_render_api_key_field',
			'ornina-settings',
			'ornina_providers_section',
			array(
				'slug'  => $slug,
				'label' => $label,
			)
		);
	}
}
add_action( 'admin_init', 'ornina_register_provider_settings' );

/**
 * Provider slug => label map.
 *
 * @return array<string, string>
 */
function ornina_get_provider_definitions() {
	return array(
		'gemini'   => __( 'Google Gemini', 'wren-wold-ai-agent' ),
		'openai'   => __( 'OpenAI (GPT)', 'wren-wold-ai-agent' ),
		'claude'   => __( 'Anthropic (Claude)', 'wren-wold-ai-agent' ),
		'deepseek' => __( 'DeepSeek', 'wren-wold-ai-agent' ),
		'mistral'  => __( 'Mistral', 'wren-wold-ai-agent' ),
		'grok'     => __( 'xAI (Grok)', 'wren-wold-ai-agent' ),
	);
}

/**
 * Render the Active Provider select field.
 */
function ornina_render_active_provider_field() {
	$providers = ornina_get_provider_definitions();
	$current   = get_option( 'ornina_active_provider', '' );
	?>
	<select name="ornina_active_provider" id="ornina_active_provider">
		<option value=""><?php esc_html_e( '— Select provider —', 'wren-wold-ai-agent' ); ?></option>
		<?php foreach ( $providers as $slug => $label ) : ?>
			<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Render a masked API key field with show/hide toggle.
 *
 * @param array $args Field args (slug, label).
 */
function ornina_render_api_key_field( $args ) {
	$slug    = isset( $args['slug'] ) ? $args['slug'] : '';
	$option  = 'ornina_api_key_' . $slug;
	$value   = get_option( $option, '' );
	$field_id = esc_attr( $option );
	?>
	<input
		type="password"
		name="<?php echo esc_attr( $option ); ?>"
		id="<?php echo $field_id; ?>"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
		autocomplete="off"
	/>
	<button
		type="button"
		class="button ornina-toggle-visibility"
		data-target="<?php echo $field_id; ?>"
		aria-label="<?php esc_attr_e( 'Show or hide API key', 'wren-wold-ai-agent' ); ?>"
	>
		<?php esc_html_e( 'Show', 'wren-wold-ai-agent' ); ?>
	</button>
	<?php
}

/**
 * Render the AI Providers settings page.
 */
function ornina_render_provider_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Enter API keys for the AI providers Ornina can use. Select which provider is active below.', 'wren-wold-ai-agent' ); ?></p>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'ornina_provider_settings' );
			do_settings_sections( 'ornina-settings' );
			submit_button();
			?>
		</form>
	</div>
	<script>
	(function () {
		document.querySelectorAll('.ornina-toggle-visibility').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var input = document.getElementById(btn.getAttribute('data-target'));
				if (!input) {
					return;
				}
				if (input.type === 'password') {
					input.type = 'text';
					btn.textContent = '<?php echo esc_js( __( 'Hide', 'wren-wold-ai-agent' ) ); ?>';
				} else {
					input.type = 'password';
					btn.textContent = '<?php echo esc_js( __( 'Show', 'wren-wold-ai-agent' ) ); ?>';
				}
			});
		});
	})();
	</script>
	<?php
}
