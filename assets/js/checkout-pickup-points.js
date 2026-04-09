(function ($) {
	'use strict';

	var config = window.lpCargonizerPickupPointsConfig || {};
	if (!config.ajaxUrl || !config.ajaxAction || !config.nonce) {
		return;
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

	function refreshCompatibilityPayload() {
		if (!config.ajaxGetAction) {
			return;
		}
		$.post(config.ajaxUrl, {
			action: config.ajaxGetAction,
			nonce: config.nonce
		}).done(function (response) {
			if (!response || !response.success || !response.data) {
				return;
			}
			window.lpCargonizerPickupPointsState = response.data;
			applyPickupPointsStateToSelectors(response.data);
			$(document.body).trigger('lp_cargonizer_pickup_points_state_ready', [response.data]);
		});
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
				return;
			}
			var points = Array.isArray(item.pickup_points) ? item.pickup_points : [];
			if (!points.length) {
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
			refreshCompatibilityPayload();
		}).always(function () {
			$select.prop('disabled', false);
			triggerCompatibilityUpdate();
		});
	});

	$(document.body).on('updated_checkout wc-blocks_checkout_updated', function () {
		refreshCompatibilityPayload();
	});
	refreshCompatibilityPayload();
})(jQuery);
