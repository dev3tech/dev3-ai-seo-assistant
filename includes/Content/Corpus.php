<?php
/**
 * Site corpus: iterates the posts DEV3 works on in memory-friendly pages, and a revision counter that
 * invalidates cached reports when posts or SEO meta change.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Content;

use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Corpus helpers.
 */
class Corpus {

	const REV_OPTION = 'dev3se_corpus_rev';
	const PAGE       = 200;

	/**
	 * Meta key prefixes that affect reports.
	 */
	const META_PREFIXES = array( '_yoast_wpseo_', 'rank_math_', '_seopress_', '_dev3se_' );

	/**
	 * Hooks that bump the revision.
	 */
	public static function register() {
		add_action( 'save_post', array( self::class, 'bump' ) );
		add_action( 'deleted_post', array( self::class, 'bump' ) );
		add_action( 'transition_post_status', array( self::class, 'bump' ) );
		add_action( 'dev3se_after_write', array( self::class, 'bump' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'meta_changed' ), 10, 3 );
		}
	}

	/**
	 * Bumps on SEO meta changes only.
	 *
	 * @param mixed  $meta_id  Meta id(s).
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Key.
	 */
	public static function meta_changed( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id, $post_id );
		foreach ( self::META_PREFIXES as $prefix ) {
			if ( is_string( $meta_key ) && 0 === strpos( $meta_key, $prefix ) ) {
				self::bump();
				return;
			}
		}
	}

	/**
	 * Increments the revision (once per request).
	 */
	public static function bump() {
		static $done = false;
		if ( $done && ! defined( 'WP_TESTS_DOMAIN' ) ) {
			return;
		}
		$done = true;
		update_option( self::REV_OPTION, (int) get_option( self::REV_OPTION, 0 ) + 1, false );
	}

	/**
	 * Current revision.
	 *
	 * @return int
	 */
	public static function rev() {
		return (int) get_option( self::REV_OPTION, 0 );
	}

	/**
	 * Statuses included in reports and bulk lists.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'publish', 'draft', 'pending', 'future', 'private' );
	}

	/**
	 * Maximum posts scanned by site reports.
	 *
	 * @return int
	 */
	public static function limit() {
		/**
		 * Maximum number of posts the site reports and link graph scan.
		 *
		 * @param int $limit Limit.
		 */
		return max( 50, (int) apply_filters( 'dev3se_corpus_limit', 3000 ) );
	}

	/**
	 * Calls $callback( WP_Post ) for each post, in pages.
	 *
	 * @param callable $callback Callback.
	 * @param array    $statuses Statuses (default: all report statuses).
	 */
	public static function each( callable $callback, array $statuses = array() ) {
		$statuses = $statuses ? $statuses : self::statuses();
		$limit    = self::limit();
		$seen     = 0;
		$page     = 1;
		do {
			$posts = get_posts(
				array(
					'post_type'              => Settings::post_types(),
					'post_status'            => $statuses,
					'posts_per_page'         => self::PAGE,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'suppress_filters'       => true,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $posts as $post ) {
				$callback( $post );
				if ( ++$seen >= $limit ) {
					return;
				}
			}
			++$page;
			$fetched = count( $posts );
		} while ( self::PAGE === $fetched );
	}
}
