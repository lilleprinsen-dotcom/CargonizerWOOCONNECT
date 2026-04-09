(function ($) {
	'use strict';

	var config = window.lpCargonizerPickupPointsConfig || {};
	if (!config.ajaxUrl || !config.ajaxAction || !config.nonce) {
		return;
	}

	$(document.body).on('change', '.lp-cargonizer-pickup-point-select', function () {
		var $select = $(this);
		var rateId = String($select.data('rate-id') || '');
		var pickupPointId = String($select.val() || '');
		if (!rateId || !pickupPointId) {
			return;
		}

		$select.prop('disabled', true);
		$.post(config.ajaxUrl, {
			action: config.ajaxAction,
			nonce: config.nonce,
			rate_id: rateId,
			pickup_point_id: pickupPointId
		}).always(function () {
			$select.prop('disabled', false);
			$(document.body).trigger('update_checkout');
		});
	});
})(jQuery);
