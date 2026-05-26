<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin licensing via the LMFWC REST API on deviscomi.it.
 *
 * Responsibilities:
 *  - Activation / deactivation of a license key on this domain
 *  - Periodic validation (admin_init + daily WP Cron, cached 24 h)
 *  - Blocking the plugin UI when no valid license is present
 *  - Dedicated "Licenza" submenu page
 */
class GS_License {

	// ── wp_options keys ───────────────────────────────────────────────────────

	const OPT_KEY       = 'gs_license_key';       // stored as base64 (obfuscation)
	const OPT_STATUS    = 'gs_license_status';     // 'active' | 'inactive'
	const OPT_DOMAIN    = 'gs_license_domain';     // home_url() recorded at activation
	const OPT_ACTIVATED = 'gs_license_activated';  // Unix timestamp

	// Transient used to gate API calls (avoids calling LMFWC on every admin hit)
	const TRANSIENT_KEY = 'gs_license_check';

	// WP Cron hook name
	const CRON_HOOK = 'gs_daily_license_check';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init() {
		add_action( 'admin_menu',            array( __CLASS__, 'add_submenu' ) );
		add_action( 'admin_init',            array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_init',            array( __CLASS__, 'maybe_check_on_admin' ) );
		add_action( 'admin_notices',         array( __CLASS__, 'maybe_show_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( self::CRON_HOOK,         array( __CLASS__, 'run_scheduled_check' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	// ── Status helpers ────────────────────────────────────────────────────────

	public static function is_active() {
		return 'active' === get_option( self::OPT_STATUS, 'inactive' );
	}

	private static function get_key() {
		$stored = get_option( self::OPT_KEY, '' );
		return $stored ? base64_decode( $stored ) : '';
	}

	private static function set_key( $raw_key ) {
		// base64 is obfuscation only, not encryption — keeps casual snooping harder.
		update_option( self::OPT_KEY, base64_encode( $raw_key ) );
	}

	private static function clear_options() {
		delete_option( self::OPT_KEY );
		delete_option( self::OPT_STATUS );
		delete_option( self::OPT_DOMAIN );
		delete_option( self::OPT_ACTIVATED );
		delete_transient( self::TRANSIENT_KEY );
	}

	private static function mask_key( $key ) {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', max( 0, $len - 8 ) ) . substr( $key, -4 );
	}

	// ── LMFWC REST API ────────────────────────────────────────────────────────

	/**
	 * Centralised HTTP wrapper for all LMFWC v2 calls.
	 * Auth: HTTP Basic with Consumer Key + Consumer Secret.
	 *
	 * @param  string $method   HTTP verb ('GET', 'POST').
	 * @param  string $endpoint Path after /wp-json/lmfwc/v2/ — no leading slash.
	 * @param  array  $body     Optional payload (serialised as JSON for POST).
	 * @return array  { success: bool, data: array|null, message: string }
	 */
	private static function api_request( $method, $endpoint, $body = array() ) {
		$url  = trailingslashit( GS_LICENSE_SERVER_URL ) . 'wp-json/lmfwc/v2/' . $endpoint;
		$auth = 'Basic ' . base64_encode( GS_API_CONSUMER_KEY . ':' . GS_API_CONSUMER_SECRET );

		$args = array(
			'method'    => strtoupper( $method ),
			'headers'   => array(
				'Authorization' => $auth,
				'Accept'        => 'application/json',
				'User-Agent'    => 'GestioneScorte/' . GESTIONE_SCORTE_VERSION . '; ' . home_url(),
			),
			'timeout'   => 15,
			'sslverify' => true,
		);

		if ( ! empty( $body ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => $response->get_error_message(),
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => __( 'Risposta non valida dal server di licenza.', 'gestione-scorte' ),
			);
		}

		// LMFWC success: { "success": true, "data": { ... } }
		if ( ! empty( $decoded['success'] ) ) {
			return array(
				'success' => true,
				'data'    => isset( $decoded['data'] ) ? $decoded['data'] : array(),
				'message' => '',
			);
		}

		// LMFWC error: { "code": "...", "message": "...", "data": { ... } }
		$msg = isset( $decoded['message'] )
			? $decoded['message']
			: __( 'Errore sconosciuto dal server di licenza.', 'gestione-scorte' );

		return array(
			'success' => false,
			'data'    => null,
			'message' => $msg,
		);
	}

	// ── License operations ────────────────────────────────────────────────────

	/**
	 * Calls LMFWC /activate and persists the result locally.
	 *
	 * @param  string $raw_key Plaintext license key from the form.
	 * @return array  { success: bool, message: string }
	 */
	public static function activate( $raw_key ) {
		$raw_key = sanitize_text_field( trim( $raw_key ) );

		if ( empty( $raw_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Inserisci una license key.', 'gestione-scorte' ),
			);
		}

		$result = self::api_request(
			'POST',
			'licenses/activate/' . rawurlencode( $raw_key ),
			array( 'label' => home_url() )   // LMFWC stores this as the activation label
		);

		if ( $result['success'] ) {
			self::set_key( $raw_key );
			update_option( self::OPT_STATUS,    'active' );
			update_option( self::OPT_DOMAIN,    home_url() );
			update_option( self::OPT_ACTIVATED, time() );
			set_transient( self::TRANSIENT_KEY, 'valid', DAY_IN_SECONDS );

			return array(
				'success' => true,
				'message' => __( 'Licenza attivata con successo!', 'gestione-scorte' ),
			);
		}

		return array(
			'success' => false,
			'message' => $result['message'],
		);
	}

	/**
	 * Calls LMFWC /deactivate (best-effort) and always clears local state.
	 *
	 * @return array  { success: bool, message: string }
	 */
	public static function deactivate() {
		$key = self::get_key();

		if ( ! empty( $key ) ) {
			// Fire-and-forget: even on API failure we clean up locally.
			self::api_request( 'POST', 'licenses/deactivate/' . rawurlencode( $key ) );
		}

		self::clear_options();

		return array(
			'success' => true,
			'message' => __( 'Licenza disattivata. Puoi riattivarla su un altro dominio.', 'gestione-scorte' ),
		);
	}

	/**
	 * Validates the stored key via LMFWC /validate and updates OPT_STATUS.
	 * Always makes a real API call (the transient gate lives in maybe_check_on_admin).
	 */
	public static function validate() {
		$key = self::get_key();

		if ( empty( $key ) ) {
			return;
		}

		$result = self::api_request( 'GET', 'licenses/validate/' . rawurlencode( $key ) );

		if ( $result['success'] ) {
			// LMFWC returns success:true only for valid/active licenses.
			// Honour explicit isValid:false only when the field is present and
			// strictly false — older LMFWC versions omit the field entirely.
			$data             = is_array( $result['data'] ) ? $result['data'] : array();
			$explicit_invalid = array_key_exists( 'isValid', $data ) && false === $data['isValid'];

			if ( ! $explicit_invalid ) {
				update_option( self::OPT_STATUS, 'active' );
				set_transient( self::TRANSIENT_KEY, 'valid', DAY_IN_SECONDS );
				return;
			}
		}

		// Key invalid, expired, or revoked.
		update_option( self::OPT_STATUS, 'inactive' );
		// Back off one hour before retrying so we don't hammer the server on 403/404.
		set_transient( self::TRANSIENT_KEY, 'invalid', HOUR_IN_SECONDS );
	}

	// ── WordPress hooks ───────────────────────────────────────────────────────

	/**
	 * Fires on admin_init: skip API call while the transient is alive (24 h window).
	 * When the transient expires the next admin page load triggers a fresh check.
	 */
	public static function maybe_check_on_admin() {
		if ( empty( self::get_key() ) ) {
			return;
		}

		// Transient present → still within cache window, nothing to do.
		if ( false !== get_transient( self::TRANSIENT_KEY ) ) {
			return;
		}

		self::validate();
	}

	/** Fired by the daily WP Cron event — always forces an API call. */
	public static function run_scheduled_check() {
		self::validate();
	}

	/**
	 * Displays a persistent error notice on all admin pages when unlicensed.
	 * Suppressed on the license settings page itself (form feedback shown there).
	 */
	public static function maybe_show_notice() {
		if ( self::is_active() ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, 'gestione-scorte-license' ) ) {
			return;
		}

		$url = admin_url( 'admin.php?page=gestione-scorte-license' );

		echo '<div class="notice notice-error"><p>';
		echo wp_kses(
			sprintf(
				/* translators: %s: URL of the license settings page */
				__( '<strong>Gestione Scorte:</strong> licenza non attiva. <a href="%s">Inserisci una license key valida</a> per utilizzare il plugin.', 'gestione-scorte' ),
				esc_url( $url )
			),
			array(
				'strong' => array(),
				'a'      => array( 'href' => array() ),
			)
		);
		echo '</p></div>';
	}

	/**
	 * Processes the activate/deactivate form POST submitted from any admin page.
	 * Redirects to the license page with a feedback message.
	 */
	public static function handle_form() {
		if ( empty( $_POST['gs_license_action'] ) ) {
			return;
		}

		if ( ! check_admin_referer( 'gs_license_action', 'gs_license_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'gestione-scorte' ), 403 );
		}

		$action = sanitize_key( $_POST['gs_license_action'] );

		if ( 'activate' === $action ) {
			$raw = isset( $_POST['gs_license_key'] )
				? sanitize_text_field( wp_unslash( $_POST['gs_license_key'] ) )
				: '';
			$result = self::activate( $raw );
		} elseif ( 'deactivate' === $action ) {
			$result = self::deactivate();
		} else {
			return;
		}

		// After a successful activation send the user straight to the main plugin
		// page (Carico/Scarico UI) — the license page is not needed and its URL
		// can 404 on certain server rewrite configurations.
		// On failure, or after deactivation, stay on the license page.
		if ( 'activate' === $action && $result['success'] ) {
			$redirect_page = 'gestione-scorte';
			$extra_args    = array( 'gs_activated' => '1' );
		} else {
			$redirect_page = 'gestione-scorte-license';
			$extra_args    = array(
				'gs_msg'      => $result['message'],   // add_query_arg encodes this
				'gs_msg_type' => $result['success'] ? 'success' : 'error',
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => $redirect_page ), $extra_args ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Menu & Assets ─────────────────────────────────────────────────────────

	public static function add_submenu() {
		add_submenu_page(
			'gestione-scorte',
			__( 'Licenza — Gestione Scorte', 'gestione-scorte' ),
			__( 'Licenza', 'gestione-scorte' ),
			'manage_options',
			'gestione-scorte-license',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		// Load on the dedicated license page and on the main page (inline panel).
		$targets = array(
			'toplevel_page_gestione-scorte',
			'gestione-scorte_page_gestione-scorte-license',
		);

		if ( ! in_array( $hook, $targets, true ) ) {
			return;
		}

		wp_enqueue_style(
			'gs-license',
			GS_PLUGIN_URL . 'assets/gs-license.css',
			array(),
			GS_VERSION
		);
	}

	// ── Views ─────────────────────────────────────────────────────────────────

	/**
	 * Full license settings page rendered via the submenu callback.
	 */
	public static function render_page() {
		$key       = self::get_key();
		$status    = get_option( self::OPT_STATUS, 'inactive' );
		$domain    = get_option( self::OPT_DOMAIN, '' );
		$activated = (int) get_option( self::OPT_ACTIVATED, 0 );
		$is_active = 'active' === $status;

		// Decode feedback appended by handle_form() redirect.
		$feedback      = '';
		$feedback_type = 'info';
		if ( ! empty( $_GET['gs_msg'] ) && ! empty( $_GET['gs_msg_type'] ) ) {
			$feedback      = sanitize_text_field( rawurldecode( wp_unslash( $_GET['gs_msg'] ) ) );
			$feedback_type = sanitize_key( $_GET['gs_msg_type'] );
		}
		?>
		<div class="wrap gs-license-wrap">

			<h1 class="gs-license-title">
				<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
				<?php esc_html_e( 'Gestione Scorte — Licenza', 'gestione-scorte' ); ?>
			</h1>

			<?php if ( $feedback ) : ?>
				<div class="notice notice-<?php echo esc_attr( $feedback_type ); ?> is-dismissible gs-license-notice">
					<p><?php echo esc_html( $feedback ); ?></p>
				</div>
			<?php endif; ?>

			<div class="gs-license-card">

				<!-- Status badge -->
				<div class="gs-license-status <?php echo $is_active ? 'gs-license-status--active' : 'gs-license-status--inactive'; ?>" aria-live="polite">
					<span class="gs-license-dot" aria-hidden="true"></span>
					<strong>
						<?php echo $is_active
							? esc_html__( 'Licenza attiva', 'gestione-scorte' )
							: esc_html__( 'Licenza non attiva', 'gestione-scorte' );
						?>
					</strong>
				</div>

				<?php if ( $is_active && $domain ) : ?>
					<!-- Active-state metadata -->
					<dl class="gs-license-meta">
						<dt><?php esc_html_e( 'Dominio', 'gestione-scorte' ); ?></dt>
						<dd><?php echo esc_html( $domain ); ?></dd>

						<?php if ( $activated ) : ?>
							<dt><?php esc_html_e( 'Attivata il', 'gestione-scorte' ); ?></dt>
							<dd><?php echo esc_html( date_i18n( get_option( 'date_format' ), $activated ) ); ?></dd>
						<?php endif; ?>

						<dt><?php esc_html_e( 'License Key', 'gestione-scorte' ); ?></dt>
						<dd><code><?php echo esc_html( self::mask_key( $key ) ); ?></code></dd>
					</dl>
				<?php endif; ?>

				<?php if ( ! $is_active ) : ?>
					<!-- Activation form -->
					<form method="post" action="" class="gs-license-form">
						<?php wp_nonce_field( 'gs_license_action', 'gs_license_nonce' ); ?>
						<input type="hidden" name="gs_license_action" value="activate">

						<div class="gs-license-field">
							<label for="gs_license_key" class="gs-license-label">
								<?php esc_html_e( 'Inserisci la tua License Key', 'gestione-scorte' ); ?>
							</label>
							<input
								type="text"
								id="gs_license_key"
								name="gs_license_key"
								class="gs-license-input"
								placeholder="GS-XXXX-XXXX-XXXX-XXXX"
								autocomplete="off"
								spellcheck="false"
							/>
						</div>

						<button type="submit" class="gs-license-btn gs-license-btn--activate">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Attiva Licenza', 'gestione-scorte' ); ?>
						</button>
					</form>

				<?php else : ?>
					<!-- Deactivation form -->
					<form method="post" action="" class="gs-license-form">
						<?php wp_nonce_field( 'gs_license_action', 'gs_license_nonce' ); ?>
						<input type="hidden" name="gs_license_action" value="deactivate">

						<p class="gs-license-deactivate-hint">
							<?php esc_html_e( 'Disattivando la licenza potrai trasferirla su un altro dominio. Le funzionalità del plugin saranno sospese su questo sito fino alla riattivazione.', 'gestione-scorte' ); ?>
						</p>

						<button
							type="submit"
							class="gs-license-btn gs-license-btn--deactivate"
							onclick="return confirm('<?php esc_attr_e( 'Sei sicuro di voler disattivare la licenza su questo dominio?', 'gestione-scorte' ); ?>')"
						>
							<span class="dashicons dashicons-migrate" aria-hidden="true"></span>
							<?php esc_html_e( 'Disattiva Licenza', 'gestione-scorte' ); ?>
						</button>
					</form>

				<?php endif; ?>

			</div><!-- /.gs-license-card -->

			<div class="gs-license-help">
				<span class="dashicons dashicons-sos" aria-hidden="true"></span>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: support site URL */
							__( 'Non trovi la tua license key? Controlla l\'email di conferma ordine o visita <a href="%s" target="_blank" rel="noopener noreferrer">deviscomi.it</a> per assistenza.', 'gestione-scorte' ),
							'https://deviscomi.it'
						),
						array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
					);
					?>
				</p>
			</div>

		</div><!-- /.wrap.gs-license-wrap -->
		<?php
	}

	/**
	 * Compact activation panel rendered inside GS_Admin::render_page() when
	 * no valid license is present. Replaces the full plugin UI.
	 */
	public static function render_inline_activation() {
		?>
		<div class="wrap gs-wrap">

			<h1 class="gs-page-title">
				<span class="dashicons dashicons-archive" aria-hidden="true"></span>
				<?php esc_html_e( 'Gestione Scorte', 'gestione-scorte' ); ?>
			</h1>

			<div class="gs-license-inline-panel">

				<div class="gs-license-inline-icon" aria-hidden="true">
					<span class="dashicons dashicons-admin-network"></span>
				</div>

				<h2><?php esc_html_e( 'Attivazione licenza richiesta', 'gestione-scorte' ); ?></h2>

				<p class="gs-license-inline-desc">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: support site URL */
							__( 'Per utilizzare Gestione Scorte è necessaria una license key valida. Puoi acquistarla su <a href="%s" target="_blank" rel="noopener noreferrer">deviscomi.it</a>.', 'gestione-scorte' ),
							'https://deviscomi.it'
						),
						array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
					);
					?>
				</p>

				<form method="post" action="" class="gs-license-form">
					<?php wp_nonce_field( 'gs_license_action', 'gs_license_nonce' ); ?>
					<input type="hidden" name="gs_license_action" value="activate">

					<div class="gs-license-inline-row">
						<input
							type="text"
							name="gs_license_key"
							class="gs-license-input gs-license-input--inline"
							placeholder="GS-XXXX-XXXX-XXXX-XXXX"
							autocomplete="off"
							spellcheck="false"
							aria-label="<?php esc_attr_e( 'License Key', 'gestione-scorte' ); ?>"
						/>
						<button type="submit" class="gs-license-btn gs-license-btn--activate">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Attiva', 'gestione-scorte' ); ?>
						</button>
					</div>
				</form>

				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gestione-scorte-license' ) ); ?>" class="gs-license-inline-link">
					<?php esc_html_e( 'Gestisci licenza →', 'gestione-scorte' ); ?>
				</a>

			</div><!-- /.gs-license-inline-panel -->

		</div><!-- /.wrap -->
		<?php
	}
}
