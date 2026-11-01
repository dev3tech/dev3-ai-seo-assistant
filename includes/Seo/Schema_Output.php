<?php
/**
 * Schema output: DEV3 JSON-LD pieces merged into Yoast's or Rank Math's graph (or printed on their own),
 * the schema-type override for non-Yoast adapters, and the FAQ shortcode.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Seo;

use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * JSON-LD.
 */
class Schema_Output {

	const META     = '_dev3se_schema';
	const FAQ_META = '_dev3se_faq';
	const MAX      = 10;

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'wpseo_schema_graph', array( $this, 'yoast_graph' ), 20, 2 );
		add_filter( 'rank_math/json_ld', array( $this, 'rank_math_graph' ), 99, 2 );
		add_action( 'wp_head', array( $this, 'standalone' ), 30 );
		add_shortcode( 'dev3se_faq', array( $this, 'faq_shortcode' ) );
	}

	/**
	 * Validates and pretty-prints JSON-LD (object or list of objects with @type). '' stays ''.
	 *
	 * @param string $json JSON.
	 * @return string|\WP_Error
	 */
	public static function normalize_json( $json ) {
		$json = trim( (string) $json );
		if ( '' === $json ) {
			return '';
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'dev3se_bad_json', __( 'The schema is not valid JSON.', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			$data = $data['@graph'];
		}
		if ( isset( $data['@type'] ) ) {
			$data = array( $data );
		}
		$pieces = array();
		foreach ( $data as $piece ) {
			if ( ! is_array( $piece ) || empty( $piece['@type'] ) ) {
				return new \WP_Error( 'dev3se_bad_json', __( 'Every schema piece needs an "@type".', 'dev3-ai-seo-assistant' ), array( 'status' => 400 ) );
			}
			unset( $piece['@context'] );
			$pieces[] = self::clean( $piece );
		}
		if ( ! $pieces ) {
			return '';
		}
		return (string) wp_json_encode( array_slice( $pieces, 0, self::MAX ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Strips tags from every string value (JSON-LD is printed in a script tag).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function clean( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'clean' ), $value );
		}
		return is_string( $value ) ? wp_strip_all_tags( $value ) : $value;
	}

	/**
	 * Stored pieces of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function pieces( $post_id ) {
		$data = json_decode( (string) get_post_meta( (int) $post_id, self::META, true ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Stored FAQ items.
	 *
	 * @param int $post_id Post id.
	 * @return array [ { question, answer } ]
	 */
	public static function faq( $post_id ) {
		$data = json_decode( (string) get_post_meta( (int) $post_id, self::FAQ_META, true ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * FAQPage piece from items.
	 *
	 * @param array  $items Items.
	 * @param string $url   Page URL.
	 * @return array
	 */
	public static function faq_piece( array $items, $url ) {
		$entities = array();
		foreach ( $items as $i => $item ) {
			if ( empty( $item['question'] ) || empty( $item['answer'] ) ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'@id'            => $url . '#dev3se-faq-' . ( $i + 1 ),
				'name'           => wp_strip_all_tags( (string) $item['question'] ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( (string) $item['answer'] ),
				),
			);
		}
		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#dev3se-faq',
			'mainEntity' => $entities,
		);
	}

	/**
	 * Pieces to output for a post: stored pieces (minus FAQPage when an SEO plugin FAQ block already
	 * prints one) + the FAQ piece for DEV3 FAQ markup.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function output_pieces( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || ! Settings::get( 'schema_output', true ) ) {
			return array();
		}
		$content    = (string) $post->post_content;
		$native_faq = false !== strpos( $content, '<!-- wp:yoast/faq-block' ) || false !== strpos( $content, '<!-- wp:rank-math/faq-block' );
		$url        = (string) get_permalink( $post );
		$out        = array();
		$has_faq    = false;
		foreach ( self::pieces( $post_id ) as $i => $piece ) {
			if ( 'FAQPage' === $piece['@type'] ) {
				if ( $native_faq ) {
					continue;
				}
				$has_faq = true;
			}
			if ( empty( $piece['@id'] ) ) {
				$piece['@id'] = $url . '#dev3se-' . ( $i + 1 );
			}
			$out[] = $piece;
		}
		$faq = self::faq( $post_id );
		if ( ! $native_faq && ! $has_faq && $faq && ( false !== strpos( $content, 'dev3se-faq' ) || has_shortcode( $content, 'dev3se_faq' ) ) ) {
			$out[] = self::faq_piece( $faq, $url );
		}
		/**
		 * Filters DEV3 schema pieces for a post.
		 *
		 * @param array $out     Pieces.
		 * @param int   $post_id Post id.
		 */
		return (array) apply_filters( 'dev3se_schema_pieces', $out, (int) $post_id );
	}

	/**
	 * Current singular post id.
	 *
	 * @return int
	 */
	private function post_id() {
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Yoast graph.
	 *
	 * @param array $graph   Graph.
	 * @param mixed $context Context.
	 * @return array
	 */
	public function yoast_graph( $graph, $context = null ) {
		unset( $context );
		$id = $this->post_id();
		if ( ! $id || ! is_array( $graph ) ) {
			return $graph;
		}
		return array_merge( $graph, self::output_pieces( $id ) );
	}

	/**
	 * Rank Math graph (+ schema-type override).
	 *
	 * @param array $data   Entities.
	 * @param mixed $jsonld JsonLD instance.
	 * @return array
	 */
	public function rank_math_graph( $data, $jsonld = null ) {
		unset( $jsonld );
		$id = $this->post_id();
		if ( ! $id || ! is_array( $data ) ) {
			return $data;
		}
		$data = self::apply_type_override( $data, (string) get_post_meta( $id, Native::SCHEMA_TYPE_KEY, true ) );
		foreach ( self::output_pieces( $id ) as $i => $piece ) {
			$data[ 'dev3se-' . $i ] = $piece;
		}
		return $data;
	}

	/**
	 * Replaces the main Article/WebPage @type in a graph.
	 *
	 * @param array  $data Entities.
	 * @param string $type Type ('' = none).
	 * @return array
	 */
	public static function apply_type_override( array $data, $type ) {
		if ( '' === $type || ! Fields::valid_schema_type( $type ) ) {
			return $data;
		}
		$family = in_array( $type, Fields::ARTICLE_TYPES, true ) ? Fields::ARTICLE_TYPES : Fields::PAGE_TYPES;
		foreach ( $data as $key => $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
				continue;
			}
			$types = (array) $entity['@type'];
			if ( array_intersect( $types, $family ) ) {
				$data[ $key ]['@type'] = $type;
				break;
			}
		}
		return $data;
	}

	/**
	 * Standalone script tag when neither Yoast nor Rank Math prints a graph.
	 */
	public function standalone() {
		$id = $this->post_id();
		if ( ! $id || defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return;
		}
		$pieces = self::output_pieces( $id );
		if ( 'native' === Adapters::current_id() && ! Adapters::has_seo_plugin() && Settings::get( 'schema_output', true ) ) {
			array_unshift( $pieces, self::base_piece( $id ) );
		}
		if ( ! $pieces ) {
			return;
		}
		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $pieces ),
		);
		echo '<script type="application/ld+json" class="dev3se-schema">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with JSON_HEX_TAG.
	}

	/**
	 * Main entity for the native adapter.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function base_piece( $post_id ) {
		$post    = get_post( $post_id );
		$type    = (string) get_post_meta( $post_id, Native::SCHEMA_TYPE_KEY, true );
		$type    = '' !== $type ? $type : ( 'post' === $post->post_type ? 'BlogPosting' : 'WebPage' );
		$url     = (string) get_permalink( $post );
		$title   = (string) get_post_meta( $post_id, '_dev3se_title', true );
		$desc    = (string) get_post_meta( $post_id, '_dev3se_description', true );
		$article = in_array( $type, Fields::ARTICLE_TYPES, true );
		$piece   = array(
			'@type'         => $type,
			'@id'           => $url . '#dev3se-main',
			'url'           => $url,
			'name'          => '' !== $title ? $title : \Dev3\AiSeoAssistant\Content\Analyzer::title( $post ),
			'datePublished' => get_post_time( 'c', true, $post ),
			'dateModified'  => get_post_modified_time( 'c', true, $post ),
		);
		if ( $article ) {
			$piece['headline'] = mb_substr( $piece['name'], 0, 110 );
			$piece['author']   = array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
			);
		}
		if ( '' !== $desc ) {
			$piece['description'] = $desc;
		}
		$thumb = get_the_post_thumbnail_url( $post, 'full' );
		if ( $thumb ) {
			$piece['image'] = $thumb;
		}
		return $piece;
	}

	/**
	 * [dev3se_faq] shortcode: the stored FAQ as accessible details elements.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function faq_shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'id' => 0 ), $atts, 'dev3se_faq' );
		$id    = $atts['id'] ? (int) $atts['id'] : (int) get_the_ID();
		$items = self::faq( $id );
		if ( ! $items ) {
			return '';
		}
		return Faq_Markup::details_html( $items );
	}
}
