(function ($) {
	'use strict';

	var config = window.lpCargonizerPickupPointsConfig || {};
	if (!config.ajaxUrl || !config.ajaxAction || !config.nonce) {
		return;
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
		}).always(function () {
			$select.prop('disabled', false);
			$(document.body).trigger('update_checkout');
		});
	});
})(jQuery);
