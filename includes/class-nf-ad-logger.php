<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_AD_Logger {
    const TABLE_LOGS = 'nf_ad_logs';
    const TABLE_RUNS = 'nf_ad_cron_runs';

    public static function maybe_update_db() {
        if ( ! current_user_can('manage_options') ) return;
        if ( get_option('nf_ad_db_version') != NF_AD_DB_VERSION ) {
            self::install_table();
            update_option('nf_ad_db_version', NF_AD_DB_VERSION);
        }
    }

    public static function install_table() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $charset = $wpdb->get_charset_collate();

        // FIX #3: ENGINE=InnoDB entfernt für bessere dbDelta Kompatibilität
        dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_LOGS . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            form_id mediumint(9) NOT NULL,
            form_title text NOT NULL,
            submission_id mediumint(9) NOT NULL,
            submission_date datetime DEFAULT NULL,
            status varchar(20) NOT NULL,
            message text,
            PRIMARY KEY  (id), KEY form_id (form_id), KEY status (status), KEY time (time)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE_RUNS . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            PRIMARY KEY  (id), KEY time (time)
        ) $charset;" );
    }

    public static function log( $fid, $sid, $sdate, $status, $msg ) {
        global $wpdb;
        $title = 'Unbekannt';
        if(class_exists('Ninja_Forms')) { 
            $form_obj = Ninja_Forms()->form($fid)->get(); 
            if($form_obj) $title = $form_obj->get_setting('title'); 
        }
        $wpdb->insert( $wpdb->prefix . self::TABLE_LOGS, [ 'time' => current_time('mysql'), 'form_id' => $fid, 'form_title' => $title, 'submission_id' => $sid, 'submission_date' => $sdate, 'status' => $status, 'message' => $msg ]);
    }

    public static function start_run( $msg = 'Bereinigung gestartet...' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_RUNS;
        
        // SAFETY FIX: Bevor wir schreiben, sicherstellen, dass die Tabelle existiert.
        // Verhindert SQL Errors, falls start_run() vor install_table() aufgerufen wird (z.B. CLI/Cron).
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( empty( $exists ) ) {
            self::install_table();
        }

        // FIX #1: Timezone Fix (WP Time statt Server Time) für korrekten Timeout Check
        $one_hour_ago = date('Y-m-d H:i:s', current_time('timestamp') - HOUR_IN_SECONDS);
        
        $wpdb->query( $wpdb->prepare("UPDATE $table SET status = 'error', message = CONCAT(message, ' [Timeout]') WHERE status = 'running' AND time < %s", $one_hour_ago) );
        $wpdb->insert( $table, [ 'time' => current_time('mysql'), 'status' => 'running', 'message' => $msg ]);
        return $wpdb->insert_id;
    }

    public static function finish_run( $run_id, $status, $msg ) {
        global $wpdb;
        if ( ! $run_id ) return;
        $wpdb->update( $wpdb->prefix . self::TABLE_RUNS, [ 'status' => $status, 'message' => $msg ], [ 'id' => $run_id ] );
        self::cleanup_runs(50); 
    }

    public static function get_logs( $limit, $page, $orderby = 'time', $order = 'DESC' ) {
        global $wpdb; $offset = ($page-1)*$limit; $table = $wpdb->prefix . self::TABLE_LOGS;
        $allowed = ['time', 'form_title', 'submission_id', 'status', 'submission_date', 'message'];
        if(!in_array($orderby, $allowed)) $orderby = 'time';
        return $wpdb->get_results( $wpdb->prepare("SELECT * FROM $table ORDER BY $orderby " . ($order==='ASC'?'ASC':'DESC') . " LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A );
    }

    public static function get_cron_logs( $limit, $page, $orderby = 'time', $order = 'DESC' ) {
        global $wpdb; $offset = ($page-1)*$limit; $table = $wpdb->prefix . self::TABLE_RUNS;
        
        // FIX #2: Robuster Check ob Tabelle existiert
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( empty( $exists ) ) return [];

        $allowed = ['time', 'status', 'message'];
        if(!in_array($orderby, $allowed)) $orderby = 'time';
        return $wpdb->get_results( $wpdb->prepare("SELECT * FROM $table ORDER BY $orderby " . ($order==='ASC'?'ASC':'DESC') . " LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A );
    }

    public static function count_logs() { global $wpdb; return $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}".self::TABLE_LOGS); }
    public static function count_cron_logs() { global $wpdb; $t=$wpdb->prefix.self::TABLE_RUNS; return $wpdb->get_var("SELECT COUNT(*) FROM $t"); }
    
    public static function truncate() { 
        global $wpdb; 
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}".self::TABLE_LOGS); 
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}".self::TABLE_RUNS); 
    }
    
    public static function cleanup_logs( $keep ) {
        global $wpdb; 
        $keep = max(10, absint($keep));
        $table = $wpdb->prefix . self::TABLE_LOGS;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ( $total <= $keep ) return;
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= ( SELECT id FROM ( SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET %d ) foo )", $keep ));
    }

    public static function cleanup_runs( $keep = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_RUNS;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ( $total <= $keep ) return;
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= ( SELECT id FROM ( SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET %d ) foo ) AND status != 'running'", $keep ));
    }
}