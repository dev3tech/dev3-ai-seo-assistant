<?php
/**
 * Base SEO adapter: maps DEV3 fields to an SEO plugin's storage (post meta by default).
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Subclasses list their meta keys; plugins with custom storage override get()/set().
 */
abstract class Adapter {

	/**
	 * Adapter id (yoast|rankmath|seopress|aioseo|native).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether the SEO plugin is active.
	 *
	 * @return bool
	 */
	abstract public function active();

	/**
	 * Field => meta key.
	 *
	 * @return array
	 */
	abstract protected function keys();

	/**
	 * Fields this adapter stores.
	 *
	 * @return string[]
	 */
	public function fields() {
		return array_keys( $this->keys() );
	}

	/**
	 * Supports a field?
	 *
	 * @param string $field Field.
	 * @return bool
	 */
	public function supports( $field ) {
		return in_array( $field, $this->fields(), true );
	}

	/**
	 * Native FAQ block name ('' = none).
	 *
	 * @return string
	 */
	public function faq_block() {
		return '';
	}

	/**
	 * Whether the focus keyword field takes a comma-separated list (primary first).
	 *
	 * @return bool
	 */
	public function multi_keywords() {
		return false;
	}

	/**
	 * JS store the editor sidebar syncs ('' = none).
	 *
	 * @return string
	 */
	public function editor_store() {
		return '';
	}

	/**
	 * Reads a field.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @return string
	 */
	public function get( $post_id, $field ) {
		$keys = $this->keys();
		if ( ! isset( $keys[ $field ] ) ) {
			return '';
		}
		return (string) get_post_meta( (int) $post_id, $keys[ $field ], true );
	}

	/**
	 * Writes a field ('' deletes it).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @param string $value   Value.
	 * @return true|\WP_Error
	 */
	public function set( $post_id, $field, $value ) {
		$keys = $this->keys();
		if ( ! isset( $keys[ $field ] ) ) {
			/* translators: 1: field label, 2: SEO plugin name */
			return new \WP_Error( 'dev3se_field_unsupported', sprintf( __( '%1$s is not stored by %2$s.', 'dev3-ai-seo-assistant' ), Fields::label( $field ), $this->label() ), array( 'status' => 400 ) );
		}
		$this->write_meta( $post_id, $keys[ $field ], $value );
		$this->after_write( $post_id );
		return true;
	}

	/**
	 * Writes or deletes one meta key (slashes are preserved).
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param string $value   Value.
	 */
	protected function write_meta( $post_id, $key, $value ) {
		if ( '' === (string) $value ) {
			delete_post_meta( (int) $post_id, $key );
		} else {
			update_post_meta( (int) $post_id, $key, wp_slash( (string) $value ) );
		}
	}

	/**
	 * Hook after a write (caches, indexables).
	 *
	 * @param int $post_id Post id.
	 */
	protected function after_write( $post_id ) {
		clean_post_cache( (int) $post_id );
		/**
		 * Fires after DEV3 wrote SEO fields into an SEO plugin.
		 *
		 * @param int    $post_id Post id.
		 * @param string $adapter Adapter id.
		 */
		do_action( 'dev3se_after_write', (int) $post_id, $this->id() );
	}

	/**
	 * Reads every supported field.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function values( $post_id ) {
		$out = array();
		foreach ( Fields::ADAPTER as $field ) {
			$out[ $field ] = $this->supports( $field ) ? $this->get( $post_id, $field ) : '';
		}
		return $out;
	}

	/**
	 * Public description for the UI.
	 *
	 * @return array
	 */
	public function describe() {
		return array(
			'id'            => $this->id(),
			'label'         => $this->label(),
			'active'        => $this->active(),
			'fields'        => $this->fields(),
			'faqBlock'      => $this->faq_block(),
			'multiKeywords' => $this->multi_keywords(),
			'store'         => $this->editor_store(),
		);
	}
}
