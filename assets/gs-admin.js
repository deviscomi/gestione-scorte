/**
 * Gestione Scorte — Frontend logic
 */
(function ($) {
	'use strict';

	// ─── State ───────────────────────────────────────────────────────────────
	var mode     = null;   // 'scarico' | 'carico'
	var products = {};     // { [productId]: { id, name, sku, stock, qty } }

	// ─── DOM cache ───────────────────────────────────────────────────────────
	var $mainView, $opView;
	var $barcodeInput, $barcodeMsg, $spinner;
	var $emptyState, $tableWrapper, $tbody;
	var $btnConfirm, $btnConfirmLabel, $resultMsg;

	// ─── Init ─────────────────────────────────────────────────────────────────
	function init() {
		$mainView        = $('#gs-main-view');
		$opView          = $('#gs-operation-view');
		$barcodeInput    = $('#gs-barcode-input');
		$barcodeMsg      = $('#gs-barcode-message');
		$spinner         = $('#gs-barcode-spinner');
		$emptyState      = $('#gs-empty-state');
		$tableWrapper    = $('#gs-table-wrapper');
		$tbody           = $('#gs-products-tbody');
		$btnConfirm      = $('#gs-btn-confirm');
		$btnConfirmLabel = $('#gs-btn-confirm-label');
		$resultMsg       = $('#gs-result-message');

		bindEvents();
	}

	function bindEvents() {
		$('#gs-btn-scarico').on('click', function () { enterMode('scarico'); });
		$('#gs-btn-carico').on('click',  function () { enterMode('carico'); });

		$('#gs-btn-back').on('click',   exitToMain);
		$('#gs-btn-cancel').on('click', exitToMain);
		$btnConfirm.on('click',         onConfirm);

		$barcodeInput.on('keydown', onBarcodeKeydown);

		$tbody.on('click',        '.gs-btn-remove', onRemoveRow);
		$tbody.on('input change', '.gs-qty-input',  onQtyChange);

		// Escape → refocus scanner from anywhere in the operation view.
		$(document).on('keydown.gs', function (e) {
			if (e.key === 'Escape' && mode) {
				e.preventDefault();
				focusBarcode();
			}
		});
	}

	// ─── Mode management ─────────────────────────────────────────────────────

	function enterMode(newMode) {
		mode     = newMode;
		products = {};

		resetTable();
		$barcodeInput.val('');
		clearMsg($barcodeMsg);
		clearMsg($resultMsg);
		$btnConfirm.prop('disabled', true);

		if (mode === 'scarico') {
			$('#gs-operation-title').text('SCARICO');
			$('#gs-operation-badge').text('scarico').attr('class', 'gs-badge gs-badge--scarico');
			$('#gs-col-qty-label').text('Quantità da Scaricare');
			$btnConfirmLabel.text('SCARICA');
			$btnConfirm.removeClass('gs-btn--carico').addClass('gs-btn--scarico-confirm');
		} else {
			$('#gs-operation-title').text('CARICO');
			$('#gs-operation-badge').text('carico').attr('class', 'gs-badge gs-badge--carico');
			$('#gs-col-qty-label').text('Quantità da Caricare');
			$btnConfirmLabel.text('CARICA');
			$btnConfirm.removeClass('gs-btn--scarico-confirm').addClass('gs-btn--carico');
		}

		$mainView.addClass('gs-hidden');
		$opView.removeClass('gs-hidden');
		focusBarcode();
	}

	function exitToMain() {
		mode     = null;
		products = {};
		$opView.addClass('gs-hidden');
		$mainView.removeClass('gs-hidden');
		clearMsg($barcodeMsg);
		clearMsg($resultMsg);
	}

	// ─── Barcode input ────────────────────────────────────────────────────────

	function onBarcodeKeydown(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			var val = $.trim($barcodeInput.val());
			if (val) {
				searchProduct(val);
			}
		}
	}

	function searchProduct(barcode) {
		clearMsg($barcodeMsg);
		clearMsg($resultMsg);
		$barcodeInput.prop('disabled', true);
		$spinner.removeClass('gs-hidden');

		$.ajax({
			url:  gsData.ajaxUrl,
			type: 'POST',
			data: {
				action:  'gs_search_product',
				nonce:   gsData.nonce,
				barcode: barcode,
			},
		})
		.done(function (res) {
			if (res.success) {
				handleProductFound(res.data);
			} else {
				var msg = (res.data && res.data.message) ? res.data.message : gsData.i18n.productNotFound;
				showMsg($barcodeMsg, msg, 'error');
			}
		})
		.fail(function () {
			showMsg($barcodeMsg, gsData.i18n.errorGeneric, 'error');
		})
		.always(function () {
			$spinner.addClass('gs-hidden');
			$barcodeInput.prop('disabled', false).val('');
			focusBarcode();
		});
	}

	// ─── Product handling ─────────────────────────────────────────────────────

	function handleProductFound(product) {
		// Soft error from server (e.g. variable product with no variant identified).
		if (product.error_type) {
			showMsg($barcodeMsg, product.message, 'warning');
			return;
		}

		if (!product.manages_stock) {
			showMsg($barcodeMsg, gsData.i18n.noStockManaged, 'warning');
			return;
		}

		var id = product.id;

		if (products[id]) {
			// Increment quantity for existing row.
			products[id].qty += 1;
			var $row = $tbody.find('tr[data-id="' + id + '"]');
			$row.find('.gs-qty-input').val(products[id].qty);
			refreshResult($row, id);
		} else {
			// Add new row.
			products[id] = {
				id:    product.id,
				name:  product.name,
				sku:   product.sku,
				stock: product.stock,
				qty:   1,
			};
			appendRow(id);
		}

		syncUI();
	}

	// ─── Table management ─────────────────────────────────────────────────────

	function appendRow(id) {
		var p      = products[id];
		var result = calcResult(p.stock, p.qty);

		var $row = $('<tr></tr>').attr('data-id', id);

		$row.append(
			$('<td class="gs-col-name"></td>').append(
				$('<span class="gs-product-name"></span>').text(p.name),
				$('<small class="gs-product-sku"></small>').text('SKU: ' + p.sku)
			),
			$('<td class="gs-col-stock"></td>').text(p.stock),
			$('<td class="gs-col-qty"></td>').append(
				$('<input type="number" class="gs-qty-input" min="1" step="1" />')
					.val(p.qty)
			),
			$('<td class="gs-col-result gs-stock-result"></td>').text(result),
			$('<td class="gs-col-actions"></td>').append(
				$('<button type="button" class="gs-btn-remove button button-small"></button>')
					.html('<span class="dashicons dashicons-trash" aria-hidden="true"></span> Rimuovi')
			)
		);

		applyResultClass($row.find('.gs-stock-result'), result);
		$tbody.append($row);
	}

	function refreshResult($row, id) {
		var p      = products[id];
		var result = calcResult(p.stock, p.qty);
		var $cell  = $row.find('.gs-stock-result');
		$cell.text(result);
		applyResultClass($cell, result);
	}

	function calcResult(stock, qty) {
		return mode === 'scarico' ? (stock - qty) : (stock + qty);
	}

	function applyResultClass($cell, value) {
		$cell
			.toggleClass('gs-stock-negative', value < 0)
			.toggleClass('gs-stock-ok',       value >= 0);
	}

	function onQtyChange() {
		var $input = $(this);
		var $row   = $input.closest('tr');
		var id     = parseInt($row.attr('data-id'), 10);
		var qty    = parseInt($input.val(), 10);

		if (isNaN(qty) || qty < 1) {
			qty = 1;
			$input.val(1);
		}

		products[id].qty = qty;
		refreshResult($row, id);
		syncUI();
	}

	function onRemoveRow() {
		var $row = $(this).closest('tr');
		var id   = parseInt($row.attr('data-id'), 10);
		delete products[id];
		$row.remove();
		syncUI();
		focusBarcode();
	}

	function resetTable() {
		$tbody.empty();
		$emptyState.removeClass('gs-hidden');
		$tableWrapper.addClass('gs-hidden');
	}

	function syncUI() {
		var hasItems = Object.keys(products).length > 0;
		$emptyState.toggleClass('gs-hidden',  hasItems);
		$tableWrapper.toggleClass('gs-hidden', !hasItems);
		$btnConfirm.prop('disabled', !hasItems);
	}

	// ─── Confirm action ───────────────────────────────────────────────────────

	function onConfirm() {
		if (!Object.keys(products).length) {
			return;
		}

		var confirmMsg = mode === 'scarico' ? gsData.i18n.confirmScarico : gsData.i18n.confirmCarico;

		// eslint-disable-next-line no-alert
		if (!window.confirm(confirmMsg)) {
			focusBarcode();
			return;
		}

		var items = [];
		for (var id in products) {
			if (products.hasOwnProperty(id)) {
				items.push({ id: parseInt(id, 10), quantity: products[id].qty });
			}
		}

		$btnConfirm.prop('disabled', true);
		$barcodeInput.prop('disabled', true);

		$.ajax({
			url:  gsData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'gs_update_stock',
				nonce:  gsData.nonce,
				mode:   mode,
				items:  items,
			},
		})
		.done(function (res) {
			if (res.success) {
				var msg = mode === 'scarico' ? gsData.i18n.successScarico : gsData.i18n.successCarico;
				if (res.data.errors && res.data.errors.length) {
					msg += '<br><strong>Attenzione:</strong><br>' + res.data.errors.join('<br>');
				}
				showMsg($resultMsg, msg, 'success');
				resetTable();
				products = {};
				$btnConfirm.prop('disabled', true);
			} else {
				var errMsg = (res.data && res.data.message) ? res.data.message : gsData.i18n.errorGeneric;
				showMsg($resultMsg, errMsg, 'error');
				$btnConfirm.prop('disabled', false);
			}
		})
		.fail(function () {
			showMsg($resultMsg, gsData.i18n.errorGeneric, 'error');
			$btnConfirm.prop('disabled', false);
		})
		.always(function () {
			$barcodeInput.prop('disabled', false);
			focusBarcode();
		});
	}

	// ─── Utilities ────────────────────────────────────────────────────────────

	function focusBarcode() {
		setTimeout(function () { $barcodeInput.trigger('focus'); }, 60);
	}

	function showMsg($el, html, type) {
		$el.html(html)
		   .removeClass('gs-msg--success gs-msg--error gs-msg--warning')
		   .addClass('gs-msg--' + type)
		   .removeClass('gs-hidden');
	}

	function clearMsg($el) {
		$el.addClass('gs-hidden').empty().removeClass('gs-msg--success gs-msg--error gs-msg--warning');
	}

	// ─── Bootstrap ────────────────────────────────────────────────────────────
	$(document).ready(init);

}(jQuery));
