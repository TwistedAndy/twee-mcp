jQuery(function($) {
	'use strict';

	/**
	 * Shared AJAX request helper
	 */
	function sendAjax(action, data, onSuccess, onError) {
		return $.ajax({
			url: twcpAdmin.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: $.extend({
				action: action,
				nonce: twcpAdmin.nonce
			}, data || {}),
			success: onSuccess,
			error: onError
		});
	}

	/**
	 * Copy a field value and show temporary button feedback.
	 */
	function copyText(value, $button, input) {
		const originalText = $button.text();
		const showSuccess = () => {
			$button.text(twcpAdmin.copied || 'Copied!').css({
				background: '#1e7e34',
				borderColor: '#1e7e34',
				color: '#ffffff'
			});
			setTimeout(() => {
				$button.text(originalText).css({
					background: '',
					borderColor: '',
					color: ''
				});
			}, 2000);
		};
		const fallbackCopy = () => {
			input.focus();
			input.select();

			try {
				if (document.execCommand('copy')) {
					showSuccess();
					return;
				}
			} catch (error) {
				// Keep the text selected for manual copying.
			}

			window.alert(twcpAdmin.copyFailed || 'Copy failed. The text is selected so you can copy it manually.');
		};

		input.select();

		if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
			navigator.clipboard.writeText(value).then(showSuccess).catch(fallbackCopy);
			return;
		}

		fallbackCopy();
	}

	$('.twcp_reset_prompt').on('click', function() {
		const input = document.getElementById(this.dataset.target);
		if (!input) {
			return;
		}

		input.value = input.dataset.default;
		$(input).trigger('input').trigger('change');
		input.focus();
	});

	$('.twcp_generate_context').on('click', function() {
		const $btn = $(this);
		const input = document.getElementById(this.dataset.target);
		const originalText = $btn.text();

		$btn.prop('disabled', true).text('Generating...');

		sendAjax('twcp_generate_context', {}, function(res) {
			$btn.prop('disabled', false).text(originalText);
			if (res.success && res.data && res.data.context) {
				input.value = res.data.context;
				$(input).trigger('input').trigger('change');
				input.focus();
			} else {
				alert((res.data && res.data.message) || 'Failed to generate the website context.');
			}
		}, function() {
			$btn.prop('disabled', false).text(originalText);
			alert('Server communication error during context generation.');
		});
	});

	// 1. Copy Server URL with visual feedback
	$('#twcp_copy_url_btn').on('click', function(e) {
		e.preventDefault();
		const input = document.getElementById('twcp_url_input');
		if (!input) {
			return;
		}

		input.select();
		copyText(input.value, $(this), input);
	});

	$('.twcp_copy_prompt').on('click', function(e) {
		e.preventDefault();
		const input = document.getElementById(this.dataset.target);
		if (!input) {
			return;
		}

		copyText(input.value, $(this), input);
	});

	// 2. Show / Hide API Key
	$('#twcp_toggle_key_btn').on('click', function(e) {
		e.preventDefault();
		const masked = $('#twcp_model_api_key').toggleClass('masked').hasClass('masked');
		$(this).text(masked ? 'Show' : 'Hide');
	});

	// 3. Dynamic Model Dropdown Filter based on Provider Dropdown
	function syncModelDropdown(provider) {
		const $modelSelect = $('#twcp_model_name');
		const $groups = $modelSelect.find('optgroup');

		$groups.each(function() {
			const groupProvider = $(this).data('provider');
			if (groupProvider === provider) {
				$(this).show().prop('disabled', false);
			} else {
				$(this).hide().prop('disabled', true);
			}
		});

		const $selected = $modelSelect.find('option:selected');
		if (!$selected.length || $selected.parent('optgroup').prop('disabled')) {
			const $firstAvailable = $modelSelect.find('optgroup:not(:disabled) option:first');
			if ($firstAvailable.length) {
				$modelSelect.val($firstAvailable.val());
			}
		}

		// Also sync vision model dropdown
		const $visionSelect = $('#twcp_vision_model');
		if ($visionSelect.length) {
			const $vgroups = $visionSelect.find('optgroup');
			$vgroups.each(function() {
				const groupProvider = $(this).data('provider');
				if (groupProvider === provider) {
					$(this).show().prop('disabled', false);
				} else {
					$(this).hide().prop('disabled', true);
				}
			});
			const $vselected = $visionSelect.find('option:selected');
			if (!$vselected.length || $vselected.parent('optgroup').prop('disabled')) {
				const $vfirst = $visionSelect.find('optgroup:not(:disabled) option:first');
				if ($vfirst.length) {
					$visionSelect.val($vfirst.val());
				}
			}
		}
	}

	// Show the ChatGPT sign-in instead of the API key for the plan provider
	function syncProviderPanels(provider) {
		$('[data-provider-panel]').each(function() {
			$(this).toggle(this.dataset.providerPanel === provider);
		});
		$('[data-provider-hide]').each(function() {
			$(this).toggle(this.dataset.providerHide !== provider);
		});
	}

	// Remember the models picked for each provider, so switching away and back keeps them
	const pickedModels = {};
	let currentProvider = $('#twcp_model_provider').val() || 'openai';

	$('#twcp_model_provider').on('change', function() {
		pickedModels[currentProvider] = {model: $('#twcp_model_name').val(), vision: $('#twcp_vision_model').val()};
		currentProvider = $(this).val();
		if (pickedModels[currentProvider]) {
			$('#twcp_model_name').val(pickedModels[currentProvider].model);
			$('#twcp_vision_model').val(pickedModels[currentProvider].vision);
		}
		syncModelDropdown(currentProvider);
		syncProviderPanels(currentProvider);
	});

	syncModelDropdown($('#twcp_model_provider').val() || 'openai');
	syncProviderPanels($('#twcp_model_provider').val() || 'openai');

	// 3a. ChatGPT plan sign-in with a pasted callback address
	function chatgptStatus(text, success) {
		$('#twcp_chatgpt_status').text(text).css('color', success ? '#1e7e34' : '#d63638');
	}

	$('#twcp_chatgpt_start').on('click', function() {
		// Open the tab inside the click so popup blockers allow it, then point it to OpenAI
		const tab = window.open('', '_blank');
		const $btn = $(this).prop('disabled', true);

		sendAjax('twcp_chatgpt_start', {}, function(res) {
			$btn.prop('disabled', false);
			if (res.success && tab) {
				tab.opener = null;
				tab.location.href = res.data.url;
				chatgptStatus('Approve the connection in the new tab, then paste the final address here.', true);
				$('#twcp_chatgpt_callback').trigger('focus');
			} else {
				if (tab) {
					tab.close();
				}
				chatgptStatus((res.data && res.data.message) || 'Allow pop-ups for this page and try again.', false);
			}
		}, function() {
			$btn.prop('disabled', false);
			if (tab) {
				tab.close();
			}
			chatgptStatus('Server communication error.', false);
		});
	});

	// A pasted callback address completes the sign-in without another click
	$('#twcp_chatgpt_callback').on('paste', function() {
		const $input = $(this);
		setTimeout(function() {
			if (/[?&]code=/.test($input.val())) {
				$('#twcp_chatgpt_finish').trigger('click');
			}
		}, 0);
	});

	$('#twcp_chatgpt_finish').on('click', function() {
		const callback = $.trim($('#twcp_chatgpt_callback').val());
		if (!callback) {
			chatgptStatus('Paste the address from the OpenAI tab first.', false);
			return;
		}

		const $btn = $(this).prop('disabled', true);
		sendAjax('twcp_chatgpt_finish', {callback: callback}, function(res) {
			$btn.prop('disabled', false);
			chatgptStatus(res.data && res.data.message, res.success);
			if (res.success) {
				window.location.reload();
			}
		}, function() {
			$btn.prop('disabled', false);
			chatgptStatus('Server communication error.', false);
		});
	});

	$('#twcp_chatgpt_disconnect').on('click', function() {
		if (!window.confirm('Disconnect ChatGPT? AI tools stop working until you sign in again or switch to an API key.')) {
			return;
		}

		sendAjax('twcp_chatgpt_disconnect', {}, function(res) {
			chatgptStatus(res.data && res.data.message, res.success);
			window.setTimeout(() => window.location.reload(), 1500);
		}, function() {
			chatgptStatus('Server communication error.', false);
		});
	});

	// 3b. Load Models from API dynamically
	$('#twcp_fetch_models_btn').on('click', function(e) {
		e.preventDefault();
		const $btn = $(this);
		const $status = $('#twcp_fetch_models_status');
		const provider = $('#twcp_model_provider').val() || 'openai';
		const apiKey = $('#twcp_model_api_key').val() || '';

		$btn.prop('disabled', true).text('Loading...');
		$status.text('').css('color', '');

		sendAjax('twcp_fetch_models', {
			provider: provider,
			api_key: apiKey
		}, function(res) {
			$btn.prop('disabled', false).text('Load Models from API');
			if (res.success && res.data && res.data.models) {
				const models = res.data.models;
				const $modelSelect = $('#twcp_model_name');
				const currentVal = $modelSelect.val();

				let $group = $modelSelect.find('optgroup[data-provider="' + provider + '"]');
				if (!$group.length) {
					$group = $('<optgroup>').attr('data-provider', provider).attr('label', provider.toUpperCase());
					$modelSelect.append($group);
				}

				$group.empty();
				let matchFound = false;
				$.each(models, function(idx, item) {
					const $opt = $('<option>').val(item.id).text(item.label || item.id);
					if (item.id === currentVal) {
						$opt.prop('selected', true);
						matchFound = true;
					}
					$group.append($opt);
				});

				if (!matchFound && models.length > 0) {
					$modelSelect.val(models[0].id);
				}

				// Update vision select as well if present
				const $visionSelect = $('#twcp_vision_model');
				if ($visionSelect.length) {
					const currentVision = $visionSelect.val();
					let $vgroup = $visionSelect.find('optgroup[data-provider="' + provider + '"]');
					if (!$vgroup.length) {
						$vgroup = $('<optgroup>').attr('data-provider', provider).attr('label', provider.toUpperCase());
						$visionSelect.append($vgroup);
					}
					$vgroup.empty();
					$.each(models, function(idx, item) {
						const $opt = $('<option>').val(item.id).text(item.label || item.id);
						$vgroup.append($opt);
					});

					// Rebuilding the list drops the selection, so the next save would store another model
					$visionSelect.val(currentVision);
					if (!$visionSelect.val() && models.length > 0) {
						$visionSelect.val(models[0].id);
					}
				}

				$status.text(res.data.message || ('Loaded ' + models.length + ' models.')).css('color', '#1e7e34');
			} else {
				$status.text((res.data && res.data.message) || 'Failed to fetch models. Check API key.').css('color', '#d63638');
			}
		}, function() {
			$btn.prop('disabled', false).text('Load Models from API');
			$status.text('Error communicating with server.').css('color', '#d63638');
		});
	});

	// 4. Test Model Connection via AJAX
	$('#twcp_test_btn').on('click', function(e) {
		e.preventDefault();
		const $btn = $(this);
		const $status = $('#twcp_test_status');

		$btn.prop('disabled', true).text('Testing...');
		$status.text('').css('color', '');

		sendAjax('twcp_test_model', {}, function(res) {
			$btn.prop('disabled', false).text('Test Connection');
			if (res.success) {
				$status.text(res.data.message || 'Connected successfully!').css('color', '#1e7e34');
			} else {
				$status.text((res.data && res.data.message) || 'Connection failed.').css('color', '#d63638');
			}
		}, function() {
			$btn.prop('disabled', false).text('Test Connection');
			$status.text('Server communication error.').css('color', '#d63638');
		});
	});

	// 5. Live Filter for ACF Block Descriptions
	$('#twcp_block_search').on('input', function() {
		const q = $(this).val().toLowerCase().trim();
		$('.block_desc_item').each(function() {
			const layout = $(this).data('layout') || '';
			const label = $(this).data('label') || '';
			const desc = $(this).find('textarea').val() || '';
			if (!q || layout.toLowerCase().indexOf(q) !== -1 || label.toLowerCase().indexOf(q) !== -1 || desc.toLowerCase().indexOf(q) !== -1) {
				$(this).show();
			} else {
				$(this).hide();
			}
		});
	});

	// 5b. Reset to Default System Prompt Handler
	$(document).on('click', '.twcp_restore_prompt_btn', function(e) {
		e.preventDefault();
		const targetId = $(this).data('target');
		const defaultVal = $(this).data('default');
		if (targetId && defaultVal !== undefined) {
			$('#' + targetId).val(defaultVal);
		}
	});

	// 5c. Generate Block Descriptions with Previews (sequential with live visual updates)
	$('#twcp_generate_blocks_btn').on('click', function(e) {
		e.preventDefault();
		const $btn = $(this);
		const originalText = $btn.text();
		const folder = $('#twcp_block_previews_folder').val() || '';
		const $status = $('#twcp_generate_blocks_status');
		const $rows = $('tr[data-layout]');

		if (!$rows.length) {
			return;
		}

		$btn.prop('disabled', true).text('Generating...');
		$status.text('Starting block descriptions generation...').css('color', '#2271b1');

		const layouts = [];
		$rows.each(function() {
			const l = $(this).data('layout');
			if (l) {
				layouts.push(l);
			}
		});

		let index = 0;
		let successCount = 0;

		function processNext() {
			if (index >= layouts.length) {
				$btn.prop('disabled', false).text(originalText);
				$status.text('Completed: ' + successCount + ' / ' + layouts.length + ' block descriptions generated.').css('color', '#1e7e34');
				return;
			}

			const layout = layouts[index];
			index++;
			$status.text('Generating layout ' + index + ' of ' + layouts.length + ' (' + layout + ')...').css('color', '#2271b1');

			sendAjax('twcp_generate_block_descriptions', {
				folder: folder,
				layout: layout
			}, function(res) {
				if (res.success && res.data && res.data.description) {
					successCount++;
					const $textarea = $('#twcp_desc_' + layout);
					$textarea.val(res.data.description);
					$textarea.css('background-color', '#e7f5ea');
					setTimeout(function() {
						$textarea.css('background-color', '');
					}, 1200);
				}
				processNext();
			}, function() {
				processNext();
			});
		}

		processNext();
	});

	// 5d. Generate Single Block Description
	$(document).on('click', '.twcp_generate_single_block_btn', function(e) {
		e.preventDefault();
		const $btn = $(this);
		const layout = $btn.data('layout');
		if (!layout) {
			return;
		}
		const originalText = $btn.text();
		const folder = $('#twcp_block_previews_folder').val() || '';
		const $textarea = $('#twcp_desc_' + layout);

		$btn.prop('disabled', true).text('Generating...');

		sendAjax('twcp_generate_block_descriptions', {
			folder: folder,
			layout: layout
		}, function(res) {
			$btn.prop('disabled', false).text(originalText);
			if (res.success && res.data && res.data.description) {
				$textarea.val(res.data.description);
				$textarea.css('background-color', '#e7f5ea');
				setTimeout(function() {
					$textarea.css('background-color', '');
				}, 1200);
			} else {
				alert((res.data && res.data.message) || 'Failed to generate block description.');
			}
		}, function() {
			$btn.prop('disabled', false).text(originalText);
			alert('Server communication error during block description generation.');
		});
	});

	// 6. Universal Batch Processor Engine
	const activeJobs = {};

	function renderBatchLogs($card, logs) {
		const $logWrapper = $card.find('.batch_log_wrapper');
		const $logBox = $card.find('.batch_log_box');

		if (!logs || !logs.length) {
			$logBox.html('<div class="log_empty" style="color: #8c8f94; font-style: italic;">No items processed in this session yet.</div>');
			return;
		}

		$logWrapper.show();
		$logBox.empty();

		$.each(logs, function(idx, item) {
			const color = item.success ? '#1e7e34' : '#d63638';
			const time = item.time ? item.time : '';
			const $entry = $('<div>').addClass('log_entry').css({
				color: color,
				marginBottom: '2px'
			});

			if (time) {
				$('<span>').css({ color: '#8c8f94' }).text('[' + time + '] ').appendTo($entry);
			}

			$('<span>').text(item.message || '').appendTo($entry);
			$logBox.append($entry);
		});

		$logBox.scrollTop($logBox[0].scrollHeight);
	}

	function updateBatchUI($card, state) {
		const total = parseInt(state.total, 10) || 0;
		const processed = parseInt(state.processed, 10) || 0;
		const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : (state.status === 'completed' ? 100 : 0);

		$card.find('.progress_fill').css('width', percent + '%');
		$card.find('.percent_text').text(percent + '%');
		$card.find('.info_text').text('Processed: ' + processed + ' / ' + total);

		const mode = state.mode || 'fill';
		const $badge = $card.find('.batch_badge');
		if ($badge.length) {
			// Items filled during a run are no longer missing, while failed ones still are
			$badge.text(mode === 'fill' ? (Math.max(0, total - (parseInt(state.success, 10) || 0)) + ' Missing') : (total + ' Total'));
		}
		$card.find('.twcp_batch_mode').val(mode);
		$card.find('.btn_undo').toggle(parseInt(state.undo, 10) > 0 && state.status !== 'running');

		// Rough cost of the items left; "Rewrite if poor" adds one quality check per slice
		const left = Math.max(0, total - processed);
		const slice = parseInt($card.data('slice'), 10) || 1;
		const calls = left * (parseInt($card.data('calls'), 10) || 1) + (mode === 'poor' ? Math.ceil(left / slice) : 0);
		const tokens = left * (parseInt($card.data('tokens'), 10) || 0) + (mode === 'poor' ? Math.ceil(left / slice) * 1500 : 0);
		$card.find('.batch_estimate').text(left && state.status !== 'completed' ? '≈ ' + calls + ' AI request' + (calls === 1 ? '' : 's') + ', ' + (tokens >= 1000 ? Math.round(tokens / 1000) + 'k' : tokens) + ' tokens' : '');

		if (state.post_type) {
			$card.find('.twcp_batch_post_type').val(state.post_type);
		}
		if (state.field) {
			$card.find('.twcp_batch_field').val(state.field);
		}

		const $start = $card.find('.btn_start');
		$start.each(function() {
			if (!$(this).data('orig-text')) {
				$(this).data('orig-text', $(this).text());
			}
		});
		const $pause = $card.find('.btn_pause');
		const $resume = $card.find('.btn_resume');
		const $reset = $card.find('.btn_reset');

		if (state.logs && state.logs.length) {
			renderBatchLogs($card, state.logs);
		}

		if (state.status === 'running') {
			$start.hide();
			$pause.show().prop('disabled', false);
			$resume.hide();
			$reset.prop('disabled', true);
			$card.find('.twcp_batch_mode').prop('disabled', true);
			$card.find('.twcp_batch_post_type, .twcp_batch_field').prop('disabled', true);
		} else if (state.status === 'paused') {
			$start.hide();
			$pause.hide();
			$resume.show().prop('disabled', false);
			$reset.prop('disabled', false);
			$card.find('.twcp_batch_mode').prop('disabled', true);
			$card.find('.twcp_batch_post_type, .twcp_batch_field').prop('disabled', true);
		} else if (state.status === 'completed') {
			$start.show().text('Completed').prop('disabled', true);
			$pause.hide();
			$resume.hide();
			$reset.prop('disabled', false);
			$card.find('.twcp_batch_mode').prop('disabled', false);
			$card.find('.twcp_batch_post_type, .twcp_batch_field').prop('disabled', false);
		} else { // idle
			$start.show().text($start.data('orig-text') || $start.text()).prop('disabled', total === 0 || !!$card.data('locked'));
			$pause.hide();
			$resume.hide();
			$reset.prop('disabled', false);
			$card.find('.twcp_batch_mode').prop('disabled', false);
			$card.find('.twcp_batch_post_type, .twcp_batch_field').prop('disabled', false);
		}
	}

	function runBatchStep(type) {
		if (!activeJobs[type] || activeJobs[type].status !== 'running') {
			return;
		}

		const $card = $('.batch_card[data-type="' + type + '"]');

		// A slice can take a minute, so follow its items while it runs
		pollBatches();

		sendAjax('twcp_batch_step', {
			type: type
		}, function(res) {
			if (res.success && res.data && res.data.state) {
				const state = res.data.state;
				activeJobs[type] = state;
				updateBatchUI($card, state);

				// A busy job is still running a slice started from another tab or before a refresh
				if (state.status === 'running') {
					setTimeout(function() {
						runBatchStep(type);
					}, state.busy ? 3000 : 600);
				}
			} else {
				alert((res.data && res.data.message) || 'Batch step failed.');
			}
		}, function() {
			activeJobs[type].status = 'paused';
			updateBatchUI($card, activeJobs[type]);
			alert('Network error during batch step. Execution paused.');
		});
	}

	// Follow the progress of running jobs: background ones and slices still in progress
	let pollTimer = null;

	function pollBatches() {
		clearTimeout(pollTimer);
		pollTimer = setTimeout(function() {
			sendAjax('twcp_batch_status', {}, function(res) {
				let running = false;
				if (res.success && res.data && res.data.batches) {
					$.each(res.data.batches, function(type, state) {
						if (!activeJobs[type] || activeJobs[type].status !== 'running') {
							return;
						}
						activeJobs[type] = state;
						updateBatchUI($('.batch_card[data-type="' + type + '"]'), state);
						running = running || state.status === 'running';
					});
				}
				if (running) {
					pollBatches();
				}
			}, function() {
				pollBatches();
			});
		}, 3000);
	}

	function runBatch(type, state) {
		if (state && state.background) {
			pollBatches();
		} else {
			runBatchStep(type);
		}
	}

	// Fetch initial batch states
	if ($('.batch_card').length) {
		sendAjax('twcp_batch_status', {}, function(res) {
			if (res.success && res.data && res.data.batches) {
				$.each(res.data.batches, function(type, state) {
					activeJobs[type] = state;
					const $card = $('.batch_card[data-type="' + type + '"]');
					if ($card.length) {
						updateBatchUI($card, state);
					}
					// A job left running by a refresh or a closed tab carries on from this page
					if (state.status === 'running') {
						$card.find('.batch_log_wrapper').show();
						runBatch(type, state);
					}
				});
			}
		});
	}

	function sendBatchControl($card, action, extraData, callback) {
		const type = $card.data('type');
		sendAjax('twcp_batch_control', $.extend({
			type: type,
			batch_action: action
		}, extraData || {}), function(res) {
			if (res.success && res.data && res.data.state) {
				activeJobs[type] = res.data.state;
				updateBatchUI($card, res.data.state);
				if (typeof callback === 'function') {
					callback(type, res.data.state);
				}
			} else if (res.data && res.data.message) {
				alert(res.data.message);
			}
		});
	}

	// Batch Button & Override Event Handlers
	$('.batch_card').on('change', '.twcp_batch_post_type', function() {
		const $card = $(this).closest('.batch_card');
		sendBatchControl($card, 'change_post_type', {
			post_type: $(this).val() || 'all',
			mode: $card.find('.twcp_batch_mode').val() || 'fill'
		});
	});

	$('.batch_card').on('change', '.twcp_batch_field', function() {
		const $card = $(this).closest('.batch_card');
		sendBatchControl($card, 'change_field', {
			field: $(this).val() || '',
			mode: $card.find('.twcp_batch_mode').val() || 'fill',
			post_type: $card.find('.twcp_batch_post_type').val() || 'all'
		});
	});

	$('.batch_card').on('change', '.twcp_batch_mode', function() {
		const $card = $(this).closest('.batch_card');
		sendBatchControl($card, 'change_mode', {
			mode: $(this).val() || 'fill',
			post_type: $card.find('.twcp_batch_post_type').val() || 'all'
		});
	});

	$('.batch_card').on('click', '.btn_start, .btn_resume', function() {
		const $card = $(this).closest('.batch_card');
		$card.find('.batch_log_wrapper').show();
		sendBatchControl($card, 'start', {
			mode: $card.find('.twcp_batch_mode').val() || 'fill',
			post_type: $card.find('.twcp_batch_post_type').val() || 'all'
		}, function(type, state) {
			runBatch(type, state);
		});
	});

	$('.batch_card').on('click', '.btn_pause', function() {
		const $card = $(this).closest('.batch_card');
		const type = $card.data('type');
		if (activeJobs[type]) {
			activeJobs[type].status = 'paused';
		}
		sendBatchControl($card, 'pause');
	});

	$('.batch_card').on('click', '.btn_reset', function() {
		if (!confirm('Are you sure you want to reset this batch? The progress offset will be reset to 0.')) {
			return;
		}
		const $card = $(this).closest('.batch_card');
		const type = $card.data('type');
		if (activeJobs[type]) {
			activeJobs[type].status = 'idle';
		}
		sendBatchControl($card, 'reset', {}, function() {
			$card.find('.batch_log_wrapper').hide();
			$card.find('.batch_log_box').empty();
		});
	});

	$('.batch_card').on('click', '.btn_undo', function() {
		const $card = $(this).closest('.batch_card');
		const count = parseInt((activeJobs[$card.data('type')] || {}).undo, 10) || 0;
		if (!confirm('Restore the values from before the last run on ' + count + ' item(s)? Changes made since then to those fields are lost.')) {
			return;
		}
		$card.find('.batch_log_wrapper').show();
		sendBatchControl($card, 'undo');
	});

	$('.batch_card').on('click', '.btn_clear_log', function(e) {
		e.preventDefault();
		const $card = $(this).closest('.batch_card');
		$card.find('.batch_log_box').html('<div class="log_empty" style="color: #8c8f94; font-style: italic;">Log cleared.</div>');
	});

	// 7. Interactive Stock Image Search & Sideload
	let stockSearchState = {
		query: '',
		provider: 'all',
		orientation: '',
		perPage: 12,
		page: 1,
		maxKnownPage: 1,
		loading: false
	};

	function updatePaginationUI(page, perPage, itemsCount, hasMore) {
		const $pagination = $('#twcp_stock_pagination');
		const $info = $('#twcp_stock_pagination_info');
		const $controls = $('#twcp_stock_pagination_controls');

		if (!itemsCount && page <= 1) {
			$pagination.hide();
			return;
		}

		$pagination.show();

		// 1. Refined text: e.g. "Showing images 13–24 (Page 2)"
		if (itemsCount > 0) {
			const start = (page - 1) * perPage + 1;
			const end = start + itemsCount - 1;
			const label = (itemsCount === 1) ? ('Showing image ' + start + ' (Page ' + page + ')') : ('Showing images ' + start + '–' + end + ' (Page ' + page + ')');
			$info.text(label);
		} else {
			$info.text('Page ' + page + ' (0 images)');
		}

		// 2. Track maximum known page
		if (hasMore) {
			stockSearchState.maxKnownPage = Math.max(stockSearchState.maxKnownPage || 1, page + 1);
		} else {
			stockSearchState.maxKnownPage = Math.max(1, page);
		}

		const maxPage = stockSearchState.maxKnownPage;
		$controls.empty();

		// « Previous button
		const $prevBtn = $('<button>').attr('type', 'button').addClass('button twcp_prev_page_btn').html('&larr; Previous').prop('disabled', page <= 1);
		$controls.append($prevBtn);

		// Numbered pagination buttons
		let pagesToShow = [];
		if (maxPage <= 7) {
			for (let p = 1; p <= maxPage; p++) {
				pagesToShow.push(p);
			}
		} else {
			pagesToShow.push(1);
			const startPage = Math.max(2, page - 2);
			const endPage = Math.min(maxPage - 1, page + 2);

			if (startPage > 2) {
				pagesToShow.push('...');
			}
			for (let p = startPage; p <= endPage; p++) {
				pagesToShow.push(p);
			}
			if (endPage < maxPage - 1) {
				pagesToShow.push('...');
			}
			pagesToShow.push(maxPage);
		}

		$.each(pagesToShow, function(idx, p) {
			if (p === '...') {
				$('<span>').addClass('twcp_page_dots').text('…').appendTo($controls);
			} else {
				const isCurrent = (p === page);
				const $pageBtn = $('<button>').attr('type', 'button').addClass('button').text(p);

				if (isCurrent) {
					$pageBtn.addClass('button-primary twcp_page_current').prop('disabled', true);
				} else {
					$pageBtn.addClass('twcp_page_num_btn').attr('data-page', p).data('page', p);
				}
				$controls.append($pageBtn);
			}
		});

		// Next » button
		const $nextBtn = $('<button>').attr('type', 'button').addClass('button twcp_next_page_btn').html('Next &rarr;').prop('disabled', !hasMore);
		$controls.append($nextBtn);
	}

	function executeStockSearch(targetPage) {
		const query = $('#twcp_stock_search_query').val().trim();
		if (!query) {
			alert('Please enter a search query.');
			return;
		}

		const provider = $('#twcp_stock_search_provider').val() || 'all';
		const orientation = $('#twcp_stock_search_orientation').val() || '';
		const perPage = parseInt($('#twcp_stock_search_per_page').val(), 10) || 12;
		const page = Math.max(1, parseInt(targetPage, 10) || 1);

		stockSearchState.query = query;
		stockSearchState.provider = provider;
		stockSearchState.orientation = orientation;
		stockSearchState.perPage = perPage;
		stockSearchState.page = page;
		stockSearchState.loading = true;

		const $btn = $('#twcp_stock_search_btn');
		const $status = $('#twcp_stock_search_status');
		const $grid = $('#twcp_stock_results_grid');

		$btn.prop('disabled', true).text('Searching...');
		$('#twcp_stock_pagination_controls button').prop('disabled', true);
		$status.text('Searching ' + provider + ' (Page ' + page + ')...').css('color', '');
		$grid.empty();

		sendAjax('twcp_search_stock_images', {
			query: query,
			provider: provider,
			orientation: orientation,
			per_page: perPage,
			page: page
		}, function(res) {
			stockSearchState.loading = false;
			$btn.prop('disabled', false).text('Search Images');

			if (res.success && res.data && res.data.results) {
				const items = res.data.results;
				const hasMore = items.length >= perPage;

				if (!items.length) {
					$status.text('No images found for "' + query + '" on page ' + page + '.').css('color', '#d63638');
					updatePaginationUI(page, perPage, 0, false);
					return;
				}

				const start = (page - 1) * perPage + 1;
				const end = start + items.length - 1;
				$status.text('Found ' + items.length + ' images (' + start + '–' + end + ').').css('color', '#1e7e34');

				$.each(items, function(i, img) {
					const thumb = img.preview_url || img.thumb_url || img.url;
					const title = img.title || img.author || img.author_name || 'Stock Photo';
					const author = img.author || img.author_name || '';
					const providerName = img.provider || provider;
					const w = img.width || '';
					const h = img.height || '';
					const dims = (w && h) ? (w + ' × ' + h) : '';

					const $card = $('<div>').addClass('stock_card').data('image', img);
					const $thumbWrap = $('<div>').addClass('stock_thumb_wrap');
					const $thumbImg = $('<img>').addClass('stock_thumb').attr({
						src: thumb,
						alt: title,
						loading: 'lazy'
					});
					const $badge = $('<span>').addClass('badge stock_badge').text(providerName);
					$thumbWrap.append($thumbImg, $badge);

					const $content = $('<div>').addClass('stock_content');
					const $meta = $('<div>');
					const $titleEl = $('<div>').addClass('stock_title').attr('title', title).text(title);
					$meta.append($titleEl);

					if (author) {
						$meta.append($('<div>').addClass('stock_author').text('By ' + author));
					}
					if (dims) {
						$meta.append($('<div>').addClass('stock_dims').text(dims));
					}

					const $actions = $('<div>').addClass('stock_actions');
					const $importBtn = $('<button>').attr('type', 'button').addClass('button button-small twcp_import_image_btn').text('Import to Media Library').data('image', img);
					const $importStatus = $('<div>').addClass('import_status');
					$actions.append($importBtn, $importStatus);

					$content.append($meta, $actions);
					$card.append($thumbWrap, $content);
					$grid.append($card);
				});

				updatePaginationUI(page, perPage, items.length, hasMore);

				if (page > 1) {
					$('html, body').animate({
						scrollTop: $grid.offset().top - 80
					}, 200);
				}
			} else {
				$status.text((res.data && res.data.message) || 'Search failed.').css('color', '#d63638');
				$('#twcp_stock_pagination').hide();
			}
		}, function() {
			stockSearchState.loading = false;
			$btn.prop('disabled', false).text('Search Images');
			$('#twcp_stock_pagination_controls button').prop('disabled', false);
			$status.text('Server communication error during search.').css('color', '#d63638');
		});
	}

	$('#twcp_stock_search_btn').on('click', function(e) {
		e.preventDefault();
		stockSearchState.maxKnownPage = 1;
		executeStockSearch(1);
	});

	$('#twcp_stock_search_query').on('keydown', function(e) {
		if (e.which === 13) {
			e.preventDefault();
			stockSearchState.maxKnownPage = 1;
			executeStockSearch(1);
		}
	});

	$('#twcp_stock_search_per_page, #twcp_stock_search_provider, #twcp_stock_search_orientation').on('change', function() {
		if ($('#twcp_stock_search_query').val().trim()) {
			stockSearchState.maxKnownPage = 1;
			executeStockSearch(1);
		}
	});

	$(document).on('click', '.twcp_page_num_btn', function(e) {
		e.preventDefault();
		const p = parseInt($(this).attr('data-page') || $(this).data('page') || $(this).text().trim(), 10);
		if (p && !stockSearchState.loading) {
			executeStockSearch(p);
		}
	});

	$(document).on('click', '.twcp_prev_page_btn', function(e) {
		e.preventDefault();
		if (!stockSearchState.loading && stockSearchState.page > 1) {
			executeStockSearch(stockSearchState.page - 1);
		}
	});

	$(document).on('click', '.twcp_next_page_btn', function(e) {
		e.preventDefault();
		if (!stockSearchState.loading) {
			executeStockSearch(stockSearchState.page + 1);
		}
	});

	$(document).on('click', '.twcp_import_image_btn', function(e) {
		e.preventDefault();
		const $btn = $(this);
		const $card = $btn.closest('.stock_card');
		const $status = $card.find('.import_status');
		const img = $btn.data('image') || $card.data('image') || {};

		const url = img.download_url || img.url || $btn.data('url') || '';
		const title = img.title || $btn.data('title') || '';
		const provider = img.provider || $btn.data('provider') || '';

		$btn.prop('disabled', true).text('Importing...');
		$status.text('Downloading & importing...').css('color', '');

		sendAjax('twcp_import_stock_image', {
			image: img,
			url: url,
			title: title,
			provider: provider
		}, function(res) {
			if (res.success && res.data && res.data.attachment_id) {
				$btn.text('Imported!').css({
					background: '#1e7e34',
					borderColor: '#1e7e34',
					color: '#ffffff'
				});

				const $link = $('<a>').attr('href', res.data.edit_url).attr('target', '_blank').css({
					color: '#1e7e34',
					fontWeight: '600'
				}).text('✓ View Attachment #' + res.data.attachment_id);

				$status.empty().append($link);
			} else {
				$btn.prop('disabled', false).text('Retry Import');
				$status.text((res.data && res.data.message) || 'Import failed.').css('color', '#d63638');
			}
		}, function() {
			$btn.prop('disabled', false).text('Retry Import');
			$status.text('Server communication error.').css('color', '#d63638');
		});
	});
});
