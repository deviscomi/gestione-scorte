<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GS_License {

	// ── Option & transient keys ────────────────────────────────────────────────

	const OPT_KEY          = 'gs_license_key';
	const OPT_STATUS       = 'gs_license_status';
	const OPT_DOMAIN       = 'gs_license_domain';
	const OPT_ACTIVATED_AT = 'gs_license_activated_at';
	const TRANSIENT_CACHE  = 'gs_license_validation_cache';
	const CRON_HOOK        = 'gs_daily_license_validation';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init() {
		add_action( 'admin_init',    array( __CLASS__, 'handle_activate_form' ),   5 );
		add_action( 'admin_init',    array( __CLASS__, 'handle_deactivate_form' ), 5 );
		add_action( 'admin_init',    array( __CLASS__, 'validate_on_admin_load' ), 10 );
		add_action( 'admin_notices', array( __CLASS__, 'show_inactive_notice' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_validation' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	// ── Public API ─────────────────────────────────────────────────────────────

	public static function is_active() {
		return 'active' === get_option( self::OPT_STATUS, 'inactive' );
	}

	// ── Form handlers ──────────────────────────────────────────────────────────

	public static function handle_activate_form() {
		if ( ! isset( $_POST['gs_license_action'] ) || 'activate' !== $_POST['gs_license_action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'gestione-scorte' ) );
		}

		check_admin_referer( 'gs_license_nonce' );

		$license_key = isset( $_POST['gs_license_key'] )
			? sanitize_text_field( wp_unslash( $_POST['gs_license_key'] ) )
			: '';

		if ( '' === $license_key ) {
			self::set_user_notice( 'error', __( 'Inserisci una license key valida.', 'gestione-scorte' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=gestione-scorte-licenza' ) );
			exit;
		}

		$result = self::api_activate( $license_key );

		if ( $result['success'] ) {
			update_option( self::OPT_KEY,          self::obfuscate( $license_key ) );
			update_option( self::OPT_STATUS,        'active' );
			update_option( self::OPT_DOMAIN,        home_url() );
			update_option( self::OPT_ACTIVATED_AT,  time() );

			set_transient( self::TRANSIENT_CACHE, array(
				'valid'      => true,
				'checked_at' => time(),
			), DAY_IN_SECONDS );

			wp_safe_redirect( admin_url( 'admin.php?page=gestione-scorte&gs_activated=1' ) );
			exit;
		}

		self::set_user_notice( 'error', sprintf(
			/* translators: %s: error message from license server */
			__( 'Attivazione fallita: %s', 'gestione-scorte' ),
			$result['message']
		) );

		wp_safe_redirect( admin_url( 'admin.php?page=gestione-scorte-licenza' ) );
		exit;
	}

	public static function handle_deactivate_form() {
		if ( ! isset( $_POST['gs_license_action'] ) || 'deactivate' !== $_POST['gs_license_action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'gestione-scorte' ) );
		}

		check_admin_referer( 'gs_license_nonce' );

		$license_key = self::get_stored_key();

		if ( '' !== $license_key ) {
			self::api_deactivate( $license_key );
		}

		self::clear_license_data();

		self::set_user_notice( 'success', __( 'Licenza disattivata con successo.', 'gestione-scorte' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=gestione-scorte-licenza' ) );
		exit;
	}

	// ── Periodic validation ────────────────────────────────────────────────────

	public static function validate_on_admin_load() {
		if ( ! self::is_active() ) {
			return;
		}

		$cached = get_transient( self::TRANSIENT_CACHE );

		if ( false !== $cached ) {
			if ( empty( $cached['valid'] ) ) {
				self::clear_license_data();
			}
			return;
		}

		$license_key = self::get_stored_key();

		if ( '' === $license_key ) {
			self::clear_license_data();
			return;
		}

		$result = self::api_validate( $license_key );

		if ( $result['success'] ) {
			set_transient( self::TRANSIENT_CACHE, array(
				'valid'      => true,
				'checked_at' => time(),
			), DAY_IN_SECONDS );
		} else {
			// 1-hour backoff on failure so a transient network error doesn't permanently lock the user out.
			set_transient( self::TRANSIENT_CACHE, array(
				'valid'      => false,
				'checked_at' => time(),
				'reason'     => $result['message'],
			), HOUR_IN_SECONDS );

			self::clear_license_data();
		}
	}

	public static function run_daily_validation() {
		if ( ! self::is_active() ) {
			return;
		}

		$license_key = self::get_stored_key();

		if ( '' === $license_key ) {
			self::clear_license_data();
			return;
		}

		delete_transient( self::TRANSIENT_CACHE );

		$result = self::api_validate( $license_key );

		if ( $result['success'] ) {
			set_transient( self::TRANSIENT_CACHE, array(
				'valid'      => true,
				'checked_at' => time(),
			), DAY_IN_SECONDS );
		} else {
			self::clear_license_data();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Gestione Scorte] Daily license validation failed: ' . $result['message'] );
		}
	}

	// ── Admin notice ───────────────────────────────────────────────────────────

	public static function show_inactive_notice() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$plugin_screens = array(
			'toplevel_page_gestione-scorte',
			'gestione-scorte_page_gestione-scorte-licenza',
		);

		if ( ! in_array( $screen->id, $plugin_screens, true ) ) {
			return;
		}

		if ( self::is_active() ) {
			return;
		}

		$license_url = admin_url( 'admin.php?page=gestione-scorte-licenza' );

		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Gestione Scorte: licenza non attiva. Inserisci una license key valida per utilizzare il plugin.', 'gestione-scorte' ),
			esc_url( $license_url ),
			esc_html__( 'Attiva la licenza', 'gestione-scorte' )
		);
	}

	// ── Page rendering ─────────────────────────────────────────────────────────

	public static function render_license_page() {
		$license_key   = self::get_stored_key();
		$status        = get_option( self::OPT_STATUS, 'inactive' );
		$domain        = get_option( self::OPT_DOMAIN, '' );
		$activated_at  = (int) get_option( self::OPT_ACTIVATED_AT, 0 );
		$is_active     = ( 'active' === $status );

		$notice_key = 'gs_license_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( $notice ) {
			delete_transient( $notice_key );
		}
		?>
		<div class="wrap gs-wrap">

			<h1 class="gs-page-title">
				<span class="dashicons dashicons-admin-network"></span>
				<?php esc_html_e( 'Licenza — Gestione Scorte', 'gestione-scorte' ); ?>
			</h1>

			<?php if ( $notice ) : ?>
				<div class="gs-license-notice gs-license-notice--<?php echo esc_attr( $notice['type'] ); ?>">
					<?php echo esc_html( $notice['message'] ); ?>
				</div>
			<?php endif; ?>

			<div class="gs-license-box">

				<div class="gs-license-status-row">
					<span class="gs-license-status-label">
						<?php esc_html_e( 'Stato:', 'gestione-scorte' ); ?>
					</span>
					<?php if ( $is_active ) : ?>
						<span class="gs-license-badge gs-license-badge--active">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Attiva', 'gestione-scorte' ); ?>
						</span>
					<?php else : ?>
						<span class="gs-license-badge gs-license-badge--inactive">
							<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
							<?php esc_html_e( 'Non attiva', 'gestione-scorte' ); ?>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( $is_active ) : ?>

					<table class="gs-license-info-table">
						<tr>
							<th><?php esc_html_e( 'License Key:', 'gestione-scorte' ); ?></th>
							<td>
								<code class="gs-license-key-display">
									<?php echo esc_html( self::mask_key( $license_key ) ); ?>
								</code>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Dominio:', 'gestione-scorte' ); ?></th>
							<td><?php echo esc_html( $domain ); ?></td>
						</tr>
						<?php if ( $activated_at ) : ?>
						<tr>
							<th><?php esc_html_e( 'Attivata il:', 'gestione-scorte' ); ?></th>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ), $activated_at ) ); ?></td>
						</tr>
						<?php endif; ?>
					</table>

					<form method="post" action="">
						<?php wp_nonce_field( 'gs_license_nonce' ); ?>
						<input type="hidden" name="gs_license_action" value="deactivate" />
						<button
							type="submit"
							class="gs-license-btn gs-license-btn--deactivate"
							onclick="return confirm('<?php echo esc_js( __( 'Sei sicuro di voler disattivare la licenza? Potrai riattivarla su un altro dominio.', 'gestione-scorte' ) ); ?>')"
						>
							<span class="dashicons dashicons-no" aria-hidden="true"></span>
							<?php esc_html_e( 'Disattiva Licenza', 'gestione-scorte' ); ?>
						</button>
					</form>

				<?php else : ?>

					<form method="post" action="" class="gs-license-form">
						<?php wp_nonce_field( 'gs_license_nonce' ); ?>
						<input type="hidden" name="gs_license_action" value="activate" />

						<div class="gs-license-field">
							<label for="gs_license_key" class="gs-license-field-label">
								<?php esc_html_e( 'License Key:', 'gestione-scorte' ); ?>
							</label>
							<input
								type="text"
								id="gs_license_key"
								name="gs_license_key"
								class="gs-license-input regular-text"
								placeholder="XXXX-XXXX-XXXX-XXXX"
								value=""
								autocomplete="off"
								spellcheck="false"
								required
							/>
						</div>

						<button type="submit" class="gs-license-btn gs-license-btn--activate">
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php esc_html_e( 'Attiva Licenza', 'gestione-scorte' ); ?>
						</button>
					</form>

				<?php endif; ?>

			</div><!-- /.gs-license-box -->

		</div><!-- /.wrap -->
		<?php
	}

	public static function render_locked_notice() {
		$license_url = admin_url( 'admin.php?page=gestione-scorte-licenza' );
		?>
		<div class="wrap gs-wrap">

			<h1 class="gs-page-title">
				<span class="dashicons dashicons-archive" aria-hidden="true"></span>
				<?php esc_html_e( 'Gestione Scorte', 'gestione-scorte' ); ?>
			</h1>

			<div class="gs-license-locked-panel">
				<span class="dashicons dashicons-lock gs-license-lock-icon" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Licenza richiesta', 'gestione-scorte' ); ?></h2>
				<p><?php esc_html_e( 'Attiva la tua licenza per utilizzare Gestione Scorte.', 'gestione-scorte' ); ?></p>
				<a href="<?php echo esc_url( $license_url ); ?>" class="gs-license-btn gs-license-btn--activate">
					<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
					<?php esc_html_e( 'Attiva Licenza', 'gestione-scorte' ); ?>
				</a>
			</div>

		</div><!-- /.wrap -->
		<?php
	}

	// ── LMFWC API calls ────────────────────────────────────────────────────────

	private static function api_activate( $license_key ) {
		$url      = self::build_api_url( 'activate', $license_key );
		$args     = self::get_api_args();
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message(), 'data' => array() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return array( 'success' => false, 'message' => __( 'Risposta non valida dal server licenze.', 'gestione-scorte' ), 'data' => array() );
		}

		if ( 200 === $code && ! empty( $body['success'] ) ) {
			return array( 'success' => true, 'message' => '', 'data' => isset( $body['data'] ) ? (array) $body['data'] : array() );
		}

		$msg = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'Errore sconosciuto dal server licenze.', 'gestione-scorte' );

		return array( 'success' => false, 'message' => $msg, 'data' => array() );
	}

	private static function api_deactivate( $license_key ) {
		$url      = self::build_api_url( 'deactivate', $license_key );
		$args     = self::get_api_args();
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message(), 'data' => array() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return array( 'success' => false, 'message' => '', 'data' => array() );
		}

		return array(
			'success' => ( 200 === $code && ! empty( $body['success'] ) ),
			'message' => isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : '',
			'data'    => isset( $body['data'] ) ? (array) $body['data'] : array(),
		);
	}

	private static function api_validate( $license_key ) {
		$url      = self::build_api_url( 'validate', $license_key );
		$args     = self::get_api_args();
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message(), 'data' => array() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return array( 'success' => false, 'message' => __( 'Risposta non valida dal server licenze.', 'gestione-scorte' ), 'data' => array() );
		}

		if ( 200 !== $code || empty( $body['success'] ) ) {
			$msg = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'Licenza non valida.', 'gestione-scorte' );
			return array( 'success' => false, 'message' => $msg, 'data' => array() );
		}

		// Domain check: stored domain must match current site.
		$stored_domain = get_option( self::OPT_DOMAIN, '' );
		if ( '' !== $stored_domain && $stored_domain !== home_url() ) {
			return array(
				'success' => false,
				'message' => __( 'Il dominio della licenza non corrisponde a questo sito.', 'gestione-scorte' ),
				'data'    => array(),
			);
		}

		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();

		// LMFWC validate endpoint returns {"success":true,"data":{"isValid":true,...}}
		// Treat any truthy success response as valid.
		$is_valid = ! empty( $body['success'] );

		if ( ! $is_valid ) {
			$msg = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'La licenza risulta non valida sul server.', 'gestione-scorte' );
			return array( 'success' => false, 'message' => $msg, 'data' => $data );
		}

		return array( 'success' => true, 'message' => '', 'data' => $data );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	private static function get_api_args() {
		return array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( GS_API_CONSUMER_KEY . ':' . GS_API_CONSUMER_SECRET ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Accept'        => 'application/json',
				'User-Agent'    => 'GestioneScorte/' . GS_VERSION . '; ' . home_url(),
			),
			'timeout'   => 15,
			'sslverify' => true,
		);
	}

	private static function build_api_url( $endpoint, $license_key ) {
		return trailingslashit( GS_LICENSE_SERVER_URL )
			. 'wp-json/lmfwc/v2/licenses/'
			. $endpoint . '/'
			. rawurlencode( $license_key );
	}

	private static function clear_license_data() {
		delete_option( self::OPT_KEY );
		delete_option( self::OPT_STATUS );
		delete_option( self::OPT_DOMAIN );
		delete_option( self::OPT_ACTIVATED_AT );
		delete_transient( self::TRANSIENT_CACHE );
	}

	private static function obfuscate( $value ) {
		return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function deobfuscate( $value ) {
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return ( false !== $decoded ) ? $decoded : '';
	}

	private static function get_stored_key() {
		$raw = get_option( self::OPT_KEY, '' );
		return '' !== $raw ? self::deobfuscate( $raw ) : '';
	}

	private static function mask_key( $key ) {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $key, -4 );
	}

	private static function set_user_notice( $type, $message ) {
		set_transient(
			'gs_license_notice_' . get_current_user_id(),
			array( 'type' => $type, 'message' => $message ),
			60
		);
	}
}
