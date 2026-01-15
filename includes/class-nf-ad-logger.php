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
    /**
     * Maximale Anzahl an Error-Details pro Run.
     *
     * WARUM 50? Verhindert DB-Bloat bei großen Cleanup-Runs mit vielen Fehlern.
     * 50 Fehlerdetails sind ausreichend für Debugging, ohne die DB zu überlasten.
     * Bei mehr als 50 Fehlern wird ein Hinweis angehängt.
     */
    const MAX_ERROR_DETAILS = 50;

    // =============================================================================
    // DB RETRY LOGIC
    // =============================================================================

    /**
     * Führt eine DB-Operation mit Retry-Logic aus (Exponential Backoff).
     * Schützt vor temporären Deadlocks und Connection-Errors.
     *
     * @param callable $callback      Die DB-Operation als Callback (sollte true/false zurückgeben).
     * @param int      $max_attempts  Maximale Anzahl Versuche (Standard: 3).
     *
     * @return bool True bei Erfolg, false bei Fehler.
     */
    private static function db_query_with_retry( $callback, $max_attempts = 3 ) {
        global $wpdb;

        $attempt = 0;
        $last_error = '';

        while ( $attempt < $max_attempts ) {
            $attempt++;

            try {
                $result = $callback();

                // Erfolg: Query hat funktioniert.
                if ( false !== $result ) {
                    return $result;
                }

                // Fehler: DB-Error auslesen.
                $last_error = $wpdb->last_error ?? 'Unknown DB error';

                // Wenn kein Deadlock, dann abbrechen (kein Retry bei anderen Fehlern).
                if ( false === stripos( $last_error, 'deadlock' ) && false === stripos( $last_error, 'lock wait timeout' ) ) {
                    break;
                }

            } catch ( Exception $e ) {
                $last_error = $e->getMessage();
            }

            // Exponential Backoff: 0.5s, 1s, 2s (verhindert Thundering Herd).
            if ( $attempt < $max_attempts ) {
                usleep( (int) ( pow( 2, $attempt - 1 ) * 500000 ) );
            }
        }

        // Alle Versuche fehlgeschlagen: Fehler loggen.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[NF Auto Delete] DB query failed after {$attempt} attempts: {$last_error}" );
        }

        return false;
    }


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
     * Erstellt die Datenbank-Tabellen für Logs und Cron-Runs.
     * Nutzt dbDelta für sichere Schema-Upgrades (idempotent).
     *
     * @return void
     */
    public static function install_table() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $charset = $wpdb->get_charset_collate();

        $logs_table = $wpdb->prefix . self::TABLE_LOGS;
        $runs_table = $wpdb->prefix . self::TABLE_RUNS;

        // dbDelta-Kompatibilität: Keine Engine-Angabe in der CREATE TABLE Definition (wird von WordPress gesetzt).
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
            error_details longtext,
            PRIMARY KEY  (id), KEY time (time)
        ) $charset;" );
    }

    // =============================================================================
    // LOGGING
    // =============================================================================

    /* --- Einträge protokollieren --- */

    /**
     * Static Cache für Formular-Titel.
     *
     * PERFORMANCE: Verhindert N+1 API-Aufrufe bei Batch-Operationen.
     * Bei 1.000 Submissions desselben Formulars wird der Titel nur 1x abgerufen.
     *
     * @var array<int, string>
     */
    private static $form_title_cache = [];

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

        // PERFORMANCE: Form-Titel aus Cache oder Ninja Forms API holen.
        $title = self::get_form_title_cached( $fid );
        $result = $wpdb->insert(
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

        // ERROR-LOGGING: Wenn Log-Eintrag fehlschlägt, trotzdem weitermachen (non-blocking).
        if ( false === $result && ! empty( $wpdb->last_error ) ) {
            error_log( '[NF Auto Delete] Failed to write log entry: ' . $wpdb->last_error );
        }
    }

    /**
     * Holt den Formulartitel aus dem Cache oder der Ninja Forms API.
     *
     * PERFORMANCE: Bei Batch-Operationen mit vielen Submissions desselben Formulars
     * wird der API-Aufruf nur einmal gemacht statt N-mal (N+1 Query Problem).
     *
     * @param int $form_id Formular-ID.
     *
     * @return string Formulartitel oder Fallback.
     */
    private static function get_form_title_cached( $form_id ) {
        $form_id = absint( $form_id );

        // Cache-Hit: Direkt zurückgeben (Fast-Path).
        if ( isset( self::$form_title_cache[ $form_id ] ) ) {
            return self::$form_title_cache[ $form_id ];
        }

        // Cache-Miss: Ninja Forms API aufrufen.
        $title = 'Unbekannt';
        if ( class_exists( 'Ninja_Forms' ) ) {
            $form_obj = Ninja_Forms()->form( $form_id )->get();
            if ( $form_obj && method_exists( $form_obj, 'get_setting' ) ) {
                $fetched_title = $form_obj->get_setting( 'title' );
                if ( ! empty( $fetched_title ) ) {
                    $title = $fetched_title;
                }
            }
        }

        // Im Cache speichern für zukünftige Aufrufe.
        self::$form_title_cache[ $form_id ] = $title;

        return $title;
    }

    // =============================================================================
    // CRON RUNS
    // =============================================================================

    /* --- Run Lifecycle --- */

    /**
     * Startet einen neuen Cleanup-Run und markiert verwaiste Runs als Timeout.
     *
     * WICHTIG: Verwaiste Runs (älter als 1 Stunde, Status "running") werden automatisch
     * als "error" markiert, um Ghost-Runs nach Server-Crashes zu bereinigen.
     *
     * @param string $msg Startnachricht für den Log.
     *
     * @return int Insert-ID des neu gestarteten Runs.
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

        // DB-RETRY: Timeout-Update mit Retry-Logic (schützt vor Deadlocks).
        self::db_query_with_retry( function() use ( $wpdb, $table, $one_hour_ago ) {
            return $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET status = 'error', message = CONCAT(message, ' [Timeout]') WHERE status = 'running' AND time < %s",
                    $one_hour_ago
                )
            );
        } );
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
     * Beendet einen Cleanup-Run und aktualisiert Status sowie Nachricht.
     *
     * Behält das Run-Typ-Tag ([CRON] oder [MANUAL]) aus der Startnachricht bei,
     * damit im Dashboard zwischen automatischen und manuellen Runs unterschieden werden kann.
     *
     * @param int    $run_id        Run-ID (vom start_run() zurückgegeben).
     * @param string $status        Endstatus ('success', 'error', 'warning', 'skipped').
     * @param string $msg           Abschlussnachricht.
     * @param array  $error_details Optional: Array mit Fehlerdetails für Modal-Anzeige.
     *
     * @return void
     */
    public static function finish_run( $run_id, $status, $msg, $error_details = [] ) {
        global $wpdb;
        if ( ! $run_id ) {
            return;
        }

        $table = $wpdb->prefix . self::TABLE_RUNS;

        // Run-Typ-Tag ([CRON] / [MANUAL]) aus der Startnachricht extrahieren und beibehalten.
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

        // Fehlerdetails begrenzen, um DB-Bloat zu verhindern.
        // Bei großen Cleanup-Runs mit vielen Fehlern könnte das Array sonst sehr groß werden.
        if ( is_array( $error_details ) && count( $error_details ) > self::MAX_ERROR_DETAILS ) {
            $truncated_count = count( $error_details ) - self::MAX_ERROR_DETAILS;
            $error_details   = array_slice( $error_details, 0, self::MAX_ERROR_DETAILS );
            $error_details[] = sprintf( '... und %d weitere Fehler (gekürzt)', $truncated_count );
        }

        // Fehlerdetails als JSON serialisieren (wenn vorhanden).
        $error_details_json = ! empty( $error_details ) ? wp_json_encode( $error_details, JSON_UNESCAPED_UNICODE ) : null;

        $wpdb->update(
            $table,
            [
                'status'        => $status,
                'message'       => $msg,
                'error_details' => $error_details_json,
            ],
            [ 'id' => $run_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        // Cleanup mit demselben Limit wie die Logs (aus den Plugin-Einstellungen).
        // WICHTIG: Wir rufen get_settings() nicht direkt hier auf, da der Logger
        // unabhängig vom Dashboard funktionieren soll. Das Limit wird beim nächsten
        // Cleanup durch run_cleanup_logic() mit dem korrekten Wert aufgerufen.
        // Hier nur Fallback-Cleanup mit konservativem Default.
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
     * @deprecated Seit v2.2.1 - Nutze truncate_logs() oder truncate_runs() für gezielte Löschung.
     *
     * @return void
     */
    public static function truncate() {
        self::truncate_logs();
        self::truncate_runs();
    }

    /**
     * Löscht nur die Löschungs-Logs (nf_ad_logs Tabelle).
     *
     * @return void
     */
    public static function truncate_logs() {
        global $wpdb;
        $logs_table = $wpdb->prefix . self::TABLE_LOGS;

        $logs_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );
        if ( ! empty( $logs_exists ) ) {
            $wpdb->query( "TRUNCATE TABLE $logs_table" );
        }
    }

    /**
     * Löscht nur die Ausführungs-Logs (nf_ad_cron_runs Tabelle).
     *
     * @return void
     */
    public static function truncate_runs() {
        global $wpdb;
        $runs_table = $wpdb->prefix . self::TABLE_RUNS;

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
     * Entfernt alte Cron-Run-Einträge und behält nur die letzten N Runs.
     *
     * WICHTIG: Laufende Runs (status='running') werden NICHT gelöscht,
     * auch wenn sie älter als das Limit sind (Schutz vor versehentlichem Löschen aktiver Runs).
     *
     * @param int $keep Anzahl der zu behaltenden Runs (Minimum: 10).
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