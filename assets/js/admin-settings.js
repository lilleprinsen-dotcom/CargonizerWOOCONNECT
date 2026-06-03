(function () {
	'use strict';

	var config = window.lpCargonizerAdminSettingsConfig || {};
	if (!config.ajaxUrl || !window.FormData || !window.fetch) {
		return;
	}

	var lastClickedSubmitter = null;

	function getNoticeContainer() {
		return document.getElementById('lp-cargonizer-admin-ajax-notices');
	}

	function showNotice(type, message) {
		var container = getNoticeContainer();
		if (!container) {
			return;
		}

		var noticeType = type === 'error' ? 'notice-error' : 'notice-success';
		container.innerHTML = '<div class="notice ' + noticeType + ' is-dismissible"><p></p></div>';
		var paragraph = container.querySelector('p');
		if (paragraph) {
			paragraph.textContent = message || 'Forespørselen er fullført.';
		}
	}

	function setButtonBusy(button, busyText) {
		if (!button) {
			return;
		}

		if (!button.dataset.lpCargonizerOriginalText) {
			button.dataset.lpCargonizerOriginalText = button.textContent;
		}

		button.disabled = true;
		button.textContent = busyText;
	}

	function clearButtonBusy(button) {
		if (!button) {
			return;
		}

		button.disabled = false;
		if (button.dataset.lpCargonizerOriginalText) {
			button.textContent = button.dataset.lpCargonizerOriginalText;
		}
	}

	function getSubmitter(event, form) {
		if (event && event.submitter) {
			return event.submitter;
		}

		if (lastClickedSubmitter && lastClickedSubmitter.form === form) {
			return lastClickedSubmitter;
		}

		return null;
	}

	function buildFormData(form, submitter, action) {
		var formData = new FormData(form);
		formData.set('action', action);

		if (submitter && submitter.name) {
			formData.set(submitter.name, submitter.value || '1');
		}

		return formData;
	}

	function parseAjaxResponse(response) {
		return response.json().catch(function () {
			return {
				success: false,
				data: {
					message: 'Kunne ikke lese responsen fra WordPress.'
				}
			};
		});
	}

	function postFormData(formData) {
		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		}).then(parseAjaxResponse);
	}

	function updateSavedSummary(data) {
		var apiKeySummary = document.querySelector('[data-lp-cargonizer-api-key-summary]');
		var senderIdSummary = document.querySelector('[data-lp-cargonizer-sender-id-summary]');
		var directPrintPrinter = document.getElementById('lp_cargonizer_direct_print_printer_id');

		if (apiKeySummary && data && data.apiKeyMasked) {
			apiKeySummary.textContent = data.apiKeyMasked;
		}
		if (senderIdSummary && data && data.senderId) {
			senderIdSummary.textContent = data.senderId;
		}
		if (directPrintPrinter && data && Object.prototype.hasOwnProperty.call(data, 'defaultPrinterId')) {
			directPrintPrinter.value = data.defaultPrinterId || '';
		}
	}

	function bindSettingsForm() {
		var form = document.querySelector('.lp-cargonizer-settings-form');
		if (!form || !config.actions || !config.actions.saveSettings) {
			return;
		}

		form.addEventListener('click', function (event) {
			if (event.target && event.target.matches('button[type="submit"], input[type="submit"]')) {
				lastClickedSubmitter = event.target;
			}
		});

		form.addEventListener('submit', function (event) {
			var submitter = getSubmitter(event, form);
			if (!submitter || submitter.name !== 'lp_cargonizer_save_settings') {
				return;
			}

			event.preventDefault();
			var formData = buildFormData(form, submitter, config.actions.saveSettings);
			setButtonBusy(submitter, 'Lagrer...');
			showNotice('success', 'Lagrer innstillinger...');

			postFormData(formData).then(function (payload) {
				var data = payload && payload.data ? payload.data : {};
				var message = data.message || (payload && payload.success ? 'Innstillinger lagret.' : 'Kunne ikke lagre innstillinger.');

				if (!payload || !payload.success) {
					showNotice('error', message);
					return;
				}

				updateSavedSummary(data);
				showNotice('success', message);
			}).catch(function () {
				showNotice('error', 'Kunne ikke lagre innstillinger uten sideinnlasting.');
			}).finally(function () {
				clearButtonBusy(submitter);
			});
		});
	}

	function bindDirectPrintForm() {
		var form = document.querySelector('.lp-cargonizer-direct-print-form');
		if (!form || !config.actions || !config.actions.directPrintUpload) {
			return;
		}

		form.addEventListener('click', function (event) {
			if (event.target && event.target.matches('button[type="submit"], input[type="submit"]')) {
				lastClickedSubmitter = event.target;
			}
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var submitter = getSubmitter(event, form) || form.querySelector('[name="lp_cargonizer_direct_print_upload"]');
			var formData = buildFormData(form, submitter, config.actions.directPrintUpload);
			var fileInput = form.querySelector('input[type="file"]');

			setButtonBusy(submitter, 'Sender...');
			showNotice('success', 'Sender fil til DirectPrint...');

			postFormData(formData).then(function (payload) {
				var data = payload && payload.data ? payload.data : {};
				var message = data.message || (payload && payload.success ? 'DirectPrint request fullført.' : 'DirectPrint feilet.');

				if (!payload || !payload.success) {
					showNotice('error', message);
					return;
				}

				if (fileInput) {
					fileInput.value = '';
				}
				showNotice('success', message);
			}).catch(function () {
				showNotice('error', 'Kunne ikke sende DirectPrint-jobben uten sideinnlasting.');
			}).finally(function () {
				clearButtonBusy(submitter);
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		bindSettingsForm();
		bindDirectPrintForm();
	});
})();
