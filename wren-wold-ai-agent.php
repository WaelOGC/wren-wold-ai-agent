<?php
/**
 * Plugin Name: Wren Wold AI Agent
 * Description: Ornina — in-house AI agent for Wren Wold
 * Version: 0.1.0
 * Author: Wren Wold
 * Text Domain: wren-wold-ai-agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ORNINA_PLUGIN_FILE', __FILE__ );
define( 'ORNINA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once ORNINA_PLUGIN_DIR . 'inc/admin/provider-settings.php';
