<?php
/**
 * Plugin Name:       DEV3 AI SEO Assistant for Yoast & Rank Math
 * Plugin URI:        https://dev3.tech/item/dev3-ai-seo-assistant
 * Description:       AI meta titles, descriptions, focus keywords, schema, internal links, content gaps and FAQ blocks, written into Yoast SEO and Rank Math fields as reviewable drafts.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-seo-assistant
 * Domain Path:       /languages
 *
 * @package Dev3\AiSeoAssistant
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3SE_VERSION', '1.0.0' );
define( 'DEV3SE_FILE', __FILE__ );
define( 'DEV3SE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3SE_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3SE_SLUG', 'dev3-ai-seo-assistant' );
define( 'DEV3SE_NAME', 'DEV3 AI SEO Assistant for Yoast & Rank Math' );
define( 'DEV3SE_MIN_PHP', '7.4' );
define( 'DEV3SE_MIN_WP', '6.0' );
define( 'DEV3SE_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3SE_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiSeoAssistant\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3SE_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiSeoAssistant\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiSeoAssistant\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiSeoAssistant\Plugin', 'instance' ) );
