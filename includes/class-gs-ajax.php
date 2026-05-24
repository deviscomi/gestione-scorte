<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GS_Ajax {

	public static function init() {
		add_action( 'wp_ajax_gs_search_product', array( __CLASS__, 'search_product' ) );
		add_action( 'wp_ajax_gs_update_stock',   array( __CLASS__, 'update_stock' ) );
	}

	// ─── Search product ───────────────────────────────────────────────────────

	public static function search_product() {
		check_ajax_referer( 'gs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'gestione-scorte' ) ), 403 );
		}

		$barcode = isset( $_POST['barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';

		if ( '' === $barcode ) {
			wp_send_json_error( array( 'message' => __( 'Barcode/SKU non valido.', 'gestione-scorte' ) ) );
		}

		$data = self::find_product( $barcode );

		if ( null === $data ) {
			wp_send_json_error( array( 'message' => __( 'Prodotto non trovato.', 'gestione-scorte' ) ) );
		}

		wp_send_json_success( $data );
	}

	// ─── Update stock ─────────────────────────────────────────────────────────

	public static function update_stock() {
		check_ajax_referer( 'gs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'gestione-scorte' ) ), 403 );
		}

		$mode  = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		$items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();

		if ( ! in_array( $mode, array( 'scarico', 'carico' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Modalità non valida.', 'gestione-scorte' ) ) );
		}

		if ( empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'Nessun prodotto da aggiornare.', 'gestione-scorte' ) ) );
		}

		$results  = array();
		$errors   = array();
		$wc_op    = ( 'scarico' === $mode ) ? 'decrease' : 'increase';

		foreach ( $items as $raw ) {
			$product_id = isset( $raw['id'] )       ? absint( $raw['id'] )       : 0;
			$quantity   = isset( $raw['quantity'] )  ? absint( $raw['quantity'] )  : 0;

			if ( ! $product_id || ! $quantity ) {
				$errors[] = sprintf(
					__( 'Dati non validi (ID %d, qty %d). Riga ignorata.', 'gestione-scorte' ),
					$product_id,
					$quantity
				);
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product || ! $product->exists() ) {
				$errors[] = sprintf( __( 'Prodotto ID %d non trovato.', 'gestione-scorte' ), $product_id );
				continue;
			}

			if ( ! $product->managing_stock() ) {
				$errors[] = sprintf(
					__( '"%s" non ha la gestione scorte attiva.', 'gestione-scorte' ),
					$product->get_name()
				);
				continue;
			}

			$old_stock = (int) $product->get_stock_quantity();
			$new_stock = wc_update_product_stock( $product_id, $quantity, $wc_op );

			if ( false === $new_stock ) {
				$errors[] = sprintf(
					__( 'Errore aggiornando le scorte per "%s".', 'gestione-scorte' ),
					$product->get_name()
				);
				continue;
			}

			$results[] = array(
				'id'        => $product_id,
				'name'      => $product->get_name(),
				'old_stock' => $old_stock,
				'new_stock' => (int) $new_stock,
			);
		}

		if ( empty( $results ) && ! empty( $errors ) ) {
			wp_send_json_error( array( 'message' => implode( '<br>', $errors ) ) );
		}

		wp_send_json_success(
			array(
				'results' => $results,
				'errors'  => $errors,
			)
		);
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Find a product/variation by SKU or common barcode meta fields.
	 * Returns formatted data array, a soft-error array, or null if not found.
	 */
	private static function find_product( $barcode ) {
		// 1. Try SKU (covers simple products and variations).
		$product_id = wc_get_product_id_by_sku( $barcode );

		// 2. Try _barcode meta.
		if ( ! $product_id ) {
			$product_id = self::id_by_meta( '_barcode', $barcode );
		}

		// 3. Try _gtin meta (used by several product feed plugins).
		if ( ! $product_id ) {
			$product_id = self::id_by_meta( '_gtin', $barcode );
		}

		if ( ! $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->exists() ) {
			return null;
		}

		// Variable parent found — cannot determine specific variant.
		if ( $product->is_type( 'variable' ) ) {
			return array(
				'error_type' => 'variable_no_variation',
				'message'    => sprintf(
					/* translators: %s: product name */
					__( 'Il prodotto "%s" ha delle varianti. Scansiona il barcode specifico della variante desiderata.', 'gestione-scorte' ),
					esc_html( $product->get_name() )
				),
			);
		}

		// Variation whose stock is managed by its parent.
		if ( $product->is_type( 'variation' ) && ! $product->managing_stock() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent && $parent->managing_stock() ) {
				return self::format_product( $parent );
			}
		}

		return self::format_product( $product );
	}

	/**
	 * Query post meta for a product/variation ID matching a given value.
	 */
	private static function id_by_meta( $meta_key, $meta_value ) {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = %s
				   AND meta_value = %s
				 LIMIT 1",
				$meta_key,
				$meta_value
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Build the product data array sent to the frontend.
	 */
	private static function format_product( WC_Product $product ) {
		$name = $product->get_name();

		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$attrs = array();
				foreach ( $product->get_variation_attributes() as $key => $value ) {
					$label   = wc_attribute_label( str_replace( 'attribute_', '', $key ) );
					$attrs[] = $label . ': ' . ( $value ?: __( 'Qualsiasi', 'gestione-scorte' ) );
				}
				$name = $parent->get_name() . ' — ' . implode( ', ', $attrs );
			}
		}

		return array(
			'id'            => $product->get_id(),
			'name'          => $name,
			'sku'           => $product->get_sku(),
			'manages_stock' => $product->managing_stock(),
			'stock'         => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'type'          => $product->get_type(),
		);
	}
}
