<?php
// accessibility check of this guide and its review status
?>
<div id="check_options_content" class="second-level-content" style="display: none;">
	<h3><?php print _("Review"); ?></h3>
	<div id="sp-review-box" class="sp-an"></div>

	<h3><?php print _("Accessibility check"); ?></h3>
	<div class="sp-an">
		<p class="sp-an-note"><?php print _("Checks the saved boxes of this guide for common problems (WCAG 2.1 AA): missing image descriptions, unclear link text, heading order, tables, colour contrast. Save the guide first to check recent changes."); ?></p>
		<button type="button" id="sp-a11y-run" class="pure-button pure-button-primary"><?php print _("Check this guide"); ?></button>
		<div id="sp-a11y-body" aria-live="polite"></div>
	</div>
</div>
