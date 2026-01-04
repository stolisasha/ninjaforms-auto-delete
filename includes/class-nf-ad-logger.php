<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// LOGGER (DB PERSISTENZ)
// =============================================================================

/**
 * Logger und Datenbank-Helper für Protokolle und Cron-Runs.
 */
class NF_AD_Logger {

    // =============================================================================
    // KONSTANTEN
    // =============================================================================

    /**
     * Tabellenname für Eintrags-Logs.
     */
    const TABLE_LOGS = 'nf_ad_logs';
    /**
     * Tabellenname für Cron-Run-Logs.
     */
    const TABLE_RUNS = 'nf_ad_cron_runs';


    // =============================================================================
    // DB SETUP
    // =============================================================================

    /* --- Versionierung & Installation --- */

    /**
     * Prüft die DB-Version und installiert/aktualisiert Tabellen bei Bedarf.
     *
     * @return void
     */
    public static function maybe_update_db() {
        if ( get_option( 'nf_ad_db_version' ) !== NF_AD_DB_VERSION ) {
            self::install_table();
            update_option( 'nf_ad_db_version', NF_AD_DB_VERSION );
        }
    }

    /**
     * Erstellt die DB-Tabellen für Logs und Cron-Runs (dbDelta).
     *
     * @return void
     */
    public static function install_table() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $charset = $wpdb->get_charset_collate();

        $logs_table = $wpdb->prefix . self::TABLE_LOGS;
        $runs_table = $wpdb->prefix . self::TABLE_RUNS;

        // dbDelta-Kompatibilität: keine Engine-Angabe in der CREATE TABLE Definition.
        dbDelta( "CREATE TABLE $logs_table (
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

        dbDelta( "CREATE TABLE $runs_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            PRIMARY KEY  (id), KEY time (time)
        ) $charset;" );
    }

    // =============================================================================
    // LOGGING
    // =============================================================================

    /* --- Einträge protokollieren --- */

    /**
     * Schreibt einen Log-Eintrag für ein Formular-Submission.
     *
     * @param int    $fid    Formular-ID.
     * @param int    $sid    Submission-ID.
     * @param string $sdate  Submission-Datum (MySQL-Datetime oder NULL).
     * @param string $status Status (z. B. "deleted", "skipped", "error").
     * @param string $msg    Detailnachricht.
     *
     * @return void
     */
    public static function log( $fid, $sid, $sdate, $status, $msg ) {
        global $wpdb;
        $title = 'Unbekannt';
        if ( class_exists( 'Ninja_Forms' ) ) {
            $form_obj = Ninja_Forms()->form( $fid )->get();
            if ( $form_obj ) {
                $title = $form_obj->get_setting( 'title' );
            }
        }
        $wpdb->insert(
            $wpdb->prefix . self::TABLE_LOGS,
            [
                'time'            => current_time( 'mysql' ),
                'form_id'         => $fid,
                'form_title'      => $title,
                'submission_id'   => $sid,
                'submission_date' => $sdate,
                'status'          => $status,
                'message'         => $msg,
            ],
            [ '%s', '%d', '%s', '%d', '%s', '%s', '%s' ]
        );
    }

    // =============================================================================
    // CRON RUNS
    // =============================================================================

    /* --- Run Lifecycle --- */

    /**
     * Startet einen Cron-Run und markiert verwaiste Runs als Timeout.
     *
     * @param string $msg Startnachricht.
     *
     * @return int Insert-ID des Runs.
     */
    public static function start_run( $msg = 'Bereinigung gestartet...' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_RUNS;
        $logs_table = $wpdb->prefix . self::TABLE_LOGS;

        // Sicherstellen, dass beide Tabellen vor dem Schreiben existieren (z. B. bei CLI/Cron).
        $exists_runs = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $exists_logs = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );
        if ( empty( $exists_runs ) || empty( $exists_logs ) ) {
            self::install_table();
        }

        // Zeitstempel auf Basis der WordPress-Zeit für den Timeout-Check.
        $one_hour_ago = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET status = 'error', message = CONCAT(message, ' [Timeout]') WHERE status = 'running' AND time < %s",
                $one_hour_ago
            )
        );
        $wpdb->insert(
            $table,
            [
                'time'    => current_time( 'mysql' ),
                'status'  => 'running',
                'message' => $msg,
            ],
            [ '%s', '%s', '%s' ]
        );
        return $wpdb->insert_id;
    }

    /**
     * Beendet einen Cron-Run und aktualisiert Status sowie Nachricht.
     *
     * @param int    $run_id Run-ID.
     * @param string $status Neuer Status.
     * @param string $msg    Abschlussnachricht.
     *
     * @return void
     */
    public static function finish_run( $run_id, $status, $msg ) {
        global $wpdb;
        if ( ! $run_id ) {
            return;
        }

        $table = $wpdb->prefix . self::TABLE_RUNS;

        // Run-Typ-Tag ([CRON] / [MANUAL]) aus der Startnachricht übernehmen.
        $existing_msg = $wpdb->get_var( $wpdb->prepare( "SELECT message FROM $table WHERE id = %d", $run_id ) );
        if ( is_string( $existing_msg ) ) {
            if ( preg_match( '/^(\[(CRON|MANUAL)\])\s+/i', $existing_msg, $m ) ) {
                $tag = strtoupper( $m[1] );
                // Nur ergänzen, wenn die neue Nachricht noch kein Run-Typ-Tag enthält.
                if ( ! preg_match( '/\[(CRON|MANUAL)\]/i', (string) $msg ) ) {
                    $msg = $tag . ' ' . ltrim( (string) $msg );
                }
            }
        }

        $wpdb->update(
            $table,
            [
                'status'  => $status,
                'message' => $msg,
            ],
            [ 'id' => $run_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        self::cleanup_runs( 50 );
    }

    // =============================================================================
    // ABFRAGEN
    // =============================================================================

    /* --- Listen & Pagination --- */

    /**
     * Liefert Eintrags-Logs (paginierte Ausgabe).
     *
     * @param int    $limit   Anzahl pro Seite.
     * @param int    $page    Seite.
     * @param string $orderby Sortierspalte.
     * @param string $order   Sortierrichtung (ASC|DESC).
     *
     * @return array
     */
    public static function get_logs( $limit, $page, $orderby = 'time', $order = 'DESC' ) {
        global $wpdb;
        $offset = ( $page - 1 ) * $limit;
        $table = $wpdb->prefix . self::TABLE_LOGS;
        $allowed = [ 'time', 'form_title', 'submission_id', 'status', 'submission_date', 'message' ];
        if ( ! in_array( $orderby, $allowed, true ) ) {
            $orderby = 'time';
        }
        $order = strtoupper( (string) $order );
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table ORDER BY $orderby " . ( $order === 'ASC' ? 'ASC' : 'DESC' ) . " LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    /**
     * Liefert Cron-Run-Logs (paginierte Ausgabe).
     *
     * @param int    $limit   Anzahl pro Seite.
     * @param int    $page    Seite.
     * @param string $orderby Sortierspalte.
     * @param string $order   Sortierrichtung (ASC|DESC).
     *
     * @return array
     */
    public static function get_cron_logs( $limit, $page, $orderby = 'time', $order = 'DESC' ) {
        global $wpdb;
        $offset = ( $page - 1 ) * $limit;
        $table = $wpdb->prefix . self::TABLE_RUNS;

        // Tabelle muss existieren, sonst leere Ausgabe zurückgeben.
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( empty( $exists ) ) {
            return [];
        }

        $allowed = [ 'time', 'status', 'message' ];
        if ( ! in_array( $orderby, $allowed, true ) ) {
            $orderby = 'time';
        }
        $order = strtoupper( (string) $order );
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table ORDER BY $orderby " . ( $order === 'ASC' ? 'ASC' : 'DESC' ) . " LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    /**
     * Zählt alle Eintrags-Logs.
     *
     * @return int
     */
    public static function count_logs() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_LOGS;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    }

    /**
     * Zählt alle Cron-Run-Logs.
     *
     * @return int
     */
    public static function count_cron_logs() {
        global $wpdb;
        $t = $wpdb->prefix . self::TABLE_RUNS;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
    }

    // =============================================================================
    // MAINTENANCE
    // =============================================================================

    /* --- Cleanup & Truncate --- */

    /**
     * Löscht alle Logs und Cron-Run-Einträge (TRUNCATE).
     *
     * @return void
     */
    public static function truncate() {
        global $wpdb;
        $logs_table = $wpdb->prefix . self::TABLE_LOGS;
        $runs_table = $wpdb->prefix . self::TABLE_RUNS;

        $logs_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );
        if ( ! empty( $logs_exists ) ) {
            $wpdb->query( "TRUNCATE TABLE $logs_table" );
        }

        $runs_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs_table ) );
        if ( ! empty( $runs_exists ) ) {
            $wpdb->query( "TRUNCATE TABLE $runs_table" );
        }
    }

    /**
     * Entfernt alte Log-Einträge und behält die letzten $keep Einträge.
     *
     * @param int $keep Anzahl der zu behaltenden Einträge.
     *
     * @return void
     */
    public static function cleanup_logs( $keep ) {
        global $wpdb;
        $keep = max( 10, absint( $keep ) );
        $keep = (int) $keep;
        $table = $wpdb->prefix . self::TABLE_LOGS;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        if ( $total <= $keep ) {
            return;
        }
        $threshold_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET %d",
                $keep
            )
        );
        if ( $threshold_id > 0 ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= %d", $threshold_id ) );
        }
    }

    /**
     * Entfernt alte Cron-Run-Einträge und behält die letzten $keep Runs.
     * Laufende Runs bleiben erhalten.
     *
     * @param int $keep Anzahl der zu behaltenden Runs.
     *
     * @return void
     */
    public static function cleanup_runs( $keep = 50 ) {
        global $wpdb;
        $keep = max( 10, absint( $keep ) );
        $keep = (int) $keep;
        $table = $wpdb->prefix . self::TABLE_RUNS;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        if ( $total <= $keep ) {
            return;
        }
        $threshold_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET %d",
                $keep
            )
        );
        if ( $threshold_id > 0 ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= %d AND status != %s", $threshold_id, 'running' ) );
        }
    }
}