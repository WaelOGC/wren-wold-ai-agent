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

	register_setting(
		'ornina_provider_settings',
		'ornina_backend_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => 'http://31-97-78-82.sslip.io',
		)
	);

	register_setting(
		'ornina_provider_settings',
		'ornina_api_key',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	add_settings_section(
		'ornina_backend_section',
		__( 'Ornina Backend', 'wren-wold-ai-agent' ),
		'__return_false',
		'ornina-settings'
	);

	add_settings_field(
		'ornina_backend_url',
		__( 'Ornina Backend URL', 'wren-wold-ai-agent' ),
		'ornina_render_backend_url_field',
		'ornina-settings',
		'ornina_backend_section'
	);

	add_settings_field(
		'ornina_api_key',
		__( 'Ornina API Key', 'wren-wold-ai-agent' ),
		'ornina_render_ornina_api_key_field',
		'ornina-settings',
		'ornina_backend_section'
	);
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
 * Render the Ornina Backend URL field.
 */
function ornina_render_backend_url_field() {
	$value = get_option( 'ornina_backend_url', 'http://31-97-78-82.sslip.io' );
	if ( '' === $value ) {
		$value = 'http://31-97-78-82.sslip.io';
	}
	?>
	<input
		type="url"
		name="ornina_backend_url"
		id="ornina_backend_url"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
	/>
	<?php
}

/**
 * Render the Ornina backend API key field (masked).
 */
function ornina_render_ornina_api_key_field() {
	$value = get_option( 'ornina_api_key', '' );
	?>
	<input
		type="password"
		name="ornina_api_key"
		id="ornina_api_key"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
		autocomplete="off"
	/>
	<button
		type="button"
		class="button ornina-toggle-visibility"
		data-target="ornina_api_key"
		aria-label="<?php esc_attr_e( 'Show or hide API key', 'wren-wold-ai-agent' ); ?>"
	>
		<?php esc_html_e( 'Show', 'wren-wold-ai-agent' ); ?>
	</button>
	<?php
}

/**
 * Handle Test Connection admin-post action.
 */
function ornina_handle_test_connection() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wren-wold-ai-agent' ) );
	}

	check_admin_referer( 'ornina_test_connection' );

	$backend_url = get_option( 'ornina_backend_url', 'http://31-97-78-82.sslip.io' );
	if ( '' === $backend_url ) {
		$backend_url = 'http://31-97-78-82.sslip.io';
	}
	$backend_url = untrailingslashit( $backend_url );
	$api_key     = get_option( 'ornina_api_key', '' );
	$endpoint    = $backend_url . '/internal/ping';

	$response = wp_remote_get(
		$endpoint,
		array(
			'headers' => array(
				'X-API-Key' => $api_key,
			),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		$result = array(
			'ok'      => false,
			'status'  => 0,
			'body'    => $response->get_error_message(),
			'endpoint'=> $endpoint,
		);
	} else {
		$result = array(
			'ok'       => true,
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'body'     => wp_remote_retrieve_body( $response ),
			'endpoint' => $endpoint,
		);
	}

	set_transient( 'ornina_test_connection_result_' . get_current_user_id(), $result, 60 );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                 => 'ornina-settings',
				'ornina_tested'        => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_ornina_test_connection', 'ornina_handle_test_connection' );

/**
 * Render the AI Providers settings page.
 */
function ornina_render_provider_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$test_result = false;
	if ( isset( $_GET['ornina_tested'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$test_result = get_transient( 'ornina_test_connection_result_' . get_current_user_id() );
		delete_transient( 'ornina_test_connection_result_' . get_current_user_id() );
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

		<hr />
		<h2><?php esc_html_e( 'Test Connection', 'wren-wold-ai-agent' ); ?></h2>
		<p><?php esc_html_e( 'Save settings above first, then test connectivity to the Ornina backend /internal/ping endpoint.', 'wren-wold-ai-agent' ); ?></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="ornina_test_connection" />
			<?php wp_nonce_field( 'ornina_test_connection' ); ?>
			<?php submit_button( __( 'Test Connection', 'wren-wold-ai-agent' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( is_array( $test_result ) ) : ?>
			<h3><?php esc_html_e( 'Connection result', 'wren-wold-ai-agent' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'Endpoint:', 'wren-wold-ai-agent' ); ?></strong>
				<code><?php echo esc_html( isset( $test_result['endpoint'] ) ? $test_result['endpoint'] : '' ); ?></code>
			</p>
			<p>
				<strong><?php esc_html_e( 'HTTP status:', 'wren-wold-ai-agent' ); ?></strong>
				<?php echo esc_html( (string) ( isset( $test_result['status'] ) ? $test_result['status'] : 0 ) ); ?>
			</p>
			<pre style="max-width:100%;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;"><?php
			echo esc_html( isset( $test_result['body'] ) ? $test_result['body'] : '' );
			?></pre>
		<?php endif; ?>
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
