/**
 * Twee MCP — Editing time tracker
 *
 * Counts the seconds this admin tab is in front and was used in the last two minutes, and sends them when the
 * tab is hidden or closed, and every ten minutes on long visits.
 */
(function() {
	'use strict';

	const config = window.twcpTracking;

	if (!config) {
		return;
	}

	const tick = 5;
	const idle = 120000;
	const watched = new WeakSet();
	let input = Date.now();
	let active = 0;

	function used() {
		input = Date.now();
	}

	// Typing in the classic editor or the block editor canvas happens in a frame of the same site
	function watch(target) {
		if (!target || watched.has(target)) {
			return;
		}

		watched.add(target);
		['mousemove', 'mousedown', 'keydown', 'wheel', 'scroll', 'touchstart', 'input'].forEach((name) => {
			target.addEventListener(name, used, {passive: true, capture: true});
		});
	}

	function send() {
		if (!active) {
			return;
		}

		const data = new FormData();
		data.append('action', 'twcp_tracking_ping');
		data.append('nonce', config.nonce);
		data.append('seconds', String(active));
		data.append('post', String(config.post || 0));
		data.append('screen', config.screen || '');
		active = 0;

		if (!navigator.sendBeacon || !navigator.sendBeacon(config.url, data)) {
			fetch(config.url, {method: 'POST', body: data, credentials: 'same-origin', keepalive: true}).catch(() => {});
		}
	}

	watch(document);

	setInterval(() => {
		document.querySelectorAll('iframe').forEach((frame) => {
			try {
				watch(frame.contentDocument);
			} catch (error) {
				// Frames of other sites cannot be watched
			}
		});

		if (document.visibilityState === 'visible' && document.hasFocus() && Date.now() - input < idle) {
			active += tick;
		}
	}, tick * 1000);

	setInterval(send, 600000);

	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'hidden') {
			send();
		}
	});

	window.addEventListener('pagehide', send);
})();
