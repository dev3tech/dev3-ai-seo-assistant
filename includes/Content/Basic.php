<?php
/**
 * Basic (non-AI) generators, so the plugin is useful with AI off or no API key.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Content;

use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Heuristics.
 */
class Basic {

	const STOPWORDS = array( 'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'for', 'with', 'at', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'these', 'those', 'as', 'your', 'you', 'our', 'we', 'my', 'how', 'what', 'why', 'when', 'where', 'which', 'who', 'do', 'does', 'can', 'will', 'into', 'about', 'than', 'then', 'so', 'if', 'not', 'no', 'yes', 'all', 'any', 'more', 'most', 'best', 'vs', 'i', 'me', 'us', 'they', 'them', 'their', 'he', 'she', 'his', 'her', 'up', 'out', 'new', 'get', 'one', 'two', 'three' );

	/**
	 * Trims to a limit at a word boundary.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Characters.
	 * @return string
	 */
	public static function trim_words( $text, $limit ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $limit + 1 );
		$space = mb_strrpos( $cut, ' ' );
		$cut   = false !== $space && $space > $limit * 0.6 ? mb_substr( $cut, 0, $space ) : mb_substr( $text, 0, $limit );
		return rtrim( $cut, ' ,;:-–—' );
	}

	/**
	 * SEO title: post title + separator + brand when it fits.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function title( $post ) {
		$max   = (int) Settings::get( 'title_max', 60 );
		$title = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		$full  = $title . ' ' . Settings::get( 'title_separator', '-' ) . ' ' . Settings::brand();
		if ( Settings::get( 'brand_suffix', true ) && mb_strlen( $full ) <= $max ) {
			return $full;
		}
		return self::trim_words( $title, $max );
	}

	/**
	 * Meta description: excerpt or the opening sentences, cut at a sentence boundary when possible.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function description( $post ) {
		$min    = (int) Settings::get( 'description_min', 120 );
		$max    = (int) Settings::get( 'description_max', 155 );
		$source = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		if ( mb_strlen( $source ) < 50 ) {
			$source = implode( ' ', array_slice( Analyzer::paragraphs( (string) $post->post_content ), 0, 3 ) );
		}
		$source = trim( preg_replace( '/\s+/u', ' ', $source ) );
		if ( mb_strlen( $source ) <= $max ) {
			return $source;
		}
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $source );
		$out       = '';
		foreach ( $sentences as $sentence ) {
			$next = trim( $out . ' ' . $sentence );
			if ( mb_strlen( $next ) > $max ) {
				break;
			}
			$out = $next;
		}
		return mb_strlen( $out ) >= $min * 0.75 ? $out : self::trim_words( $source, $max - 1 ) . '…';
	}

	/**
	 * Tokens without stopwords.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( $text ) {
		$words = preg_split( '/\s+/u', trim( Analyzer::normalize( $text ) ) );
		return array_values( array_filter( (array) $words, static fn( $w ) => mb_strlen( $w ) > 1 && ! in_array( $w, self::STOPWORDS, true ) && ! is_numeric( $w ) ) );
	}

	/**
	 * Focus keyword: the 1-3 word phrase from the title that the body uses most.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function keyword( $post ) {
		$title_words = preg_split( '/\s+/u', trim( Analyzer::normalize( get_the_title( $post ) ) ) );
		$body        = Analyzer::normalize( Analyzer::text( (string) $post->post_content ) );
		$best        = '';
		$best_score  = 0;
		$n_words     = count( $title_words );
		for ( $n = 3; $n >= 1; $n-- ) {
			for ( $i = 0; $i + $n <= $n_words; $i++ ) {
				$gram = array_slice( $title_words, $i, $n );
				if ( in_array( $gram[0], self::STOPWORDS, true ) || in_array( end( $gram ), self::STOPWORDS, true ) || mb_strlen( implode( '', $gram ) ) < 3 ) {
					continue;
				}
				$phrase = implode( ' ', $gram );
				$count  = substr_count( $body, ' ' . $phrase . ' ' );
				$score  = $count * $n;
				if ( $score > $best_score ) {
					$best       = $phrase;
					$best_score = $score;
				}
			}
		}
		if ( '' === $best ) {
			$tokens = self::tokens( get_the_title( $post ) );
			$best   = $tokens ? implode( ' ', array_slice( $tokens, 0, 2 ) ) : '';
		}
		return $best;
	}

	/**
	 * Schema type from simple signals.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function schema_type( $post ) {
		$title = mb_strtolower( get_the_title( $post ) );
		if ( count( self::faq( $post ) ) >= 2 ) {
			return 'FAQPage';
		}
		if ( 'page' === $post->post_type ) {
			if ( preg_match( '/\bcontact\b/u', $title ) ) {
				return 'ContactPage';
			}
			if ( preg_match( '/\babout\b/u', $title ) ) {
				return 'AboutPage';
			}
			return 'WebPage';
		}
		return preg_match( '/\b(how to|guide|tutorial|setup|install)\b/u', $title ) ? 'TechArticle' : 'BlogPosting';
	}

	/**
	 * FAQ from question headings + the paragraph after each.
	 *
	 * @param \WP_Post $post Post.
	 * @return array [ { question, answer } ]
	 */
	public static function faq( $post ) {
		$items = array();
		$parts = preg_split( '/(<h[2-4]\b[^>]*>.*?<\/h[2-4]>)/is', (string) $post->post_content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$count = count( $parts );
		for ( $i = 1; $i < $count; $i += 2 ) {
			$q = trim( html_entity_decode( wp_strip_all_tags( $parts[ $i ] ), ENT_QUOTES, 'UTF-8' ) );
			if ( '?' !== mb_substr( $q, -1 ) ) {
				continue;
			}
			$paras = Analyzer::paragraphs( isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '' );
			if ( $paras ) {
				$items[] = array(
					'question' => $q,
					'answer'   => self::trim_words( $paras[0], 600 ),
				);
			}
		}
		return array_slice( $items, 0, (int) Settings::get( 'faq_count', 5 ) );
	}

	/**
	 * All basic meta drafts.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string[] $fields Fields.
	 * @return array field => value
	 */
	public static function meta( $post, array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			switch ( $field ) {
				case 'seo_title':
					$out[ $field ] = self::title( $post );
					break;
				case 'meta_description':
				case 'og_description':
					$out[ $field ] = self::description( $post );
					break;
				case 'focus_keyword':
					$out[ $field ] = self::keyword( $post );
					break;
				case 'og_title':
					$out[ $field ] = self::trim_words( \Dev3\AiSeoAssistant\Content\Analyzer::title( $post ), 90 );
					break;
				case 'schema_type':
					$out[ $field ] = self::schema_type( $post );
					break;
			}
		}
		return $out;
	}
}
