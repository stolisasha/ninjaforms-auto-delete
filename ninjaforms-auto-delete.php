<?php
/**
 * Plugin Name: Ninja Forms - Auto Delete
 * Description: Löscht Einträge und Dateianhänge automatisch nach einer festgelegten Anzahl von Tagen. 
 * Version: 1.2.1
 * Author: Alex Schlair
 * Author URI: https://www.pronto-media.at
 * Text Domain: nf-auto-delete
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =============================================================================
// KONSTANTEN
// =============================================================================

/* --- Versionierung & Pfade --- */
$nf_ad_plugin_data = get_file_data( __FILE__, [ 'Version' => 'Version' ], 'plugin' );
define( 'NF_AD_VERSION', $nf_ad_plugin_data['Version'] );
define( 'NF_AD_DB_VERSION', '1.2' );
define( 'NF_AD_PATH', plugin_dir_path( __FILE__ ) );

// =============================================================================
// SYSTEMANFORDERUNGEN
// =============================================================================

/* --- PHP-Version prüfen --- */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p><strong>Auto Delete for Ninja Forms</strong> benötigt PHP 7.4 oder höher.</p></div>';
    } );
    return;
}

// =============================================================================
// BOOTSTRAP
// =============================================================================

/* --- Kernklassen laden --- */

// Der Logger wird vorab geladen, da er für Aktivierung und DB-Initialisierung benötigt wird.
require_once NF_AD_PATH . 'includes/class-nf-ad-logger.php';

// =============================================================================
// INITIALISIERUNG
// =============================================================================

/* --- Plugin-Hooks registrieren --- */
add_action( 'plugins_loaded', 'nf_ad_init', 20 );

/**
 * Initialisiert das Plugin, sobald alle Plugins geladen sind.
 *
 * Lädt abhängige Klassen erst, wenn Ninja Forms verfügbar ist, und registriert
 * alle benötigten Hooks (Cron, Admin-Menü, AJAX-Endpunkte).
 *
 * @return void
 */
function nf_ad_init() {
    // Abhängigkeit: Ninja Forms muss aktiv sein.
    if ( ! class_exists( 'Ninja_Forms' ) ) {
        return;
    }

    // Abhängige Klassen erst nach erfolgreichem Abhängigkeits-Check laden.
    require_once NF_AD_PATH . 'includes/class-nf-ad-uploads-deleter.php';
    require_once NF_AD_PATH . 'includes/class-nf-ad-submissions-eraser.php';
    require_once NF_AD_PATH . 'includes/class-nf-ad-dashboard.php';

    // Cron-Hook registrieren.
    add_action( 'nf_ad_daily_event', [ 'NF_AD_Submissions_Eraser', 'run_cleanup_cron' ] );

    // Admin-Hooks und AJAX-Endpunkte registrieren.
    if ( is_admin() ) {
        add_action( 'admin_menu', [ 'NF_AD_Dashboard', 'register_menu' ] );
        add_action( 'wp_ajax_nf_ad_retry_delete', [ 'NF_AD_Dashboard', 'ajax_retry_delete' ] );
        add_action( 'wp_ajax_nf_ad_clear_logs', [ 'NF_AD_Dashboard', 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_nf_ad_force_cleanup', [ 'NF_AD_Dashboard', 'ajax_force_cleanup' ] );
        add_action( 'wp_ajax_nf_ad_calculate', [ 'NF_AD_Dashboard', 'ajax_calculate' ] );

        // DB-Update Check (Safe, da Logger immer geladen ist)
        add_action( 'admin_init', [ 'NF_AD_Logger', 'maybe_update_db' ] );
    }

    /* --- Cron-Event validieren (Self-Healing) --- */
    // Re-Registrierung, falls Cron aktiv ist, aber kein Event geplant ist.
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

// =============================================================================
// AKTIVIERUNG / DEAKTIVIERUNG
// =============================================================================

/* --- Aktivierung --- */
register_activation_hook( __FILE__, 'nf_ad_activate' );

/**
 * Activation-Hook.
 *
 * Erstellt/aktualisiert die Log-Tabellen und setzt die DB-Version.
 *
 * @return void
 */
function nf_ad_activate() {
    // Bestehende Cron-Events entfernen, um Doppel-Planungen zu vermeiden.
    wp_clear_scheduled_hook( 'nf_ad_daily_event' );
    
    // Log-Tabellen anlegen/aktualisieren.
    NF_AD_Logger::install_table();
    
    update_option( 'nf_ad_db_version', NF_AD_DB_VERSION );
}

/* --- Deaktivierung --- */
register_deactivation_hook( __FILE__, 'nf_ad_deactivate' );

/**
 * Deactivation-Hook.
 *
 * Entfernt geplante Cron-Events des Plugins.
 *
 * @return void
 */
function nf_ad_deactivate() {
    $timestamp = wp_next_scheduled( 'nf_ad_daily_event' );
    if ( $timestamp ) wp_unschedule_event( $timestamp, 'nf_ad_daily_event' );
}