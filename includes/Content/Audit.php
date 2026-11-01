<?php
/**
 * Site audit: one light health row per post (cached per corpus revision), the bulk list query and the
 * content-gap report (missing meta, duplicates, cannibalisation, thin content, orphans, alt text).
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Content;

use Dev3\AiSeoAssistant\Links\Graph;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Audit.
 */
class Audit {

	/**
	 * Memo.
	 *
	 * @var array|null
	 */
	private static $rows = null;

	/**
	 * Issue ids with labels.
	 *
	 * @return array
	 */
	public static function issues() {
		return array(
			'no_title'          => __( 'No SEO title', 'dev3-ai-seo-assistant' ),
			'long_title'        => __( 'SEO title too long', 'dev3-ai-seo-assistant' ),
			'no_description'    => __( 'No meta description', 'dev3-ai-seo-assistant' ),
			'short_description' => __( 'Description too short', 'dev3-ai-seo-assistant' ),
			'long_description'  => __( 'Description too long', 'dev3-ai-seo-assistant' ),
			'no_keyword'        => __( 'No focus keyword', 'dev3-ai-seo-assistant' ),
			'duplicate_title'   => __( 'Duplicate SEO title', 'dev3-ai-seo-assistant' ),
			'cannibal'          => __( 'Shared focus keyword', 'dev3-ai-seo-assistant' ),
			'thin'              => __( 'Thin content (< 300 words)', 'dev3-ai-seo-assistant' ),
			'orphan'            => __( 'Orphan (no inbound links)', 'dev3-ai-seo-assistant' ),
			'no_links'          => __( 'No internal links out', 'dev3-ai-seo-assistant' ),
			'missing_alt'       => __( 'Images without alt text', 'dev3-ai-seo-assistant' ),
		);
	}

	/**
	 * Clears the memo.
	 */
	public static function flush() {
		self::$rows = null;
		delete_transient( 'dev3se_audit' );
	}

	/**
	 * Health rows for every post in the corpus.
	 *
	 * @return array id => row
	 */
	public static function rows() {
		$key = Corpus::rev() . ':' . Adapters::current_id() . ':' . md5( (string) wp_json_encode( array( Settings::post_types(), Settings::get( 'title_max' ), Settings::get( 'description_min' ), Settings::get( 'description_max' ) ) ) );
		if ( null !== self::$rows && self::$rows['key'] === $key ) {
			return self::$rows['rows'];
		}
		$cached = get_transient( 'dev3se_audit' );
		if ( is_array( $cached ) && isset( $cached['key'] ) && $cached['key'] === $key ) {
			self::$rows = $cached;
			return $cached['rows'];
		}
		$adapter = Adapters::current();
		$graph   = Graph::instance();
		$inbound = $graph->inbound_counts();
		$t_max   = (int) Settings::get( 'title_max', 60 );
		$d_min   = (int) Settings::get( 'description_min', 120 );
		$d_max   = (int) Settings::get( 'description_max', 155 );
		$rows    = array();
		Corpus::each(
			static function ( $post ) use ( &$rows, $adapter, $graph, $inbound, $t_max, $d_min, $d_max ) {
				$title  = $adapter->supports( 'seo_title' ) ? $adapter->get( $post->ID, 'seo_title' ) : '';
				$desc   = $adapter->supports( 'meta_description' ) ? $adapter->get( $post->ID, 'meta_description' ) : '';
				$kw     = $adapter->supports( 'focus_keyword' ) ? Analyzer::primary_keyword( $adapter->get( $post->ID, 'focus_keyword' ) ) : '';
				$text   = Analyzer::text( $post->post_content );
				$words  = Analyzer::words( $text );
				$images = Analyzer::images( $post->post_content );
				$out    = 'publish' === $post->post_status ? count( $graph->outbound_ids( $post->post_content, $post->ID ) ) : 0;
				$in     = isset( $inbound[ $post->ID ] ) ? $inbound[ $post->ID ] : 0;
				$eff    = '' !== $title ? $title : Analyzer::default_title( $post );
				$d_len  = mb_strlen( $desc );
				$issues = array();
				$checks = array(
					'no_title'          => '' === $title,
					'long_title'        => mb_strlen( $eff ) > $t_max,
					'no_description'    => 0 === $d_len,
					'short_description' => $d_len > 0 && $d_len < $d_min,
					'long_description'  => $d_len > $d_max,
					'no_keyword'        => '' === $kw,
					'thin'              => $words < 300,
					'orphan'            => 'publish' === $post->post_status && 0 === $in,
					'no_links'          => 'publish' === $post->post_status && 0 === $out,
					'missing_alt'       => $images['missing_alt'] > 0,
				);
				foreach ( $checks as $issue => $hit ) {
					if ( $hit ) {
						$issues[] = $issue;
					}
				}
				$rows[ $post->ID ] = array(
					'id'          => $post->ID,
					'title'       => \Dev3\AiSeoAssistant\Content\Analyzer::title( $post ),
					'type'        => $post->post_type,
					'status'      => $post->post_status,
					'modified'    => mysql_to_rfc3339( $post->post_modified_gmt ),
					'seo_title'   => $title,
					'title_len'   => mb_strlen( $eff ),
					'description' => $desc,
					'desc_len'    => $d_len,
					'keyword'     => $kw,
					'words'       => $words,
					'outbound'    => $out,
					'inbound'     => $in,
					'missing_alt' => $images['missing_alt'],
					'issues'      => $issues,
				);
			}
		);
		// Cross-post issues.
		$by_title = array();
		$by_kw    = array();
		foreach ( $rows as $id => $row ) {
			if ( '' !== $row['seo_title'] ) {
				$by_title[ mb_strtolower( $row['seo_title'] ) ][] = $id;
			}
			if ( '' !== $row['keyword'] ) {
				$by_kw[ mb_strtolower( $row['keyword'] ) ][] = $id;
			}
		}
		foreach ( array(
			'duplicate_title' => $by_title,
			'cannibal'        => $by_kw,
		) as $issue => $groups ) {
			foreach ( $groups as $ids ) {
				if ( count( $ids ) > 1 ) {
					foreach ( $ids as $id ) {
						$rows[ $id ]['issues'][] = $issue;
					}
				}
			}
		}
		foreach ( $rows as $id => $row ) {
			$rows[ $id ]['score'] = self::row_score( $row );
		}
		self::$rows = array(
			'key'  => $key,
			'rows' => $rows,
		);
		set_transient( 'dev3se_audit', self::$rows, DAY_IN_SECONDS );
		return $rows;
	}

	/**
	 * Light score of a row.
	 *
	 * @param array $row Row.
	 * @return int
	 */
	public static function row_score( array $row ) {
		$weights = array(
			'no_title'          => 10,
			'long_title'        => 12,
			'no_description'    => 20,
			'short_description' => 8,
			'long_description'  => 8,
			'no_keyword'        => 15,
			'duplicate_title'   => 10,
			'cannibal'          => 8,
			'thin'              => 10,
			'orphan'            => 8,
			'no_links'          => 6,
			'missing_alt'       => 5,
		);
		$score   = 100;
		foreach ( $row['issues'] as $issue ) {
			$score -= isset( $weights[ $issue ] ) ? $weights[ $issue ] : 0;
		}
		return max( 0, $score );
	}

	/**
	 * Lower-cased primary keyword => post ids.
	 *
	 * @return array
	 */
	public static function keyword_map() {
		$map = array();
		foreach ( self::rows() as $id => $row ) {
			if ( '' !== $row['keyword'] ) {
				$map[ mb_strtolower( $row['keyword'] ) ][] = (int) $id;
			}
		}
		return $map;
	}

	/**
	 * Filtered, paginated rows for the bulk screen.
	 *
	 * @param array $args { type, status, search, issue, ids, orderby, order, page, per_page, ids_only }.
	 * @return array { items, total, ids? }
	 */
	public static function query( array $args ) {
		$rows = array_values( self::rows() );
		$type = isset( $args['type'] ) ? (string) $args['type'] : '';
		if ( '' !== $type ) {
			$rows = array_filter( $rows, static fn( $r ) => $r['type'] === $type );
		}
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( '' !== $status ) {
			$rows = array_filter( $rows, static fn( $r ) => $r['status'] === $status );
		}
		$issue = isset( $args['issue'] ) ? (string) $args['issue'] : '';
		if ( '' !== $issue ) {
			$rows = array_filter( $rows, static fn( $r ) => in_array( $issue, $r['issues'], true ) );
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids  = array_map( 'intval', (array) $args['ids'] );
			$rows = array_filter( $rows, static fn( $r ) => in_array( $r['id'], $ids, true ) );
		}
		$search = isset( $args['search'] ) ? mb_strtolower( trim( (string) $args['search'] ) ) : '';
		if ( '' !== $search ) {
			$rows = array_filter( $rows, static fn( $r ) => false !== mb_strpos( mb_strtolower( $r['title'] . ' ' . $r['keyword'] . ' ' . $r['seo_title'] ), $search ) );
		}
		$orderby = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'title', 'score', 'words', 'modified', 'inbound' ), true ) ? $args['orderby'] : 'modified';
		$desc    = ! isset( $args['order'] ) || 'asc' !== strtolower( (string) $args['order'] );
		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby, $desc ) {
				$cmp = is_string( $a[ $orderby ] ) ? strnatcasecmp( $a[ $orderby ], $b[ $orderby ] ) : $a[ $orderby ] <=> $b[ $orderby ];
				return $desc ? -$cmp : $cmp;
			}
		);
		$total = count( $rows );
		if ( ! empty( $args['ids_only'] ) ) {
			return array(
				'ids'   => array_map( static fn( $r ) => $r['id'], $rows ),
				'total' => $total,
			);
		}
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$items    = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		foreach ( $items as &$item ) {
			$item['editUrl'] = (string) get_edit_post_link( $item['id'], 'raw' );
			$item['url']     = (string) get_permalink( $item['id'] );
		}
		unset( $item );
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Site report.
	 *
	 * @return array
	 */
	public static function report() {
		$rows   = self::rows();
		$counts = array_fill_keys( array_keys( self::issues() ), 0 );
		$bands  = array(
			'good' => 0,
			'ok'   => 0,
			'poor' => 0,
		);
		$total  = 0;
		$sum    = 0;
		foreach ( $rows as $row ) {
			foreach ( $row['issues'] as $issue ) {
				++$counts[ $issue ];
			}
			++$total;
			$sum += $row['score'];
			if ( $row['score'] >= 80 ) {
				++$bands['good'];
			} elseif ( $row['score'] >= 50 ) {
				++$bands['ok'];
			} else {
				++$bands['poor'];
			}
		}
		$shape  = static fn( $id ) => array(
			'id'      => (int) $id,
			'title'   => $rows[ $id ]['title'],
			'score'   => $rows[ $id ]['score'],
			'editUrl' => (string) get_edit_post_link( $id, 'raw' ),
		);
		$groups = static function ( $field ) use ( $rows, $shape ) {
			$by = array();
			foreach ( $rows as $id => $row ) {
				if ( '' !== $row[ $field ] ) {
					$by[ mb_strtolower( $row[ $field ] ) ][] = $id;
				}
			}
			$out = array();
			foreach ( $by as $value => $ids ) {
				if ( count( $ids ) > 1 ) {
					$out[] = array(
						'value' => $rows[ $ids[0] ][ $field ],
						'posts' => array_map( $shape, $ids ),
					);
				}
			}
			return array_slice( $out, 0, 50 );
		};
		$pick   = static function ( $issue, $limit = 50 ) use ( $rows, $shape ) {
			$ids = array_keys( array_filter( $rows, static fn( $r ) => in_array( $issue, $r['issues'], true ) ) );
			return array_map( $shape, array_slice( $ids, 0, $limit ) );
		};
		return array(
			'total'      => $total,
			'average'    => $total ? (int) round( $sum / $total ) : 0,
			'bands'      => $bands,
			'counts'     => $counts,
			'labels'     => self::issues(),
			'duplicates' => $groups( 'seo_title' ),
			'cannibal'   => $groups( 'keyword' ),
			'orphans'    => $pick( 'orphan' ),
			'thin'       => $pick( 'thin' ),
			'missingAlt' => $pick( 'missing_alt' ),
			'noMeta'     => $pick( 'no_description' ),
		);
	}
}
