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

	// 1. Copy the Server URL, client ID or example prompt named by data-target, with visual feedback
	$('.twcp_copy_prompt').on('click', function(e) {
		e.preventDefault();
		const input = document.getElementById(this.dataset.target);
		if (!input) {
			return;
		}

		copyText(input.value, $(this), input);
	});

	// 1b. Client ID for a ChatGPT connector that cannot register itself
	function clientStatus(text, success) {
		$('#twcp_client_status').text(text || '').css('color', success ? '#1e7e34' : '#d63638');
	}

	$('#twcp_client_create').on('click', function() {
		const callback = $.trim($('#twcp_client_callback').val());
		if (!callback) {
			clientStatus('Paste the Callback URL from ChatGPT first.', false);
			return;
		}

		const $btn = $(this).prop('disabled', true);
		$('#twcp_client_result').hide();
		clientStatus('', true);

		sendAjax('twcp_client_id', {callback: callback}, function(res) {
			$btn.prop('disabled', false);
			if (res.success && res.data && res.data.client_id) {
				$('#twcp_client_id').val(res.data.client_id);
				$('#twcp_client_result').css('display', 'flex');
			} else {
				clientStatus((res.data && res.data.message) || 'Could not create a client ID.', false);
			}
		}, function() {
			$btn.prop('disabled', false);
			clientStatus('Server communication error.', false);
		});
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

	// 5. Universal Batch Processor Engine
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

		// Rough cost of the items left; combined jobs send one request per slice, and "Rewrite if poor" adds one
		// quality check per slice
		const left = Math.max(0, total - processed);
		const slice = parseInt($card.data('slice'), 10) || 1;
		const calls = ($card.data('combine') ? Math.ceil(left / slice) : left * (parseInt($card.data('calls'), 10) || 1)) + (mode === 'poor' ? Math.ceil(left / slice) : 0);
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

	// Preview: run a job on one item with the instructions as they are on the screen, and show the replies without saving
	function loadPreviewItems($cards) {
		const cards = $cards.map(function() {
			const $card = $(this);

			return {
				type: $card.data('type'),
				post_type: $card.find('.twcp_batch_post_type').val() || 'all',
				mode: $card.find('.twcp_batch_mode').val() || 'fill',
				search: $card.find('.twcp_preview_search').val() || ''
			};
		}).get();

		sendAjax('twcp_batch_items', {cards: cards}, function(res) {
			$cards.each(function() {
				const items = res.success ? (res.data.items[$(this).data('type')] || []) : [];
				const $select = $(this).find('.twcp_preview_item');
				$select.empty().append($('<option>', {value: '', text: items.length ? 'Choose an item…' : 'No items found'}));
				items.forEach(function(item) {
					$select.append($('<option>', {value: item.id, text: item.label}));
				});
				$select.val(items.length ? items[0].id : '');
			});
		});
	}

	// One request lists the items of every card as the page loads, so a preview opens with its list ready
	const $previewCards = $('.batch_preview').closest('.batch_card');
	if ($previewCards.length) {
		loadPreviewItems($previewCards);
	}

	// JSON replies, such as SEO tags, show as "Title: ..." lines like the current value
	function previewLines(data) {
		if (Array.isArray(data)) {
			return data.map(previewLines).join('\n\n');
		}
		if (!data || typeof data !== 'object') {
			return String(data);
		}

		return Object.keys(data).map(function(key) {
			const label = key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ');

			return data[key] && typeof data[key] === 'object' ? label + ':\n' + previewLines(data[key]) : label + ': ' + data[key];
		}).join('\n');
	}

	function previewText(reply) {
		try {
			const data = JSON.parse(reply);
			if (data && typeof data === 'object') {
				return previewLines(data);
			}
		} catch (e) {
		}

		return reply;
	}

	function previewColumn(title, text, error) {
		return $('<div class="preview_column">').append(
			$('<strong>').text(title),
			$('<div class="preview_text">').toggleClass('preview_error', !!error).text(text)
		);
	}

	$('.batch_card').on('change', '.twcp_batch_post_type, .twcp_batch_mode', function() {
		const $card = $(this).closest('.batch_card');
		if ($card.find('.batch_preview').length) {
			loadPreviewItems($card);
		}
	});

	let previewSearch;
	$('.batch_card').on('input', '.twcp_preview_search', function() {
		const $card = $(this).closest('.batch_card');
		clearTimeout(previewSearch);
		previewSearch = setTimeout(function() {
			loadPreviewItems($card);
		}, 300);
	});

	$('.batch_card').on('click', '.twcp_preview_run', function() {
		const $card = $(this).closest('.batch_card');
		const $button = $(this);
		const $result = $card.find('.batch_preview_result').prop('hidden', false);
		const id = $card.find('.twcp_preview_item').val();
		if (!id) {
			$result.empty().append(previewColumn('Preview', 'Choose an item first.', true));
			return;
		}

		// The card's prompts and the shared context as they are on the screen, saved or not
		const prompts = {};
		$card.find('.batch_prompts textarea').add('#twcp_general_context, #twcp_target_keywords').each(function() {
			prompts[this.name] = $(this).val();
		});

		$button.prop('disabled', true).text('Generating…');
		$result.empty().append($('<p class="text text_muted">').text('Waiting for the model…'));
		sendAjax('twcp_batch_preview', {
			type: $card.data('type'),
			id: id,
			mode: $card.find('.twcp_batch_mode').val() || 'fill',
			prompts: prompts
		}, function(res) {
			$button.prop('disabled', false).text('Run Preview');
			$result.empty();
			if (!res.success) {
				$result.append(previewColumn('Preview', (res.data && res.data.message) || 'The preview failed.', true));
				return;
			}
			const $columns = $('<div class="preview_columns">').append(previewColumn('Current', res.data.current || 'Empty'));
			res.data.results.forEach(function(result) {
				$columns.append(previewColumn('Preview', result.error || previewText(result.reply), !!result.error));
			});
			$result.append($columns);
			res.data.results.forEach(function(result) {
				$result.append($('<details class="preview_prompt">').append($('<summary>').text('Prompt sent'), $('<pre>').text(result.prompt)));
			});
		}, function() {
			$button.prop('disabled', false).text('Run Preview');
			$result.empty().append(previewColumn('Preview', 'The preview request failed. Try again.', true));
		});
	});

	$('.batch_card').on('click', '.btn_clear_log', function(e) {
		e.preventDefault();
		const $card = $(this).closest('.batch_card');
		$card.find('.batch_log_box').html('<div class="log_empty" style="color: #8c8f94; font-style: italic;">Log cleared.</div>');
	});
});
