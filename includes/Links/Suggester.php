<?php
/**
 * Internal-link suggestions: candidates by embeddings (or lexical TF-IDF without AI), anchors chosen by
 * DEV3 AI and verified to exist in the source paragraph (or found lexically).
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Links;

use Dev3\AiSeoAssistant\Content\Analyzer;
use Dev3\AiSeoAssistant\Content\Basic;
use Dev3\AiSeoAssistant\Content\Corpus;
use Dev3\AiSeoAssistant\Core3\Api\Ai;
use Dev3\AiSeoAssistant\Core3\Api\Prompts;
use Dev3\AiSeoAssistant\Core3\Core\Settings_Store;
use Dev3\AiSeoAssistant\Core3\Data\Embeddings_Store;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Suggester.
 */
class Suggester {

	/**
	 * Whether AI paths can run.
	 *
	 * @return bool
	 */
	public static function ai_ready() {
		return Ai::enabled( 'admin' ) && '' !== Settings_Store::api_key();
	}

	/**
	 * Related published posts by embeddings.
	 *
	 * @param \WP_Post $post    Post.
	 * @param int      $limit   Limit.
	 * @param array    $exclude Ids to skip.
	 * @return array|\WP_Error [ { id, score } ]
	 */
	public static function by_embeddings( $post, $limit, array $exclude ) {
		$store  = Embeddings_Store::instance();
		$text   = Index::doc_text( $post );
		$stored = $store->get( Index::TYPE, $post->ID );
		if ( $stored && Embeddings_Store::hash( $text ) === $stored['hash'] ) {
			$vector = $stored['vector'];
		} else {
			$res = Ai::embed( 'seo_links', array( $text ), 'RETRIEVAL_DOCUMENT', 'admin', array( 'cache' => DAY_IN_SECONDS ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$vector = $res['vectors'][0];
			if ( 'publish' === $post->post_status ) {
				$store->upsert( Index::TYPE, $post->ID, $vector, Embeddings_Store::hash( $text ) );
			}
		}
		$hits = $store->search(
			$vector,
			Index::TYPE,
			$limit,
			array(
				'exclude'   => $exclude,
				'min_score' => (float) Settings::get( 'link_min_score', 0.55 ),
			)
		);
		return array_map(
			static fn( $h ) => array(
				'id'    => (int) $h['object_id'],
				'score' => round( (float) $h['score'], 3 ),
			),
			$hits
		);
	}

	/**
	 * Related published posts by lexical TF-IDF cosine (no AI).
	 *
	 * @param \WP_Post $post    Post.
	 * @param int      $limit   Limit.
	 * @param array    $exclude Ids to skip.
	 * @return array [ { id, score } ]
	 */
	public static function by_terms( $post, $limit, array $exclude ) {
		$docs   = self::term_docs();
		$df     = $docs['df'];
		$n      = max( 1, $docs['n'] );
		$vec    = static function ( array $tf ) use ( $df, $n ) {
			$out  = array();
			$norm = 0.0;
			foreach ( $tf as $term => $count ) {
				$w            = ( 1 + log( $count ) ) * log( 1 + $n / ( 1 + ( $df[ $term ] ?? 0 ) ) );
				$out[ $term ] = $w;
				$norm        += $w * $w;
			}
			$norm = sqrt( $norm ) ? sqrt( $norm ) : 1.0;
			foreach ( $out as $t => $w ) {
				$out[ $t ] = $w / $norm;
			}
			return $out;
		};
		$query  = $vec( self::tf( $post ) );
		$skip   = array_flip( array_map( 'intval', $exclude ) );
		$scores = array();
		foreach ( $docs['tf'] as $id => $tf ) {
			if ( isset( $skip[ $id ] ) ) {
				continue;
			}
			$doc = $vec( $tf );
			$dot = 0.0;
			foreach ( $query as $term => $w ) {
				if ( isset( $doc[ $term ] ) ) {
					$dot += $w * $doc[ $term ];
				}
			}
			if ( $dot > 0.08 ) {
				$scores[ $id ] = $dot;
			}
		}
		arsort( $scores );
		$out = array();
		foreach ( array_slice( $scores, 0, $limit, true ) as $id => $score ) {
			$out[] = array(
				'id'    => (int) $id,
				'score' => round( $score, 3 ),
			);
		}
		return $out;
	}

	/**
	 * Term frequencies of a post (title x3, keyword x3, headings x2, text).
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function tf( $post ) {
		$title    = \Dev3\AiSeoAssistant\Content\Analyzer::title( $post );
		$keyword  = Analyzer::primary_keyword( Adapters::read( $post->ID, 'focus_keyword' ) );
		$headings = implode( ' ', array_map( static fn( $h ) => $h['text'], Analyzer::headings( $post->post_content ) ) );
		$text     = str_repeat( $title . ' ', 3 ) . str_repeat( $keyword . ' ', 3 ) . str_repeat( $headings . ' ', 2 ) . mb_substr( Analyzer::text( $post->post_content ), 0, 4000 );
		return array_count_values( Basic::tokens( $text ) );
	}

	/**
	 * Term docs for the published corpus (cached per corpus revision).
	 *
	 * @return array { n, df, tf }
	 */
	private static function term_docs() {
		$cached = get_transient( 'dev3se_terms' );
		if ( is_array( $cached ) && isset( $cached['rev'] ) && Corpus::rev() === $cached['rev'] ) {
			return $cached;
		}
		$tf = array();
		$df = array();
		Corpus::each(
			static function ( $post ) use ( &$tf, &$df ) {
				$terms           = self::tf( $post );
				$tf[ $post->ID ] = $terms;
				foreach ( array_keys( $terms ) as $term ) {
					$df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
				}
			},
			array( 'publish' )
		);
		$data = array(
			'rev' => Corpus::rev(),
			'n'   => count( $tf ),
			'df'  => $df,
			'tf'  => $tf,
		);
		set_transient( 'dev3se_terms', $data, DAY_IN_SECONDS );
		return $data;
	}

	/**
	 * Lexical anchor: target keyword, title, or a 2-6 word phrase of the title, found in a paragraph.
	 *
	 * @param \WP_Post $target Target.
	 * @param array    $paras  Paragraphs (Linker::paragraphs()).
	 * @param array    $used   Paragraph use counts.
	 * @return array|null { paragraph, anchor }
	 */
	public static function lexical_anchor( $target, array $paras, array $used ) {
		$phrases = array();
		$keyword = Analyzer::primary_keyword( Adapters::read( $target->ID, 'focus_keyword' ) );
		if ( '' !== $keyword ) {
			$phrases[] = $keyword;
		}
		$title     = \Dev3\AiSeoAssistant\Content\Analyzer::title( $target );
		$phrases[] = $title;
		$words     = preg_split( '/\s+/u', trim( preg_replace( '/[^\p{L}\p{N}\s\'-]+/u', ' ', $title ) ) );
		$count     = count( $words );
		for ( $n = min( 6, $count ); $n >= 2; $n-- ) {
			for ( $i = 0; $i + $n <= $count; $i++ ) {
				$gram = array_slice( $words, $i, $n );
				$low  = array_map( 'mb_strtolower', $gram );
				if ( in_array( $low[0], Basic::STOPWORDS, true ) || in_array( end( $low ), Basic::STOPWORDS, true ) ) {
					continue;
				}
				$phrases[] = implode( ' ', $gram );
			}
		}
		foreach ( array_unique( $phrases ) as $phrase ) {
			if ( mb_strlen( $phrase ) < 4 ) {
				continue;
			}
			foreach ( $paras as $index => $p ) {
				if ( ( $used[ $index ] ?? 0 ) >= 2 ) {
					continue;
				}
				$text = Linker::linkable_text( $p['inner'] );
				$pos  = mb_stripos( $text, $phrase );
				if ( false !== $pos && Analyzer::contains( $phrase, $text ) ) {
					return array(
						'paragraph' => $index,
						'anchor'    => mb_substr( $text, $pos, mb_strlen( $phrase ) ),
					);
				}
			}
		}
		return null;
	}

	/**
	 * Suggestions for a post.
	 *
	 * @param \WP_Post $post  Post.
	 * @param int      $limit Max suggestions.
	 * @param bool     $ai    Use DEV3 AI (embeddings + anchors) when available.
	 * @return array|\WP_Error { method, suggestions: [ { target_id, title, url, score, paragraph, paragraph_text, anchor, context, reason } ], tokens }
	 */
	public static function suggest( $post, $limit = 0, $ai = true ) {
		$limit    = $limit > 0 ? $limit : (int) Settings::get( 'link_suggestions', 5 );
		$existing = Graph::instance()->outbound_ids( (string) $post->post_content, $post->ID );
		$exclude  = array_merge( array( (int) $post->ID ), $existing );
		$use_ai   = $ai && self::ai_ready();
		$method   = 'lexical';
		$hits     = array();
		if ( $use_ai ) {
			$hits = self::by_embeddings( $post, $limit * 3, $exclude );
			if ( is_wp_error( $hits ) ) {
				return $hits;
			}
			$method = 'embeddings';
		}
		if ( ! $hits ) {
			$hits   = self::by_terms( $post, $limit * 3, $exclude );
			$method = $use_ai ? 'embeddings+lexical' : 'lexical';
		}
		$targets = array();
		foreach ( $hits as $hit ) {
			$t = get_post( $hit['id'] );
			if ( $t && 'publish' === $t->post_status ) {
				$targets[ $t->ID ] = array(
					'post'  => $t,
					'score' => $hit['score'],
				);
			}
		}
		$paras   = Linker::paragraphs( (string) $post->post_content );
		$anchors = array();
		$tokens  = 0;
		$reasons = array();
		if ( $use_ai && $targets && $paras ) {
			$ai_res = self::ai_anchors( $paras, $targets );
			if ( ! is_wp_error( $ai_res ) ) {
				$anchors = $ai_res['anchors'];
				$reasons = $ai_res['reasons'];
				$tokens  = $ai_res['tokens'];
			}
		}
		$used = array();
		foreach ( $anchors as $a ) {
			$used[ $a['paragraph'] ] = ( $used[ $a['paragraph'] ] ?? 0 ) + 1;
		}
		$out = array();
		foreach ( $targets as $id => $t ) {
			$anchor = isset( $anchors[ $id ] ) ? $anchors[ $id ] : self::lexical_anchor( $t['post'], $paras, $used );
			if ( $anchor && ! isset( $anchors[ $id ] ) ) {
				$used[ $anchor['paragraph'] ] = ( $used[ $anchor['paragraph'] ] ?? 0 ) + 1;
			}
			$p_text = $anchor ? $paras[ $anchor['paragraph'] ]['text'] : '';
			$out[]  = array(
				'target_id'      => $id,
				'title'          => \Dev3\AiSeoAssistant\Content\Analyzer::title( $t['post'] ),
				'url'            => (string) get_permalink( $t['post'] ),
				'score'          => $t['score'],
				'paragraph'      => $anchor ? (int) $anchor['paragraph'] : null,
				'paragraph_text' => $p_text,
				'anchor'         => $anchor ? $anchor['anchor'] : '',
				'context'        => $anchor ? Linker::context( $p_text, $anchor['anchor'] ) : '',
				'reason'         => isset( $reasons[ $id ] ) ? $reasons[ $id ] : '',
			);
		}
		// Suggestions with an anchor first, then by score.
		usort(
			$out,
			static function ( $a, $b ) {
				if ( ( '' === $a['anchor'] ) !== ( '' === $b['anchor'] ) ) {
					return '' === $a['anchor'] ? 1 : -1;
				}
				return $b['score'] <=> $a['score'];
			}
		);
		return array(
			'method'      => $method,
			'suggestions' => array_slice( $out, 0, $limit ),
			'existing'    => count( $existing ),
			'tokens'      => $tokens,
		);
	}

	/**
	 * DEV3 AI anchor choice, verified against paragraph text.
	 *
	 * @param array $paras   Paragraphs.
	 * @param array $targets id => { post, score }.
	 * @return array|\WP_Error { anchors: id => { paragraph, anchor }, reasons, tokens }
	 */
	public static function ai_anchors( array $paras, array $targets ) {
		$lines = array();
		foreach ( array_slice( $paras, 0, 40, true ) as $i => $p ) {
			$lines[] = $i . '. ' . mb_substr( Linker::linkable_text( $p['inner'] ), 0, 500 );
		}
		$t_lines = array();
		foreach ( $targets as $id => $t ) {
			$t_lines[] = $id . ': ' . \Dev3\AiSeoAssistant\Content\Analyzer::title( $t['post'] ) . ' | ' . Analyzer::primary_keyword( Adapters::read( $id, 'focus_keyword' ) );
		}
		$res = Prompts::run(
			'dev3se_anchors',
			array(
				'paragraphs' => implode( "\n", $lines ),
				'targets'    => implode( "\n", $t_lines ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$anchors = array();
		$reasons = array();
		$used    = array();
		foreach ( (array) ( $res['data']['links'] ?? array() ) as $link ) {
			$id     = (int) ( $link['target_id'] ?? 0 );
			$pi     = (int) ( $link['paragraph'] ?? -1 );
			$anchor = trim( (string) ( $link['anchor'] ?? '' ) );
			if ( ! isset( $targets[ $id ] ) || isset( $anchors[ $id ] ) || ! isset( $paras[ $pi ] ) || '' === $anchor || ( $used[ $pi ] ?? 0 ) >= 2 ) {
				continue;
			}
			// Verify: the anchor must exist verbatim (outside existing links) in that paragraph.
			$text = Linker::linkable_text( $paras[ $pi ]['inner'] );
			$pos  = mb_strpos( $text, $anchor );
			if ( false === $pos ) {
				$pos = mb_stripos( $text, $anchor );
			}
			if ( false === $pos ) {
				continue;
			}
			$anchors[ $id ] = array(
				'paragraph' => $pi,
				'anchor'    => mb_substr( $text, $pos, mb_strlen( $anchor ) ),
			);
			$reasons[ $id ] = sanitize_text_field( (string) ( $link['reason'] ?? '' ) );
			$used[ $pi ]    = ( $used[ $pi ] ?? 0 ) + 1;
		}
		return array(
			'anchors' => $anchors,
			'reasons' => $reasons,
			'tokens'  => (int) ( $res['usage']['total'] ?? 0 ),
		);
	}
}
