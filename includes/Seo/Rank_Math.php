<?php
/**
 * Rank Math adapter (free): rank_math_* post meta. The focus keyword is a comma-separated list
 * (primary first). Schema type is a DEV3 override applied to Rank Math's JSON-LD graph, so Rank Math's
 * own schema templates are never rewritten.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Rank Math.
 */
class Rank_Math extends Adapter {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'rankmath';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Rank Math';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Keys.
	 *
	 * @return array
	 */
	protected function keys() {
		return array(
			'seo_title'        => 'rank_math_title',
			'meta_description' => 'rank_math_description',
			'focus_keyword'    => 'rank_math_focus_keyword',
			'og_title'         => 'rank_math_facebook_title',
			'og_description'   => 'rank_math_facebook_description',
			'schema_type'      => Native::SCHEMA_TYPE_KEY,
		);
	}

	/**
	 * FAQ block.
	 *
	 * @return string
	 */
	public function faq_block() {
		return 'rank-math/faq-block';
	}

	/**
	 * Comma-separated keywords.
	 *
	 * @return bool
	 */
	public function multi_keywords() {
		return true;
	}

	/**
	 * Editor store.
	 *
	 * @return string
	 */
	public function editor_store() {
		return 'rank-math';
	}

	/**
	 * Keyword list normalised (max 5, Rank Math free's limit).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @param string $value   Value.
	 * @return true|\WP_Error
	 */
	public function set( $post_id, $field, $value ) {
		if ( 'focus_keyword' === $field && '' !== $value ) {
			$parts = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $value ) ) ) ) );
			$value = implode( ',', array_slice( $parts, 0, 5 ) );
		}
		return parent::set( $post_id, $field, $value );
	}
}
