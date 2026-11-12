/**
 * Provides the click events for the layouts section in the flyout.
 *
 * @author little9 (Jamie Little)
 *
 * rewritten to be data-driven. A layout is three column widths in twelfths
 * ("left-main-right", adding up to 12, 0 = hidden column). The buttons in the flyout carry
 * their layout in data-layout (see SectionService::LAYOUTS); custom widths can also be applied.
 * Click handlers are bound once (section.js calls layout() on every section click, which used
 * to stack another handler each time).
 */
/*jslint browser: true*/
/*global $, jQuery, alert*/
function layout() {
	"use strict";

	var myLayout = {
		settings : {
			sectionDataUrl : 'helpers/section_data.php',
			updateSectionLayoutUrl : 'helpers/update_section_layout.php'
		},
		init : function() {
			$(document).ready(function() {
				myLayout.initialLayout();
				myLayout.layoutSections();
				myLayout.activateLayoutButtons();
				myLayout.highlightLayout($('.sp_section').first());
			});
		},

		initialLayout : function() {
			// Appends an initial section when a tab has none.
			if ($('[id^=section]').length == 0) {
				$.ajax({
					url : myLayout.settings.sectionDataUrl,
					type : 'POST',
					data : { action : 'create' },
					dataType : 'html',
					success : function(html) {
						$('div#tabs-0').append(html);
					}
				});
			}
		},

		/** Returns [l, m, r] for a valid layout string, otherwise null (same rules as the server). */
		parse : function(layoutCode) {
			var m = /^\s*(\d{1,2})-(\d{1,2})-(\d{1,2})\s*$/.exec(String(layoutCode || ''));
			if (!m) { return null; }
			var w = [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
			if (w[0] + w[1] + w[2] !== 12 || w[1] < 2) { return null; }
			for (var i = 0; i < 3; i++) { if (w[i] === 1 || w[i] > 12) { return null; } }
			return w;
		},

		pureClass : function(twelfths) {
			if (twelfths === 0) { return 'hidden-column'; }
			if (twelfths === 12) { return 'pure-u-1'; }
			var a = twelfths, b = 12, t;
			while (b) { t = b; b = a % b; a = t; }
			return 'pure-u-' + (twelfths / a) + '-' + (12 / a);
		},

		moveColumnContent : function(widths, sectionId) {
			// Boxes in a hidden column are moved to the end of the main column.
			var target = $('#section_' + sectionId + ' #container-1 .portal-column');
			$.each([0, 2], function(i, col) {
				if (widths[col] === 0) {
					target.append($('#section_' + sectionId + ' #container-' + col + ' .portal-column').children());
				}
			});
		},

		layoutSection : function(sectionId, layoutCode) {
			var widths = myLayout.parse(layoutCode);
			if (!widths) { widths = [4, 4, 4]; layoutCode = '4-4-4'; }
			for (var col = 0; col < 3; col++) {
				$('div#section_' + sectionId + ' div#container-' + col)
					.attr('class', '').addClass(myLayout.pureClass(widths[col]));
			}
			myLayout.moveColumnContent(widths, sectionId);
			$('div#section_' + sectionId).data('layout', layoutCode).attr('data-layout', layoutCode);
		},

		layoutSections : function() {
			$('div[id^="section_"]').each(function() {
				var sectionId = $(this).attr('id').split('section_')[1];
				myLayout.layoutSection(sectionId, String($(this).attr('data-layout') || $(this).data('layout')));
			});
		},

		/** Highlights the button (or fills in the custom widths) for the section's layout. */
		highlightLayout : function(section) {
			var code = (section && section.length) ? String(section.first().attr('data-layout')) : '';
			var buttons = $('#layout_options_container .layout-icon');
			buttons.removeClass('active-layout-icon').attr('aria-pressed', 'false');
			var match = buttons.filter(function() { return $(this).attr('data-layout') === code; });
			match.addClass('active-layout-icon').attr('aria-pressed', 'true');
			var w = myLayout.parse(code);
			for (var i = 0; i < 3; i++) { $('#custom_layout_' + i).val(w ? w[i] : ''); }
			$('#custom_layout_message').text(w && !match.length ? 'Current layout: custom (' + code + ')' : '');
		},

		selectedSectionId : function() {
			var id = $('#layout_options_content').data('selected-section');
			if (!id) {
				var sel = $('.section_selected_area').attr('id');
				id = sel ? sel.split('section_')[1] : '';
			}
			return id;
		},

		applyLayout : function(layoutCode) {
			var sectionId = myLayout.selectedSectionId();
			if (!sectionId || !myLayout.parse(layoutCode)) { return false; }
			myLayout.layoutSection(sectionId, layoutCode);
			myLayout.highlightLayout($('#section_' + sectionId));
			$('#autosave-spinner').show();
			$.ajax({
				url : myLayout.settings.updateSectionLayoutUrl,
				type : 'GET',
				data : { section_id : sectionId, layout : layoutCode }
			}).always(function() {
				$('#autosave-spinner').hide();
			});
			return true;
		},

		activateLayoutButtons : function() {
			if (window.spLayoutButtonsBound) { return; }
			window.spLayoutButtonsBound = true;

			$(document).on('click', '#layout_options_container .layout-icon', function() {
				myLayout.applyLayout($(this).attr('data-layout'));
			});
			$(document).on('keydown', '#layout_options_container .layout-icon', function(e) {
				if (e.which === 13 || e.which === 32) { e.preventDefault(); $(this).trigger('click'); }
			});
			$(document).on('click', '#custom_layout_apply', function() {
				var code = [0, 1, 2].map(function(i) { return parseInt($('#custom_layout_' + i).val() || '0', 10) || 0; }).join('-');
				if (!myLayout.parse(code)) {
					$('#custom_layout_message').text('The widths must add up to 12, the main column must be at least 2, and other columns 0 or at least 2.');
					return;
				}
				if (!myLayout.applyLayout(code)) {
					$('#custom_layout_message').text('Select a section first (click its arrows icon).');
				}
			});
		},

		// kept for older callers
		selectedLayout : function() {},
		bindUiActions : function() {},
		updateSectionLayout : function() {}
	};
	return myLayout;
}
