<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiSeoAssistant
 */

namespace Dev3\AiSeoAssistant;

use Dev3\AiSeoAssistant\Ai\Prompt_Library;
use Dev3\AiSeoAssistant\Content\Corpus;
use Dev3\AiSeoAssistant\Core3\Core\Assets;
use Dev3\AiSeoAssistant\Core3\Core\Privacy;
use Dev3\AiSeoAssistant\Core3\Core\Schema;
use Dev3\AiSeoAssistant\Data\Drafts;
use Dev3\AiSeoAssistant\Links\Index;
use Dev3\AiSeoAssistant\Seo\Adapters;
use Dev3\AiSeoAssistant\Seo\Fields;
use Dev3\AiSeoAssistant\Service\Bulk;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3se_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether bundles were registered this request.
	 *
	 * @var bool
	 */
	private static $assets = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3SE_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_unschedule_hook( 'dev3se_run_job' );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3se_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3se_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3se_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3se_schema_sql', array( Drafts::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Registers the JS bundles once per request with the plugin's boot data.
	 */
	public static function assets() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		$adapter      = Adapters::current();
		Assets::register(
			array( 'admin', 'editor' ),
			array(
				'adapter'   => $adapter->describe(),
				'fields'    => Fields::describe(),
				'limits'    => array(
					'title'   => Fields::max( 'seo_title' ),
					'descMin' => (int) Settings::get( 'description_min', 120 ),
					'descMax' => Fields::max( 'meta_description' ),
				),
				'postTypes' => Settings::post_types(),
				'siteName'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'homeUrl'   => home_url( '/' ),
				'separator' => (string) Settings::get( 'title_separator', '-' ),
			)
		);
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ) );
		add_action( 'init', array( Prompt_Library::class, 'register' ), 5 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3SE_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( self::CRON_HOOK, array( Index::class, 'flush_queue' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'before_delete_post', array( Drafts::class, 'delete_for_post' ) );
		Privacy::init();
		// Background job types must be registered on every request (the runner looks them up).
		Bulk::register();
		Index::register();
		Corpus::register();
		( new Seo\Schema_Output() )->register();
		( new Seo\Native_Output() )->register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
			( new Admin\Posts_List() )->register();
		}
		( new Admin\Editor() )->register();
	}

	/**
	 * Translations (on init, as WP 6.7+ expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-seo-assistant', false, dirname( plugin_basename( DEV3SE_FILE ) ) . '/languages' );
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Post_Controller() )->register_routes();
		( new Rest\Queue_Controller() )->register_routes();
	}
}
