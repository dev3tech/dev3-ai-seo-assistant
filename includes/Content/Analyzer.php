<?php
/**
 * Content analysis (no AI): text extraction, headings, paragraphs, SEO checks and a 0-100 score.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant\Content;

use Dev3\AiSeoAssistant\Links\Graph;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Seo\Schema_Output;
use Dev3\AiSeoAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Analyzer.
 */
class Analyzer {

	/**
	 * Plain text of HTML/block content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function text( $content ) {
		$content = preg_replace( '/<!--.*?-->/s', ' ', (string) $content );
		$content = strip_shortcodes( (string) $content );
		$content = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $content );
		$content = preg_replace( '/<\/(p|h[1-6]|li|div|summary|details|blockquote|td|th|figcaption)>/i', "$0\n", (string) $content );
		$text    = html_entity_decode( wp_strip_all_tags( (string) $content ), ENT_QUOTES, 'UTF-8' );
		$text    = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		return trim( (string) preg_replace( "/\s*\n\s*/u", "\n", (string) $text ) );
	}

	/**
	 * Plain post title (entities decoded, e.g. &#038; → &).
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string
	 */
	public static function title( $post ) {
		return html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Word count.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function words( $text ) {
		$text = trim( (string) $text );
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
	}

	/**
	 * Headings.
	 *
	 * @param string $content Content.
	 * @return array [ { level, text } ]
	 */
	public static function headings( $content ) {
		$out = array();
		if ( preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', (string) $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $h ) {
				$text = trim( html_entity_decode( wp_strip_all_tags( $h[2] ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $text ) {
					$out[] = array(
						'level' => (int) $h[1],
						'text'  => $text,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Paragraph texts (from <p> elements).
	 *
	 * @param string $content Content.
	 * @return string[]
	 */
	public static function paragraphs( $content ) {
		$out = array();
		if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', (string) $content, $m ) ) {
			foreach ( $m[1] as $p ) {
				$text = trim( html_entity_decode( wp_strip_all_tags( $p ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}
		if ( ! $out ) {
			$out = array_values( array_filter( array_map( 'trim', explode( "\n", self::text( $content ) ) ) ) );
		}
		return $out;
	}

	/**
	 * Images and how many lack alt text.
	 *
	 * @param string $content Content.
	 * @return array { total, missing_alt }
	 */
	public static function images( $content ) {
		$total   = 0;
		$missing = 0;
		if ( preg_match_all( '/<img\b[^>]*>/i', (string) $content, $m ) ) {
			foreach ( $m[0] as $img ) {
				++$total;
				if ( ! preg_match( '/\balt=("|\')\s*[^"\'\s][^"\']*\1/i', $img ) ) {
					++$missing;
				}
			}
		}
		return array(
			'total'       => $total,
			'missing_alt' => $missing,
		);
	}

	/**
	 * Lower-case, punctuation-free text for keyword matching.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = mb_strtolower( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return ' ' . trim( (string) $text ) . ' ';
	}

	/**
	 * Keyword occurs in text (whole words, case-insensitive)?
	 *
	 * @param string $keyword Keyword.
	 * @param string $text    Text.
	 * @return bool
	 */
	public static function contains( $keyword, $text ) {
		$kw = trim( self::normalize( $keyword ) );
		return '' !== $kw && false !== mb_strpos( self::normalize( $text ), ' ' . $kw . ' ' );
	}

	/**
	 * Occurrences of a keyword.
	 *
	 * @param string $keyword Keyword.
	 * @param string $text    Text.
	 * @return int
	 */
	public static function occurrences( $keyword, $text ) {
		$kw = trim( self::normalize( $keyword ) );
		return '' === $kw ? 0 : (int) preg_match_all( '/(?<= )' . preg_quote( $kw, '/' ) . '(?= )/u', self::normalize( $text ) );
	}

	/**
	 * Primary keyword from a (possibly comma-separated) value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function primary_keyword( $value ) {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		return $parts ? (string) reset( $parts ) : '';
	}

	/**
	 * Full SEO snapshot of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function snapshot( $post_id ) {
		$post     = get_post( (int) $post_id );
		$adapter  = Adapters::current();
		$values   = $adapter->values( $post->ID );
		$content  = (string) $post->post_content;
		$text     = self::text( $content );
		$headings = self::headings( $content );
		$paras    = self::paragraphs( $content );
		$links    = Graph::instance()->for_post( $post->ID );
		$images   = self::images( $content );
		$schema   = Schema_Output::pieces( $post->ID );

		$data = array(
			'post'        => array(
				'id'       => $post->ID,
				'title'    => self::title( $post ),
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'url'      => (string) get_permalink( $post ),
				'slug'     => $post->post_name,
				'excerpt'  => wp_strip_all_tags( (string) $post->post_excerpt ),
				'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
				'editUrl'  => (string) get_edit_post_link( $post->ID, 'raw' ),
			),
			'adapter'     => $adapter->describe(),
			'values'      => $values,
			'schema'      => (string) get_post_meta( $post->ID, Schema_Output::META, true ),
			'schemaTypes' => array_values( array_unique( array_map( static fn( $p ) => is_array( $p['@type'] ) ? implode( '/', $p['@type'] ) : (string) $p['@type'], $schema ) ) ),
			'faq'         => Schema_Output::faq( $post->ID ),
			'stats'       => array(
				'words'      => self::words( $text ),
				'headings'   => $headings,
				'h2'         => count( array_filter( $headings, static fn( $h ) => 2 === $h['level'] ) ),
				'paragraphs' => count( $paras ),
				'images'     => $images,
				'outbound'   => count( $links['outbound'] ),
				'inbound'    => count( $links['inbound'] ),
			),
			'links'       => $links,
			'defaults'    => array(
				'seo_title'        => self::default_title( $post ),
				'meta_description' => self::default_description( $post ),
			),
		);

		$data['checks'] = self::checks( $data, $text, $paras );
		$data['score']  = self::score( $data['checks'] );
		return $data;
	}

	/**
	 * What the SEO title falls back to when empty.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function default_title( $post ) {
		return self::title( $post ) . ' ' . Settings::get( 'title_separator', '-' ) . ' ' . Settings::brand();
	}

	/**
	 * What the meta description falls back to (search engines pick text themselves).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function default_description( $post ) {
		return Basic::description( $post );
	}

	/**
	 * Checks.
	 *
	 * @param array    $data  Snapshot so far.
	 * @param string   $text  Plain text.
	 * @param string[] $paras Paragraphs.
	 * @return array [ { id, label, status: good|warn|bad, message, weight } ]
	 */
	public static function checks( array $data, $text, array $paras ) {
		$v      = $data['values'];
		$checks = array();
		$t_max  = (int) Settings::get( 'title_max', 60 );
		$d_min  = (int) Settings::get( 'description_min', 120 );
		$d_max  = (int) Settings::get( 'description_max', 155 );
		$title  = '' !== $v['seo_title'] ? $v['seo_title'] : $data['defaults']['seo_title'];
		$t_len  = mb_strlen( $title );
		$d_len  = mb_strlen( $v['meta_description'] );
		$kw     = self::primary_keyword( $v['focus_keyword'] );

		$add = static function ( $id, $label, $status, $message, $weight = 1 ) use ( &$checks ) {
			$checks[] = array(
				'id'      => $id,
				'label'   => $label,
				'status'  => $status,
				'message' => $message,
				'weight'  => $weight,
			);
		};

		if ( '' === $v['seo_title'] ) {
			/* translators: %d: characters */
			$add( 'title', __( 'SEO title', 'dev3-ai-seo-assistant' ), $t_len > $t_max ? 'bad' : 'warn', sprintf( __( 'No custom SEO title; the default is %d characters.', 'dev3-ai-seo-assistant' ), $t_len ), 2 );
		} elseif ( $t_len > $t_max ) {
			/* translators: 1: length, 2: limit */
			$add( 'title', __( 'SEO title', 'dev3-ai-seo-assistant' ), 'bad', sprintf( __( 'Too long: %1$d characters (limit %2$d), so search engines cut it.', 'dev3-ai-seo-assistant' ), $t_len, $t_max ), 2 );
		} elseif ( $t_len < 30 ) {
			/* translators: %d: length */
			$add( 'title', __( 'SEO title', 'dev3-ai-seo-assistant' ), 'warn', sprintf( __( 'Short: %d characters. Use more of the space.', 'dev3-ai-seo-assistant' ), $t_len ), 2 );
		} else {
			/* translators: %d: length */
			$add( 'title', __( 'SEO title', 'dev3-ai-seo-assistant' ), 'good', sprintf( __( 'Good length (%d characters).', 'dev3-ai-seo-assistant' ), $t_len ), 2 );
		}

		if ( 0 === $d_len ) {
			$add( 'description', __( 'Meta description', 'dev3-ai-seo-assistant' ), 'bad', __( 'Missing. Search engines will pick a random snippet.', 'dev3-ai-seo-assistant' ), 2 );
		} elseif ( $d_len < $d_min || $d_len > $d_max ) {
			/* translators: 1: length, 2: min, 3: max */
			$add( 'description', __( 'Meta description', 'dev3-ai-seo-assistant' ), 'warn', sprintf( __( '%1$d characters; aim for %2$d-%3$d.', 'dev3-ai-seo-assistant' ), $d_len, $d_min, $d_max ), 2 );
		} else {
			/* translators: %d: length */
			$add( 'description', __( 'Meta description', 'dev3-ai-seo-assistant' ), 'good', sprintf( __( 'Good length (%d characters).', 'dev3-ai-seo-assistant' ), $d_len ), 2 );
		}

		if ( '' === $kw ) {
			$add( 'keyword', __( 'Focus keyword', 'dev3-ai-seo-assistant' ), 'bad', __( 'No focus keyword set.', 'dev3-ai-seo-assistant' ), 2 );
		} else {
			$add( 'keyword', __( 'Focus keyword', 'dev3-ai-seo-assistant' ), 'good', sprintf( /* translators: %s: keyword */ __( 'Focus keyword: "%s".', 'dev3-ai-seo-assistant' ), $kw ), 2 );
			$add( 'kw_title', __( 'Keyword in SEO title', 'dev3-ai-seo-assistant' ), self::contains( $kw, $title ) ? 'good' : 'warn', self::contains( $kw, $title ) ? __( 'The SEO title contains the keyword.', 'dev3-ai-seo-assistant' ) : __( 'Add the keyword to the SEO title, ideally near the start.', 'dev3-ai-seo-assistant' ) );
			$add( 'kw_description', __( 'Keyword in meta description', 'dev3-ai-seo-assistant' ), self::contains( $kw, $v['meta_description'] ) ? 'good' : 'warn', self::contains( $kw, $v['meta_description'] ) ? __( 'The description contains the keyword.', 'dev3-ai-seo-assistant' ) : __( 'Mention the keyword in the meta description.', 'dev3-ai-seo-assistant' ) );
			$first = $paras ? $paras[0] : '';
			$add( 'kw_intro', __( 'Keyword in introduction', 'dev3-ai-seo-assistant' ), self::contains( $kw, $first ) ? 'good' : 'warn', self::contains( $kw, $first ) ? __( 'The first paragraph uses the keyword.', 'dev3-ai-seo-assistant' ) : __( 'Use the keyword in the first paragraph.', 'dev3-ai-seo-assistant' ) );
			$in_head = (bool) array_filter( $data['stats']['headings'], static fn( $h ) => $h['level'] > 1 && self::contains( $kw, $h['text'] ) );
			$add( 'kw_heading', __( 'Keyword in subheadings', 'dev3-ai-seo-assistant' ), $in_head ? 'good' : 'warn', $in_head ? __( 'A subheading uses the keyword.', 'dev3-ai-seo-assistant' ) : __( 'Use the keyword in at least one subheading.', 'dev3-ai-seo-assistant' ) );
			$in_slug = self::contains( $kw, str_replace( '-', ' ', (string) $data['post']['slug'] ) );
			$add( 'kw_slug', __( 'Keyword in URL', 'dev3-ai-seo-assistant' ), $in_slug ? 'good' : 'warn', $in_slug ? __( 'The URL slug contains the keyword.', 'dev3-ai-seo-assistant' ) : __( 'The URL slug does not contain the keyword.', 'dev3-ai-seo-assistant' ) );
			$words   = max( 1, $data['stats']['words'] );
			$count   = self::occurrences( $kw, $text );
			$density = round( 100 * $count * max( 1, count( explode( ' ', trim( self::normalize( $kw ) ) ) ) ) / $words, 1 );
			/* translators: 1: count, 2: density percent */
			$msg = sprintf( __( 'Used %1$d times (%2$s%%).', 'dev3-ai-seo-assistant' ), $count, $density );
			$add( 'kw_density', __( 'Keyword density', 'dev3-ai-seo-assistant' ), ( $density >= 0.5 && $density <= 3 ) ? 'good' : 'warn', $msg );
			$others = self::keyword_used_elsewhere( $kw, (int) $data['post']['id'] );
			if ( $others ) {
				/* translators: %d: number of posts */
				$add( 'kw_unique', __( 'Keyword cannibalisation', 'dev3-ai-seo-assistant' ), 'warn', sprintf( _n( '%d other post targets this keyword.', '%d other posts target this keyword.', count( $others ), 'dev3-ai-seo-assistant' ), count( $others ) ) );
			}
		}

		$words = $data['stats']['words'];
		/* translators: %d: words */
		$add( 'length', __( 'Content length', 'dev3-ai-seo-assistant' ), $words >= 300 ? 'good' : ( $words >= 150 ? 'warn' : 'bad' ), sprintf( __( '%d words.', 'dev3-ai-seo-assistant' ), $words ) );
		$add( 'subheadings', __( 'Subheadings', 'dev3-ai-seo-assistant' ), $data['stats']['h2'] > 0 ? 'good' : 'warn', $data['stats']['h2'] > 0 ? __( 'The content is split into sections.', 'dev3-ai-seo-assistant' ) : __( 'Add H2 subheadings to structure the content.', 'dev3-ai-seo-assistant' ) );
		$img = $data['stats']['images'];
		if ( $img['total'] > 0 ) {
			/* translators: %d: images */
			$add( 'alt', __( 'Image alt text', 'dev3-ai-seo-assistant' ), $img['missing_alt'] ? 'warn' : 'good', $img['missing_alt'] ? sprintf( _n( '%d image has no alt text.', '%d images have no alt text.', $img['missing_alt'], 'dev3-ai-seo-assistant' ), $img['missing_alt'] ) : __( 'Every image has alt text.', 'dev3-ai-seo-assistant' ) );
		}
		/* translators: %d: links */
		$add( 'outbound', __( 'Internal links', 'dev3-ai-seo-assistant' ), $data['stats']['outbound'] > 0 ? 'good' : 'warn', $data['stats']['outbound'] > 0 ? sprintf( _n( 'Links to %d other post.', 'Links to %d other posts.', $data['stats']['outbound'], 'dev3-ai-seo-assistant' ), $data['stats']['outbound'] ) : __( 'No links to other posts on this site.', 'dev3-ai-seo-assistant' ) );
		/* translators: %d: links */
		$add( 'inbound', __( 'Inbound links', 'dev3-ai-seo-assistant' ), $data['stats']['inbound'] > 0 ? 'good' : 'warn', $data['stats']['inbound'] > 0 ? sprintf( _n( '%d post links here.', '%d posts link here.', $data['stats']['inbound'], 'dev3-ai-seo-assistant' ), $data['stats']['inbound'] ) : __( 'Orphan: no other post links here.', 'dev3-ai-seo-assistant' ) );
		return $checks;
	}

	/**
	 * Score 0-100 (good = full weight, warn = half).
	 *
	 * @param array $checks Checks.
	 * @return int
	 */
	public static function score( array $checks ) {
		$total = 0;
		$got   = 0;
		foreach ( $checks as $c ) {
			$total += $c['weight'];
			if ( 'good' === $c['status'] ) {
				$got += $c['weight'];
			} elseif ( 'warn' === $c['status'] ) {
				$got += $c['weight'] / 2;
			}
		}
		return $total ? (int) round( 100 * $got / $total ) : 0;
	}

	/**
	 * Other posts with the same primary focus keyword (current adapter's meta key).
	 *
	 * @param string $keyword Keyword.
	 * @param int    $exclude Post id to skip.
	 * @return int[]
	 */
	public static function keyword_used_elsewhere( $keyword, $exclude ) {
		$keyword = trim( (string) $keyword );
		if ( '' === $keyword ) {
			return array();
		}
		$all = Audit::keyword_map();
		$key = mb_strtolower( $keyword );
		return isset( $all[ $key ] ) ? array_values( array_diff( $all[ $key ], array( (int) $exclude ) ) ) : array();
	}
}
