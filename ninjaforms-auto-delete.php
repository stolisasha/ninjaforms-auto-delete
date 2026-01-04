<?php
/**
 * Plugin Name: Ninja Forms - Auto Delete
 * Description: Löscht Einträge und Dateianhänge automatisch nach einer festgelegten Anzahl von Tagen. 
 * Version: 1.1.0
 * Author: Alex Schlair
 * Author URI: https://www.pronto-media.at
 * Text Domain: nf-auto-delete
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Versionierung und Pfade
$nf_ad_plugin_data = get_file_data( __FILE__, [ 'Version' => 'Version' ], 'plugin' );
define( 'NF_AD_VERSION', $nf_ad_plugin_data['Version'] );
define( 'NF_AD_DB_VERSION', '1.2' );
define( 'NF_AD_PATH', plugin_dir_path( __FILE__ ) );

// 1. FAIL-SAFE PHP CHECK
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p><strong>Auto Delete for Ninja Forms</strong> benötigt PHP 7.4 oder höher.</p></div>';
    } );
    return;
}

// 2. BASIS-KLASSE LADEN
// Der Logger muss IMMER verfügbar sein, da der Activation-Hook ihn für DB-Checks braucht,
// selbst wenn Ninja Forms gerade nicht aktiv ist.
require_once NF_AD_PATH . 'includes/class-nf-ad-logger.php';

// 3. INIT & SAFE LOADING
add_action( 'plugins_loaded', 'nf_ad_init', 20 );

function nf_ad_init() {
    // Sicherheitscheck: Ist Ninja Forms überhaupt aktiv?
    // Falls nein: Still beenden. Kein Fatal Error, keine Deaktivierung durch WP.
    if ( ! class_exists( 'Ninja_Forms' ) ) {
        return;
    }

    // Erst JETZT laden wir die Logik-Klassen.
    // Das garantiert, dass wir keine NF-Klassen aufrufen, wenn NF fehlt.
    require_once NF_AD_PATH . 'includes/class-nf-ad-uploads-deleter.php';
    require_once NF_AD_PATH . 'includes/class-nf-ad-submissions-eraser.php';
    require_once NF_AD_PATH . 'includes/class-nf-ad-dashboard.php';

    // Cron Hook (Action muss immer gebunden sein)
    add_action( 'nf_ad_daily_event', [ 'NF_AD_Submissions_Eraser', 'run_cleanup_cron' ] );

    // Dashboard & Logik Hooks
    if ( is_admin() ) {
        add_action( 'admin_menu', [ 'NF_AD_Dashboard', 'register_menu' ] );
        add_action( 'wp_ajax_nf_ad_retry_delete', [ 'NF_AD_Dashboard', 'ajax_retry_delete' ] );
        add_action( 'wp_ajax_nf_ad_clear_logs', [ 'NF_AD_Dashboard', 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_nf_ad_force_cleanup', [ 'NF_AD_Dashboard', 'ajax_force_cleanup' ] );
        add_action( 'wp_ajax_nf_ad_calculate', [ 'NF_AD_Dashboard', 'ajax_calculate' ] );

        // DB-Update Check (Safe, da Logger immer geladen ist)
        add_action( 'admin_init', [ 'NF_AD_Logger', 'maybe_update_db' ] );
    }

    // ---------------------------------------------------------
    // SELF-HEALING: Cron-Existenz erzwingen
    // ---------------------------------------------------------
    // Wenn der Cron aktiv ist, aber WP das Event „vergessen“ hat,
    // wird es beim nächsten Seitenaufruf automatisch neu registriert.
    $settings  = get_option( 'nf_ad_settings', [] );
    $is_active = ! empty( $settings['cron_active'] );

    if ( $is_active && ! wp_next_scheduled( 'nf_ad_daily_event' ) ) {
        // Wunsch-Stunde holen (Fallback: 3 Uhr nachts)
        $cron_hour = isset( $settings['cron_hour'] ) ? (int) $settings['cron_hour'] : 3;

        // Nächsten Ausführungszeitpunkt in WP-Zeitzone berechnen
        $tz     = wp_timezone();
        $now    = new DateTimeImmutable( 'now', $tz );
        $target = $now->setTime( $cron_hour, 0, 0 );

        // Wenn die Zeit heute schon vorbei ist, planen wir für morgen
        if ( $target->getTimestamp() <= $now->getTimestamp() ) {
            $target = $target->modify( '+1 day' );
        }

        wp_schedule_event( $target->getTimestamp(), 'daily', 'nf_ad_daily_event' );
    }
}

// 4. ACTIVATION / DEACTIVATION
register_activation_hook( __FILE__, 'nf_ad_activate' );
function nf_ad_activate() {
    // Cron resetten
    wp_clear_scheduled_hook( 'nf_ad_daily_event' );
    
    // DB erstellen (Logger Klasse ist sicher geladen)
    NF_AD_Logger::install_table();
    
    update_option( 'nf_ad_db_version', NF_AD_DB_VERSION );
}

register_deactivation_hook( __FILE__, 'nf_ad_deactivate' );
function nf_ad_deactivate() {
    $timestamp = wp_next_scheduled( 'nf_ad_daily_event' );
    if ( $timestamp ) wp_unschedule_event( $timestamp, 'nf_ad_daily_event' );
}