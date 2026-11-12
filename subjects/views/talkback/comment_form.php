<!-- start pluslet -->
<div class="pluslet">
    <div class="titlebar">
        <div class="titlebar_text"><?php print sp__( "Tell Us What You Think" ); ?>
        </div>
    </div>
    <div class="pluslet_body">
        <p><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
			<?php print sp__( "<strong>Wait! Do you need help right now?</strong><br>Visit the Research Desk!" ); ?>
        </p>
        <br>
        <div class="tip">
            <h2><?php echo sp__("Need help <strong>now</strong>? <br/><a href=''>Ask a Librarian</a>.</h2>"); ?>
                <form id="tellus" action="<?php echo $form_action; ?>" method="post" class="pure-form">
                    <div class="talkback_form <?php echo $tb_bonus_css; ?>">
                        <p><label for="tb_comment"><strong><?php echo sp__( "Your comment:" ); ?></strong></label><br/>
                            <textarea name="the_suggestion" id="tb_comment" cols="26" rows="6" class="form-item"><?php echo htmlspecialchars( html_entity_decode( (string) $this_comment, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' ); // 4.7.1: was echoed raw (reflected XSS) ?></textarea><br/><br/>
                            <label for="tb_email"><strong><?php echo sp__( "Your email (optional):" ); ?></strong></label><br/>
                            <input type="text" name="name" id="tb_email" size="20" value="<?php echo htmlspecialchars( html_entity_decode( (string) $this_name, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' ); // 4.7.1: was echoed raw (reflected XSS) ?>"
                                   class="form-item"/>
                            <br/>
						    <?php echo sp__( "(In case we need to contact you)" ); ?>
                            <br/><br/>

                            <button type="submit" name="submit_comment" class="pure-button pure-button-topsearch"><?php echo sp__( "Submit" ); ?></button>
                        </p>
                    </div>
                </form>
        </div>
        <div class="tipend"></div>

    </div>
</div>
<!-- end pluslet -->