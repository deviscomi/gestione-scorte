<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GS_Admin {

	public static function init() {
		add_action( 'admin_menu',            array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	// ─── Menu ─────────────────────────────────────────────────────────────────

	public static function add_menu() {
		add_menu_page(
			__( 'Gestione Scorte', 'gestione-scorte' ),
			__( 'Gestione Scorte', 'gestione-scorte' ),
			'manage_woocommerce',
			'gestione-scorte',
			array( __CLASS__, 'render_page' ),
			'dashicons-archive',
			58
		);
	}

	// ─── Assets ───────────────────────────────────────────────────────────────

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_gestione-scorte' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'gs-admin',
			GS_PLUGIN_URL . 'assets/gs-admin.css',
			array(),
			GS_VERSION
		);

		wp_enqueue_script(
			'gs-admin',
			GS_PLUGIN_URL . 'assets/gs-admin.js',
			array( 'jquery' ),
			GS_VERSION,
			true
		);

		wp_localize_script(
			'gs-admin',
			'gsData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gs_nonce' ),
				'i18n'    => array(
					'productNotFound' => __( 'Prodotto non trovato per il barcode/SKU inserito.', 'gestione-scorte' ),
					'noStockManaged'  => __( 'Questo prodotto non ha la gestione scorte attiva.', 'gestione-scorte' ),
					'confirmScarico'  => __( 'Confermi lo scarico delle scorte per i prodotti in lista?', 'gestione-scorte' ),
					'confirmCarico'   => __( 'Confermi il carico delle scorte per i prodotti in lista?', 'gestione-scorte' ),
					'successScarico'  => __( 'Scarico effettuato con successo!', 'gestione-scorte' ),
					'successCarico'   => __( 'Carico effettuato con successo!', 'gestione-scorte' ),
					'errorGeneric'    => __( 'Si è verificato un errore. Riprova.', 'gestione-scorte' ),
					'noStockMgmt'     => __( 'Il prodotto non ha la gestione scorte abilitata.', 'gestione-scorte' ),
				),
			)
		);
	}

	// ─── Page HTML ────────────────────────────────────────────────────────────

	public static function render_page() {
		?>
		<div class="wrap gs-wrap">

			<h1 class="gs-page-title">
				<span class="dashicons dashicons-archive"></span>
				<?php esc_html_e( 'Gestione Scorte', 'gestione-scorte' ); ?>
			</h1>

			<!-- ═══════════════════════════════════════ MAIN VIEW ══ -->
			<div id="gs-main-view">
				<p class="gs-subtitle">
					<?php esc_html_e( 'Seleziona l\'operazione da eseguire:', 'gestione-scorte' ); ?>
				</p>
				<div class="gs-cta-container">
					<button id="gs-btn-scarico" class="gs-cta gs-cta--scarico">
						<span class="gs-cta-icon" aria-hidden="true">📦</span>
						<span class="gs-cta-label"><?php esc_html_e( 'SCARICO', 'gestione-scorte' ); ?></span>
						<span class="gs-cta-desc"><?php esc_html_e( 'Decrementa le scorte', 'gestione-scorte' ); ?></span>
					</button>
					<button id="gs-btn-carico" class="gs-cta gs-cta--carico">
						<span class="gs-cta-icon" aria-hidden="true">📥</span>
						<span class="gs-cta-label"><?php esc_html_e( 'CARICO', 'gestione-scorte' ); ?></span>
						<span class="gs-cta-desc"><?php esc_html_e( 'Incrementa le scorte', 'gestione-scorte' ); ?></span>
					</button>
				</div>
			</div>

			<!-- ═══════════════════════════════ OPERATION VIEW ══ -->
			<div id="gs-operation-view" class="gs-hidden">

				<div class="gs-op-header">
					<button id="gs-btn-back" class="gs-back-btn" title="<?php esc_attr_e( 'Torna alla selezione', 'gestione-scorte' ); ?>">
						<span class="dashicons dashicons-arrow-left-alt"></span>
					</button>
					<h2 id="gs-operation-title"></h2>
					<span id="gs-operation-badge" class="gs-badge"></span>
				</div>

				<!-- Barcode input -->
				<div class="gs-barcode-card">
					<label for="gs-barcode-input" class="gs-barcode-label">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Scansiona o inserisci il barcode / SKU:', 'gestione-scorte' ); ?>
					</label>
					<div class="gs-barcode-row">
						<input
							type="text"
							id="gs-barcode-input"
							class="gs-barcode-input"
							placeholder="<?php esc_attr_e( 'Barcode o SKU...', 'gestione-scorte' ); ?>"
							autocomplete="off"
							autofocus
						/>
						<span id="gs-barcode-spinner" class="gs-spinner gs-hidden" aria-hidden="true"></span>
					</div>
					<div id="gs-barcode-message" class="gs-message gs-hidden" role="alert"></div>
				</div>

				<!-- Products table -->
				<div class="gs-table-section">

					<div id="gs-empty-state" class="gs-empty-state">
						<span class="dashicons dashicons-archive" aria-hidden="true"></span>
						<p><?php esc_html_e( 'Nessun prodotto scansionato. Usa il campo sopra per iniziare.', 'gestione-scorte' ); ?></p>
					</div>

					<div id="gs-table-wrapper" class="gs-hidden">
						<table id="gs-products-table" class="gs-table widefat striped">
							<thead>
								<tr>
									<th class="gs-col-name"><?php esc_html_e( 'Nome Articolo', 'gestione-scorte' ); ?></th>
									<th class="gs-col-stock"><?php esc_html_e( 'Scorta Attuale', 'gestione-scorte' ); ?></th>
									<th class="gs-col-qty" id="gs-col-qty-label"><?php esc_html_e( 'Quantità', 'gestione-scorte' ); ?></th>
									<th class="gs-col-result"><?php esc_html_e( 'Scorta Risultante', 'gestione-scorte' ); ?></th>
									<th class="gs-col-actions"><?php esc_html_e( 'Azioni', 'gestione-scorte' ); ?></th>
								</tr>
							</thead>
							<tbody id="gs-products-tbody"></tbody>
						</table>
					</div>

				</div>

				<!-- Action buttons -->
				<div class="gs-action-buttons">
					<button id="gs-btn-confirm" class="gs-btn gs-btn--confirm" disabled>
						<span class="gs-btn-icon" aria-hidden="true">✓</span>
						<span id="gs-btn-confirm-label"></span>
					</button>
					<button id="gs-btn-cancel" class="gs-btn gs-btn--cancel">
						<span class="gs-btn-icon" aria-hidden="true">✕</span>
						<?php esc_html_e( 'ANNULLA', 'gestione-scorte' ); ?>
					</button>
				</div>

				<!-- Feedback message after confirm -->
				<div id="gs-result-message" class="gs-message gs-hidden" role="alert"></div>

			</div><!-- /#gs-operation-view -->

		</div><!-- /.wrap -->
		<?php
	}
}
