<?php
/**
 * SEO adapters: Yoast SEO and Rank Math (real plugins in wp-env), SEOPress, AIOSEO (model stub), native.
 *
 * @package Dev3\AiSeoAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Seo\Fields;

class AdapterTest extends Dev3se_Base_Case {

	public function test_real_seo_plugins_are_loaded_and_detected() {
		$this->assertTrue( defined( 'WPSEO_VERSION' ), 'Yoast SEO is loaded in the test suite' );
		$this->assertTrue( class_exists( 'RankMath' ), 'Rank Math is loaded in the test suite' );
		$this->assertSame( 'yoast', Adapters::detect() );
		$this->assertSame( 'yoast', Adapters::current_id() );
		$this->adapter( 'rankmath' );
		$this->assertSame( 'rankmath', Adapters::current_id() );
		Adapters::$force = 'bogus';
		$this->assertSame( 'yoast', Adapters::current_id(), 'Unknown adapter falls back to detection' );
		Adapters::$force = '';
	}

	public function test_yoast_meta_keys_and_schema_type_rules() {
		$this->adapter( 'yoast' );
		$id = $this->post();
		$a  = Adapters::current();
		$a->set( $id, 'seo_title', 'Espresso at Home - Bean Blog' );
		$a->set( $id, 'meta_description', 'Pull better shots with this guide.' );
		$a->set( $id, 'focus_keyword', 'espresso at home' );
		$a->set( $id, 'og_title', 'Make cafe espresso at home' );
		$this->assertSame( 'Espresso at Home - Bean Blog', get_post_meta( $id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Pull better shots with this guide.', get_post_meta( $id, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'espresso at home', get_post_meta( $id, '_yoast_wpseo_focuskw', true ) );
		$this->assertSame( 'Make cafe espresso at home', get_post_meta( $id, '_yoast_wpseo_opengraph-title', true ) );

		$a->set( $id, 'schema_type', 'FAQPage' );
		$this->assertSame( 'FAQPage', get_post_meta( $id, '_yoast_wpseo_schema_page_type', true ) );
		$this->assertSame( 'FAQPage', $a->get( $id, 'schema_type' ) );
		$a->set( $id, 'schema_type', 'TechArticle' );
		$this->assertSame( 'TechArticle', get_post_meta( $id, '_yoast_wpseo_schema_article_type', true ) );
		$this->assertSame( '', get_post_meta( $id, '_yoast_wpseo_schema_page_type', true ), 'Article type clears the page type so it wins' );
		$this->assertSame( 'TechArticle', $a->get( $id, 'schema_type' ) );
		$this->assertWPError( $a->set( $id, 'schema_type', 'Nonsense' ) );
		$a->set( $id, 'schema_type', '' );
		$this->assertSame( '', $a->get( $id, 'schema_type' ) );

		$a->set( $id, 'seo_title', '' );
		$this->assertFalse( metadata_exists( 'post', $id, '_yoast_wpseo_title' ), 'Empty value deletes the key' );
	}

	public function test_yoast_value_reaches_the_front_end_title() {
		$this->adapter( 'yoast' );
		$id = $this->post( array( 'title' => 'Front end check' ) );
		Adapters::current()->set( $id, 'seo_title', 'Custom DEV3 Yoast Title' );
		Adapters::current()->set( $id, 'meta_description', 'Custom DEV3 Yoast description.' );
		// Yoast's own meta API reads what DEV3 wrote (the rendered <title> is verified in wp-env with curl).
		$this->assertSame( 'Custom DEV3 Yoast Title', WPSEO_Meta::get_value( 'title', $id ) );
		$this->assertSame( 'Custom DEV3 Yoast description.', WPSEO_Meta::get_value( 'metadesc', $id ) );
	}

	public function test_rank_math_keys_and_keyword_list() {
		$this->adapter( 'rankmath' );
		$id = $this->post();
		$a  = Adapters::current();
		$this->assertTrue( $a->multi_keywords() );
		$this->assertSame( 'rank-math/faq-block', $a->faq_block() );
		$a->set( $id, 'seo_title', 'RM title' );
		$a->set( $id, 'meta_description', 'RM description' );
		$a->set( $id, 'focus_keyword', 'espresso, home espresso,espresso, a, b, c, d' );
		$a->set( $id, 'og_description', 'Social' );
		$this->assertSame( 'RM title', get_post_meta( $id, 'rank_math_title', true ) );
		$this->assertSame( 'RM description', get_post_meta( $id, 'rank_math_description', true ) );
		$this->assertSame( 'espresso,home espresso,a,b,c', get_post_meta( $id, 'rank_math_focus_keyword', true ), 'Deduped, max 5' );
		$this->assertSame( 'Social', get_post_meta( $id, 'rank_math_facebook_description', true ) );
		$a->set( $id, 'schema_type', 'NewsArticle' );
		$this->assertSame( 'NewsArticle', get_post_meta( $id, '_dev3se_schema_type', true ), 'Rank Math schema type is a DEV3 graph override' );
	}

	public function test_rank_math_front_end_title_uses_the_written_meta() {
		$this->adapter( 'rankmath' );
		$id = $this->post( array( 'title' => 'RM front' ) );
		Adapters::current()->set( $id, 'seo_title', 'Rank Math DEV3 Title' );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( 'Rank Math DEV3 Title', \RankMath\Post::get_meta( 'title', $id ) );
	}

	public function test_seopress_and_native_keys() {
		$this->adapter( 'seopress' );
		$id = $this->post();
		Adapters::current()->set( $id, 'seo_title', 'SP title' );
		Adapters::current()->set( $id, 'focus_keyword', 'sp keyword' );
		$this->assertSame( 'SP title', get_post_meta( $id, '_seopress_titles_title', true ) );
		$this->assertSame( 'sp keyword', get_post_meta( $id, '_seopress_analysis_target_kw', true ) );

		$this->adapter( 'native' );
		Adapters::current()->set( $id, 'meta_description', 'Native desc' );
		$this->assertSame( 'Native desc', get_post_meta( $id, '_dev3se_description', true ) );
		$this->assertSame( 'Native desc', Adapters::read( $id, 'meta_description' ) );
	}

	public function test_aioseo_adapter_writes_through_its_model() {
		if ( ! class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			require_once __DIR__ . '/stubs/aioseo-model.php';
		}
		if ( ! defined( 'AIOSEO_VERSION' ) ) {
			define( 'AIOSEO_VERSION', '4.0.0-stub' );
		}
		$this->adapter( 'aioseo' );
		$id = $this->post();
		$a  = Adapters::current();
		$this->assertTrue( $a->active() );
		$a->set( $id, 'seo_title', 'AIO title' );
		$a->set( $id, 'focus_keyword', 'aio keyword' );
		$this->assertSame( 'AIO title', $a->get( $id, 'seo_title' ) );
		$this->assertSame( 'aio keyword', $a->get( $id, 'focus_keyword' ) );
		$data = json_decode( \AIOSEO\Plugin\Common\Models\Post::getPost( $id )->keyphrases, true );
		$this->assertSame( 'aio keyword', $data['focus']['keyphrase'] );
	}

	public function test_field_sanitising() {
		$this->assertSame( 'Hello world', Fields::sanitize( 'seo_title', '  <b>Hello</b> world ' ) );
		$this->assertSame( 'Line one line two', Fields::sanitize( 'meta_description', "Line one\n\nline two" ) );
		$this->assertWPError( Fields::sanitize( 'schema_type', 'Car' ) );
		$this->assertWPError( Fields::sanitize( 'nope', 'x' ) );
		$this->assertWPError( Fields::sanitize( 'schema_jsonld', '{"name":"no type"}' ) );
		$json = Fields::sanitize( 'schema_jsonld', '{"@context":"https://schema.org","@type":"HowTo","name":"<b>Brew</b>"}' );
		$this->assertSame( array( array( '@type' => 'HowTo', 'name' => 'Brew' ) ), json_decode( $json, true ) );
	}
}
