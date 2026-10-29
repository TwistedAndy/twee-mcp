/**
 * Stock image search on the Images tab: searches the connected providers and imports a photo into the Media Library
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

	// Search state, kept for the pagination
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

		// Text such as "Showing images 13–24 (Page 2)"
		if (itemsCount > 0) {
			const start = (page - 1) * perPage + 1;
			const end = start + itemsCount - 1;
			const label = (itemsCount === 1) ? ('Showing image ' + start + ' (Page ' + page + ')') : ('Showing images ' + start + '–' + end + ' (Page ' + page + ')');
			$info.text(label);
		} else {
			$info.text('Page ' + page + ' (0 images)');
		}

		// Remember the highest page seen so far
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
