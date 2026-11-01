<?php
/**
 * Internal link graph from post content: outbound/inbound links between published posts, orphans.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Links;

use Dev3\AiSeoAssistant\Content\Corpus;

defined( 'ABSPATH' ) || exit;

/**
 * Graph (cached per corpus revision).
 */
class Graph {

	/**
	 * Instance.
	 *
	 * @var Graph|null
	 */
	private static $instance = null;

	/**
	 * Built data { rev, paths: path=>id, titles: id=>title, out: id=>ids }.
	 *
	 * @var array|null
	 */
	private $data = null;

	/**
	 * Singleton.
	 *
	 * @return Graph
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Drops the in-memory copy (tests / after writes).
	 */
	public static function reset() {
		self::$instance = null;
	}

	/**
	 * Normalised path key of a URL ('' when external).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function path_key( $url ) {
		$url  = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
		$home = wp_parse_url( home_url( '/' ) );
		if ( '' === $url || '#' === $url[0] || preg_match( '/^(mailto|tel|javascript):/i', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			return '';
		}
		if ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return '';
		}
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			foreach ( array( 'p', 'page_id' ) as $k ) {
				if ( ! empty( $q[ $k ] ) && is_numeric( $q[ $k ] ) ) {
					return 'id:' . (int) $q[ $k ];
				}
			}
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$base = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return '/' . trim( strtolower( rawurldecode( $path ) ), '/' );
	}

	/**
	 * Hrefs in content.
	 *
	 * @param string $content Content.
	 * @return string[]
	 */
	public static function hrefs( $content ) {
		if ( ! preg_match_all( '/<a\b[^>]*\bhref=("|\')(.*?)\1/is', (string) $content, $m ) ) {
			return array();
		}
		return $m[2];
	}

	/**
	 * Builds or loads the graph.
	 *
	 * @return array
	 */
	private function data() {
		$rev = Corpus::rev();
		if ( null !== $this->data && $this->data['rev'] === $rev ) {
			return $this->data;
		}
		$cached = get_transient( 'dev3se_graph' );
		if ( is_array( $cached ) && isset( $cached['rev'] ) && $cached['rev'] === $rev ) {
			$this->data = $cached;
			return $this->data;
		}
		$paths    = array();
		$titles   = array();
		$contents = array();
		Corpus::each(
			static function ( $post ) use ( &$paths, &$titles, &$contents ) {
				$paths[ self::path_key( (string) get_permalink( $post ) ) ] = $post->ID;
				$paths[ 'id:' . $post->ID ]                                 = $post->ID;
				$titles[ $post->ID ]                                        = \Dev3\AiSeoAssistant\Content\Analyzer::title( $post );
				$contents[ $post->ID ]                                      = self::hrefs( $post->post_content );
			},
			array( 'publish' )
		);
		$out = array();
		foreach ( $contents as $id => $hrefs ) {
			$out[ $id ] = self::resolve( $hrefs, $paths, $id );
		}
		$this->data = array(
			'rev'    => $rev,
			'paths'  => $paths,
			'titles' => $titles,
			'out'    => $out,
		);
		set_transient( 'dev3se_graph', $this->data, DAY_IN_SECONDS );
		return $this->data;
	}

	/**
	 * Resolves hrefs to post ids.
	 *
	 * @param string[] $hrefs Hrefs.
	 * @param array    $paths Path map.
	 * @param int      $source_id Source id.
	 * @return int[]
	 */
	private static function resolve( array $hrefs, array $paths, $source_id ) {
		$ids = array();
		foreach ( $hrefs as $href ) {
			$key = self::path_key( $href );
			if ( '' !== $key && isset( $paths[ $key ] ) && (int) $paths[ $key ] !== (int) $source_id ) {
				$ids[] = (int) $paths[ $key ];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Post ids a content links to.
	 *
	 * @param string $content Content.
	 * @param int    $source_id Source id.
	 * @return int[]
	 */
	public function outbound_ids( $content, $source_id = 0 ) {
		$data = $this->data();
		return self::resolve( self::hrefs( $content ), $data['paths'], $source_id );
	}

	/**
	 * Inbound source ids for a post.
	 *
	 * @param int $post_id Post id.
	 * @return int[]
	 */
	public function inbound_ids( $post_id ) {
		$data = $this->data();
		$in   = array();
		foreach ( $data['out'] as $source => $targets ) {
			if ( in_array( (int) $post_id, $targets, true ) ) {
				$in[] = (int) $source;
			}
		}
		return $in;
	}

	/**
	 * Inbound counts for all published posts.
	 *
	 * @return array id => count
	 */
	public function inbound_counts() {
		$data   = $this->data();
		$counts = array_fill_keys( array_keys( $data['titles'] ), 0 );
		foreach ( $data['out'] as $targets ) {
			foreach ( $targets as $t ) {
				if ( isset( $counts[ $t ] ) ) {
					++$counts[ $t ];
				}
			}
		}
		return $counts;
	}

	/**
	 * Outbound ids per published post.
	 *
	 * @return array id => ids
	 */
	public function outbound_map() {
		return $this->data()['out'];
	}

	/**
	 * Published posts without inbound links.
	 *
	 * @return int[]
	 */
	public function orphans() {
		return array_keys( array_filter( $this->inbound_counts(), static fn( $c ) => 0 === $c ) );
	}

	/**
	 * Links of one post (works for drafts too).
	 *
	 * @param int $post_id Post id.
	 * @return array { outbound: [ {id,title,url} ], inbound: [ ... ] }
	 */
	public function for_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return array(
				'outbound' => array(),
				'inbound'  => array(),
			);
		}
		$shape = static function ( $id ) {
			return array(
				'id'    => (int) $id,
				'title' => \Dev3\AiSeoAssistant\Content\Analyzer::title( $id ),
				'url'   => (string) get_permalink( $id ),
			);
		};
		return array(
			'outbound' => array_map( $shape, $this->outbound_ids( (string) $post->post_content, $post->ID ) ),
			'inbound'  => array_map( $shape, $this->inbound_ids( $post->ID ) ),
		);
	}
}
