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
                        <p><strong><?php echo sp__( "Your comment:" ); ?></strong><br/>
                            <textarea name="the_suggestion" cols="26" rows="6" class="form-item"
                                      value="<?php echo $this_comment; ?>"></textarea><br/><br/>
                            <strong><?php echo sp__( "Your email (optional):" ); ?></strong><br/>
                            <input type="text" name="name" size="20" value="<?php echo $this_name; ?>"
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