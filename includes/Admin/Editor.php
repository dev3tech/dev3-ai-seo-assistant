<?php
/**
 * Block editor integration: the "DEV3 AI SEO" sidebar.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Admin;

use Dev3\AiSeoAssistant\Core3\Core\Assets;
use Dev3\AiSeoAssistant\Core3\Core\Capabilities;
use Dev3\AiSeoAssistant\Plugin;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Editor.
 */
class Editor {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Sidebar bundle (post editor only, enabled post types, users who can generate).
	 */
	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( $screen->post_type, Settings::post_types(), true ) || ! Capabilities::can_generate() ) {
			return;
		}
		Plugin::assets();
		Assets::enqueue( 'editor' );
	}
}
