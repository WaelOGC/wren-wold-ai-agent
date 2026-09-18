<?php
/**
 * Ornina Chat — Phase 1 internal chat admin UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Ornina Chat under the Ornina Settings menu.
 */
function ornina_register_chat_menu() {
	add_submenu_page(
		'ornina-settings',
		__( 'Ornina Chat', 'wren-wold-ai-agent' ),
		__( 'Ornina Chat', 'wren-wold-ai-agent' ),
		'manage_options',
		'ornina-chat',
		'ornina_render_chat_page'
	);
}
add_action( 'admin_menu', 'ornina_register_chat_menu' );

/**
 * Enqueue chat assets on the Ornina Chat admin page only.
 *
 * @param string $hook Current admin hook.
 */
function ornina_chat_admin_assets( $hook ) {
	if ( 'ornina-settings_page_ornina-chat' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'ornina-chat-admin',
		ORNINA_PLUGIN_URI . 'assets/js/admin/ornina-chat.js',
		array(),
		ORNINA_PLUGIN_VERSION,
		true
	);

	wp_localize_script(
		'ornina-chat-admin',
		'orninaChatAdmin',
		array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'ornina_chat_send' ),
			'conversationId' => wp_generate_password( 32, false, false ),
			'i18n'           => array(
				'sending' => __( 'Sending…', 'wren-wold-ai-agent' ),
				'error'   => __( 'Chat request failed.', 'wren-wold-ai-agent' ),
				'empty'   => __( 'Please enter a message.', 'wren-wold-ai-agent' ),
			),
		)
	);

	wp_register_style( 'ornina-chat-admin', false, array(), ORNINA_PLUGIN_VERSION );
	wp_enqueue_style( 'ornina-chat-admin' );
	wp_add_inline_style(
		'ornina-chat-admin',
		'
		.ornina-chat-wrap{max-width:720px}
		.ornina-chat-log{height:420px;overflow-y:auto;background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0;display:flex;flex-direction:column;gap:10px}
		.ornina-chat-msg{max-width:80%;padding:10px 12px;border-radius:8px;line-height:1.45;white-space:pre-wrap;word-break:break-word}
		.ornina-chat-msg--user{align-self:flex-end;background:#2271b1;color:#fff}
		.ornina-chat-msg--assistant{align-self:flex-start;background:#f0f0f1;color:#1d2327}
		.ornina-chat-msg--error{align-self:center;background:#fcf0f1;color:#b32d2e;border:1px solid #d63638}
		.ornina-chat-form{display:flex;gap:8px;align-items:flex-start}
		.ornina-chat-form textarea{flex:1;min-height:64px;resize:vertical}
		.ornina-chat-form .button{height:auto;min-height:36px}
		'
	);
}
add_action( 'admin_enqueue_scripts', 'ornina_chat_admin_assets' );

/**
 * Render the Ornina Chat admin page.
 */
function ornina_render_chat_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap ornina-chat-wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Phase 1 test chat with the Ornina backend (Gemini). Conversation resets on page reload.', 'wren-wold-ai-agent' ); ?></p>
		<div id="ornina-chat-log" class="ornina-chat-log" aria-live="polite"></div>
		<form id="ornina-chat-form" class="ornina-chat-form">
			<label class="screen-reader-text" for="ornina-chat-input"><?php esc_html_e( 'Message', 'wren-wold-ai-agent' ); ?></label>
			<textarea id="ornina-chat-input" name="message" rows="3" placeholder="<?php esc_attr_e( 'Type a message…', 'wren-wold-ai-agent' ); ?>"></textarea>
			<button type="submit" class="button button-primary" id="ornina-chat-send"><?php esc_html_e( 'Send', 'wren-wold-ai-agent' ); ?></button>
		</form>
	</div>
	<?php
}

/**
 * AJAX: forward a chat message to the Ornina Python backend.
 */
function ornina_ajax_chat_send() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wren-wold-ai-agent' ) ), 403 );
	}

	check_ajax_referer( 'ornina_chat_send', 'nonce' );

	$message         = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['conversation_id'] ) ) : '';

	if ( '' === $message || '' === $conversation_id ) {
		wp_send_json_error( array( 'message' => __( 'Message and conversation_id are required.', 'wren-wold-ai-agent' ) ), 400 );
	}

	$backend_url = get_option( 'ornina_backend_url', 'http://31-97-78-82.sslip.io' );
	if ( '' === $backend_url ) {
		$backend_url = 'http://31-97-78-82.sslip.io';
	}
	$backend_url = untrailingslashit( $backend_url );

	$api_key        = get_option( 'ornina_api_key', '' );
	$gemini_api_key = get_option( 'ornina_api_key_gemini', '' );

	if ( '' === $api_key ) {
		wp_send_json_error( array( 'message' => __( 'Ornina API key is not configured.', 'wren-wold-ai-agent' ) ), 400 );
	}
	if ( '' === $gemini_api_key ) {
		wp_send_json_error( array( 'message' => __( 'Google Gemini API key is not configured.', 'wren-wold-ai-agent' ) ), 400 );
	}

	$endpoint = $backend_url . '/internal/chat';
	$response = wp_remote_post(
		$endpoint,
		array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-API-Key'    => $api_key,
			),
			'body'    => wp_json_encode(
				array(
					'conversation_id' => $conversation_id,
					'message'         => $message,
					'gemini_api_key'  => $gemini_api_key,
				)
			),
			'timeout' => 60,
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error(
			array(
				'message'  => $response->get_error_message(),
				'endpoint' => $endpoint,
			),
			502
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = wp_remote_retrieve_body( $response );
	$data   = json_decode( $body, true );

	if ( $status < 200 || $status >= 300 ) {
		$detail = '';
		if ( is_array( $data ) && isset( $data['detail'] ) ) {
			$detail = is_string( $data['detail'] ) ? $data['detail'] : wp_json_encode( $data['detail'] );
		} else {
			$detail = $body;
		}
		wp_send_json_error(
			array(
				'message'  => $detail ? $detail : __( 'Backend chat request failed.', 'wren-wold-ai-agent' ),
				'status'   => $status,
				'endpoint' => $endpoint,
			),
			$status > 0 ? $status : 502
		);
	}

	if ( ! is_array( $data ) || ! isset( $data['reply'] ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Unexpected backend response.', 'wren-wold-ai-agent' ),
				'body'    => $body,
			),
			502
		);
	}

	wp_send_json_success(
		array(
			'reply'           => $data['reply'],
			'conversation_id' => isset( $data['conversation_id'] ) ? $data['conversation_id'] : $conversation_id,
		)
	);
}
add_action( 'wp_ajax_ornina_chat_send', 'ornina_ajax_chat_send' );
