<div id="analytics_options_content" class="second-level-content" style="display: none;">

	<h3><?php print _("Analytics"); ?></h3>
	<?php // rebuilt — views, tabs, boxes, links and databases for this guide, with an Excel download ?>
	<div class="sp-an">
		<label for="sp-an-days" class="sp-an-label"><?php print _("Period"); ?></label>
		<select id="sp-an-days">
			<option value="30"><?php print _("Last 30 days"); ?></option>
			<option value="90" selected><?php print _("Last 90 days"); ?></option>
			<option value="365"><?php print _("Last 12 months"); ?></option>
			<option value="0"><?php print _("All time"); ?></option>
		</select>
		<a id="sp-an-xlsx" class="sp-an-xlsx" href="#"><?php print _("Download as Excel"); ?></a>
		<div id="sp-an-body" aria-live="polite"></div>
		<p class="sp-an-note"><?php print _("Counts come from visitors' browsers; ad blockers and private browsing reduce them. For downloads and searches inside databases, use the vendors' COUNTER reports."); ?></p>
	</div>
</div>
