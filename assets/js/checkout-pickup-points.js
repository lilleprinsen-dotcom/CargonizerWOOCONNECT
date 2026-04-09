(function ($) {
	'use strict';

	var config = window.lpCargonizerPickupPointsConfig || {};
	if (!config.ajaxUrl || !config.ajaxAction || !config.nonce) {
		return;
	}
	var refreshDebounceTimer = null;
	var pendingRefreshRequest = null;
	var queuedRefreshAfterCurrent = false;
	var lastRefreshStartedAt = 0;
	var selectorIdCounter = 0;

	function hasPickupSelectors() {
		return $('.lp-cargonizer-pickup-point-select').length > 0;
	}

	function normalizeRateId(value) {
		return String(value || '');
	}

	function getChosenShippingRateIds() {
		var ids = [];
		$('input[name^="shipping_method"]:checked').each(function () {
			var value = normalizeRateId($(this).val());
			if (value) {
				ids.push(value);
			}
		});
		return ids;
	}

	function ensurePickupSelectorForRate(rateId) {
		rateId = normalizeRateId(rateId);
		if (!rateId) {
			return $();
		}
		var $existing = $('.lp-cargonizer-pickup-point-select[data-rate-id="' + rateId + '"]');
		if ($existing.length) {
			return $existing.first();
		}
		var $shippingInput = $('input[name^="shipping_method"][value="' + rateId + '"]');
		if (!$shippingInput.length) {
			return $();
		}
		var $row = $shippingInput.closest('li, .wc-block-components-radio-control__option, .wc-block-components-shipping-rates-control__package');
		if (!$row.length) {
			return $();
		}
		selectorIdCounter += 1;
		var selectorId = 'lp-cargonizer-pickup-dynamic-' + selectorIdCounter;
		var $container = $('<div class="lp-cargonizer-checkout-pickup-point" style="margin:8px 0 0 24px;"></div>');
		var $label = $('<label style="display:block;margin:0 0 6px;font-weight:600;"></label>')
			.attr('for', selectorId)
			.text('Pickup point');
		var $select = $('<select class="lp-cargonizer-pickup-point-select"></select>')
			.attr('id', selectorId)
			.attr('data-rate-id', rateId)
			.attr('data-selected-pickup-point-id', '')
			.attr('data-state', 'loading');
		$select.append($('<option></option>').attr('value', '').text('Fetching pickup points…'));
		$container.append($label).append($select);
		$row.append($container);
		return $select;
	}

	function triggerCompatibilityUpdate() {
		$(document.body).trigger('lp_cargonizer_pickup_point_updated');
		if ($('form.checkout').length) {
			$(document.body).trigger('update_checkout');
		}
		if (window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.emitEvent === 'function') {
			window.wc.blocksCheckout.emitEvent('lp-cargonizer-pickup-point-updated');
		}
	}

	function setSelectorState($select, state, message) {
		if (!$select || !$select.length) {
			return;
		}
		var $container = $select.closest('.lp-cargonizer-checkout-pickup-point');
		var normalizedState = String(state || 'loading');
		$select.attr('data-state', normalizedState);
		if (normalizedState === 'loaded') {
			$container.show();
			$select.prop('disabled', false);
			return;
		}
		if (normalizedState === 'unavailable') {
			$container.hide();
			$select.prop('disabled', true);
			return;
		}
		$container.show();
		$select.prop('disabled', true);
		$select.empty().append(
			$('<option></option>')
				.attr('value', '')
				.text(String(message || (normalizedState === 'error' ? 'Pickup points unavailable.' : 'Fetching pickup points…')))
		);
	}

	function applyErrorStateToLoadingSelectors(message) {
		$('.lp-cargonizer-pickup-point-select').each(function () {
			var $select = $(this);
			var state = String($select.attr('data-state') || '');
			if (state === '' || state === 'loading') {
				setSelectorState($select, 'error', message || 'Could not fetch pickup points.');
			}
		});
	}

	function refreshCompatibilityPayload() {
		if (!config.ajaxGetAction) {
			return;
		}
		if (!hasPickupSelectors() && !getChosenShippingRateIds().length) {
			return;
		}
		if (pendingRefreshRequest) {
			queuedRefreshAfterCurrent = true;
			return;
		}
		lastRefreshStartedAt = Date.now();
		pendingRefreshRequest = $.post(config.ajaxUrl, {
			action: config.ajaxGetAction,
			nonce: config.nonce
		}).done(function (response) {
			if (!response || !response.success || !response.data) {
				applyErrorStateToLoadingSelectors('Could not resolve pickup point state.');
				return;
			}
			window.lpCargonizerPickupPointsState = response.data;
			applyPickupPointsStateToSelectors(response.data);
			$(document.body).trigger('lp_cargonizer_pickup_points_state_ready', [response.data]);
		}).fail(function () {
			applyErrorStateToLoadingSelectors('Could not fetch pickup points.');
		}).always(function () {
			pendingRefreshRequest = null;
			if (queuedRefreshAfterCurrent) {
				queuedRefreshAfterCurrent = false;
				refreshCompatibilityPayload();
			}
		});
	}

	function scheduleCompatibilityRefresh() {
		if (!hasPickupSelectors() && !getChosenShippingRateIds().length) {
			return;
		}
		var now = Date.now();
		var minIntervalMs = 400;
		var elapsed = now - lastRefreshStartedAt;
		var debounceMs = elapsed >= minIntervalMs ? 250 : (minIntervalMs - elapsed);
		if (refreshDebounceTimer) {
			clearTimeout(refreshDebounceTimer);
		}
		refreshDebounceTimer = setTimeout(function () {
			refreshDebounceTimer = null;
			refreshCompatibilityPayload();
		}, debounceMs);
	}

	function applyPickupPointsStateToSelectors(state) {
		if (!state || !Array.isArray(state.items)) {
			return;
		}
		state.items.forEach(function (item) {
			if (!item || !item.rate_id) {
				return;
			}
			var $select = $('.lp-cargonizer-pickup-point-select[data-rate-id="' + item.rate_id + '"]');
			if (!$select.length) {
				$select = ensurePickupSelectorForRate(item.rate_id);
			}
			if (!$select.length) {
				return;
			}
			var state = String(item.state || '');
			var message = String(item.message || '');
			var points = Array.isArray(item.pickup_points) ? item.pickup_points : [];
			if (state === 'unavailable' || item.unavailable) {
				setSelectorState($select, 'unavailable', message);
				return;
			}
			if (state === 'error' || item.error) {
				setSelectorState($select, 'error', message || 'Pickup points unavailable.');
				return;
			}
			if (!points.length) {
				setSelectorState($select, 'loading', message || 'Fetching pickup points…');
				return;
			}
			var selectedId = String(item.selected_pickup_point_id || points[0].id || '');
			$select.empty();
			points.forEach(function (point) {
				if (!point || !point.id) {
					return;
				}
				var label = point.label || point.id;
				var $option = $('<option></option>').attr('value', String(point.id)).text(String(label));
				if (String(point.id) === selectedId) {
					$option.prop('selected', true);
				}
				$select.append($option);
			});
			$select.data('selected-pickup-point-id', selectedId);
			setSelectorState($select, 'loaded', '');
		});
	}

	var pendingRequest = null;
	$(document.body)
		.off('change.lpCargonizerPickupPoints', '.lp-cargonizer-pickup-point-select')
		.on('change.lpCargonizerPickupPoints', '.lp-cargonizer-pickup-point-select', function () {
		var $select = $(this);
		var rateId = String($select.data('rate-id') || '');
		var pickupPointId = String($select.val() || '');
		if (!rateId || !pickupPointId) {
			return;
		}
		var previousPickupPointId = String($select.data('selected-pickup-point-id') || '');
		if (previousPickupPointId === pickupPointId) {
			return;
		}

		$select.prop('disabled', true);
		if (pendingRequest && typeof pendingRequest.abort === 'function') {
			pendingRequest.abort();
		}
		pendingRequest = $.post(config.ajaxUrl, {
			action: config.ajaxAction,
			nonce: config.nonce,
			rate_id: rateId,
			pickup_point_id: pickupPointId
		}).done(function () {
			$select.data('selected-pickup-point-id', pickupPointId);
			scheduleCompatibilityRefresh();
		}).fail(function (xhr) {
			var response = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
			if (response && response.pickup_point_id) {
				$select.data('selected-pickup-point-id', String(response.pickup_point_id));
				$select.val(String(response.pickup_point_id));
				scheduleCompatibilityRefresh();
			}
		}).always(function () {
			$select.prop('disabled', false);
			triggerCompatibilityUpdate();
		});
	});

	$(document.body).on('updated_checkout wc-blocks_checkout_updated', function () {
		scheduleCompatibilityRefresh();
	});
	$(document.body).on('change', 'input[name^="shipping_method"]', function () {
		scheduleCompatibilityRefresh();
	});
	if (hasPickupSelectors() || getChosenShippingRateIds().length) {
		scheduleCompatibilityRefresh();
	}
})(jQuery);
