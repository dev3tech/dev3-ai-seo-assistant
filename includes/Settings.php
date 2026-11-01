<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant;

use Dev3\AiSeoAssistant\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3se_settings_defaults / dev3se_settings_sanitize.
 */
class Settings {

	const ADAPTERS = array( 'auto', 'yoast', 'rankmath', 'seopress', 'aioseo', 'native' );

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'seo_adapter'       => 'auto',
				'title_max'         => 60,
				'description_min'   => 120,
				'description_max'   => 155,
				'title_separator'   => '-',
				'brand_suffix'      => true,
				'brand_name'        => '',
				'content_language'  => '',
				'tone'              => 'clear',
				'instructions'      => '',
				'post_types'        => array( 'post', 'page' ),
				'link_suggestions'  => 5,
				'link_min_score'    => 0.55,
				'auto_index'        => true,
				'faq_count'         => 5,
				'schema_output'     => true,
				'native_output'     => true,
				'posts_list_column' => true,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		if ( isset( $input['seo_adapter'] ) && in_array( $input['seo_adapter'], self::ADAPTERS, true ) ) {
			$out['seo_adapter'] = $input['seo_adapter'];
		}
		$ints = array(
			'title_max'        => array( 30, 80 ),
			'description_min'  => array( 50, 160 ),
			'description_max'  => array( 80, 320 ),
			'link_suggestions' => array( 1, 15 ),
			'faq_count'        => array( 2, 10 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['link_min_score'] ) && is_numeric( $input['link_min_score'] ) ) {
			$out['link_min_score'] = round( min( 0.95, max( 0.1, (float) $input['link_min_score'] ) ), 2 );
		}
		foreach ( array( 'brand_suffix', 'auto_index', 'schema_output', 'native_output', 'posts_list_column' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		if ( isset( $input['title_separator'] ) ) {
			$sep                    = trim( sanitize_text_field( wp_unslash( (string) $input['title_separator'] ) ) );
			$out['title_separator'] = '' === $sep ? '-' : mb_substr( $sep, 0, 3 );
		}
		if ( isset( $input['brand_name'] ) ) {
			$out['brand_name'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['brand_name'] ) ), 0, 60 );
		}
		if ( isset( $input['content_language'] ) ) {
			$out['content_language'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['content_language'] ) ), 0, 40 );
		}
		if ( isset( $input['tone'] ) && in_array( $input['tone'], array_keys( self::tones() ), true ) ) {
			$out['tone'] = $input['tone'];
		}
		if ( isset( $input['instructions'] ) ) {
			$out['instructions'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['instructions'] ) ), 0, 1000 );
		}
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$types             = array_values( array_filter( array_map( 'sanitize_key', $input['post_types'] ), 'post_type_exists' ) );
			$out['post_types'] = $types ? $types : array( 'post' );
		}
		if ( isset( $out['description_min'], $out['description_max'] ) && $out['description_min'] >= $out['description_max'] ) {
			$out['description_min'] = max( 50, $out['description_max'] - 30 );
		}
		return $out;
	}

	/**
	 * Tone presets.
	 *
	 * @return array
	 */
	public static function tones() {
		return array(
			'clear'        => __( 'Clear and helpful', 'dev3-ai-seo-assistant' ),
			'friendly'     => __( 'Friendly', 'dev3-ai-seo-assistant' ),
			'professional' => __( 'Professional', 'dev3-ai-seo-assistant' ),
			'bold'         => __( 'Bold and punchy', 'dev3-ai-seo-assistant' ),
			'technical'    => __( 'Technical', 'dev3-ai-seo-assistant' ),
		);
	}

	/**
	 * Shortcut getter.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Post types the plugin works on (public ones only).
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = (array) self::get( 'post_types', array( 'post', 'page' ) );
		$types = array_values( array_filter( $types, 'post_type_exists' ) );
		/**
		 * Filters the post types DEV3 AI SEO Assistant works on.
		 *
		 * @param string[] $types Post types.
		 */
		return (array) apply_filters( 'dev3se_post_types', $types ? $types : array( 'post' ) );
	}

	/**
	 * Brand name used in title suffixes.
	 *
	 * @return string
	 */
	public static function brand() {
		$brand = (string) self::get( 'brand_name', '' );
		return '' !== $brand ? $brand : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
