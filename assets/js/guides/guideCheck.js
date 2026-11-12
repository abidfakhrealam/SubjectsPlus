/**
 * editor "Check" panel — accessibility problems in this guide's boxes and the guide's review state.
 */
(function ($) {
	'use strict';
	var sid = function () { return $('#guide-parent-wrap').data('subject-id'); };

	function review() {
		var $b = $('#sp-review-box');
		$.getJSON('./helpers/guide_check.php', { subject_id: sid(), what: 'review' }).done(function (r) {
			$b.empty();
			if (!r.ok) { $b.text(r.message || ''); return; }
			var g = r.review;
			$b.append($('<p></p>').text(g.last_reviewed ? 'Last reviewed: ' + g.last_reviewed + (g.reviewed_by ? ' by ' + g.reviewed_by : '') : 'Not reviewed yet.'));
			if (g.due) {
				$b.append($('<p></p>').addClass('sp-rev-' + g.state).text((g.state === 'overdue' ? 'Review overdue since ' : 'Next review due: ') + g.due + ' (every ' + g.months + ' months)'));
			}
			$('<button type="button" class="pure-button pure-button-primary"></button>').text('Mark as reviewed today').appendTo($b).on('click', function () {
				$.ajax({ url: './helpers/guide_check.php', type: 'POST', dataType: 'json', data: { subject_id: sid(), what: 'reviewed' } }).always(review);
			});
		});
	}

	$(document).on('click', '#show_check_options', review);
	$(document).on('click', '#sp-a11y-run', function () {
		var $b = $('#sp-a11y-body').text('Checking…');
		$.getJSON('./helpers/guide_check.php', { subject_id: sid(), what: 'a11y' }).done(function (r) {
			$b.empty();
			if (!r.problems.length) { $b.append($('<p class="sp-a11y-ok"></p>').text('No problems found in the saved boxes.')); return; }
			$b.append($('<p></p>').text(r.problems.length + ' problem(s) found:'));
			var $ul = $('<ul class="sp-a11y-list"></ul>').appendTo($b);
			r.problems.forEach(function (p) {
				var $li = $('<li></li>').appendTo($ul);
				$('<a href="#"></a>').text(p.tab + ' › ' + (p.box || 'box ' + p.pluslet_id)).appendTo($li).on('click', function (e) {
					e.preventDefault();
					var $tab = $('#tabs .ui-tabs-nav li').eq(p.tab_index).find('a');
					if ($tab.length) { $tab.trigger('click'); }
					var $box = $('#pluslet-' + p.pluslet_id);
					if ($box.length) {
						$('html,body').animate({ scrollTop: $box.offset().top - 80 }, 300);
						$box.addClass('sp-a11y-flash');
						setTimeout(function () { $box.removeClass('sp-a11y-flash'); }, 2500);
					}
				});
				$('<div class="sp-a11y-msg"></div>').text(p.message).appendTo($li);
				$('<code></code>').text(p.snippet).appendTo($li);
			});
		}).fail(function () { $b.text('The check could not be run.'); });
	});
})(jQuery);
