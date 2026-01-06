<?php
/**
 * Plugin Name: Ninja Forms - Auto Delete
 * Description: Löscht Einträge und Dateianhänge automatisch nach einer festgelegten Anzahl von Tagen. 
 * Version: 2.2.3
 * Author: Alex Schlair
 * Author URI: https://www.pronto-media.at
 * Text Domain: nf-auto-delete
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// KONSTANTEN
// =============================================================================

/* --- Versionierung & Pfade --- */
 $nf_ad_plugin_data = get_file_data(
     __FILE__,
     [ 'Version' => 'Version' ],
     'plugin'
 );
 $nf_ad_version = isset( $nf_ad_plugin_data['Version'] ) ? trim( (string) $nf_ad_plugin_data['Version'] ) : '1.0.0';
 define( 'NF_AD_VERSION', $nf_ad_version );
define( 'NF_AD_DB_VERSION', '1.2' );
define( 'NF_AD_PATH', plugin_dir_path( __FILE__ ) );

// =============================================================================
// SYSTEMANFORDERUNGEN
// =============================================================================

/* --- PHP-Version prüfen --- */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action(
        'admin_notices',
        static function() {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Auto Delete for Ninja Forms', 'nf-auto-delete' ) . '</strong> ' . esc_html__( 'benötigt PHP 7.4 oder höher.', 'nf-auto-delete' ) . '</p></div>';
        }
    );
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

    load_plugin_textdomain( 'nf-auto-delete', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    NF_AD_Logger::maybe_update_db();

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
        add_action( 'wp_ajax_nf_ad_clear_runs', [ 'NF_AD_Dashboard', 'ajax_clear_runs' ] );
        add_action( 'wp_ajax_nf_ad_force_cleanup', [ 'NF_AD_Dashboard', 'ajax_force_cleanup' ] );
        add_action( 'wp_ajax_nf_ad_calculate', [ 'NF_AD_Dashboard', 'ajax_calculate' ] );
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
// HEALTHCHECK REST-API
// =============================================================================

/* --- REST-API Endpoint für Monitoring --- */
add_action( 'rest_api_init', 'nf_ad_register_healthcheck' );

/**
 * Registriert den Healthcheck-Endpoint für Monitoring-Tools.
 *
 * Endpoint: GET /wp-json/nf-ad/v1/health
 * Gibt Status-Informationen über das Plugin zurück (Tabellen, letzter Run, nächster Cron).
 *
 * @return void
 */
function nf_ad_register_healthcheck() {
    register_rest_route( 'nf-ad/v1', '/health', [
        'methods'             => 'GET',
        'callback'            => 'nf_ad_healthcheck',
        'permission_callback' => function() {
            // Nur für Admins zugänglich (Sicherheit).
            return current_user_can( 'manage_options' );
        },
    ]);
}

/**
 * Healthcheck-Callback: Gibt Plugin-Status zurück.
 *
 * @return WP_REST_Response
 */
function nf_ad_healthcheck() {
    global $wpdb;

    $health = [
        'status'         => 'healthy',
        'version'        => NF_AD_VERSION,
        'db_version'     => NF_AD_DB_VERSION,
        'last_run'       => null,
        'next_scheduled' => null,
        'tables_exist'   => false,
        'cron_active'    => false,
        'lock_active'    => false,
    ];

    // Prüfe ob Datenbank-Tabellen existieren.
    $logs_table = $wpdb->prefix . 'nf_ad_logs';
    $runs_table = $wpdb->prefix . 'nf_ad_cron_runs';

    $logs_exist = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );
    $runs_exist = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs_table ) );

    $health['tables_exist'] = ( $logs_exist && $runs_exist );

    // Letzter Cron-Run (wenn Tabelle existiert).
    if ( $runs_exist ) {
        $last_run = $wpdb->get_row(
            "SELECT time, status FROM {$runs_table} ORDER BY id DESC LIMIT 1"
        );
        if ( $last_run ) {
            $health['last_run'] = [
                'time'   => $last_run->time,
                'status' => $last_run->status,
            ];
        }
    }

    // Nächster geplanter Cron-Lauf.
    $next = wp_next_scheduled( 'nf_ad_daily_event' );
    if ( $next ) {
        $health['next_scheduled'] = wp_date( 'Y-m-d H:i:s', $next );
        $health['cron_active']    = true;
    }

    // Deadlock-Protection: Ist ein Cleanup gerade aktiv?
    $health['lock_active'] = (bool) get_transient( 'nf_ad_cleanup_running' );

    return rest_ensure_response( $health );
}

// =============================================================================
// AKTIVIERUNG / DEAKTIVIERUNG
// =============================================================================

/* --- Aktivierung --- */
register_activation_hook( __FILE__, 'nf_ad_activate' );

/**
 * Aktivierungs-Hook.
 *
 * Erstellt/aktualisiert die Log-Tabellen und setzt die Datenbank-Version.
 * Räumt bestehende Cron-Events auf, um Doppel-Planungen zu vermeiden.
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
 * Deaktivierungs-Hook.
 *
 * Entfernt geplante Cron-Events des Plugins.
 * Tabellen und Daten bleiben erhalten (nur bei Deinstallation über uninstall.php gelöscht).
 *
 * @return void
 */
function nf_ad_deactivate() {
    wp_clear_scheduled_hook( 'nf_ad_daily_event' );
}