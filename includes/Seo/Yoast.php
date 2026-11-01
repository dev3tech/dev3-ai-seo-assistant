<?php
/**
 * Yoast SEO adapter (free): _yoast_wpseo_* post meta. Yoast rebuilds the post's indexable on
 * shutdown when its meta changes (Indexable_Post_Meta_Watcher), so the front end updates at once.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast SEO.
 */
class Yoast extends Adapter {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'yoast';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Yoast SEO';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Keys.
	 *
	 * @return array
	 */
	protected function keys() {
		return array(
			'seo_title'        => '_yoast_wpseo_title',
			'meta_description' => '_yoast_wpseo_metadesc',
			'focus_keyword'    => '_yoast_wpseo_focuskw',
			'og_title'         => '_yoast_wpseo_opengraph-title',
			'og_description'   => '_yoast_wpseo_opengraph-description',
			'schema_type'      => '_yoast_wpseo_schema_page_type',
		);
	}

	/**
	 * FAQ block.
	 *
	 * @return string
	 */
	public function faq_block() {
		return 'yoast/faq-block';
	}

	/**
	 * Editor store.
	 *
	 * @return string
	 */
	public function editor_store() {
		return 'yoast-seo/editor';
	}

	/**
	 * Schema type = page type when set, otherwise article type.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @return string
	 */
	public function get( $post_id, $field ) {
		if ( 'schema_type' === $field ) {
			$page = (string) get_post_meta( (int) $post_id, '_yoast_wpseo_schema_page_type', true );
			return '' !== $page ? $page : (string) get_post_meta( (int) $post_id, '_yoast_wpseo_schema_article_type', true );
		}
		return parent::get( $post_id, $field );
	}

	/**
	 * Page types go to the page-type field; article types to the article-type field (and clear the page type,
	 * so the article type wins); '' clears both.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @param string $value   Value.
	 * @return true|\WP_Error
	 */
	public function set( $post_id, $field, $value ) {
		if ( 'schema_type' !== $field ) {
			return parent::set( $post_id, $field, $value );
		}
		if ( '' === $value ) {
			$this->write_meta( $post_id, '_yoast_wpseo_schema_page_type', '' );
			$this->write_meta( $post_id, '_yoast_wpseo_schema_article_type', '' );
		} elseif ( in_array( $value, Fields::ARTICLE_TYPES, true ) ) {
			$this->write_meta( $post_id, '_yoast_wpseo_schema_article_type', $value );
			$this->write_meta( $post_id, '_yoast_wpseo_schema_page_type', '' );
		} elseif ( in_array( $value, Fields::PAGE_TYPES, true ) ) {
			$this->write_meta( $post_id, '_yoast_wpseo_schema_page_type', $value );
		} else {
			return new \WP_Error( 'dev3se_bad_schema_type', __( 'Unknown schema type.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		$this->after_write( $post_id );
		return true;
	}
}
