<?php
/**
 * SEOPress adapter (optional): _seopress_* post meta.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * SEOPress.
 */
class Seopress extends Adapter {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'seopress';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'SEOPress';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Keys.
	 *
	 * @return array
	 */
	protected function keys() {
		return array(
			'seo_title'        => '_seopress_titles_title',
			'meta_description' => '_seopress_titles_desc',
			'focus_keyword'    => '_seopress_analysis_target_kw',
			'og_title'         => '_seopress_social_fb_title',
			'og_description'   => '_seopress_social_fb_desc',
			'schema_type'      => Native::SCHEMA_TYPE_KEY,
		);
	}

	/**
	 * SEOPress stores several keywords comma-separated.
	 *
	 * @return bool
	 */
	public function multi_keywords() {
		return true;
	}
}
