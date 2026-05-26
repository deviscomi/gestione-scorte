<?php
/**
 * Plugin Name:       Gestione Scorte
 * Plugin URI:        https://example.com/gestione-scorte
 * Description:       Gestione rapida delle scorte WooCommerce tramite barcode scanner per punto vendita fisico ed e-commerce.
 * Version:           1.1.0
 * Author:            Devis Comi
 * Author URI:        https://deviscomi.it
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gestione-scorte
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Version & GitHub update source ──────────────────────────────────────────

define( 'GESTIONE_SCORTE_VERSION',     '1.1.0' );
define( 'GESTIONE_SCORTE_GITHUB_USER', 'deviscomi' );
define( 'GESTIONE_SCORTE_GITHUB_REPO', 'gestione-scorte' );
define( 'GESTIONE_SCORTE_GITHUB_TOKEN', '' ); // optional: Personal Access Token for private repos / rate-limit bypass

// ─── Internal aliases ─────────────────────────────────────────────────────────

define( 'GS_VERSION',    GESTIONE_SCORTE_VERSION );
define( 'GS_PLUGIN_FILE', __FILE__ );
define( 'GS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'GS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// ─── License server ───────────────────────────────────────────────────────────

define( 'GS_LICENSE_SERVER_URL',  'https://deviscomi.it' );
define( 'GS_API_CONSUMER_KEY',    'ck_78e5cee10020fcf4248c4b265cd9b88289cbb925' );
define( 'GS_API_CONSUMER_SECRET', 'cs_4910b243080a2d3e80b4150e2185d6060d35a8af' );

// ─── Auto-updater (GitHub Releases) ──────────────────────────────────────────
// Loaded unconditionally so update checks work even when WooCommerce is absent.

require_once GS_PLUGIN_DIR . 'includes/class-gs-updater.php';
GS_Updater::init();

// ─── Activation check ────────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'gs_activation_check' );

function gs_activation_check() {
	if ( ! gs_is_woocommerce_active() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			__( 'Il plugin <strong>Gestione Scorte</strong> richiede WooCommerce installato e attivo. Installa e attiva WooCommerce prima di procedere.', 'gestione-scorte' ),
			__( 'Dipendenza mancante — Gestione Scorte', 'gestione-scorte' ),
			array( 'back_link' => true )
		);
	}

	if ( ! wp_next_scheduled( 'gs_daily_license_validation' ) ) {
		wp_schedule_event( time(), 'daily', 'gs_daily_license_validation' );
	}
}

register_deactivation_hook( __FILE__, 'gs_deactivation_cleanup' );

function gs_deactivation_cleanup() {
	wp_clear_scheduled_hook( 'gs_daily_license_validation' );
}

// ─── Runtime dependency check (admin_init) ───────────────────────────────────

add_action( 'admin_init', 'gs_check_woocommerce_dependency' );

function gs_check_woocommerce_dependency() {
	if ( gs_is_woocommerce_active() ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		add_action( 'admin_notices', 'gs_woocommerce_missing_notice' );
	}
}

function gs_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			_e(
				'<strong>Gestione Scorte</strong> richiede WooCommerce installato e attivo. Il plugin è stato disattivato automaticamente.',
				'gestione-scorte'
			);
			?>
		</p>
	</div>
	<?php
}

function gs_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'gs_init' );

function gs_init() {
	if ( ! gs_is_woocommerce_active() ) {
		return;
	}

	require_once GS_PLUGIN_DIR . 'includes/class-gs-license.php';
	require_once GS_PLUGIN_DIR . 'includes/class-gs-admin.php';
	require_once GS_PLUGIN_DIR . 'includes/class-gs-ajax.php';

	GS_License::init();
	GS_Admin::init();
	GS_Ajax::init();
}
