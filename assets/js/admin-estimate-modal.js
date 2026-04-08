(function(){
	var config = window.lpCargonizerEstimateModalConfig || {};
			function getOrderIdFromCurrentUrl() {
				try {
					var url = new URL(window.location.href);
					var orderId = url.searchParams.get('post') || url.searchParams.get('id') || '';
					return orderId ? String(orderId) : '';
				} catch (e) {
					return '';
				}
			}

			var modal = document.getElementById('lp-cargonizer-estimate-modal');
			if (!modal) { return; }
			var loading = document.getElementById('lp-cargonizer-estimate-loading');
			var errorBox = document.getElementById('lp-cargonizer-estimate-error');
			var content = document.getElementById('lp-cargonizer-estimate-content');
			var overview = document.getElementById('lp-cargonizer-estimate-overview');
			var recipient = document.getElementById('lp-cargonizer-estimate-recipient');
			var lines = document.getElementById('lp-cargonizer-estimate-lines');
			var colliList = document.getElementById('lp-cargonizer-colli-list');
			var colliValidation = document.getElementById('lp-cargonizer-colli-validation');
			var addBtn = document.getElementById('lp-cargonizer-add-colli');
			var closeBottomBtn = document.getElementById('lp-cargonizer-close-bottom');
			var shippingOptionsList = document.getElementById('lp-cargonizer-shipping-options-list');
			var selectAllShippingBtn = document.getElementById('lp-cargonizer-select-all-shipping');
			var resultsContent = document.getElementById('lp-cargonizer-results-content');
			var runEstimateBtn = document.getElementById('lp-cargonizer-run-estimate');
			var modalTitle = document.getElementById('lp-cargonizer-modal-title');
			var estimatePriceResults = document.getElementById('lp-cargonizer-estimate-price-results');
			var bookingPrinterSection = document.getElementById('lp-cargonizer-booking-printer-section');
			var bookingPrinterChoice = document.getElementById('lp-cargonizer-booking-printer-choice');
			var bookingPrinterHelp = document.getElementById('lp-cargonizer-booking-printer-help');
			var bookingResultsSection = document.getElementById('lp-cargonizer-booking-results');
			var bookingResultsContent = document.getElementById('lp-cargonizer-booking-results-content');
			var runBookingBtn = document.getElementById('lp-cargonizer-run-booking');
			var currentOrderId = null;
			var currentRecipient = {};
			var latestEstimateResults = [];
			var currentMode = 'estimate';
			var currentBookingState = null;

			function esc(s){
				s = (s === null || s === undefined) ? '' : String(s);
				return s.replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; });
			}

			function toNum(v){
				var n = parseFloat(v);
				return isNaN(n) ? 0 : n;
			}

			function validateNumberField(input){
				var raw = String(input.value || '').trim();
				var hasValue = raw !== '';
				var value = parseFloat(raw);
				var isValid = !hasValue || (!isNaN(value) && value >= 0);
				input.style.borderColor = isValid ? '' : '#b32d2e';
				input.style.backgroundColor = isValid ? '' : '#fff6f6';
				if (!isValid) {
					input.setAttribute('aria-invalid', 'true');
				} else {
					input.removeAttribute('aria-invalid');
				}
				return isValid;
			}

			function validateColliRow(row){
				var fields = row.querySelectorAll('[data-colli-field="weight"],[data-colli-field="length"],[data-colli-field="width"],[data-colli-field="height"]');
				var valid = true;
				fields.forEach(function(field){
					if (!validateNumberField(field)) {
						valid = false;
					}
				});
				return valid;
			}

			function volumeHtml(row){
				var l = toNum(row.querySelector('[data-colli-field="length"]').value);
				var w = toNum(row.querySelector('[data-colli-field="width"]').value);
				var h = toNum(row.querySelector('[data-colli-field="height"]').value);
				return ((l * w * h) / 1000).toFixed(3);
			}

			function bindVolume(row){
				var out = row.querySelector('.lp-volume');
				var fields = row.querySelectorAll('[data-colli-field="length"],[data-colli-field="width"],[data-colli-field="height"]');
				fields.forEach(function(el){
					el.addEventListener('input', function(){ out.textContent = volumeHtml(row); });
				});
				out.textContent = volumeHtml(row);
			}

			function collectColliData(){
				var rows = colliList.querySelectorAll('.lp-colli-row');
				var allValid = true;
				var packages = Array.prototype.map.call(rows, function(row, index){
					if (!validateColliRow(row)) {
						allValid = false;
					}
					var name = row.querySelector('[data-colli-field="name"]').value.trim();
					var description = row.querySelector('[data-colli-field="description"]').value.trim();
					var weight = toNum(row.querySelector('[data-colli-field="weight"]').value);
					var length = toNum(row.querySelector('[data-colli-field="length"]').value);
					var width = toNum(row.querySelector('[data-colli-field="width"]').value);
					var height = toNum(row.querySelector('[data-colli-field="height"]').value);
					var volume = toNum(((length * width * height) / 1000).toFixed(3));
					return {
						index: index,
						name: name,
						description: description,
						weight: weight,
						length: length,
						width: width,
						height: height,
						volume: volume
					};
				});

				var payload = {
					order_id: currentOrderId,
					packages: packages
				};

				modal.setAttribute('data-colli-payload', JSON.stringify(payload));
				if (!allValid) {
					colliValidation.textContent = 'Én eller flere kolli-rader har ugyldige mål/vekt. Bruk numeriske verdier som er 0 eller høyere.';
					colliValidation.style.display = 'block';
				} else {
					colliValidation.style.display = 'none';
				}

				return {
					isValid: allValid,
					payload: payload
				};
			}

			function createColli(pkg){
				var row = document.createElement('div');
				row.className = 'lp-colli-row';
				row.style.cssText = 'border:1px solid #dcdcde;padding:10px 12px;margin-bottom:8px;background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.03);';
				row.innerHTML = '' +
					'<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">' +
					'<strong class="lp-colli-title">Kolli</strong><button type="button" class="button-link-delete lp-remove-colli">Fjern</button></div>' +
					'<div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr)) repeat(4,minmax(110px,1fr));gap:8px;margin-top:8px;align-items:end;">' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Navn<input type="text" class="regular-text lp-colli-name" data-colli-field="name" style="width:100%;" value="'+esc(pkg.name || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Beskrivelse<input type="text" class="regular-text lp-colli-description" data-colli-field="description" style="width:100%;" value="'+esc(pkg.description || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Vekt (kg)<input type="number" step="0.01" min="0" class="small-text lp-colli-weight" data-colli-field="weight" style="width:100%;" value="'+esc(pkg.weight || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Lengde (cm)<input type="number" step="0.01" min="0" class="small-text lp-colli-length" data-colli-field="length" style="width:100%;" value="'+esc(pkg.length || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Bredde (cm)<input type="number" step="0.01" min="0" class="small-text lp-colli-width" data-colli-field="width" style="width:100%;" value="'+esc(pkg.width || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Høyde (cm)<input type="number" step="0.01" min="0" class="small-text lp-colli-height" data-colli-field="height" style="width:100%;" value="'+esc(pkg.height || '')+'"></label>' +
					'</div>' +
					'<div style="margin-top:8px;"><strong>Volum:</strong> <span class="lp-volume" data-colli-field="volume">0.000</span> dm³</div>';
				row.querySelector('.lp-remove-colli').addEventListener('click', function(){ row.remove(); refreshColliRowTitles(); collectColliData(); });
				row.querySelectorAll('[data-colli-field="weight"],[data-colli-field="length"],[data-colli-field="width"],[data-colli-field="height"]').forEach(function(input){
					input.addEventListener('input', function(){
						validateNumberField(input);
						collectColliData();
					});
					input.addEventListener('blur', function(){ validateNumberField(input); });
				});
				row.querySelectorAll('[data-colli-field="name"],[data-colli-field="description"]').forEach(function(input){
					input.addEventListener('input', collectColliData);
				});
				bindVolume(row);
				validateColliRow(row);
				colliList.appendChild(row);
				refreshColliRowTitles();
				collectColliData();
			}

			function refreshColliRowTitles(){
				var rows = colliList.querySelectorAll('.lp-colli-row');
				rows.forEach(function(row, idx){
					var title = row.querySelector('.lp-colli-title');
					if (title) {
						title.textContent = formatColliTitle(idx + 1);
					}
				});
			}


			function renderShippingOptions(options){
				if (!Array.isArray(options) || !options.length) {
					shippingOptionsList.innerHTML = '<em>Ingen fraktvalg funnet.</em>';
					if (selectAllShippingBtn) {
						selectAllShippingBtn.textContent = 'Velg alle';
					}
					return;
				}

				var html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;">';
				options.forEach(function(option){
					var label = option.label || ((option.agreement_name || '') + ' - ' + (option.product_name || ''));
					var agreementText = option.agreement_description || option.agreement_name || option.agreement_id || '—';
					var productText = option.product_name || option.product_id || '—';
					var isManualNorgespakke = !!option.is_manual_norgespakke || (((option.agreement_id || '') + '|' + (option.product_id || '')) === 'manual|norgespakke');
					var isManual = !!option.is_manual;
					var deliveryToPickupPoint = !!option.delivery_to_pickup_point;
					var deliveryToHome = !!option.delivery_to_home;
					var deliveryTypes = [];
					if (deliveryToPickupPoint) { deliveryTypes.push('HENTESTED'); }
					if (deliveryToHome) { deliveryTypes.push('HJEMLEVERING'); }
					var deliveryTypeText = deliveryTypes.length ? deliveryTypes.join(' / ') : 'Ikke satt';
					var smsServiceId = '';
					var smsServiceName = '';
					if (Array.isArray(option.services)) {
						option.services.forEach(function(service){
							var serviceName = (service && service.service_name) ? String(service.service_name) : '';
							var lowerServiceName = serviceName.toLowerCase();
							if (!smsServiceId && (lowerServiceName.indexOf('sms varsling') !== -1 || lowerServiceName.indexOf('sms varsel') !== -1 || lowerServiceName.indexOf('sms notification') !== -1)) {
								smsServiceId = (service && service.service_id) ? String(service.service_id) : '';
								smsServiceName = serviceName;
							}
						});
					}
					html += '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid #dcdcde;background:#fff;line-height:1.35;">' +
						'<input type="checkbox" class="lp-shipping-option" data-method-key="'+esc(option.key || '')+'" data-agreement-id="'+esc(option.agreement_id || '')+'" data-agreement-name="'+esc(option.agreement_name || '')+'" data-agreement-description="'+esc(option.agreement_description || '')+'" data-agreement-number="'+esc(option.agreement_number || '')+'" data-carrier-id="'+esc(option.carrier_id || '')+'" data-carrier-name="'+esc(option.carrier_name || '')+'" data-product-id="'+esc(option.product_id || '')+'" data-product-name="'+esc(option.product_name || '')+'" data-is-manual="'+(isManual ? '1' : '')+'" data-is-manual-norgespakke="'+(isManualNorgespakke ? '1' : '')+'" data-delivery-to-pickup-point="'+(deliveryToPickupPoint ? '1' : '')+'" data-delivery-to-home="'+(deliveryToHome ? '1' : '')+'" data-sms-service-id="'+esc(smsServiceId)+'" data-sms-service-name="'+esc(smsServiceName)+'">' +
						'<span style="display:flex;flex-direction:column;gap:3px;">' +
							'<strong>'+esc(label)+(isManual ? ' <span style="font-weight:400;color:#646970;">(manuell)</span>' : '')+'</strong>' +
							'<span style="color:#646970;">Transportør: '+esc(option.carrier_name || '—')+'</span>' +
							'<span style="color:#646970;">Fraktavtale: '+esc(agreementText)+'</span>' +
							'<span style="color:#646970;">Produkt: '+esc(productText)+'</span>' +
							'<span style="color:#646970;">Levering: '+esc(deliveryTypeText)+'</span>' +
						'</span>' +
					'</label>';
				});
				html += '</div>';
				shippingOptionsList.innerHTML = html;
				if (selectAllShippingBtn) {
					selectAllShippingBtn.textContent = 'Velg alle';
				}
			}

			function toggleSelectAllShippingOptions(){
				var checkboxes = shippingOptionsList.querySelectorAll('.lp-shipping-option');
				if (!checkboxes.length) { return; }
				var allSelected = true;
				checkboxes.forEach(function(input){
					if (!input.checked) {
						allSelected = false;
					}
				});
				var shouldSelect = !allSelected;
				checkboxes.forEach(function(input){
					input.checked = shouldSelect;
				});
				if (selectAllShippingBtn) {
					selectAllShippingBtn.textContent = shouldSelect ? 'Fjern alle' : 'Velg alle';
				}
			}

			function getSelectedMethods(){
				var selected = [];
				shippingOptionsList.querySelectorAll('.lp-shipping-option:checked').forEach(function(input){
					selected.push({
						key: input.getAttribute('data-method-key') || '',
						agreement_id: input.getAttribute('data-agreement-id') || '',
						agreement_name: input.getAttribute('data-agreement-name') || '',
						agreement_description: input.getAttribute('data-agreement-description') || '',
						agreement_number: input.getAttribute('data-agreement-number') || '',
						carrier_id: input.getAttribute('data-carrier-id') || '',
						carrier_name: input.getAttribute('data-carrier-name') || '',
						product_id: input.getAttribute('data-product-id') || '',
						product_name: input.getAttribute('data-product-name') || '',
						is_manual: input.getAttribute('data-is-manual') === '1',
						is_manual_norgespakke: input.getAttribute('data-is-manual-norgespakke') === '1',
						sms_service_id: input.getAttribute('data-sms-service-id') || '',
						sms_service_name: input.getAttribute('data-sms-service-name') || '',
						delivery_to_pickup_point: input.getAttribute('data-delivery-to-pickup-point') === '1',
						delivery_to_home: input.getAttribute('data-delivery-to-home') === '1'
					});
				});
				return selected;
			}


			function methodKeyForRow(row){
				return (row && row.agreement_id ? row.agreement_id : '') + '|' + (row && row.product_id ? row.product_id : '');
			}

			function needsSmsService(row){
				if (!row) { return false; }
				if (row.requires_sms_service) { return true; }
				var errorText = (row.error || '').toLowerCase();
				return errorText.indexOf('sms varsling') !== -1 || errorText.indexOf('sms varsel') !== -1 || errorText.indexOf('sms notification') !== -1 || errorText.indexOf('requires sms') !== -1;
			}

			function needsServicepartner(row){
				if (!row) { return false; }
				if (row.requires_servicepartner) { return true; }
				var errorText = ((row.error || '') + ' ' + (row.parsed_error_message || '') + ' ' + (row.error_code || '')).toLowerCase();
				return errorText.indexOf('servicepartner må angis') !== -1 || errorText.indexOf('servicepartner maa angis') !== -1 || errorText.indexOf('servicepartner must be specified') !== -1 || errorText.indexOf('missing servicepartner') !== -1;
			}

			function shortRaw(text, maxLen){
				var value = String(text || '').trim();
				var max = maxLen || 350;
				if (!value) { return ''; }
				return value.length > max ? (value.slice(0, max) + '...') : value;
			}

			function isManualNorgespakkeRow(row){
				if (!row) { return false; }
				if (row.is_manual_norgespakke) { return true; }
				var key = (row.agreement_id || '') + '|' + (row.product_id || '');
				return key === 'manual|norgespakke';
			}

			function getManualNorgespakkePackages(row){
				if (!isManualNorgespakkeRow(row) || row.selected_price_source !== 'manual_norgespakke') { return []; }
				var debug = row.norgespakke_debug || {};
				if (!Array.isArray(debug.packages)) { return []; }
				return debug.packages;
			}

			function formatColliTitle(indexOrNumber){
				var parsed = parseInt(indexOrNumber, 10);
				var number = (!isNaN(parsed) && parsed > 0) ? parsed : 1;
				return 'Kolli ' + number;
			}

			function buildColliLineHtml(pkg, displayNumber){
				var packageData = pkg || {};
				var colliTitle = formatColliTitle(displayNumber);
				var nameOrDescription = packageData.name || packageData.description || colliTitle;
				var weight = (packageData.weight !== undefined && packageData.weight !== null && packageData.weight !== '') ? packageData.weight : '0';
				var length = (packageData.length !== undefined && packageData.length !== null && packageData.length !== '') ? packageData.length : '0';
				var width = (packageData.width !== undefined && packageData.width !== null && packageData.width !== '') ? packageData.width : '0';
				var height = (packageData.height !== undefined && packageData.height !== null && packageData.height !== '') ? packageData.height : '0';
				return '<li style="margin-bottom:4px;">' +
					esc(colliTitle) + ': ' + esc(nameOrDescription) + ', ' + esc(weight) + ' kg, ' + esc(length) + 'x' + esc(width) + 'x' + esc(height) + ' cm' +
				'</li>';
			}

			function renderOptimizedShipmentBreakdown(row){
				if (!row || row.optimized_partition_used !== true) { return ''; }
				if (!Array.isArray(row.optimized_shipments) || !row.optimized_shipments.length) { return ''; }
				if (!row.optimized_shipment_count || row.optimized_shipment_count <= 1) { return ''; }

				var shipmentSections = row.optimized_shipments.map(function(shipment, shipmentIdx){
					var shipmentData = shipment || {};
					var packagesSummary = Array.isArray(shipmentData.packages_summary) ? shipmentData.packages_summary : [];
					var packageIndexes = Array.isArray(shipmentData.package_indexes) ? shipmentData.package_indexes : [];
					var priceText = shipmentData.final_price_ex_vat !== undefined && shipmentData.final_price_ex_vat !== null && shipmentData.final_price_ex_vat !== ''
						? shipmentData.final_price_ex_vat
						: (shipmentData.rounded_price !== undefined && shipmentData.rounded_price !== null && shipmentData.rounded_price !== '' ? shipmentData.rounded_price : '');
					var shipmentHeader = '<div style="font-weight:600;color:#1d2327;">Delsendelse ' + esc(shipmentIdx + 1) + (priceText !== '' ? ' <span style="font-weight:400;color:#646970;">(' + esc(priceText) + ' kr)</span>' : '') + '</div>';
					var packageLines = '';
					if (packagesSummary.length) {
						packageLines = packagesSummary.map(function(pkg, pkgIdx){
							var globalNumber = null;
							if (packageIndexes.length > pkgIdx) {
								var parsedIndex = parseInt(packageIndexes[pkgIdx], 10);
								if (!isNaN(parsedIndex) && parsedIndex >= 0) {
									globalNumber = parsedIndex + 1;
								}
							}
							var displayNumber = globalNumber !== null ? globalNumber : (pkgIdx + 1);
							return buildColliLineHtml(pkg, displayNumber);
						}).join('');
						packageLines = '<ul style="margin:4px 0 0 18px;">' + packageLines + '</ul>';
					} else if (packageIndexes.length) {
						var indexesText = packageIndexes.map(function(packageIndex){
							var parsedIndex = parseInt(packageIndex, 10);
							if (isNaN(parsedIndex) || parsedIndex < 0) {
								return '';
							}
							return formatColliTitle(parsedIndex + 1);
						}).filter(function(item){ return !!item; }).join(', ');
						packageLines = indexesText
							? '<div style="margin-top:4px;color:#50575e;">' + esc(indexesText) + '</div>'
							: '<div style="margin-top:4px;color:#50575e;">Ingen kollidetaljer tilgjengelig.</div>';
					} else {
						packageLines = '<div style="margin-top:4px;color:#50575e;">Ingen kollidetaljer tilgjengelig.</div>';
					}

					return '<div style="margin-top:6px;padding-top:6px;border-top:1px dashed #dcdcde;">' + shipmentHeader + packageLines + '</div>';
				}).join('');

				if (!shipmentSections) { return ''; }

				return '<details style="margin-top:6px;">' +
					'<summary style="cursor:pointer;">Vis kollioppdeling</summary>' +
					'<div style="margin-top:6px;padding:8px 10px;border:1px solid #dcdcde;background:#f6f7f7;border-radius:4px;font-size:12px;line-height:1.5;">' + shipmentSections + '</div>' +
				'</details>';
			}

			function renderManualNorgespakkeSummary(row, options){
				var packages = getManualNorgespakkePackages(row);
				if (!packages.length) { return ''; }
				var opts = options || {};
				var compact = !!opts.compact;
				var title = opts.title || 'Kollioversikt';
				var wrapperStyle = compact
					? 'margin-top:6px;padding:8px 10px;border:1px solid #dcdcde;background:#f6f7f7;border-radius:4px;font-size:12px;line-height:1.5;'
					: 'margin-top:8px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;border-radius:4px;line-height:1.5;';
				var lines = packages.map(function(pkg, idx){
					var number = idx + 1;
					var weight = (pkg.weight !== undefined && pkg.weight !== null && pkg.weight !== '') ? pkg.weight : '0';
					var length = (pkg.length !== undefined && pkg.length !== null && pkg.length !== '') ? pkg.length : '0';
					var width = (pkg.width !== undefined && pkg.width !== null && pkg.width !== '') ? pkg.width : '0';
					var height = (pkg.height !== undefined && pkg.height !== null && pkg.height !== '') ? pkg.height : '0';
					var hasSize = parseFloat(length) > 0 || parseFloat(width) > 0 || parseFloat(height) > 0;
					var sizeText = hasSize ? (' – ' + length + 'x' + width + 'x' + height + ' cm') : '';
					return '<li style="margin-bottom:4px;">' + esc(formatColliTitle(number)) + ' – ' + esc(weight) + ' kg' + esc(sizeText) + '</li>';
				}).join('');
				return '<div style="' + wrapperStyle + '">' +
					'<div style="font-weight:600;margin-bottom:4px;">' + esc(title) + ': ' + esc(packages.length) + ' kolli</div>' +
					'<ul style="margin:0 0 0 18px;">' + lines + '</ul>' +
				'</div>';
			}

			function renderEstimateDebug(row, options){
				if (!row) { return ''; }
				var opts = options || {};
				var isManualNorgespakke = isManualNorgespakkeRow(row) && row.selected_price_source === 'manual_norgespakke';
				if (isManualNorgespakke) {
					var debug = row.norgespakke_debug || {};
					var packageRows = getManualNorgespakkePackages(row);
					var packageHtml = packageRows.map(function(pkg, idx){
						var packageNumber = parseInt(pkg.package_number, 10);
						if (isNaN(packageNumber) || packageNumber < 1) {
							packageNumber = idx + 1;
						}
						return '<li style="margin-bottom:6px;">' +
							esc(formatColliTitle(packageNumber)) + ': ' + esc(pkg.name || pkg.description || '—') +
							' | vekt ' + esc(pkg.weight || '0') + ' kg' +
							' | LxBxH ' + esc(pkg.length || '0') + 'x' + esc(pkg.width || '0') + 'x' + esc(pkg.height || '0') + ' cm' +
							' | grunnpris ' + esc(pkg.base_price || '0') + ' kr' +
							' | håndtering ' + (pkg.handling_triggered ? 'ja' : 'nei') +
							' (' + esc(pkg.handling_fee || '0') + ' kr)' +
							' | kolli-sum ' + esc(pkg.package_total || '0') + ' kr' +
							' | årsak: ' + esc(pkg.handling_reason || '—') +
						'</li>';
					}).join('');
					var lines = [
						'Metode: manuell Norgespakke',
						'Ingen Logistra/Cargonizer-kall brukt: ja',
						'Antall kolli: ' + (debug.number_of_packages !== undefined ? debug.number_of_packages : '—'),
						'Total grunnfrakt: ' + (debug.total_base_freight || '—'),
						'Total rabatt: ' + (debug.total_discount || '—'),
						'Total håndtering: ' + (debug.total_handling || '—'),
						'Drivstoff %: ' + (debug.fuel_percent || row.fuel_surcharge || '—'),
						'Drivstoff (kr): ' + (debug.fuel_amount || row.recalculated_fuel_surcharge || '—'),
						'Bomtillegg (kr): ' + (debug.toll_surcharge || row.toll_surcharge || '—'),
						'MVA %: ' + (debug.vat_percent || row.vat_percent || '—'),
						'Avrunding: ' + (debug.rounding_mode || row.rounding_mode || '—'),
						'Sluttpris eks mva: ' + (debug.final_price_ex_vat || row.final_price_ex_vat || '—')
					];
					var html = renderManualNorgespakkeSummary(row, { title: 'Kollioversikt for lager' }) +
						'<div style="margin-top:4px;color:#646970;">' + esc(lines.join(' | ')) + '</div>' +
						(packageHtml ? '<ul style="margin:8px 0 0 18px;">' + packageHtml + '</ul>' : '');
					if (opts.asDetails === false) {
						return html;
					}
					return '<details style="margin-top:6px;"><summary>'+esc(opts.summaryTitle || 'Debug')+'</summary>' + html + '</details>';
				}
				var asDetails = opts.asDetails !== false;
				var summaryTitle = opts.summaryTitle || 'Debug';
				var summary = row.request_summary || {};
				var packages = Array.isArray(summary.packages) ? summary.packages : [];
				var packageText = packages.map(function(pkg, idx){
					return (idx + 1) + ': ' +
						'w=' + (pkg.weight || 0) + 'kg, ' +
						'LxBxH=' + (pkg.length || 0) + 'x' + (pkg.width || 0) + 'x' + (pkg.height || 0) + 'cm';
				}).join(' | ');
				var source = row.selected_price_source || 'ingen';
				var selectedValue = row.selected_price_value || '—';
				var alternatives = [];
				if (row.net_amount) { alternatives.push('net_amount=' + row.net_amount); }
				if (row.gross_amount) { alternatives.push('gross_amount=' + row.gross_amount); }
				if (row.estimated_cost) { alternatives.push('estimated_cost=' + row.estimated_cost); }
				if (row.fallback_price) { alternatives.push('fallback_price=' + row.fallback_price); }
				var formulaText = [
					'base_freight = (list_price - total_handling_fee - toll_surcharge) / (1 + fuel_percent / 100)',
					'discounted_base = base_freight * (1 - discount_percent / 100)',
					'recalculated_fuel_surcharge = discounted_base * fuel_percent / 100',
					'subtotal_ex_vat = discounted_base + recalculated_fuel_surcharge + toll_surcharge + total_handling_fee'
				].join(' | ');
				var fields = [
					'HTTP: ' + (row.http_status || '—'),
					'Error code: ' + (row.error_code || '—'),
					'Error type: ' + (row.error_type || '—'),
					'Melding: ' + (row.parsed_error_message || row.error || '—'),
					'Detaljer: ' + (row.error_details || '—'),
					'Prisfelt brukt som listepris/grunnlag: ' + source + ' = ' + selectedValue,
					'Alternative prisfelt i respons: ' + (alternatives.length ? alternatives.join(', ') : 'ingen'),
					'price_source_config: ' + (row.price_source_config || '—'),
					'configured_price_source_key: ' + (row.configured_price_source_key || '—'),
					'selected_price_source: ' + (row.selected_price_source || '—'),
					'selected_price_value: ' + (row.selected_price_value || '—'),
					'actual_fallback_priority: ' + (Array.isArray(row.actual_fallback_priority) ? row.actual_fallback_priority.join(' -> ') : '—'),
					'fallback_step_used: ' + (row.fallback_step_used || '—'),
					'price_source_fallback_used: ' + (row.price_source_fallback_used ? 'ja' : 'nei'),
					'price_source_fallback_reason: ' + (row.price_source_fallback_reason || '—'),
					'rounding_mode: ' + (row.rounding_mode || '—'),
					'original_price: ' + (row.original_price || '—'),
					'original_list_price: ' + (row.original_list_price || '—'),
					'utledet_grunnfrakt: ' + (row.extracted_base_freight || '—'),
					'beregningsgrunnlag_etter_utleding: ' + (row.base_price || '—'),
					'discount_percent: ' + (row.discount_percent || '—'),
					'discounted_base: ' + (row.discounted_base || '—'),
					'fuel_percent: ' + (row.fuel_surcharge || '—') + '%',
					'recalculated_fuel_surcharge: ' + (row.recalculated_fuel_surcharge || '—'),
					'toll_surcharge: ' + (row.toll_surcharge || '—'),
					'handling_fee: ' + (row.handling_fee || '—'),
					'manual_handling_fee: ' + (row.manual_handling_fee || '—'),
					'bring_manual_handling_fee: ' + (row.bring_manual_handling_fee || '—'),
					'total_handling_fee: ' + (row.total_handling_fee || '—'),
					'bring_manual_handling_triggered: ' + (row.bring_manual_handling_triggered ? 'ja' : 'nei'),
					'bring_manual_handling_package_count: ' + (row.bring_manual_handling_package_count !== undefined ? row.bring_manual_handling_package_count : '—'),
					'subtotal_ex_vat: ' + (row.subtotal_ex_vat || '—'),
					'vat_percent: ' + (row.vat_percent || '—'),
					'price_incl_vat: ' + (row.price_incl_vat || '—'),
					'leveringstype: ' + formatDeliveryTypeText(row),
					'rounded_price: ' + (row.rounded_price || '—'),
					'final_price_ex_vat: ' + (row.final_price_ex_vat || '—'),
					'estimated_cost: ' + (row.estimated_cost || '—'),
					'gross_amount: ' + (row.gross_amount || '—'),
					'net_amount: ' + (row.net_amount || '—'),
					'fallback_price: ' + (row.fallback_price || '—'),
					'Agreement: ' + (summary.agreement_id || row.agreement_id || '—'),
					'Produkt: ' + (summary.product_id || row.product_id || '—'),
					'Kolli: ' + (summary.number_of_packages !== undefined ? summary.number_of_packages : '—'),
					'Servicepartner: ' + (summary.selected_servicepartner || row.selected_servicepartner || '—'),
					'SMS service valgt: ' + ((summary.use_sms_service || row.use_sms_service) ? 'Ja' : 'Nei'),
					'Pakker: ' + (packageText || '—'),
					'Formel: ' + formulaText
				];
				var optimization = row.optimization_debug || null;
				var optimizationHtml = '';
				var optimizationStateText = '';
				if (row.optimization_state === 'pending') {
					optimizationStateText = 'Baseline vist. Optimaliserer kombinasjoner...';
				} else if (row.optimization_state === 'done') {
					optimizationStateText = (optimization && optimization.optimization_changed_result) ? 'Optimalisering fant bedre løsning' : 'Optimalisering fant ikke bedre løsning';
				} else if (row.optimization_state === 'failed') {
					optimizationStateText = 'Optimalisering feilet, baseline beholdt';
				}
				if (optimization && (optimization.enabled || optimization.reason || Array.isArray(optimization.variants))) {
					var variants = Array.isArray(optimization.variants) ? optimization.variants : [];
					var variantRows = variants.map(function(variant){
						var groups = Array.isArray(variant.groups) ? variant.groups : [];
						var groupText = groups.map(function(group){
							return '[' + (Array.isArray(group.package_indexes) ? group.package_indexes.join(',') : '—') + '] status=' + (group.status || '—') + ', http=' + (group.http_status || '—') + ', source=' + (group.selected_price_source || '—') + ', val=' + (group.selected_price_value || '—') + ', avrundet=' + (group.rounded_price || '—') + ', eks mva=' + (group.final_price_ex_vat || '—') + (group.error_code ? ', code=' + group.error_code : '') + (group.error ? ', feil=' + group.error : '');
						}).join(' | ');
						var baselineLabel = variant.is_baseline ? 'baseline/samlet' : ('partition #' + variant.partition_index);
						return '<li style="margin-bottom:6px;">Variant ' + esc(baselineLabel) + (variant.is_winner ? ' (vinner)' : '') + ': delsendelser=' + esc(variant.shipment_count || 0) + ', status=' + esc(variant.status || '—') + ', total avrundet=' + esc(variant.total_rounded_price || '—') + ', total eks mva=' + esc(variant.total_final_price_ex_vat || '—') + (variant.error ? ', feil=' + esc(variant.error) : '') + (groupText ? '<div style="margin-top:4px;color:#646970;">' + esc(groupText) + '</div>' : '') + '</li>';
					}).join('');
					var changedText = optimization.optimization_changed_result ? 'ja' : 'nei';
					optimizationHtml = '<div style="margin-top:8px;padding:8px;border:1px solid #dcdcde;background:#f6f7f7;">'
						+ '<strong>DSV-optimalisering</strong>: '
						+ (optimizationStateText ? '<div style="margin-top:4px;font-weight:600;">' + esc(optimizationStateText) + '</div>' : '')
						+ 'enabled=' + esc(optimization.enabled ? 'ja' : 'nei')
						+ ', baseline_attempted=' + esc(optimization.baseline_estimate_attempted ? 'ja' : 'nei')
						+ ', baseline_status=' + esc(optimization.baseline_estimate_status || '—')
						+ ', reason=' + esc(optimization.reason || '—')
						+ ', partitions_tested=' + esc(optimization.partitions_tested !== undefined ? optimization.partitions_tested : '—')
						+ ', winner=' + esc(optimization.winner_partition_index !== undefined ? optimization.winner_partition_index : '—')
						+ ', winner_final_ex_vat=' + esc(optimization.winner_total_final_price_ex_vat !== undefined ? optimization.winner_total_final_price_ex_vat : '—')
						+ ', winner_rounded=' + esc(optimization.winner_total_rounded_price !== undefined ? optimization.winner_total_rounded_price : '—')
						+ ', winner shipments=' + esc(optimization.winner_shipment_count !== undefined ? optimization.winner_shipment_count : '—')
						+ ', changed_result=' + esc(changedText)
						+ '<div style="margin-top:4px;font-weight:600;">Samlet DSV-estimat ble forsøkt først. Optimalisering endret resultat: ' + esc(changedText) + '.</div>'
						+ (variantRows ? '<ul style="margin:8px 0 0 18px;">' + variantRows + '</ul>' : '')
						+ '</div>';
				}
				var rawXml = shortRaw(row.raw_response || '', 1200);
				if (!rawXml && !alternatives.length && !row.selected_price_source && !row.parsed_error_message && !row.error && !optimizationHtml) {
					return '';
				}
				var debugContent = '<div style="margin-top:4px;color:#646970;">'+esc(fields.join(' | '))+'</div>' + optimizationHtml +
					(rawXml ? '<pre style="white-space:pre-wrap;max-height:160px;overflow:auto;background:#f6f7f7;padding:8px;border:1px solid #dcdcde;margin-top:6px;">'+esc(rawXml)+'</pre>' : '');
				if (!asDetails) {
					return debugContent;
				}
				return '<details style="margin-top:6px;"><summary>'+esc(summaryTitle)+'</summary>' + debugContent + '</details>';
			}

			function renderServicepartnerControls(row){
				var needsPartner = needsServicepartner(row);
				var needsSms = needsSmsService(row);
				if (!needsPartner && !needsSms) {
					var baseMessage = row.human_error ? (row.error ? row.error + ' — ' + row.human_error : row.human_error) : (row.error || 'OK');
					return '<span style="color:'+(row.error ? '#b32d2e' : '#2271b1')+';">'+esc(baseMessage)+'</span>' + renderEstimateDebug(row);
				}
				var methodKey = methodKeyForRow(row);
				var options = Array.isArray(row.servicepartner_options) ? row.servicepartner_options : [];
				var currentValue = row.selected_servicepartner || '';
				var optionsHtml = '<option value="">Velg servicepartner…</option>';
				if (options.length) {
					options.forEach(function(opt){
						var value = (opt && opt.value) ? String(opt.value) : '';
						var label = (opt && opt.label) ? String(opt.label) : value;
						if (!value) { return; }
						optionsHtml += '<option value="'+esc(value)+'" '+(currentValue === value ? 'selected' : '')+'>'+esc(label)+'</option>';
					});
				}
				var infoParts = [];
				var partnerDebug = row.servicepartner_fetch || {};
				if (needsPartner) {
					var infoText = 'Denne metoden krever servicepartner. Velg servicepartner og prøv igjen.';
					if (!options.length) { infoText += ' Ingen valg lastet enda.'; }
					if (partnerDebug && partnerDebug.error_message) {
						infoText += ' Feil ved henting: ' + partnerDebug.error_message;
					}
					if (partnerDebug && partnerDebug.success && !options.length) {
						infoText += ' Ingen servicepartnere returnert fra API.';
					}
					infoParts.push('<div style="color:#b32d2e;">'+esc(infoText)+'</div>');
					if (partnerDebug && (partnerDebug.http_status || partnerDebug.error_message || partnerDebug.raw_response_body || partnerDebug.request_url)) {
						var spDebug = 'HTTP: ' + (partnerDebug.http_status || '—') +
							' | Melding: ' + (partnerDebug.error_message || '—') +
							' | URL: ' + (partnerDebug.request_url || '—');
						if (partnerDebug.raw_response_body) {
							spDebug += ' | Kort respons: ' + shortRaw(partnerDebug.raw_response_body, 250);
						}
						infoParts.push('<div style="color:#646970;">'+esc(spDebug)+'</div>');
					}
					if (partnerDebug && partnerDebug.custom_params_debug) {
						var customDebug = [];
						Object.keys(partnerDebug.custom_params_debug).forEach(function(key){
							var row = partnerDebug.custom_params_debug[key] || {};
							customDebug.push(key + '=' + (row.value || '—') + ' (' + (row.source || 'unknown') + ')');
						});
						if (customDebug.length) {
							infoParts.push('<div style="color:#646970;">Custom params brukt: '+esc(customDebug.join(' | '))+'</div>');
						}
					}
				}
				if (needsSms) {
					var smsMissing = row.sms_service_missing;
					var smsInfo = smsMissing ? (row.sms_service_error || 'SMS Varsling ble krevd, men tjenesten ble ikke funnet i transport_agreements for dette produktet.') : 'Denne metoden krever SMS Varsling. Kryss av og prøv igjen.';
					infoParts.push('<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" class="lp-sms-service-toggle" data-method-key="'+esc(methodKey)+'" '+((row.use_sms_service && !smsMissing) ? 'checked' : '')+' '+(smsMissing ? 'disabled' : '')+'>Bruk SMS Varsling'+(row.sms_service_name ? ' ('+esc(row.sms_service_name)+')' : '')+'</label>');
					infoParts.push('<div style="color:'+(smsMissing ? '#b32d2e' : '#646970')+';">'+esc(smsInfo)+'</div>');
				}
				return '' +
					'<div style="display:flex;flex-direction:column;gap:6px;">' +
						infoParts.join('') +
						(needsPartner ? '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;"><select class="lp-servicepartner-select" data-method-key="'+esc(methodKey)+'" style="min-width:220px;">'+optionsHtml+'</select><button type="button" class="button button-small lp-servicepartner-refresh" data-method-key="'+esc(methodKey)+'">Hent servicepartnere</button></div>' : '') +
						'<button type="button" class="button button-primary button-small lp-method-retry" data-method-key="'+esc(methodKey)+'">Prøv igjen</button>' +
						(row.error ? '<div style="color:#646970;">Siste feil: '+esc(row.error)+'</div>' : '') +
						renderEstimateDebug(row) +
					'</div>';
			}

			function mergeResultByMethod(updatedRow){
				var key = methodKeyForRow(updatedRow);
				if (!key) { return; }
				var replaced = false;
				latestEstimateResults = latestEstimateResults.map(function(row){
					if (methodKeyForRow(row) === key) {
						replaced = true;
						return updatedRow;
					}
					return row;
				});
				if (!replaced) {
					latestEstimateResults.push(updatedRow);
				}
			}

			function getMethodDataByKey(methodKey){
				var parts = String(methodKey || '').split('|');
				if (parts.length < 2) { return null; }
				var selected = getSelectedMethods();
				for (var i = 0; i < selected.length; i++) {
					if ((selected[i].agreement_id || '') === parts[0] && (selected[i].product_id || '') === parts[1]) {
						return selected[i];
					}
				}
				for (var j = 0; j < latestEstimateResults.length; j++) {
					if (methodKeyForRow(latestEstimateResults[j]) === methodKey) {
						return {
							agreement_id: latestEstimateResults[j].agreement_id || '',
							agreement_name: latestEstimateResults[j].agreement_name || '',
							agreement_description: latestEstimateResults[j].agreement_description || '',
							agreement_number: latestEstimateResults[j].agreement_number || '',
								carrier_id: latestEstimateResults[j].carrier_id || '',
								carrier_name: latestEstimateResults[j].carrier_name || '',
								product_id: latestEstimateResults[j].product_id || '',
								product_name: latestEstimateResults[j].product_name || '',
								sms_service_id: latestEstimateResults[j].sms_service_id || '',
								sms_service_name: latestEstimateResults[j].sms_service_name || ''
							};
					}
				}
				return null;
			}

			function fetchServicepartnersForMethod(methodKey){
				var methodData = getMethodDataByKey(methodKey);
				if (!methodData) { return Promise.resolve([]); }
				var form = new FormData();
				form.append('action', 'lp_cargonizer_get_servicepartner_options');
				form.append('nonce', (config.nonces && config.nonces.servicepartners ? config.nonces.servicepartners : ''));
				form.append('order_id', currentOrderId || '');
				form.append('agreement_id', methodData.agreement_id || '');
				form.append('product_id', methodData.product_id || '');
				form.append('carrier_id', methodData.carrier_id || '');
				form.append('carrier_name', methodData.carrier_name || '');
				form.append('product_name', methodData.product_name || '');
				form.append('recipient_country', (currentRecipient && currentRecipient.country) ? currentRecipient.country : '');
				form.append('recipient_postcode', (currentRecipient && currentRecipient.postcode) ? currentRecipient.postcode : '');
				return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						var debug = (res && res.data && res.data.debug) ? res.data.debug : {};
						var options = (res && res.success && res.data && Array.isArray(res.data.options)) ? res.data.options : [];
						latestEstimateResults = latestEstimateResults.map(function(row){
							if (methodKeyForRow(row) === methodKey) {
								row.servicepartner_options = options;
								row.servicepartner_fetch = debug;
								if (!res.success) {
									row.error = (res.data && res.data.message) ? res.data.message : (row.error || 'Henting av servicepartnere feilet.');
								}
							}
							return row;
						});
						renderEstimateResults(latestEstimateResults);
						if (currentMode === 'booking' && bookingResultsContent) {
							var select = bookingResultsContent.querySelector('.lp-servicepartner-select[data-method-key="'+methodKey+'"]');
							if (select) {
								var optionsHtml = '<option value="">Velg servicepartner…</option>';
								options.forEach(function(opt){
									var value = (opt && opt.value) ? String(opt.value) : '';
									var label = (opt && opt.label) ? String(opt.label) : value;
									if (!value) { return; }
									optionsHtml += '<option value="'+esc(value)+'">'+esc(label)+'</option>';
								});
								select.innerHTML = optionsHtml;
							}
						}
						return options;
					})
					.catch(function(){
						latestEstimateResults = latestEstimateResults.map(function(row){
							if (methodKeyForRow(row) === methodKey) {
								row.servicepartner_fetch = { success:false, http_status:0, error_message:'Teknisk feil ved henting av servicepartnere.', raw_response_body:'', request_url:'' };
								row.error = 'Teknisk feil ved henting av servicepartnere.';
							}
							return row;
						});
						renderEstimateResults(latestEstimateResults);
						return [];
					});
			}

			function runEstimateForSingleMethod(methodKey, selectedServicepartner, useSmsService){
				var colli = collectColliData();
				if (!colli.isValid || !colli.payload.packages || !colli.payload.packages.length) { return; }
				var methodData = getMethodDataByKey(methodKey);
				if (!methodData) { return; }
				var isDsvMethod = ((methodData.carrier_id || '') + ' ' + (methodData.carrier_name || '')).toLowerCase().indexOf('dsv') !== -1;
				var shouldRunProgressiveDsv = isDsvMethod && colli.payload.packages.length > 1;
				var form = new FormData();
				form.append('action', shouldRunProgressiveDsv ? 'lp_cargonizer_run_bulk_estimate_baseline' : 'lp_cargonizer_run_bulk_estimate');
				form.append('nonce', shouldRunProgressiveDsv
					? (config.nonces && config.nonces.estimateBaseline ? config.nonces.estimateBaseline : '')
					: (config.nonces && config.nonces.estimate ? config.nonces.estimate : ''));
				form.append('order_id', currentOrderId);
				colli.payload.packages.forEach(function(pkg, idx){
					Object.keys(pkg).forEach(function(key){ form.append('packages['+idx+']['+key+']', pkg[key]); });
				});
				Object.keys(methodData).forEach(function(key){ form.append('methods[0]['+key+']', methodData[key]); });
				if (selectedServicepartner) {
					form.append('methods[0][servicepartner]', selectedServicepartner);
					methodData.servicepartner = selectedServicepartner;
				}
				if (useSmsService) {
					form.append('methods[0][use_sms_service]', '1');
					methodData.use_sms_service = true;
				} else {
					methodData.use_sms_service = false;
				}
				fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data || !Array.isArray(res.data.results) || !res.data.results.length) {
							return;
						}
						var row = res.data.results[0];
						mergeResultByMethod(row);
						renderEstimateResults(latestEstimateResults);
						if (shouldRunProgressiveDsv && row && row.optimization_state === 'pending') {
							optimizeDsvMethod(methodData, colli.payload.packages);
						}
					});
			}

			function formatDeliveryTypeText(row){
				if (!row) { return 'Ikke satt'; }
				var types = [];
				if (row.delivery_to_pickup_point) { types.push('HENTESTED'); }
				if (row.delivery_to_home) { types.push('HJEMLEVERING'); }
				return types.length ? types.join(' / ') : 'Ikke satt';
			}

			function formatDeliveryFlag(value){
				return value ? 'Ja' : 'Nei';
			}

			function renderEstimateResults(results){
				function parsePriceNumber(value) {
					if (value === null || value === undefined || value === '') {
						return NaN;
					}
					var normalized = String(value).trim().replace(/\s+/g, '').replace(/[^0-9,.-]/g, '');
					if (!normalized) {
						return NaN;
					}
					var hasComma = normalized.indexOf(',') !== -1;
					var hasDot = normalized.indexOf('.') !== -1;
					if (hasComma && hasDot) {
						if (normalized.lastIndexOf(',') > normalized.lastIndexOf('.')) {
							normalized = normalized.replace(/\./g, '').replace(',', '.');
						} else {
							normalized = normalized.replace(/,/g, '');
						}
					} else if (hasComma) {
						normalized = normalized.replace(',', '.');
					}
					var parsed = parseFloat(normalized);
					return isNaN(parsed) ? NaN : parsed;
				}

				if (!Array.isArray(results) || !results.length) {
					latestEstimateResults = [];
					resultsContent.innerHTML = '<em>Ingen resultater å vise.</em>';
					return;
				}
				latestEstimateResults = results.slice();

				var okResults = [];
				var failedResults = [];
				results.forEach(function(row){
					var status = (row && row.status) ? String(row.status).toLowerCase() : '';
					if (status === 'ok') {
						okResults.push(row);
					} else {
						failedResults.push(row);
					}
				});

				okResults.sort(function(a, b){
					var aValue = parsePriceNumber(a && a.rounded_price !== undefined && a.rounded_price !== '' ? a.rounded_price : (a ? a.final_price_ex_vat : ''));
					var bValue = parsePriceNumber(b && b.rounded_price !== undefined && b.rounded_price !== '' ? b.rounded_price : (b ? b.final_price_ex_vat : ''));
					var aMissing = isNaN(aValue);
					var bMissing = isNaN(bValue);
					if (aMissing && bMissing) { return 0; }
					if (aMissing) { return 1; }
					if (bMissing) { return -1; }
					return aValue - bValue;
				});

				function formatDeliveryMode(deliveryRow){
					var hasPickup = !!deliveryRow.delivery_to_pickup_point;
					var hasHome = !!deliveryRow.delivery_to_home;
					if (hasPickup && hasHome) { return 'Hentested + hjemlevering'; }
					if (hasPickup) { return 'Hentested'; }
					if (hasHome) { return 'Hjemlevering'; }
					return '—';
				}

				function renderOkRow(row){
					var toText = function(value){
						return value !== '' && value !== undefined && value !== null ? value : '—';
					};
					var listPriceText = row.original_list_price !== '' && row.original_list_price !== undefined
						? row.original_list_price
						: (row.selected_price_value || '—');
					var discountPercentText = toText(row.discount_percent);
					var fuelPercentText = toText(row.fuel_surcharge);
					var fuelAmountText = toText(row.recalculated_fuel_surcharge);
					var tollSurchargeText = toText(row.toll_surcharge);
					var handlingFeeText = toText(row.total_handling_fee !== '' && row.total_handling_fee !== undefined ? row.total_handling_fee : row.handling_fee);
					var actualPriceText = row.rounded_price !== '' && row.rounded_price !== undefined
						? row.rounded_price
						: (row.final_price_ex_vat !== '' && row.final_price_ex_vat !== undefined ? row.final_price_ex_vat : '—');
					var statusText = row.status || 'unknown';
					var packageSummaryHtml = renderManualNorgespakkeSummary(row, { compact: true, title: 'Kolli' });
					var multiShipmentInfo = (row.optimized_partition_used && (row.optimized_shipment_count || 0) > 1)
						? '<div style="margin-top:6px;color:#8a4b00;font-weight:600;">Optimalisert som ' + esc(row.optimized_shipment_count) + ' separate delsendelser (må bookes separat).</div>'
						: '';
					var optimizationInfo = '';
					if (row.optimization_state === 'pending') {
						optimizationInfo = '<div style="margin-top:6px;color:#125228;font-weight:600;">Baseline vist. Optimaliserer kombinasjoner...</div>';
					} else if (row.optimization_state === 'done') {
						optimizationInfo = '<div style="margin-top:6px;color:#125228;font-weight:600;">' + esc((row.optimization_debug && row.optimization_debug.optimization_changed_result) ? 'Optimalisering fant bedre løsning' : 'Optimalisering fant ikke bedre løsning') + '</div>';
					} else if (row.optimization_state === 'failed') {
						optimizationInfo = '<div style="margin-top:6px;color:#b32d2e;font-weight:600;">Optimalisering feilet, baseline beholdt.</div>';
					}
					var showOptimizedBreakdown = row.optimized_partition_used === true &&
						(row.optimized_shipment_count || 0) > 1 &&
						Array.isArray(row.optimized_shipments) &&
						row.optimized_shipments.length > 0 &&
						row.optimization_state === 'done' &&
						!!(row.optimization_debug && row.optimization_debug.optimization_changed_result);
					var optimizedBreakdownHtml = showOptimizedBreakdown ? renderOptimizedShipmentBreakdown(row) : '';
					var detailsHtml = '<details><summary>Vis beregning</summary>' + renderEstimateDebug(row, { asDetails: false }) + '</details>';
					return '<tr>' +
					'<td>'+esc(row.method_name || row.product_id || 'Ukjent metode') + packageSummaryHtml + multiShipmentInfo + optimizationInfo + optimizedBreakdownHtml + '</td>' +
					'<td>'+esc(formatDeliveryMode(row))+'</td>' +
					'<td>'+esc(listPriceText)+'</td>' +
					'<td>'+esc(discountPercentText)+'</td>' +
						'<td>'+esc(fuelPercentText)+'</td>' +
						'<td>'+esc(fuelAmountText)+'</td>' +
						'<td>'+esc(tollSurchargeText)+'</td>' +
						'<td>'+esc(handlingFeeText)+'</td>' +
						'<td style="font-weight:700;background:#e7f6ec;color:#125228;border-left:3px solid #1d7f45;">'+esc(actualPriceText)+'</td>' +
						'<td>'+esc(statusText)+'</td>' +
						'<td>'+detailsHtml+'</td>' +
					'</tr>';
				}

				function renderFailedRow(row){
					var debugFields = [];
					if (row.error_code) { debugFields.push('Kode: ' + row.error_code); }
					if (row.error_type) { debugFields.push('Type: ' + row.error_type); }
					if (row.error_details) { debugFields.push('Detaljer: ' + row.error_details); }
					if (row.http_status) { debugFields.push('HTTP: ' + row.http_status); }
					if (row.human_error) { debugFields.push('Forklaring: ' + row.human_error); }
					if (row.parsed_error_message && row.parsed_error_message !== row.error) { debugFields.push('Parsed: ' + row.parsed_error_message); }
					var debugText = debugFields.length ? debugFields.join(' | ') : '—';
					return '<tr>' +
					'<td>'+esc(row.method_name || row.product_id || 'Ukjent metode')+'</td>' +
					'<td>'+esc(formatDeliveryMode(row))+'</td>' +
					'<td>'+esc(row.status || 'failed')+'</td>' +
					'<td>'+esc(row.error || row.parsed_error_message || 'Ukjent feil')+'</td>' +
					'<td>'+esc(debugText)+'</td>' +
				'</tr>';
			}

				var okRows = okResults.map(renderOkRow).join('');
				var okTableHtml = '<table class="widefat striped"><thead><tr><th>Fraktmetode</th><th>Leveringsmåte</th><th>Listepris/grunnlag</th><th>Rabatt %</th><th>Drivstoff %</th><th>Drivstoff (kr)</th><th>Bomtillegg (kr)</th><th>Håndteringstillegg (kr)</th><th>Faktisk pris</th><th>Status</th><th>Beregning/debug</th></tr></thead><tbody>' +
					(okRows || '<tr><td colspan="11"><em>Ingen vellykkede metoder.</em></td></tr>') +
					'</tbody></table>';

				var failedSectionHtml = '';
				if (failedResults.length) {
					var failedRows = failedResults.map(renderFailedRow).join('');
					failedSectionHtml = '<details style="margin-top:12px;"><summary>Vis metoder som feilet (' + failedResults.length + ')</summary>' +
						'<div style="margin-top:8px;">' +
						'<table class="widefat striped"><thead><tr><th>Fraktmetode</th><th>Leveringsmåte</th><th>Status</th><th>Feilmelding</th><th>Debug/details</th></tr></thead><tbody>' + failedRows + '</tbody></table>' +
						'</div>' +
					'</details>';
				}

				resultsContent.innerHTML = okTableHtml + failedSectionHtml;
			}

			function setModalMode(mode){
				currentMode = (mode === 'booking') ? 'booking' : 'estimate';
				if (modalTitle) {
					modalTitle.textContent = currentMode === 'booking' ? 'Book shipment' : 'Estimer fraktkostnad';
				}
				if (estimatePriceResults) {
					estimatePriceResults.style.display = currentMode === 'booking' ? 'none' : 'block';
				}
				if (bookingPrinterSection) {
					bookingPrinterSection.style.display = currentMode === 'booking' ? 'block' : 'none';
				}
				if (bookingResultsSection) {
					bookingResultsSection.style.display = currentMode === 'booking' ? 'block' : 'none';
				}
				if (runEstimateBtn) {
					runEstimateBtn.style.display = currentMode === 'booking' ? 'none' : '';
				}
				if (runBookingBtn) {
					runBookingBtn.style.display = currentMode === 'booking' ? '' : 'none';
				}
			}

			function fetchPrinters(){
				if (!bookingPrinterChoice) { return Promise.resolve(null); }
				bookingPrinterChoice.innerHTML = '<option value="">Laster printere…</option>';
				if (bookingPrinterHelp) {
					bookingPrinterHelp.textContent = '';
				}
				var form = new FormData();
				form.append('action', 'lp_cargonizer_get_printers');
				form.append('nonce', (config.nonces && config.nonces.printers ? config.nonces.printers : ''));
				return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data) {
							var message = (res && res.data && res.data.message) ? res.data.message : 'Kunne ikke hente printere.';
							bookingPrinterChoice.innerHTML = '<option value="">Ingen utskrift</option>';
							if (bookingPrinterHelp) {
								bookingPrinterHelp.textContent = message;
							}
							return null;
						}
						populatePrinterChoice(res.data.printers || [], res.data.default_printer_id || '');
						return res.data;
					})
					.catch(function(){
						bookingPrinterChoice.innerHTML = '<option value="">Ingen utskrift</option>';
						if (bookingPrinterHelp) {
							bookingPrinterHelp.textContent = 'Teknisk feil ved henting av printere.';
						}
						return null;
					});
			}

			function populatePrinterChoice(printers, defaultPrinterId){
				if (!bookingPrinterChoice) { return; }
				var printerList = Array.isArray(printers) ? printers : [];
				var defaultId = String(defaultPrinterId || '');
				var defaultPrinter = null;
				printerList.forEach(function(printer){
					var printerId = (printer && printer.id) ? String(printer.id) : '';
					if (!defaultPrinter && defaultId !== '' && printerId === defaultId) {
						defaultPrinter = printer;
					}
				});
				var html = '';
				if (defaultPrinter) {
					var defaultLabel = (defaultPrinter && defaultPrinter.label) ? String(defaultPrinter.label) : defaultId;
					html += '<option value="__default__">Bruk standard printer (' + esc(defaultLabel) + ')</option>';
				}
				html += '<option value="">Ingen utskrift</option>';
				printerList.forEach(function(printer){
					var printerId = (printer && printer.id) ? String(printer.id) : '';
					var printerLabel = (printer && printer.label) ? String(printer.label) : printerId;
					if (!printerId) { return; }
					html += '<option value="' + esc(printerId) + '">' + esc(printerLabel) + '</option>';
				});
				bookingPrinterChoice.innerHTML = html;
				bookingPrinterChoice.value = defaultPrinter ? '__default__' : '';
				if (bookingPrinterHelp) {
					bookingPrinterHelp.textContent = defaultPrinter
						? 'Standardvalg bruker din lagrede standardprinter, eller velg en eksplisitt override.'
						: 'Ingen standardprinter funnet. Velg printer for override, eller behold Ingen utskrift.';
				}
			}

			function getSelectedSingleMethodForBooking(){
				var methods = getSelectedMethods();
				if (methods.length === 0) {
					return { error: 'Velg nøyaktig én fraktmetode før booking (ingen valgt).' };
				}
				if (methods.length > 1) {
					return { error: 'Velg nøyaktig én fraktmetode før booking (flere valgt).' };
				}
				return { method: methods[0] };
			}

			function renderBookingSuccess(booking, method){
				var bookingData = booking || {};
				var methodLabel = '';
				if (method && (method.product_name || method.agreement_name)) {
					methodLabel = [method.agreement_name || '', method.product_name || ''].filter(function(v){ return !!v; }).join(' - ');
				}
				var pieceNumbers = Array.isArray(bookingData.piece_numbers) ? bookingData.piece_numbers.filter(function(v){ return v !== null && v !== undefined && v !== ''; }) : [];
				var printData = bookingData.print || {};
				var printHtml = '<div>Ingen utskrift sendt</div>';
				if (printData.attempted && printData.success) {
					printHtml = '<div style="color:#125228;">Label print queued on ' + esc(printData.printer_label || printData.printer_id || 'valgt printer') + '</div>';
				} else if (printData.attempted && !printData.success) {
					printHtml = '<div style="color:#b32d2e;">Booking fullført, men utskrift feilet: ' + esc(printData.message || 'Ukjent printfeil') + '</div>';
				}
				return '' +
					'<div style="color:#125228;font-weight:600;">Booking fullført.</div>' +
					'<div><strong>Consignment number:</strong> ' + esc(bookingData.consignment_number || '—') + '</div>' +
					'<div><strong>Piece numbers:</strong> ' + esc(pieceNumbers.length ? pieceNumbers.join(', ') : '—') + '</div>' +
					'<div><strong>Tracking URL:</strong> ' + (bookingData.tracking_url ? '<a href="' + esc(bookingData.tracking_url) + '" target="_blank" rel="noopener noreferrer">' + esc(bookingData.tracking_url) + '</a>' : '—') + '</div>' +
					(methodLabel ? '<div><strong>Fraktmetode:</strong> ' + esc(methodLabel) + '</div>' : '') +
					printHtml;
			}

			function renderBookingError(errorData, method){
				var data = errorData || {};
				var methodKey = ((method && method.agreement_id) ? method.agreement_id : '') + '|' + ((method && method.product_id) ? method.product_id : '');
				var htmlParts = ['<div style="color:#b32d2e;font-weight:600;">' + esc(data.message || 'Booking feilet.') + '</div>'];
				if (data.requires_servicepartner) {
					var options = Array.isArray(data.servicepartner_options) ? data.servicepartner_options : [];
					var optionsHtml = '<option value="">Velg servicepartner…</option>';
					options.forEach(function(opt){
						var value = (opt && opt.value) ? String(opt.value) : '';
						var label = (opt && opt.label) ? String(opt.label) : value;
						if (!value) { return; }
						optionsHtml += '<option value="' + esc(value) + '">' + esc(label) + '</option>';
					});
					htmlParts.push('<div style="color:#b32d2e;">Denne metoden krever servicepartner. Velg servicepartner og prøv igjen.</div>');
					htmlParts.push('<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;"><select class="lp-servicepartner-select" data-method-key="' + esc(methodKey) + '" style="min-width:220px;">' + optionsHtml + '</select><button type="button" class="button button-small lp-servicepartner-refresh" data-method-key="' + esc(methodKey) + '">Hent servicepartnere</button></div>');
				}
				if (data.requires_sms_service) {
					var smsLabel = (method && method.sms_service_name) ? ' (' + esc(method.sms_service_name) + ')' : '';
					htmlParts.push('<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" class="lp-sms-service-toggle" data-method-key="' + esc(methodKey) + '">Bruk SMS Varsling' + smsLabel + '</label>');
				}
				if (data.requires_servicepartner || data.requires_sms_service) {
					htmlParts.push('<button type="button" class="button button-primary button-small lp-booking-method-retry" data-method-key="' + esc(methodKey) + '">Prøv booking igjen</button>');
				}
				bookingResultsContent.innerHTML = htmlParts.join('');
			}

			function renderExistingBookingState(bookingState){
				if (!bookingResultsContent) { return; }
				if (!bookingState || !bookingState.booked) {
					bookingResultsContent.innerHTML = 'Ingen booking kjørt enda.';
					return;
				}
				bookingResultsContent.innerHTML = '<div style="color:#8a4b00;font-weight:600;">Ordren er allerede booket.</div>' + renderBookingSuccess(bookingState, null);
				if (runBookingBtn) {
					runBookingBtn.disabled = true;
					runBookingBtn.textContent = 'Allerede booket';
				}
			}


			function fetchShippingOptions(){
				shippingOptionsList.innerHTML = '<em>Laster fraktvalg...</em>';
				var form = new FormData();
				form.append('action', 'lp_cargonizer_get_shipping_options');
				form.append('nonce', (config.nonces && config.nonces.fetchOptions ? config.nonces.fetchOptions : ''));
				return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data) {
							var message = (res && res.data && res.data.message) ? res.data.message : 'Kunne ikke hente fraktvalg.';
							shippingOptionsList.innerHTML = '<span style="color:#b32d2e;">' + esc(message) + '</span>';
							return;
						}
						renderShippingOptions(res.data.options || []);
					})
					.catch(function(){
						shippingOptionsList.innerHTML = '<span style="color:#b32d2e;">Teknisk feil ved henting av fraktvalg.</span>';
					});
			}

			function validateBeforeEstimate(){
				var colli = collectColliData();
				if (!colli.payload.packages || !colli.payload.packages.length) {
					colliValidation.textContent = 'Du må legge til minst ett kolli.';
					colliValidation.style.display = 'block';
					return null;
				}
				if (!colli.isValid) {
					return null;
				}
				var methods = getSelectedMethods();
				if (!methods.length) {
					resultsContent.innerHTML = '<span style="color:#b32d2e;">Velg minst én fraktmetode før estimering.</span>';
					return null;
				}
				return { packages: colli.payload.packages, methods: methods };
			}

			function appendEstimatePayload(form, packages, methods){
				form.append('order_id', currentOrderId);
				packages.forEach(function(pkg, idx){
					Object.keys(pkg).forEach(function(key){
						form.append('packages['+idx+']['+key+']', pkg[key]);
					});
				});
				methods.forEach(function(method, idx){
					Object.keys(method).forEach(function(key){
						form.append('methods['+idx+']['+key+']', method[key]);
					});
				});
			}

			function optimizeDsvMethod(method, packages){
				var form = new FormData();
				form.append('action', 'lp_cargonizer_optimize_dsv_estimates');
				form.append('nonce', (config.nonces && config.nonces.optimizeDsv ? config.nonces.optimizeDsv : ''));
				appendEstimatePayload(form, packages, [method]);
				return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data || !Array.isArray(res.data.results) || !res.data.results.length) {
							throw new Error('optimize_failed');
						}
						var updatedRow = res.data.results[0];
						updatedRow.optimization_state = 'done';
						mergeResultByMethod(updatedRow);
						renderEstimateResults(latestEstimateResults);
					})
					.catch(function(){
						var methodKey = (method.agreement_id || '') + '|' + (method.product_id || '');
						latestEstimateResults = latestEstimateResults.map(function(row){
							if (methodKeyForRow(row) === methodKey) {
								row.optimization_state = 'failed';
								row.optimization_debug = row.optimization_debug || {};
								row.optimization_debug.enabled = false;
								row.optimization_debug.optimization_changed_result = false;
								row.optimization_debug.reason = 'Optimalisering feilet, beholdt baseline-resultat.';
							}
							return row;
						});
						renderEstimateResults(latestEstimateResults);
					});
			}

			function runEstimate(){
				var validData = validateBeforeEstimate();
				if (!validData) { return; }
				runEstimateBtn.disabled = true;
				runEstimateBtn.textContent = 'Estimerer...';
				resultsContent.innerHTML = '<em>Henter estimater...</em>';

				var form = new FormData();
				form.append('action', 'lp_cargonizer_run_bulk_estimate_baseline');
				form.append('nonce', (config.nonces && config.nonces.estimateBaseline ? config.nonces.estimateBaseline : ''));
				appendEstimatePayload(form, validData.packages, validData.methods);

				fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data) {
							resultsContent.innerHTML = '<span style="color:#b32d2e;">Estimering feilet.</span>';
							return;
						}
						latestEstimateResults = (res.data.results || []).slice();
						renderEstimateResults(latestEstimateResults);

						validData.methods.forEach(function(method){
							var methodKey = (method.agreement_id || '') + '|' + (method.product_id || '');
							for (var i = 0; i < latestEstimateResults.length; i++) {
								if (methodKeyForRow(latestEstimateResults[i]) === methodKey && latestEstimateResults[i].optimization_state === 'pending') {
									optimizeDsvMethod(method, validData.packages);
									break;
								}
							}
						});
					})
					.catch(function(){
						resultsContent.innerHTML = '<span style="color:#b32d2e;">Teknisk feil ved estimering.</span>';
					})
					.finally(function(){
						runEstimateBtn.disabled = false;
						runEstimateBtn.textContent = 'Estimer fraktpris';
					});
			}

			function retryBookingForSingleMethod(methodKey, selectedServicepartner, useSmsService){
				var methodData = getMethodDataByKey(methodKey);
				if (!methodData) { return; }
				if (selectedServicepartner) {
					methodData.servicepartner = selectedServicepartner;
				}
				if (useSmsService) {
					methodData.use_sms_service = true;
				}
				runBooking(methodData);
			}

			function runBooking(preselectedMethod){
				if (currentMode !== 'booking') { return; }
				if (currentBookingState && currentBookingState.booked) {
					renderExistingBookingState(currentBookingState);
					return;
				}
				var colli = collectColliData();
				if (!colli.isValid || !colli.payload.packages || !colli.payload.packages.length) {
					bookingResultsContent.innerHTML = '<span style="color:#b32d2e;">Du må legge til minst ett gyldig kolli før booking.</span>';
					return;
				}
				var selectedResult = preselectedMethod ? { method: preselectedMethod } : getSelectedSingleMethodForBooking();
				if (!selectedResult.method) {
					bookingResultsContent.innerHTML = '<span style="color:#b32d2e;">' + esc(selectedResult.error || 'Valideringsfeil ved valg av fraktmetode.') + '</span>';
					return;
				}

				var method = selectedResult.method;
				var methodKey = (method.agreement_id || '') + '|' + (method.product_id || '');
				if (bookingResultsContent) {
					var servicepartnerSelect = bookingResultsContent.querySelector('.lp-servicepartner-select[data-method-key="'+methodKey+'"]');
					if (servicepartnerSelect) {
						method.servicepartner = servicepartnerSelect.value || '';
					}
					var smsServiceToggle = bookingResultsContent.querySelector('.lp-sms-service-toggle[data-method-key="'+methodKey+'"]');
					if (smsServiceToggle) {
						method.use_sms_service = !!smsServiceToggle.checked;
					}
				}
				var printerChoice = bookingPrinterChoice ? (bookingPrinterChoice.value || '') : '';
				runBookingBtn.disabled = true;
				runBookingBtn.textContent = 'Booker...';
				bookingResultsContent.innerHTML = '<em>Booker shipment...</em>';

				var bookingNonce = (config.nonces && config.nonces.book ? config.nonces.book : '');
				if (window.console && typeof window.console.log === 'function') {
					window.console.log({
						source: 'lp-cargonizer-booking',
						bookingNonce: bookingNonce,
						availableNonces: config && config.nonces ? config.nonces : null
					});
				}
				if (!bookingNonce) {
					bookingResultsContent.innerHTML = '<span style="color:#b32d2e;">Booking nonce mangler i frontend-konfigurasjonen.</span>';
					runBookingBtn.disabled = false;
					runBookingBtn.textContent = 'Book shipment';
					return;
				}

				var form = new FormData();
				form.append('action', 'lp_cargonizer_book_shipment');
				form.append('nonce', bookingNonce);
				form.append('order_id', currentOrderId);
				colli.payload.packages.forEach(function(pkg, idx){
					Object.keys(pkg).forEach(function(key){
						form.append('packages['+idx+']['+key+']', pkg[key]);
					});
				});
				Object.keys(method).forEach(function(key){
					form.append('methods[0]['+key+']', method[key]);
				});
				form.append('printer_choice', printerChoice);

				fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (res && res.success && res.data && res.data.booking) {
							currentBookingState = res.data.booking;
							bookingResultsContent.innerHTML = renderBookingSuccess(res.data.booking, method);
							runBookingBtn.disabled = true;
							runBookingBtn.textContent = 'Booket';
							return;
						}
						var errorData = (res && res.data) ? res.data : { message: 'Booking feilet.' };
						renderBookingError(errorData, method);
						runBookingBtn.disabled = false;
						runBookingBtn.textContent = 'Book shipment';
					})
					.catch(function(){
						bookingResultsContent.innerHTML = '<span style="color:#b32d2e;">Teknisk feil ved booking.</span>';
						runBookingBtn.disabled = false;
						runBookingBtn.textContent = 'Book shipment';
					});
			}

			function openModal(){ modal.style.display = 'block'; }
			function closeModal(){ modal.style.display = 'none'; }

			modal.addEventListener('click', function(e){
				if (e.target === modal || e.target.classList.contains('lp-cargonizer-close')) { closeModal(); }
			});

			if (closeBottomBtn) {
				closeBottomBtn.addEventListener('click', closeModal);
			}

			addBtn.addEventListener('click', function(){ createColli({}); });
			runEstimateBtn.addEventListener('click', runEstimate);
			if (runBookingBtn) {
				runBookingBtn.addEventListener('click', function(){ runBooking(); });
			}
			if (selectAllShippingBtn) {
				selectAllShippingBtn.addEventListener('click', toggleSelectAllShippingOptions);
			}


			resultsContent.addEventListener('click', function(e){
				var refreshBtn = e.target.closest('.lp-servicepartner-refresh');
				if (refreshBtn) {
					e.preventDefault();
					fetchServicepartnersForMethod(refreshBtn.getAttribute('data-method-key') || '');
					return;
				}
				var retryBtn = e.target.closest('.lp-method-retry');
				if (retryBtn) {
					e.preventDefault();
					var methodKey = retryBtn.getAttribute('data-method-key') || '';
					var select = resultsContent.querySelector('.lp-servicepartner-select[data-method-key="'+methodKey+'"]');
					var selectedServicepartner = select ? (select.value || '') : '';
					var smsToggle = resultsContent.querySelector('.lp-sms-service-toggle[data-method-key="'+methodKey+'"]');
					var useSmsService = smsToggle ? !!smsToggle.checked : false;
					runEstimateForSingleMethod(methodKey, selectedServicepartner, useSmsService);
				}
			});

			resultsContent.addEventListener('change', function(e){
				var smsToggle = e.target.closest('.lp-sms-service-toggle');
				if (smsToggle) {
					var smsMethodKey = smsToggle.getAttribute('data-method-key') || '';
					latestEstimateResults = latestEstimateResults.map(function(row){
						if (methodKeyForRow(row) === smsMethodKey) {
							row.use_sms_service = !!smsToggle.checked;
						}
						return row;
					});
					return;
				}

				var select = e.target.closest('.lp-servicepartner-select');
				if (!select) { return; }
				var methodKey = select.getAttribute('data-method-key') || '';
				latestEstimateResults = latestEstimateResults.map(function(row){
					if (methodKeyForRow(row) === methodKey) {
						row.selected_servicepartner = select.value || '';
					}
					return row;
				});
			});

			if (bookingResultsContent) {
				bookingResultsContent.addEventListener('click', function(e){
					var refreshBtn = e.target.closest('.lp-servicepartner-refresh');
					if (refreshBtn) {
						e.preventDefault();
						fetchServicepartnersForMethod(refreshBtn.getAttribute('data-method-key') || '');
						return;
					}
					var retryBtn = e.target.closest('.lp-booking-method-retry');
					if (!retryBtn) { return; }
					e.preventDefault();
					var methodKey = retryBtn.getAttribute('data-method-key') || '';
					var select = bookingResultsContent.querySelector('.lp-servicepartner-select[data-method-key="'+methodKey+'"]');
					var selectedServicepartner = select ? (select.value || '') : '';
					var smsToggle = bookingResultsContent.querySelector('.lp-sms-service-toggle[data-method-key="'+methodKey+'"]');
					var useSmsService = smsToggle ? !!smsToggle.checked : false;
					retryBookingForSingleMethod(methodKey, selectedServicepartner, useSmsService);
				});
			}

			function loadOrderData(){
				var form = new FormData();
				form.append('action', 'lp_cargonizer_get_order_estimate_data');
				form.append('nonce', (config.nonces && config.nonces.orderData ? config.nonces.orderData : ''));
				form.append('order_id', currentOrderId);

				return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						loading.style.display = 'none';
						if (!res || !res.success || !res.data) {
							var serverMessage = res && res.data && res.data.message ? res.data.message : '';
							errorBox.textContent = serverMessage || 'Kunne ikke hente ordredata.';
							errorBox.style.display = 'block';
							return;
						}

						var d = res.data;
						currentRecipient = d.recipient || {};
						currentBookingState = d.booking_state || null;
						overview.innerHTML = '<h3 style="margin:0 0 8px 0;">Oversikt over sendingen</h3>' +
							'<div><strong>Ordre:</strong> #' + esc(d.order.number) + '</div>' +
							'<div><strong>Dato:</strong> ' + esc(d.order.date) + '</div>' +
							'<div><strong>Total:</strong> ' + esc(d.order.total) + '</div>';

						recipient.innerHTML = '<h3 style="margin:0 0 8px 0;">Kunde / mottaker</h3>' +
							'<div><strong>Navn:</strong> ' + esc(d.recipient.name) + '</div>' +
							'<div><strong>Adresse:</strong> ' + esc(d.recipient.address_1) + ' ' + esc(d.recipient.address_2) + ', ' + esc(d.recipient.postcode) + ' ' + esc(d.recipient.city) + ', ' + esc(d.recipient.country) + '</div>' +
							'<div><strong>E-post:</strong> ' + esc(d.recipient.email) + '</div>' +
							'<div><strong>Telefon:</strong> ' + esc(d.recipient.phone) + '</div>';

						var rows = d.items.map(function(item){
							return '<tr><td>'+esc(item.name)+'</td><td>'+esc(item.quantity)+'</td><td>'+esc(item.sku)+'</td></tr>';
						}).join('');
						lines.innerHTML = '<h3 style="margin:0 0 8px 0;">Ordrelinjer</h3><table class="widefat striped"><thead><tr><th>Produkt</th><th>Antall</th><th>SKU</th></tr></thead><tbody>' + (rows || '<tr><td colspan="3">Ingen ordrelinjer.</td></tr>') + '</tbody></table>';

						if (Array.isArray(d.packages) && d.packages.length) {
							d.packages.forEach(function(pkg){ createColli(pkg); });
						} else {
							createColli({});
						}

						collectColliData();
						fetchShippingOptions();
						if (currentMode === 'booking') {
							fetchPrinters();
							renderExistingBookingState(currentBookingState);
						}
						content.style.display = 'block';
					})
					.catch(function(){
						loading.style.display = 'none';
						errorBox.textContent = 'Teknisk feil ved henting av ordredata.';
						errorBox.style.display = 'block';
					});
			}

			function openForMode(mode, orderId){
				currentOrderId = orderId || getOrderIdFromCurrentUrl();
				setModalMode(mode);
				openModal();
				loading.style.display = 'block';
				errorBox.style.display = 'none';
				content.style.display = 'none';
				colliList.innerHTML = '';
				colliValidation.style.display = 'none';
				latestEstimateResults = [];
				currentRecipient = {};
				currentBookingState = null;
				resultsContent.innerHTML = 'Ingen estimater kjørt enda.';
				shippingOptionsList.innerHTML = '<em>Laster fraktvalg...</em>';
				if (bookingResultsContent) {
					bookingResultsContent.innerHTML = 'Ingen booking kjørt enda.';
				}
				if (bookingPrinterChoice) {
					bookingPrinterChoice.innerHTML = '<option value="">Ingen utskrift</option>';
				}
				if (bookingPrinterHelp) {
					bookingPrinterHelp.textContent = '';
				}
				if (runBookingBtn) {
					runBookingBtn.disabled = false;
					runBookingBtn.textContent = 'Book shipment';
				}
				loadOrderData();
			}

			document.addEventListener('click', function(e){
				var btn = e.target.closest('.lp-cargonizer-estimate-open');
				if (btn) {
					e.preventDefault();
					openForMode('estimate', btn.getAttribute('data-order-id') || '');
					return;
				}
				var bookingBtn = e.target.closest('.lp-cargonizer-book-open');
				if (!bookingBtn) { return; }
				e.preventDefault();
				openForMode('booking', bookingBtn.getAttribute('data-order-id') || '');
			});
		
})();
