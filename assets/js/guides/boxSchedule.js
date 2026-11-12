/**
 * show/hide dates for guide boxes. The dates live in the box settings panel
 * (Pluslet::scheduleSettings) and are saved on their own with "Save dates".
 * Scheduled boxes get a small label in their title bar so editors can see them at a glance.
 */
(function ($) {
	'use strict';

	function fmt(d) {
		if (!d) { return ''; }
		var p = d.split('-'), m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		return parseInt(p[2], 10) + ' ' + m[parseInt(p[1], 10) - 1] + ' ' + p[0];
	}

	function label($wrap) {
		var from = $wrap.find('.sched-from').val(), to = $wrap.find('.sched-to').val();
		var $box = $wrap.closest('.pluslet');
		$box.find('.sp-sched-badge').remove();
		if (!from && !to) { return; }
		var today = new Date().toISOString().slice(0, 10);
		var hidden = (from && today < from) || (to && today > to);
		var text = from && to ? fmt(from) + ' – ' + fmt(to) : (from ? 'from ' + fmt(from) : 'until ' + fmt(to));
		var $b = $('<span class="sp-sched-badge"></span>').toggleClass('sp-sched-off', !!hidden)
			.attr('title', hidden ? 'Not shown on the public page today' : 'Shown on the public page today')
			.text((hidden ? 'Hidden now · ' : 'Scheduled · ') + text);
		var $t = $box.find('.titlebar_text').first();
		($t.length ? $t : $box.children().first()).append(' ').append($b);
	}

	$(function () {
		$('.sp-schedule').each(function () { label($(this)); });
	});

	$(document).on('click', '.sp-schedule .sched-save', function () {
		var $w = $(this).closest('.sp-schedule'), $msg = $w.find('.sched-msg');
		$msg.text('…');
		$.ajax({
			url: 'helpers/box_schedule.php', type: 'POST', dataType: 'json',
			data: { pluslet_id: $w.data('pluslet'), show_from: $w.find('.sched-from').val(), hide_after: $w.find('.sched-to').val() }
		}).done(function (r) {
			$msg.text(r.message || '');
			if (r.ok) { label($w); }
		}).fail(function (x) {
			var m = 'Could not save.';
			try { m = JSON.parse(x.responseText).message || m; } catch (e) {}
			$msg.text(m);
		});
	});
})(jQuery);
