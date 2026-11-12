<?php
// language of the public pages (English / हिन्दी …). Staff pages always use English.
// A visitor picks a language with ?lang=hi (remembered in a cookie); the default is
// $sp_public_language in config.php. Translations: assets/locale/<code>/public.php.
if ( ! function_exists( 'sp_public_lang' ) ) {
    function sp_public_lang() {
        static $lang = null;
        if ( $lang !== null ) {
            return $lang;
        }
        $langs  = sp_public_languages();
        $script = str_replace( '\\', '/', (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) );
        if ( PHP_SAPI === 'cli' || strpos( $script, '/subjects/' ) === false ) {
            return $lang = 'en';
        }
        $want = isset( $_GET['lang'] ) ? (string) $_GET['lang'] : ( isset( $_COOKIE['sp_lang'] ) ? (string) $_COOKIE['sp_lang'] : (string) ( $GLOBALS['sp_public_language'] ?? 'en' ) );
        if ( isset( $_GET['lang'] ) && isset( $langs[ $want ] ) && ! headers_sent() ) {
            setcookie( 'sp_lang', $want, array( 'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax' ) );
        }

        return $lang = isset( $langs[ $want ] ) ? $want : 'en';
    }
    function sp_public_languages() {
        $l = $GLOBALS['sp_public_languages'] ?? array( 'en' => 'English' );

        return is_array( $l ) && $l ? $l : array( 'en' => 'English' );
    }
    /** Translate a public-page string (falls back to gettext / English). */
    function sp__( $s ) {
        static $maps = array();
        $l = sp_public_lang();
        if ( $l === 'en' ) {
            return _( $s );
        }
        if ( ! isset( $maps[ $l ] ) ) {
            $f          = dirname( dirname( __DIR__ ) ) . '/assets/locale/' . preg_replace( '/[^a-z_-]/i', '', $l ) . '/public.php';
            $maps[ $l ] = is_file( $f ) ? (array) include $f : array();
        }

        return $maps[ $l ][ $s ] ?? ( $maps[ $l ][ trim( $s ) ] ?? _( $s ) );
    }
    /** "English | हिन्दी" links for the public header. */
    function sp_lang_switcher() {
        $langs = sp_public_languages();
        if ( count( $langs ) < 2 || ( isset( $GLOBALS['sp_public_language_switch'] ) && ! $GLOBALS['sp_public_language_switch'] ) ) {
            return '';
        }
        $cur   = sp_public_lang();
        $links = array();
        foreach ( $langs as $code => $name ) {
            $q           = $_GET;
            $q['lang']   = $code;
            $url         = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ) . '?' . http_build_query( $q );
            $links[]     = $code === $cur
                ? '<strong lang="' . $code . '" aria-current="true">' . htmlspecialchars( $name ) . '</strong>'
                : '<a href="' . htmlspecialchars( $url ) . '" lang="' . $code . '" hreflang="' . $code . '">' . htmlspecialchars( $name ) . '</a>';
        }

        return '<nav class="sp-lang" aria-label="Language / भाषा">' . implode( ' <span aria-hidden="true">|</span> ', $links ) . '</nav>';
    }
}
