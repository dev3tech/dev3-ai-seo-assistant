<?php
/**
 * Minimal stand-in for All in One SEO's documented post model (AIOSEO is optional and not installed in
 * the test suite). Same public surface DEV3 uses: getPost(), properties, save().
 *
 * @package Dev3\AiSeoAssistant
 */

namespace AIOSEO\Plugin\Common\Models;

// phpcs:disable WordPress.NamingConventions, Generic.Files.OneObjectStructurePerFile

/**
 * Stub model.
 */
class Post {

	/**
	 * Saved rows by post id.
	 *
	 * @var array
	 */
	public static $rows = array();

	public $post_id        = 0;
	public $title          = '';
	public $description    = '';
	public $keyphrases     = '';
	public $og_title       = '';
	public $og_description = '';

	/**
	 * Loads (or creates) the row of a post.
	 *
	 * @param int $post_id Post id.
	 * @return Post
	 */
	public static function getPost( $post_id ) {
		$model = new self();
		if ( isset( self::$rows[ $post_id ] ) ) {
			foreach ( self::$rows[ $post_id ] as $k => $v ) {
				$model->$k = $v;
			}
		}
		return $model;
	}

	/**
	 * Persists the row.
	 */
	public function save() {
		self::$rows[ (int) $this->post_id ] = get_object_vars( $this );
	}
}
