<?php
/**
 * Analyzer checks/score, basic (non-AI) generators, site audit, link graph and link insertion.
 *
 * @package Dev3\AiSeoAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSeoAssistant\Content\Analyzer;
use Dev3\AiSeoAssistant\Content\Audit;
use Dev3\AiSeoAssistant\Content\Basic;
use Dev3\AiSeoAssistant\Core3\Core\Settings_Store;
use Dev3\AiSeoAssistant\Links\Graph;
use Dev3\AiSeoAssistant\Links\Linker;

class ContentTest extends Dev3se_Base_Case {

	public function test_text_headings_paragraphs_and_keyword_matching() {
		$html = '<!-- wp:heading --><h2>Grind size &amp; espresso</h2><!-- /wp:heading --><p>First <b>paragraph</b>.</p><p></p><img src="a.jpg" alt=""><img src="b.jpg" alt="Beans">';
		$this->assertSame( array( array( 'level' => 2, 'text' => 'Grind size & espresso' ) ), Analyzer::headings( $html ) );
		$this->assertSame( array( 'First paragraph.' ), Analyzer::paragraphs( $html ) );
		$this->assertSame( array( 'total' => 2, 'missing_alt' => 1 ), Analyzer::images( $html ) );
		$this->assertTrue( Analyzer::contains( 'Grind Size', 'the grind size matters' ) );
		$this->assertFalse( Analyzer::contains( 'grind', 'grinder' ), 'Whole words only' );
		$this->assertSame( 2, Analyzer::occurrences( 'espresso', 'Espresso, espresso!' ) );
		$this->assertSame( 'espresso', Analyzer::primary_keyword( ' espresso , latte' ) );
	}

	public function test_snapshot_checks_and_score_improve_with_meta() {
		$this->login();
		$this->adapter( 'yoast' );
		$id   = $this->post( array( 'slug' => 'espresso-at-home' ) );
		$snap = Analyzer::snapshot( $id );
		$by   = array_column( $snap['checks'], 'status', 'id' );
		$this->assertSame( 'bad', $by['description'] );
		$this->assertSame( 'bad', $by['keyword'] );
		$this->assertArrayNotHasKey( 'kw_title', $by, 'Keyword checks need a keyword' );
		$low = $snap['score'];

		update_post_meta( $id, '_yoast_wpseo_metadesc', str_repeat( 'Espresso at home tips. ', 6 ) );
		update_post_meta( $id, '_yoast_wpseo_focuskw', 'espresso at home' );
		update_post_meta( $id, '_yoast_wpseo_title', 'Espresso at home: a simple guide to better shots' );
		$snap = Analyzer::snapshot( $id );
		$by   = array_column( $snap['checks'], 'status', 'id' );
		$this->assertSame( 'good', $by['kw_title'] );
		$this->assertSame( 'good', $by['kw_slug'] );
		$this->assertSame( 'good', $by['kw_intro'] );
		$this->assertGreaterThan( $low, $snap['score'] );
		$this->assertSame( 'Espresso at home', substr( $snap['values']['seo_title'], 0, 16 ) );
	}

	public function test_basic_generators() {
		Settings_Store::update(
			array(
				'brand_name'      => 'Bean Blog',
				'title_separator' => '|',
			)
		);
		Settings_Store::flush();
		$post = get_post(
			$this->post(
				array(
					'title'   => 'Espresso grinders compared',
					'content' => '<h2>What grind size for espresso grinders?</h2><p>Fine, like table salt. Espresso grinders need fine steps.</p><h2>Plain heading</h2><p>Text.</p><h3>Are burr grinders better?</h3><p>Yes, burrs are consistent.</p><p>' . str_repeat( 'Espresso grinders are the key to great espresso. ', 8 ) . '</p>',
				)
			)
		);
		$this->assertSame( 'Espresso grinders compared | Bean Blog', Basic::title( $post ) );
		$this->assertSame( 'espresso grinders', Basic::keyword( $post ) );
		$faq = Basic::faq( $post );
		$this->assertCount( 2, $faq );
		$this->assertSame( 'What grind size for espresso grinders?', $faq[0]['question'] );
		$this->assertSame( 'FAQPage', Basic::schema_type( $post ) );
		$desc = Basic::description( $post );
		$this->assertLessThanOrEqual( 155, mb_strlen( $desc ) );
		$this->assertStringStartsWith( 'Fine, like table salt.', $desc );

		$long = str_repeat( 'Long title word ', 10 );
		$this->assertLessThanOrEqual( 60, mb_strlen( Basic::trim_words( $long, 60 ) ) );
		$page = get_post( $this->post( array( 'title' => 'Contact us', 'type' => 'page', 'content' => '<p>Write to us.</p>' ) ) );
		$this->assertSame( 'ContactPage', Basic::schema_type( $page ) );
	}

	public function test_link_graph_inbound_outbound_and_orphans() {
		$this->login();
		$target = $this->post( array( 'title' => 'Grinder guide', 'slug' => 'grinder-guide' ) );
		$other  = $this->post( array( 'title' => 'Milk guide', 'slug' => 'milk-guide' ) );
		$source = $this->post(
			array(
				'title'   => 'Espresso basics',
				'content' => '<p>See the <a href="' . get_permalink( $target ) . '">grinder guide</a> and <a href="/?p=' . $target . '">again</a> and <a href="https://elsewhere.test/x">external</a>.</p>',
			)
		);
		Graph::reset();
		$links = Graph::instance()->for_post( $source );
		$this->assertSame( array( $target ), array_column( $links['outbound'], 'id' ), 'Deduped, external ignored' );
		$this->assertSame( array( $source ), Graph::instance()->inbound_ids( $target ) );
		$orphans = Graph::instance()->orphans();
		$this->assertContains( $other, $orphans );
		$this->assertContains( $source, $orphans );
		$this->assertNotContains( $target, $orphans );
	}

	public function test_audit_rows_issues_and_report() {
		$this->login();
		$this->adapter( 'yoast' );
		$a = $this->post( array( 'title' => 'Alpha' ) );
		$b = $this->post( array( 'title' => 'Beta' ) );
		update_post_meta( $a, '_yoast_wpseo_focuskw', 'espresso' );
		update_post_meta( $b, '_yoast_wpseo_focuskw', 'Espresso' );
		update_post_meta( $a, '_yoast_wpseo_title', 'Same title' );
		update_post_meta( $b, '_yoast_wpseo_title', 'Same title' );
		Audit::flush();
		$rows = Audit::rows();
		$this->assertContains( 'cannibal', $rows[ $a ]['issues'] );
		$this->assertContains( 'duplicate_title', $rows[ $b ]['issues'] );
		$this->assertContains( 'no_description', $rows[ $a ]['issues'] );
		$this->assertContains( 'thin', $rows[ $a ]['issues'] );
		$report = Audit::report();
		$this->assertSame( 2, $report['total'] );
		$this->assertCount( 1, $report['cannibal'] );
		$this->assertCount( 2, $report['cannibal'][0]['posts'] );
		$q = Audit::query( array( 'issue' => 'cannibal', 'search' => 'alp' ) );
		$this->assertSame( 1, $q['total'] );
		$this->assertSame( $a, $q['items'][0]['id'] );
		$this->assertSame( array( $b ), Analyzer::keyword_used_elsewhere( 'espresso', $a ) );
	}

	public function test_linker_inserts_links_in_the_right_paragraph_only_outside_links() {
		$content = "<!-- wp:paragraph -->\n<p>Intro about beans.</p>\n<!-- /wp:paragraph -->\n<p>Good <a href=\"/x\">espresso grinders</a> and espresso grinders &amp; scales.</p>\n<p>Milk frothing tips.</p>";
		$res     = Linker::apply(
			$content,
			array(
				array(
					'paragraph' => 1,
					'anchor'    => 'espresso grinders',
					'url'       => 'https://site.test/g/',
				),
				array(
					'paragraph' => 2,
					'anchor'    => 'milk frothing',
					'url'       => 'https://site.test/m/',
				),
				array(
					'paragraph' => 0,
					'anchor'    => 'not there',
					'url'       => 'https://site.test/n/',
				),
			)
		);
		$this->assertSame( 2, $res['applied'] );
		$this->assertSame( 1, $res['skipped'] );
		$this->assertStringContainsString( '<a href="/x">espresso grinders</a> and <a href="https://site.test/g/">espresso grinders</a> &amp; scales', $res['content'] );
		$this->assertStringContainsString( '<a href="https://site.test/m/">Milk frothing</a> tips', $res['content'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $res['content'], 'Block comments survive' );
		$this->assertNull( Linker::link_in( 'Decaffeinated', 'caffeinated', '/c/' ) );
		$this->assertStringContainsString( '…', Linker::context( str_repeat( 'word ', 60 ) . 'anchor here ' . str_repeat( 'tail ', 60 ), 'anchor here' ) );
	}
}
