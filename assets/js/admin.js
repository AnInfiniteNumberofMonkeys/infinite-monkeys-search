/**
 * Infinite Monkeys Search - settings page: re-index button and status.
 */
(function ($) {
	'use strict';

	var cfg = window.imsearchAdmin;
	if (!cfg) {
		return;
	}

	var $btn = $('#imsearch-reindex');
	var $progress = $('.imsearch-progress');
	var $bar = $progress.find('progress');
	var $text = $progress.find('.imsearch-progress-text');
	var $error = $('.imsearch-error');
	var running = false;

	function render(s) {
		var building = s.status === 'building';
		$('[data-imsearch="state"]').text(building ? cfg.i18n.building : cfg.i18n.idle);
		$('[data-imsearch="docs"]').text(s.docs);
		$('[data-imsearch="words"]').text(s.words);
		$('[data-imsearch="finished_h"]').text(s.finished_h || cfg.i18n.never);
		$('[data-imsearch="change_h"]').text(s.change_h || cfg.i18n.never);
		$('[data-imsearch="next_h"]').text(s.next_h || cfg.i18n.off);
		$progress.prop('hidden', !building);
		$bar.val(s.percent || 0);
		$text.text((s.done || 0) + ' / ' + (s.total || 0));
		$btn.prop('disabled', building);
		if (s.error) {
			$error.text(cfg.i18n.error + ' ' + s.error).prop('hidden', false);
		} else {
			$error.prop('hidden', true);
		}
	}

	function post(action) {
		return $.post(cfg.ajax, { action: action, nonce: cfg.nonce });
	}

	function loop() {
		running = true;
		post('imsearch_step')
			.done(function (r) {
				if (!r || !r.success) {
					running = false;
					return;
				}
				render(r.data);
				if (r.data.status === 'building') {
					setTimeout(loop, 300);
				} else {
					running = false;
				}
			})
			.fail(function () {
				running = false;
				// Retry: a step may have timed out on a slow host.
				setTimeout(loop, 3000);
			});
	}

	$btn.on('click', function () {
		if (running) {
			return;
		}
		$btn.prop('disabled', true);
		$progress.prop('hidden', false);
		post('imsearch_reindex').done(function (r) {
			if (r && r.success) {
				render(r.data);
				if (r.data.status === 'building') {
					loop();
				}
			} else {
				$btn.prop('disabled', false);
			}
		}).fail(function () {
			$btn.prop('disabled', false);
		});
	});

	var initial = cfg.status;
	initial.finished_h = $('[data-imsearch="finished_h"]').text();
	initial.change_h = $('[data-imsearch="change_h"]').text();
	initial.next_h = $('[data-imsearch="next_h"]').text();
	render(initial);
	if (initial.status === 'building') {
		loop();
	}
})(jQuery);
