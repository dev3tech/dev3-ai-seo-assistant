<?php
/**
 * Native adapter: DEV3 stores the fields itself and prints them (Native_Output) when no SEO plugin is active.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Native DEV3 meta.
 */
class Native extends Adapter {

	const SCHEMA_TYPE_KEY = '_dev3se_schema_type';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'native';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'DEV3 native meta', 'dev3-ai-seo-assistant' );
	}

	/**
	 * Always available.
	 *
	 * @return bool
	 */
	public function active() {
		return true;
	}

	/**
	 * Keys.
	 *
	 * @return array
	 */
	protected function keys() {
		return array(
			'seo_title'        => '_dev3se_title',
			'meta_description' => '_dev3se_description',
			'focus_keyword'    => '_dev3se_focus_keyword',
			'og_title'         => '_dev3se_og_title',
			'og_description'   => '_dev3se_og_description',
			'schema_type'      => self::SCHEMA_TYPE_KEY,
		);
	}
}
