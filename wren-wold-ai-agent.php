<?php
/**
 * Plugin Name: Wren Wold AI Agent
 * Description: Ornina — in-house AI agent for Wren Wold
 * Version: 0.1.2
 * Author: Wren Wold
 * Text Domain: wren-wold-ai-agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ORNINA_PLUGIN_FILE', __FILE__ );
define( 'ORNINA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ORNINA_PLUGIN_URI', plugin_dir_url( __FILE__ ) );
define( 'ORNINA_PLUGIN_VERSION', '0.1.2' );

require_once ORNINA_PLUGIN_DIR . 'inc/admin/provider-settings.php';

if ( is_admin() ) {
	require_once ORNINA_PLUGIN_DIR . 'inc/importers/matterhorn-import.php';
	require ORNINA_PLUGIN_DIR . 'inc/admin/matterhorn-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ORNINA_PLUGIN_DIR . 'inc/importers/matterhorn-import.php';
}
