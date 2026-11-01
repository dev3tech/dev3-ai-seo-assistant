<?php
/**
 * Front-end output for the native adapter (no SEO plugin active): <title>, meta description, Open Graph.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Native head tags.
 */
class Native_Output {

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'pre_get_document_title', array( $this, 'title' ), 20 );
		add_action( 'wp_head', array( $this, 'head' ), 2 );
	}

	/**
	 * Whether DEV3 prints SEO tags.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return Settings::get( 'native_output', true ) && 'native' === Adapters::current_id() && ! Adapters::has_seo_plugin();
	}

	/**
	 * Current singular post id.
	 *
	 * @return int
	 */
	private function post_id() {
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Document title.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public function title( $title ) {
		$id = $this->post_id();
		if ( ! $id || ! self::enabled() ) {
			return $title;
		}
		$custom = (string) get_post_meta( $id, '_dev3se_title', true );
		return '' !== $custom ? $custom : $title;
	}

	/**
	 * Meta tags.
	 */
	public function head() {
		$id = $this->post_id();
		if ( ! $id || ! self::enabled() ) {
			return;
		}
		$desc     = (string) get_post_meta( $id, '_dev3se_description', true );
		$og_title = (string) get_post_meta( $id, '_dev3se_og_title', true );
		$og_desc  = (string) get_post_meta( $id, '_dev3se_og_description', true );
		$title    = (string) get_post_meta( $id, '_dev3se_title', true );
		$og_title = '' !== $og_title ? $og_title : ( '' !== $title ? $title : \Dev3\AiSeoAssistant\Content\Analyzer::title( $id ) );
		$og_desc  = '' !== $og_desc ? $og_desc : $desc;
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . "\" />\n";
		}
		echo '<meta property="og:type" content="' . esc_attr( 'post' === get_post_type( $id ) ? 'article' : 'website' ) . "\" />\n";
		echo '<meta property="og:title" content="' . esc_attr( $og_title ) . "\" />\n";
		if ( '' !== $og_desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . "\" />\n";
		}
		echo '<meta property="og:url" content="' . esc_url( get_permalink( $id ) ) . "\" />\n";
		$thumb = get_the_post_thumbnail_url( $id, 'large' );
		if ( $thumb ) {
			echo '<meta property="og:image" content="' . esc_url( $thumb ) . "\" />\n";
		}
		echo '<meta name="twitter:card" content="' . esc_attr( $thumb ? 'summary_large_image' : 'summary' ) . "\" />\n";
	}
}
