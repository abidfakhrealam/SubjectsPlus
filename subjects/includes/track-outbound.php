<?php
/* count clicks on links that leave SubjectsPlus (databases on the A–Z list, home page, record pages)
   for the usage reports. Guide pages already have their own tracker (track.js), so this stays quiet there.
   Only the link address and text are sent; no cookies. */
global $PublicPath;
$sp_track_url = ( isset( $PublicPath ) && $PublicPath !== '' ? $PublicPath : '' ) . 'track.php';
?>
<script>
(function () {
	document.addEventListener('click', function (e) {
		if (window.track && window.track.trackLinks) { return; }
		var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
		if (!a || !/^https?:/i.test(a.href) || a.host === location.host) { return; }
		var q = 'event_type=database&link_url=' + encodeURIComponent(a.href)
			+ '&link_title=' + encodeURIComponent((a.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 190))
			+ '&page_title=' + encodeURIComponent(document.title.slice(0, 190));
		var u = <?php echo json_encode( $sp_track_url ); ?> + '?' + q;
		try { fetch(u, { keepalive: true, credentials: 'omit' }); } catch (x) { (new Image()).src = u; }
	}, true);
})();
</script>
