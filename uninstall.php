<?php
/**
 * Fired when the plugin is deleted.
 * @package NF_Auto_Delete
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$table_logs = $wpdb->prefix . 'nf_ad_logs';
$wpdb->query( "DROP TABLE IF EXISTS $table_logs" );

$table_runs = $wpdb->prefix . 'nf_ad_cron_runs';
$wpdb->query( "DROP TABLE IF EXISTS $table_runs" );

delete_option( 'nf_ad_settings' );
delete_option( 'nf_ad_db_version' );

$timestamp = wp_next_scheduled( 'nf_ad_daily_event' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'nf_ad_daily_event' );
}