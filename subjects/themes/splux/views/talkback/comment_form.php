<div>
    <h4><?php echo _("Need help now? Contact Us."); ?></h4>
    <form id="tellus" action="<?php echo $form_action; ?>" method="post">
        <div class="talkback_form <?php echo $tb_bonus_css; ?>">
            <p class="mb-2"><label for="the_suggestion"><strong><?php echo _( "Your comment:" ); ?></strong></label></p>
            <textarea name="the_suggestion" id="the_suggestion" cols="26" rows="6" class="form-item"><?php echo htmlspecialchars( html_entity_decode( (string) $this_comment, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' ); // 4.7.1: was echoed raw (reflected XSS) ?></textarea>
            <p class="mb-2 mt-3"><label for="suggestion_email"><strong><?php echo _( "Your email (optional):" ); ?></strong></label></p>
            <input type="text" id="suggestion_email" name="suggestion_email" size="20" value="<?php echo htmlspecialchars( html_entity_decode( (string) $this_name, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' ); // 4.7.1: was echoed raw (reflected XSS) ?>" class="form-item"/>
            <p style="font-size:.875rem;"><em><?php echo _( "(In case we need to contact you)" ); ?></em></p>
                <button type="submit" name="submit_comment" class="pure-button pure-button-topsearch btn-default"><?php echo _( "Submit" ); ?></button>
        </div>
    </form>
</div>