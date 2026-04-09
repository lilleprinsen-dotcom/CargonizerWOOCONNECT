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
			action: config.ajaxGetAction
		}).done(function (response) {
			if (!response || !response.success || !response.data) {
				return;
			}
			window.lpCargonizerPickupPointsState = response.data;
			$(document.body).trigger('lp_cargonizer_pickup_points_state_ready', [response.data]);
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
