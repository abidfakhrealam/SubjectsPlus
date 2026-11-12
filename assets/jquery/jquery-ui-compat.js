/*
 * compatibility for SubjectsPlus code written for jQuery UI 1.9.
 * jQuery UI 1.10+ removed the tabs "select" method; SubjectsPlus uses it in the guide editor and on
 * public guide pages (opening a tab from the address, e.g. guide.php#tab-3).
 * Same behaviour as 1.9: a number selects by position, a string selects the tab whose link ends with it.
 */
(function ($) {
	if (!$ || !$.ui || !$.ui.tabs || $.ui.tabs.prototype.select) { return; }
	$.widget('ui.tabs', $.ui.tabs, {
		select: function (index) {
			if (typeof index === 'string') {
				var wanted = index;
				index = this.anchors.index(this.anchors.filter(function () {
					var href = this.getAttribute('href') || '';
					return href.slice(-wanted.length) === wanted;
				}));
			}
			index = parseInt(index, 10);
			if (isNaN(index) || index < 0) { return this; }
			this.option('active', index);
			return this;
		}
	});
}(jQuery));
