<?php
/**
 * Created by PhpStorm.
 * User: pvillanueva
 * Date: 06/05/18
 */
?>

<?php
// 4.7.1: the theme loaded modernizr.js and js-offcanvas from another library's web site (a third-party
// script with full access to every page). Off-canvas now uses the plugin only if you add it locally,
// e.g. assets/jquery/libs/js-offcanvas.pkgd.js, and falls back to a simple toggle otherwise.
if ( is_file( dirname( __DIR__, 3 ) . '/assets/jquery/libs/js-offcanvas.pkgd.js' ) ) {
	echo '<script src="' . htmlspecialchars( $AssetPath . 'jquery/libs/js-offcanvas.pkgd.js', ENT_QUOTES ) . '"></script>';
}
?>

<script>
    // Append Around: https://github.com/filamentgroup/AppendAround

    (function( $ ){
        $.fn.appendAround = function(){
            return this.each(function(){
                var $self = $( this ),
                    att = "data-set",
                    $parent = $self.parent(),
                    parent = $parent[ 0 ],
                    attval = $parent.attr( att ),
                    $set = $( "["+ att +"='" + attval + "']" );
                function isHidden( elem ){
                    return $(elem).css( "display" ) === "none";
                }
                function appendToVisibleContainer(){
                    if( isHidden( parent ) ){
                        var found = 0;
                        $set.each(function(){
                            if( !isHidden( this ) && !found ){
                                $self.appendTo( this );
                                found++;
                                parent = this;
                            }
                        });
                    }
                }
                appendToVisibleContainer();
                $(window).bind( "resize", appendToVisibleContainer );
            });
        };
    }( jQuery ));

    // Off Canvas: https://github.com/vmitsaras/js-offcanvas/
    $( function(){
        $( document ).bind( "create.offcanvas", function( e ){
            $(e.target).removeClass('is-hidden');
        } );
        if ( ! $.fn.offcanvas ) {
            // no plugin: the menu button shows / hides the sidebar
            $( '.js-sidebar-toggler' ).attr( 'aria-controls', 'left' ).attr( 'aria-expanded', 'false' ).on( 'click', function( e ){
                e.preventDefault();
                var open = $( '#left' ).toggleClass( 'is-hidden' ).hasClass( 'is-hidden' ) === false;
                $( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
                $( '.nav-icon' ).toggleClass( 'open-nav-icon', open );
            } );
            $( ".js-append-around" ).appendAround();
            return;
        }
        $( '#left' ).offcanvas( {
            modifiers: 'left,overlay',
            triggerButton: '.js-sidebar-toggler',
            resize: true
        } )
            .on( "resizing.offcanvas", function( e ){
                $('.nav-icon').removeClass('open-nav-icon');
            })
            .on( "close.offcanvas", function( e ){
                $('.nav-icon').removeClass('open-nav-icon');
            })
            .on( "open.offcanvas", function( e ){
                $('.nav-icon').addClass('open-nav-icon');
            });
        $( ".js-append-around" ).appendAround();
        $( document ).trigger( "enhance" );
    });

</script>