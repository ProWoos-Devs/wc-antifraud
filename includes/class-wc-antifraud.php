<?php
/**
 * Main Plugin Class
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class — orchestrator and lifecycle manager
 */
class WC_Antifraud {

	const OPTION_KEY = 'wcaf_options';

	/**
	 * Singleton instance
	 *
	 * @var WC_Antifraud|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return WC_Antifraud
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load required files
	 */
	private function load_dependencies() {
		$dir = WCAF_PLUGIN_DIR . 'includes/';
		require_once $dir . 'class-wcaf-helpers.php';
		require_once $dir . 'class-wcaf-ip-tracker.php';
		require_once $dir . 'class-wcaf-email-alerts.php';
		require_once $dir . 'class-wcaf-order-status.php';
		require_once $dir . 'class-wcaf-fraud-checks.php';
		require_once $dir . 'class-wcaf-rest-hardening.php';
		require_once $dir . 'class-wcaf-settings.php';
	}

	/**
	 * Register hooks
	 */
	private function init_hooks() {
		add_action( 'init', [ $this, 'init' ] );
		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
		register_activation_hook( WCAF_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( WCAF_PLUGIN_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Init callback
	 */
	public function init() {
		load_plugin_textdomain( 'wc-antifraud', false, dirname( WCAF_PLUGIN_BASENAME ) . '/languages' );
		WCAF_Order_Status::init();

		if ( is_admin() ) {
			WCAF_Settings::init();
		}
	}

	/**
	 * Plugins loaded callback
	 */
	public function on_plugins_loaded() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', function () {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'WC Antifraud requires WooCommerce to be installed and active.', 'wc-antifraud' )
				);
			} );
			return;
		}

		new WCAF_Fraud_Checks();
		WCAF_REST_Hardening::init();
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		$defaults = self::get_default_options();
		$existing = get_option( self::OPTION_KEY, [] );
		update_option( self::OPTION_KEY, wp_parse_args( $existing, $defaults ) );
		WCAF_IP_Tracker::initialize();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		WCAF_IP_Tracker::cleanup_old_data();
		flush_rewrite_rules();
	}

	/**
	 * Default plugin options
	 *
	 * @return array
	 */
	public static function get_default_options() {
		return [
			'target_amount'         => 0,
			'amount_tolerance'      => 0.01,
			'email_recipients'      => get_option( 'admin_email' ),
			'enable_unknown_origin' => 1,
			'enable_disposable'     => 0,
			'disposable_domains'    => '',
			'enable_proxy_check'    => 0,
			'enable_ip_repeat'      => 0,
			'ip_repeat_threshold'   => 3,
			'ip_repeat_window'      => 3600,
			'blocked_emails'        => '',
			'blocked_ips'           => '',
			'blocked_phones'        => '',
		];
	}
}

/**
 * Return the plugin singleton
 *
 * @return WC_Antifraud
 */
function wc_antifraud() {
	return WC_Antifraud::get_instance();
}
