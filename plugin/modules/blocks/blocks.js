/**
 * Block descriptions on the Blocks tab: describes the page builder layouts from their fields and preview screenshots
 */
jQuery(function($) {
	'use strict';

	function sendAjax(action, data, onSuccess, onError) {
		return $.ajax({
			url: twcpAdmin.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: $.extend({action: action, nonce: twcpAdmin.nonce}, data || {}),
			success: onSuccess,
			error: onError
		});
	}

	// Describe every layout, one after another, and show each description as it comes
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

	// Describe one layout
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
});
