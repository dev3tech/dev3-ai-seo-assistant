<?php
/**
 * Field registry: every value DEV3 can draft, apply and undo.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Field keys, labels and limits.
 */
class Fields {

	/**
	 * Fields stored by the active SEO plugin (adapter).
	 */
	const ADAPTER = array( 'seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description', 'schema_type' );

	/**
	 * Fields stored by DEV3 itself.
	 */
	const PLUGIN = array( 'schema_jsonld', 'content' );

	/**
	 * Meta fields the meta writer generates.
	 */
	const META = array( 'seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description' );

	/**
	 * Article-family schema types.
	 */
	const ARTICLE_TYPES = array( 'Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle', 'AdvertiserContentArticle', 'SatiricalArticle', 'ScholarlyArticle', 'TechArticle', 'Report' );

	/**
	 * Page-family schema types.
	 */
	const PAGE_TYPES = array( 'WebPage', 'ItemPage', 'AboutPage', 'FAQPage', 'QAPage', 'ProfilePage', 'ContactPage', 'MedicalWebPage', 'CollectionPage', 'CheckoutPage', 'RealEstateListing', 'SearchResultsPage' );

	/**
	 * All field keys.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array_merge( self::ADAPTER, self::PLUGIN );
	}

	/**
	 * Valid key?
	 *
	 * @param string $field Field.
	 * @return bool
	 */
	public static function valid( $field ) {
		return in_array( $field, self::all(), true );
	}

	/**
	 * Valid schema type value?
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function valid_schema_type( $type ) {
		return '' === $type || in_array( $type, self::ARTICLE_TYPES, true ) || in_array( $type, self::PAGE_TYPES, true );
	}

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'seo_title'        => __( 'SEO title', 'dev3-ai-seo-assistant' ),
			'meta_description' => __( 'Meta description', 'dev3-ai-seo-assistant' ),
			'focus_keyword'    => __( 'Focus keyword', 'dev3-ai-seo-assistant' ),
			'og_title'         => __( 'Social title', 'dev3-ai-seo-assistant' ),
			'og_description'   => __( 'Social description', 'dev3-ai-seo-assistant' ),
			'schema_type'      => __( 'Schema type', 'dev3-ai-seo-assistant' ),
			'schema_jsonld'    => __( 'Schema (JSON-LD)', 'dev3-ai-seo-assistant' ),
			'content'          => __( 'Post content', 'dev3-ai-seo-assistant' ),
		);
	}

	/**
	 * One label.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public static function label( $field ) {
		$labels = self::labels();
		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * Maximum length for a field (0 = none).
	 *
	 * @param string $field Field.
	 * @return int
	 */
	public static function max( $field ) {
		switch ( $field ) {
			case 'seo_title':
				return (int) Settings::get( 'title_max', 60 );
			case 'meta_description':
				return (int) Settings::get( 'description_max', 155 );
			case 'og_title':
				return 90;
			case 'og_description':
				return 200;
			case 'focus_keyword':
				return 191;
		}
		return 0;
	}

	/**
	 * Sanitises a value for a field.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return string|\WP_Error
	 */
	public static function sanitize( $field, $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		switch ( $field ) {
			case 'seo_title':
			case 'og_title':
			case 'focus_keyword':
				return mb_substr( trim( sanitize_text_field( $value ) ), 0, 191 );
			case 'meta_description':
			case 'og_description':
				return mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_textarea_field( $value ) ) ), 0, 400 );
			case 'schema_type':
				$value = trim( sanitize_text_field( $value ) );
				return self::valid_schema_type( $value ) ? $value : new \WP_Error( 'dev3se_bad_schema_type', __( 'Unknown schema type.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
			case 'schema_jsonld':
				return Schema_Output::normalize_json( $value );
			case 'content':
				return wp_kses_post( $value );
		}
		return new \WP_Error( 'dev3se_bad_field', __( 'Unknown field.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
	}

	/**
	 * Field descriptors for the UI.
	 *
	 * @return array
	 */
	public static function describe() {
		$out = array();
		foreach ( self::all() as $field ) {
			$out[] = array(
				'key'   => $field,
				'label' => self::label( $field ),
				'max'   => self::max( $field ),
			);
		}
		return $out;
	}
}
