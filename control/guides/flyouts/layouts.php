
<div id="layout_options_content" class="second-level-content"
     style="display: none;">
    <h3><?php print _("Choose Layout"); ?></h3>
    <p id="select_section_message"><?php print _("Please select a section first. Click the arrows icon on the appropriate section so that it turns pink."); ?> <i class="fa fa-arrows"></i>
        <br><br>
        <img
                src="<?php print $AssetPath; ?>images/admin/section-selector.png"
                alt="<?php print _("Highlighted 4 arrows icon example"); ?>"  />
    </p>
    <div id="layout_options_container">
        <?php
        // layouts come from SectionService::LAYOUTS; icons are drawn from the column widths.
        $sp_layout_ids = array( '0-12-0' => 'col-single', '6-6-0' => 'col-double', '4-8-0' => 'col-48',
                                '8-4-0' => 'col-84', '4-4-4' => 'col-triple', '3-6-3' => 'col-363' );
        foreach ( \SubjectsPlus\Control\Guide\SectionService::LAYOUTS as $sp_group => $sp_layouts ) {
            print '<h4 class="layout-group">' . _( $sp_group ) . "</h4>\n<ul class=\"layout_options\">\n";
            foreach ( $sp_layouts as $sp_code => $sp_label ) {
                $sp_id = isset( $sp_layout_ids[ $sp_code ] ) ? $sp_layout_ids[ $sp_code ] : 'col-' . $sp_code;
                print '<li class="layout-icon layout-gen" id="' . $sp_id . '" data-layout="' . $sp_code . '" title="'
                    . htmlspecialchars( _( $sp_label ) ) . '" role="button" tabindex="0" aria-label="'
                    . htmlspecialchars( _( $sp_label ) ) . '">';
                foreach ( explode( '-', $sp_code ) as $sp_w ) {
                    if ( (int) $sp_w > 0 ) {
                        print '<span style="flex:' . (int) $sp_w . '"></span>';
                    }
                }
                print "</li>\n";
            }
            print "</ul>\n";
        }
        ?>
        <h4 class="layout-group"><?php print _("Custom widths"); ?></h4>
        <div id="custom_layout">
            <p class="layout-help"><?php print _("Column widths in twelfths (left, main, right). They must add up to 12; use 0 to hide the left or right column."); ?></p>
            <input type="number" min="0" max="12" id="custom_layout_0" aria-label="<?php print _("Left column"); ?>" />
            <input type="number" min="2" max="12" id="custom_layout_1" aria-label="<?php print _("Main column"); ?>" />
            <input type="number" min="0" max="12" id="custom_layout_2" aria-label="<?php print _("Right column"); ?>" />
            <button type="button" id="custom_layout_apply" class="pure-button pure-button-primary"><?php print _("Apply"); ?></button>
            <p id="custom_layout_message" class="layout-help" role="status"></p>
        </div>
    </div>


    <h3><?php print _("Add New Section"); ?></h3>
    <ul class="layout_options">
        <li class="top-panel-option-item"><a id="add_section" href="#"><img
                    src="<?php print $AssetPath; ?>images/icons/section2.svg"
                    title="<?php print _("New Section"); ?>" class="custom-icon" /></a></li>
    </ul>
</div>