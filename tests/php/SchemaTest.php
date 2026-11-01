<?php
/**
 * Schema output (Yoast graph, Rank Math graph, standalone), FAQ markup, native head output, posts list.
 *
 * @package Dev3\AiSeoAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSeoAssistant\Admin\Posts_List;
use Dev3\AiSeoAssistant\Core3\Core\Settings_Store;
use Dev3\AiSeoAssistant\Seo\Faq_Markup;
use Dev3\AiSeoAssistant\Seo\Native_Output;
use Dev3\AiSeoAssistant\Seo\Schema_Output;

class SchemaTest extends Dev3se_Base_Case {

	private function with_schema( $content = '<p>Hi</p>' ) {
		$id = $this->post( array( 'content' => $content ) );
		update_post_meta( $id, Schema_Output::META, wp_slash( Schema_Output::normalize_json( '[{"@type":"HowTo","name":"Brew"},{"@type":"FAQPage","mainEntity":[]}]' ) ) );
		$this->go_to( '/?p=' . $id );
		return $id;
	}

	public function test_yoast_graph_gets_dev3_pieces() {
		$this->with_schema();
		$graph = ( new Schema_Output() )->yoast_graph( array( array( '@type' => 'WebPage' ) ) );
		$types = array_column( $graph, '@type' );
		$this->assertSame( array( 'WebPage', 'HowTo', 'FAQPage' ), $types );
		$this->assertStringContainsString( '#dev3se-1', $graph[1]['@id'] );
	}

	public function test_native_faq_block_prevents_duplicate_faq_schema() {
		$this->with_schema( "<!-- wp:yoast/faq-block {\"questions\":[]} -->\n<div class=\"schema-faq wp-block-yoast-faq-block\"></div>\n<!-- /wp:yoast/faq-block -->" );
		$graph = ( new Schema_Output() )->yoast_graph( array() );
		$this->assertSame( array( 'HowTo' ), array_column( $graph, '@type' ) );
	}

	public function test_rank_math_graph_type_override_and_pieces() {
		$id = $this->with_schema();
		update_post_meta( $id, '_dev3se_schema_type', 'NewsArticle' );
		$data = ( new Schema_Output() )->rank_math_graph(
			array(
				'WebPage'     => array( '@type' => 'WebPage' ),
				'richSnippet' => array( '@type' => 'BlogPosting' ),
			)
		);
		$this->assertSame( 'NewsArticle', $data['richSnippet']['@type'] );
		$this->assertSame( 'WebPage', $data['WebPage']['@type'] );
		$this->assertSame( 'HowTo', $data['dev3se-0']['@type'] );
		$this->assertSame( 'FAQPage', Schema_Output::apply_type_override( array( array( '@type' => array( 'WebPage' ) ) ), 'FAQPage' )[0]['@type'] );
	}

	public function test_faq_piece_from_dev3_markup_and_shortcode() {
		Settings_Store::update( array( 'seo_adapter' => 'native' ) );
		Settings_Store::flush();
		$items = array(
			array(
				'question' => 'Q1?',
				'answer'   => 'A1 <b>bold</b>',
			),
		);
		$id    = $this->post( array( 'content' => Faq_Markup::blocks( $items, '' ) ) );
		update_post_meta( $id, Schema_Output::FAQ_META, wp_slash( wp_json_encode( Faq_Markup::clean( $items ) ) ) );
		$pieces = Schema_Output::output_pieces( $id );
		$this->assertSame( 'FAQPage', $pieces[0]['@type'] );
		$this->assertSame( 'A1 bold', $pieces[0]['mainEntity'][0]['acceptedAnswer']['text'] );
		$html = do_shortcode( '[dev3se_faq id="' . $id . '"]' );
		$this->assertStringContainsString( '<summary>Q1?</summary>', $html );
		Settings_Store::update( array( 'schema_output' => false ) );
		Settings_Store::flush();
		$this->assertSame( array(), Schema_Output::output_pieces( $id ) );
	}

	public function test_faq_markup_is_valid_block_markup_for_each_plugin() {
		$items = array(
			array(
				'question' => 'Does grind > dose matter?',
				'answer'   => 'Yes & a lot.',
			),
		);
		$yoast = Faq_Markup::blocks( $items, 'yoast/faq-block', false );
		$parsed = parse_blocks( $yoast );
		$this->assertSame( 'yoast/faq-block', $parsed[0]['blockName'] );
		$this->assertSame( 'Does grind &gt; dose matter?', $parsed[0]['attrs']['questions'][0]['jsonQuestion'] );
		$this->assertStringContainsString( '<strong class="schema-faq-question">Does grind &gt; dose matter?</strong>', $yoast );
		$rm = parse_blocks( Faq_Markup::blocks( $items, 'rank-math/faq-block', false ) );
		$this->assertSame( 'rank-math/faq-block', $rm[0]['blockName'] );
		$this->assertTrue( $rm[0]['attrs']['questions'][0]['visible'] );
		$core = parse_blocks( Faq_Markup::blocks( $items, '', true ) );
		$names = array_values( array_filter( array_column( $core, 'blockName' ) ) );
		$this->assertSame( array( 'core/heading', 'core/details' ), $names );
	}

	public function test_normalize_json_rules() {
		$this->assertSame( '', Schema_Output::normalize_json( '' ) );
		$this->assertWPError( Schema_Output::normalize_json( '{oops' ) );
		$graph = Schema_Output::normalize_json( '{"@context":"https://schema.org","@graph":[{"@type":"Event","name":"<i>Cupping</i>"}]}' );
		$this->assertSame( array( array( '@type' => 'Event', 'name' => 'Cupping' ) ), json_decode( $graph, true ) );
		$many = wp_json_encode( array_fill( 0, 20, array( '@type' => 'Thing' ) ) );
		$this->assertCount( 10, json_decode( Schema_Output::normalize_json( $many ), true ) );
	}

	public function test_native_output_only_without_an_seo_plugin() {
		$id = $this->post();
		update_post_meta( $id, '_dev3se_description', 'Native description' );
		$this->go_to( '/?p=' . $id );
		ob_start();
		( new Native_Output() )->head();
		$html = ob_get_clean();
		$this->assertSame( '', $html, 'Yoast/Rank Math are active in tests, so DEV3 stays silent' );
		$this->assertFalse( Native_Output::enabled() );
	}

	public function test_posts_list_column_and_bulk_redirect() {
		$this->login();
		$id   = $this->post();
		$list = new Posts_List();
		$this->assertArrayHasKey( 'dev3se_score', $list->column( array() ) );
		ob_start();
		$list->cell( 'dev3se_score', $id );
		$cell = ob_get_clean();
		$this->assertMatchesRegularExpression( '/dev3se-score--(good|ok|poor)/', $cell );
		$this->assertStringContainsString( '#/bulk/ids=3,4', $list->handle_bulk( 'x', 'dev3se_optimize', array( 3, 4 ) ) );
		$this->assertSame( 'x', $list->handle_bulk( 'x', 'trash', array( 3 ) ) );
		$actions = $list->row_action( array(), get_post( $id ) );
		$this->assertStringContainsString( 'Optimize with DEV3 AI', $actions['dev3se'] );
	}
}
