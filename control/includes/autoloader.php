<?php

    // never show PHP warnings/notices to visitors (they reveal server paths and confuse users).
    // They go to the PHP error log instead. Set $sp_debug = TRUE in config.php to show them while developing.
    if ( ! function_exists( 'sp_apply_error_policy' ) ) {
        function sp_apply_error_policy() {
            $debug = ! empty( $GLOBALS['sp_debug'] );
            ini_set( 'display_errors', $debug ? '1' : '0' );
            ini_set( 'log_errors', '1' );
            error_reporting( $debug ? E_ALL : ( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED ) );
        }
    }
    sp_apply_error_policy();

    require_once __DIR__ . '/i18n.php'; // public-page language (sp__)

    // Composer-managed libraries (PHPMailer, phpseclib, ...). Run `composer install` after updating composer.json.
    require_once dirname(dirname(__DIR__)) . '/lib/vendor/autoload.php';

    require_once dirname(dirname(__DIR__)).'/lib/Symfony/Component/ClassLoader/UniversalClassLoader.php';
    
    use Symfony\Component\ClassLoader\UniversalClassLoader;
    
    $loader = new UniversalClassLoader();
   
    
    $loader->registerNamespace('Assetic',  dirname(dirname(__DIR__)) . '/lib');
    $loader->registerNamespace('SubjectsPlus',  dirname(dirname(__DIR__)) . '/lib');
    $loader->registerNamespace('CSSMin',  dirname(dirname(__DIR__)) . '/lib');
    $loader->registerNamespace('RichterLibrary', dirname(dirname(__DIR__)) . '/lib');
    $loader->registerNamespace('ReCaptcha', dirname(dirname(__DIR__)) . '/lib');


    $loader->register();

    // HTMLPurifier is now loaded by Composer (ezyang/htmlpurifier) via lib/vendor/autoload.php

?>