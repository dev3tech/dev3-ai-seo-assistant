<?php
/**
 * All in One SEO adapter (optional). AIOSEO keeps post SEO data in its own table (aioseo_posts),
 * reached through its documented model \AIOSEO\Plugin\Common\Models\Post.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * All in One SEO.
 */
class Aioseo extends Adapter {

	const MODEL = '\AIOSEO\Plugin\Common\Models\Post';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'aioseo';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'All in One SEO';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function active() {
		return defined( 'AIOSEO_VERSION' ) && class_exists( self::MODEL );
	}

	/**
	 * Model columns.
	 *
	 * @return array
	 */
	protected function keys() {
		return array(
			'seo_title'        => 'title',
			'meta_description' => 'description',
			'focus_keyword'    => 'keyphrases',
			'og_title'         => 'og_title',
			'og_description'   => 'og_description',
			'schema_type'      => Native::SCHEMA_TYPE_KEY,
		);
	}

	/**
	 * Model instance.
	 *
	 * @param int $post_id Post id.
	 * @return object|null
	 */
	private function model( $post_id ) {
		if ( ! class_exists( self::MODEL ) ) {
			return null;
		}
		$class = self::MODEL;
		return $class::getPost( (int) $post_id );
	}

	/**
	 * Reads a value.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @return string
	 */
	public function get( $post_id, $field ) {
		if ( 'schema_type' === $field ) {
			return (string) get_post_meta( (int) $post_id, Native::SCHEMA_TYPE_KEY, true );
		}
		$keys  = $this->keys();
		$model = isset( $keys[ $field ] ) ? $this->model( $post_id ) : null;
		if ( ! $model ) {
			return '';
		}
		$value = isset( $model->{$keys[ $field ]} ) ? $model->{$keys[ $field ]} : '';
		if ( 'focus_keyword' === $field ) {
			$data = is_string( $value ) ? json_decode( $value, true ) : (array) json_decode( (string) wp_json_encode( $value ), true );
			return isset( $data['focus']['keyphrase'] ) ? (string) $data['focus']['keyphrase'] : '';
		}
		return (string) $value;
	}

	/**
	 * Writes a value through the model.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   Field.
	 * @param string $value   Value.
	 * @return true|\WP_Error
	 */
	public function set( $post_id, $field, $value ) {
		if ( 'schema_type' === $field ) {
			$this->write_meta( $post_id, Native::SCHEMA_TYPE_KEY, $value );
			$this->after_write( $post_id );
			return true;
		}
		$keys  = $this->keys();
		$model = isset( $keys[ $field ] ) ? $this->model( $post_id ) : null;
		if ( ! $model ) {
			return new \WP_Error( 'dev3se_aioseo_missing', __( 'All in One SEO is not active.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		if ( 'focus_keyword' === $field ) {
			$current = isset( $model->keyphrases ) ? $model->keyphrases : '';
			$data    = is_string( $current ) ? (array) json_decode( $current, true ) : (array) json_decode( (string) wp_json_encode( $current ), true );
			if ( ! isset( $data['additional'] ) ) {
				$data['additional'] = array();
			}
			$data['focus']     = array(
				'keyphrase' => $value,
				'score'     => 0,
				'analysis'  => (object) array(),
			);
			$model->keyphrases = wp_json_encode( $data );
		} else {
			$model->{$keys[ $field ]} = $value;
		}
		if ( empty( $model->post_id ) ) {
			$model->post_id = (int) $post_id;
		}
		$model->save();
		$this->after_write( $post_id );
		return true;
	}
}
