/**
 * Editor Analytics panel.
 * rebuilt to show page views, tab clicks, clicks per box, top links and database clicks for this
 * guide over a chosen period, with an Excel download. All text is inserted as text (the old version
 * inserted tab names from the statistics as HTML).
 */
/* global $, jQuery */
function analytics() {
	var my = {
		url: './helpers/guide_analytics.php',
		subjectId: function () { return $('#guide-parent-wrap').data('subject-id'); },
		init: function () {
			$(document).on('change', '#sp-an-days', my.load);
			$(document).on('click', '#show_analytics_options', my.load);
			$(document).on('click', '#sp-an-xlsx', function (e) {
				e.preventDefault();
				window.location = my.url + '?format=xlsx&subject_id=' + encodeURIComponent(my.subjectId()) + '&days=' + $('#sp-an-days').val();
			});
		},
		table: function (title, rows, cols) {
			var $s = $('<div class="sp-an-sec"></div>').append($('<h4></h4>').text(title));
			if (!rows.length) { return $s.append($('<p class="sp-an-none"></p>').text('None yet.')); }
			var $t = $('<table class="sp-an-table"></table>');
			rows.forEach(function (r) {
				var $tr = $('<tr></tr>');
				cols.forEach(function (c, i) { $tr.append($(i === cols.length - 1 ? '<td class="n"></td>' : '<td></td>').text(r[c])); });
				$t.append($tr);
			});
			return $s.append($t);
		},
		load: function () {
			var $b = $('#sp-an-body').text('Loading…');
			$.getJSON(my.url, { subject_id: my.subjectId(), days: $('#sp-an-days').val() }).done(function (d) {
				$b.empty();
				$b.append($('<p class="sp-an-total"></p>').text('Page views: ' + d.views));
				$b.append(my.table('Views by month', d.months, ['month', 'views']));
				$b.append(my.table('Tabs clicked', d.tabs, ['tab', 'clicks']));
				$b.append(my.table('Link clicks by box', d.boxes, ['box', 'clicks']));
				$b.append(my.table('Databases opened from this guide', d.databases, ['database', 'clicks']));
				$b.append(my.table('Top links', d.links, ['text', 'clicks']));
			}).fail(function () { $b.text('Could not load the statistics.'); });
		}
	};
	return my;
}
