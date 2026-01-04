<?php
/**
 * Uninstall-Skript: Wird ausgeführt, wenn das Plugin endgültig gelöscht wird.
 *
 * Entfernt die Plugin-eigenen Datenbanktabellen und Optionswerte sowie den geplanten Cron-Hook.
 *
 * @package NF_Auto_Delete
 */

// =============================================================================
// SICHERHEITSPRÜFUNG
// =============================================================================

 // Schutz: Direktaufruf des Uninstall-Skripts verhindern.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// =============================================================================
// BEREINIGUNG
// =============================================================================

global $wpdb;

/* --- Datenbanktabellen entfernen --- */

// Tabellen nur entfernen, wenn sie existieren.
$table_logs = $wpdb->prefix . 'nf_ad_logs';
$wpdb->query( "DROP TABLE IF EXISTS $table_logs" );

$table_runs = $wpdb->prefix . 'nf_ad_cron_runs';
$wpdb->query( "DROP TABLE IF EXISTS $table_runs" );

/* --- Optionen entfernen --- */
delete_option( 'nf_ad_settings' );
delete_option( 'nf_ad_db_version' );

/* --- Cron-Hook entfernen --- */

// Geplanten Daily-Event entfernen, falls vorhanden.
$timestamp = wp_next_scheduled( 'nf_ad_daily_event' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'nf_ad_daily_event' );
}