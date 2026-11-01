<?php
/**
 * Adapter registry: detection, forced choice and read/write of any field (adapter or plugin scoped).
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registry.
 */
class Adapters {

	/**
	 * Instances.
	 *
	 * @var Adapter[]|null
	 */
	private static $all = null;

	/**
	 * Forced adapter for tests.
	 *
	 * @var string
	 */
	public static $force = '';

	/**
	 * All adapters keyed by id.
	 *
	 * @return Adapter[]
	 */
	public static function all() {
		if ( null === self::$all ) {
			$list = array( new Yoast(), new Rank_Math(), new Seopress(), new Aioseo(), new Native() );
			/**
			 * Filters the SEO adapters (add your own subclass of Adapter).
			 *
			 * @param Adapter[] $list Adapters.
			 */
			$list      = (array) apply_filters( 'dev3se_adapters', $list );
			self::$all = array();
			foreach ( $list as $adapter ) {
				if ( $adapter instanceof Adapter ) {
					self::$all[ $adapter->id() ] = $adapter;
				}
			}
		}
		return self::$all;
	}

	/**
	 * Resets the registry (tests).
	 */
	public static function reset() {
		self::$all   = null;
		self::$force = '';
	}

	/**
	 * First active SEO plugin (ignores the setting).
	 *
	 * @return string
	 */
	public static function detect() {
		foreach ( self::all() as $id => $adapter ) {
			if ( 'native' !== $id && $adapter->active() ) {
				return $id;
			}
		}
		return 'native';
	}

	/**
	 * Active adapter id.
	 *
	 * @return string
	 */
	public static function current_id() {
		$all     = self::all();
		$setting = '' !== self::$force ? self::$force : (string) Settings::get( 'seo_adapter', 'auto' );
		$id      = isset( $all[ $setting ] ) ? $setting : self::detect();
		/**
		 * Filters which SEO plugin DEV3 writes into.
		 *
		 * @param string $id yoast|rankmath|seopress|aioseo|native.
		 */
		$id = (string) apply_filters( 'dev3se_adapter', $id );
		return isset( $all[ $id ] ) ? $id : 'native';
	}

	/**
	 * Active adapter.
	 *
	 * @return Adapter
	 */
	public static function current() {
		$all = self::all();
		return $all[ self::current_id() ];
	}

	/**
	 * Adapter by id (falls back to the current one).
	 *
	 * @param string $id Id.
	 * @return Adapter
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : self::current();
	}

	/**
	 * Whether a real SEO plugin is in charge (not native).
	 *
	 * @return bool
	 */
	public static function has_seo_plugin() {
		return 'native' !== self::detect();
	}

	/**
	 * Reads any field.
	 *
	 * @param int         $post_id Post id.
	 * @param string      $field   Field.
	 * @param string|null $adapter Adapter id (null = current).
	 * @return string
	 */
	public static function read( $post_id, $field, $adapter = null ) {
		if ( 'schema_jsonld' === $field ) {
			return (string) get_post_meta( (int) $post_id, Schema_Output::META, true );
		}
		if ( 'content' === $field ) {
			$post = get_post( (int) $post_id );
			return $post ? (string) $post->post_content : '';
		}
		$target = null === $adapter ? self::current() : self::get( $adapter );
		return $target->supports( $field ) ? $target->get( $post_id, $field ) : '';
	}

	/**
	 * Writes any field.
	 *
	 * @param int         $post_id Post id.
	 * @param string      $field   Field.
	 * @param string      $value   Sanitised value.
	 * @param string|null $adapter Adapter id (null = current).
	 * @return true|\WP_Error
	 */
	public static function write( $post_id, $field, $value, $adapter = null ) {
		if ( 'schema_jsonld' === $field ) {
			if ( '' === $value ) {
				delete_post_meta( (int) $post_id, Schema_Output::META );
			} else {
				update_post_meta( (int) $post_id, Schema_Output::META, wp_slash( $value ) );
			}
			return true;
		}
		if ( 'content' === $field ) {
			$result = wp_update_post(
				array(
					'ID'           => (int) $post_id,
					'post_content' => wp_slash( $value ),
				),
				true
			);
			return is_wp_error( $result ) ? $result : true;
		}
		$target = null === $adapter ? self::current() : self::get( $adapter );
		return $target->set( $post_id, $field, $value );
	}

	/**
	 * Descriptions for the UI.
	 *
	 * @return array
	 */
	public static function describe() {
		$out = array();
		foreach ( self::all() as $adapter ) {
			$out[] = $adapter->describe();
		}
		return $out;
	}
}
