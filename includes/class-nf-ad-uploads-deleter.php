<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// UPLOADS DELETER (DATEI-BEREINIGUNG)
// =============================================================================

/**
 * Löscht Upload-Dateien, die an Ninja-Forms-Submissions hängen.
 * Enthält Sicherheitsprüfungen (Symlink-Schutz, Jail-Check im Upload-Verzeichnis).
 */
class NF_AD_Uploads_Deleter {

    // =============================================================================
    // KONSTANTEN
    // =============================================================================

    /**
     * Maximale Anzahl an Upload-Zeilen pro Batch.
     * Identisch mit Submissions_Eraser für konsistentes Verhalten.
     */
    const BATCH_LIMIT = 50;

    /**
     * Maximale Laufzeit pro Durchlauf (Sekunden), um Timeouts zu vermeiden.
     * Identisch mit Submissions_Eraser für konsistentes Verhalten.
     */
    const TIME_LIMIT = 20;

    // =============================================================================
    // SCHEMA CACHE
    // =============================================================================

    /**
     * Gecachtes Schema der Uploads-Tabelle (Performance-Optimierung).
     * Wird einmalig pro Request geladen und wiederverwendet.
     *
     * @var array{columns:array<int,string>,types:array<string,string>}|null
     */
    private static $uploads_table_schema = null;

    /**
     * Lädt das Schema der Uploads-Tabelle (Spalten + MySQL-Typen) mit Caching.
     * Vermeidet wiederholte DESCRIBE-Queries bei mehrfachen Aufrufen.
     *
     * @param string $table Tabellenname.
     *
     * @return array{columns:array<int,string>,types:array<string,string>}
     */
    private static function get_uploads_table_schema( $table ) {
        if ( null !== self::$uploads_table_schema ) {
            return self::$uploads_table_schema;
        }

        global $wpdb;

        $columns = array();
        $types   = array();

        $desc = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );
        if ( is_array( $desc ) ) {
            foreach ( $desc as $row ) {
                if ( empty( $row['Field'] ) ) {
                    continue;
                }
                $field     = (string) $row['Field'];
                $columns[] = $field;
                $types[ $field ] = isset( $row['Type'] ) ? (string) $row['Type'] : '';
            }
        }

        self::$uploads_table_schema = array(
            'columns' => $columns,
            'types'   => $types,
        );

        return self::$uploads_table_schema;
    }

    /**
     * Erkennt welche Datums-Spalte für die Alters-Filterung genutzt werden soll.
     *
     * WICHTIG: Ninja Forms File Uploads nutzt je nach Version unterschiedliche Spalten.
     * Wir prüfen alle gängigen Varianten in Reihenfolge der Wahrscheinlichkeit.
     *
     * @param array<int,string> $columns Liste der Spaltennamen.
     *
     * @return string|null Gefundene Datums-Spalte oder null.
     */
    private static function detect_uploads_date_column( $columns ) {
        foreach ( array( 'date_updated', 'updated_at', 'date_modified', 'modified_at', 'created_at', 'date_created', 'created', 'created_on', 'date_added', 'date', 'timestamp' ) as $c ) {
            if ( in_array( $c, $columns, true ) ) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Prüft ob eine Spalte ein Integer-Timestamp ist (statt DATETIME).
     *
     * Manche Add-on-Versionen nutzen INT-Unix-Timestamps, andere DATETIME-Strings.
     * Diese Methode erkennt den Typ anhand der MySQL-Type-Definition.
     *
     * @param string|null $col Spaltenname.
     * @param array<string,string> $types Map von Spaltenname → MySQL-Typ.
     *
     * @return bool True wenn Integer-Typ, false wenn DATETIME/VARCHAR.
     */
    private static function is_int_timestamp_column( $col, $types ) {
        if ( ! $col || ! isset( $types[ $col ] ) ) {
            return false;
        }
        return ( false !== strpos( strtolower( (string) $types[ $col ] ), 'int' ) );
    }

    /**
     * Detect a deleted/status flag column (to match add-on UI).
     *
     * @param array<int,string> $columns Columns list.
     *
     * @return string|null
     */
    private static function detect_deleted_column( $columns ) {
        foreach ( array( 'deleted', 'is_deleted', 'deleted_at', 'date_deleted', 'trashed', 'status' ) as $c ) {
            if ( in_array( $c, $columns, true ) ) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Debug-Logging für Entwicklung und Troubleshooting.
     * Schreibt nur wenn WP_DEBUG aktiv ist, um Performance-Overhead zu vermeiden.
     *
     * @param string $message Nachricht.
     * @param array  $context Optionaler Kontext (wird als JSON serialisiert).
     *
     * @return void
     */
    private static function debug_log( $message, $context = [] ) {
        // Nur loggen wenn WordPress Debug-Modus aktiv ist.
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $log_msg = '[NF Auto Delete - Uploads] ' . $message;

        // Kontext als JSON anhängen (wenn vorhanden).
        if ( ! empty( $context ) ) {
            $log_msg .= ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
        }

        error_log( $log_msg );
    }

    // =============================================================================
    // DRY RUN (SIMULATION)
    // =============================================================================

    /* --- Berechnung ohne Löschung --- */

    /**
     * Berechnet die Anzahl der betroffenen Upload-Dateien für alle Formulare, ohne Änderungen vorzunehmen.
     *
     * Diese Methode nutzt EXAKT dieselbe Logik wie die tatsächliche Löschung, um Konsistenz zu gewährleisten.
     * Optimiert durch intelligente file_exists() Checks (inspiriert vom NF File Uploads Add-on).
     *
     * WICHTIG: Limit von 5.000 Uploads pro Form. Bei Limit-Erreichen wird Flag gesetzt.
     *
     * @return array{count:int,limit_reached:bool} Count und Flag ob Limit erreicht wurde.
     */
    public static function calculate_dry_run() {
        $settings = class_exists( 'NF_AD_Dashboard' ) ? NF_AD_Dashboard::get_settings() : array();

        // Defensive: Wenn Ninja Forms nicht geladen ist, 0 zurückgeben.
        if ( ! function_exists( 'Ninja_Forms' ) ) {
            return [ 'count' => 0, 'limit_reached' => false ];
        }

        $file_action = $settings['file_handling'] ?? 'keep';

        // Wenn Files behalten werden sollen, nichts zu zählen.
        if ( 'keep' === $file_action ) {
            return [ 'count' => 0, 'limit_reached' => false ];
        }

        $global = (int) ( $settings['global'] ?? 365 );
        $forms  = Ninja_Forms()->form()->get_forms();

        // DEFENSIVE: Prüfe ob get_forms() valide Daten zurückgibt (Edge-Case: DB-Fehler, Partial Plugin Load).
        if ( ! is_array( $forms ) || empty( $forms ) ) {
            return [ 'count' => 0, 'limit_reached' => false ];
        }

        $total_count = 0;
        $any_limit_reached = false;

        foreach ( $forms as $form ) {
            // DEFENSIVE: Prüfe ob Form-Objekt und ID valide sind.
            if ( ! is_object( $form ) || ! method_exists( $form, 'get_id' ) ) {
                continue;
            }

            $fid = $form->get_id();
            if ( ! $fid || $fid < 1 ) {
                continue; // Ungültige Form-ID überspringen
            }
            $rule = $settings['forms'][ $fid ] ?? array( 'mode' => 'global' );

            if ( isset( $rule['mode'] ) && 'never' === $rule['mode'] ) {
                continue;
            }

            $days = ( isset( $rule['mode'] ) && 'custom' === $rule['mode'] ) ? (int) $rule['days'] : $global;
            if ( $days < 1 ) {
                $days = 365;
            }

            // Cutoff-Datum berechnen (WordPress-Zeitzone).
            $cutoff_obj = current_datetime()->modify( '-' . max( 1, absint( $days ) ) . ' days' );
            $cutoff     = $cutoff_obj->format( 'Y-m-d H:i:s' );

            // NEUE ARCHITEKTUR: Nur noch Tabellen-basierte Zählung.
            // Meta-basierte Legacy-Uploads werden ignoriert (klare Trennung: nur Ninja Forms Uploads Add-on Tabelle).
            $result = self::count_from_uploads_table( $fid, $cutoff );
            $total_count += $result['count'];

            if ( $result['limit_reached'] ) {
                $any_limit_reached = true;
            }
        }

        return [
            'count' => $total_count,
            'limit_reached' => $any_limit_reached,
        ];
    }

    /**
     * Zählt Uploads aus der offiziellen Ninja Forms File Uploads Tabelle.
     * Nutzt dieselbe Logik wie cleanup_uploads_for_form(), aber ohne zu löschen.
     *
     * BUGFIX: Implementiert Pagination um Uploads korrekt zu zählen.
     * Maximum: 5.000 Zeilen (Performance-Schutz), darüber hinaus Abbruch mit Hinweis.
     *
     * WICHTIG: Zählt NUR Uploads die NICHT per-submission gelöscht werden (kein submission_id in Tabelle).
     * Uploads MIT submission_id werden von count_from_submission_meta() gezählt.
     *
     * @param int    $fid    Formular-ID.
     * @param string $cutoff Cutoff-Datum im Format 'Y-m-d H:i:s'.
     *
     * @return array{count:int,limit_reached:bool} Count und Flag ob Limit erreicht wurde.
     */
    private static function count_from_uploads_table( $fid, $cutoff ) {
        global $wpdb;

        $uploads_table = $wpdb->prefix . 'ninja_forms_uploads';
        $uploads_table_like = $wpdb->esc_like( $uploads_table );

        // Prüfe ob Tabelle existiert.
        $has_uploads_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $uploads_table_like ) ) === $uploads_table );
        if ( ! $has_uploads_table ) {
            // BUGFIX: Richtige Return-Struktur (array statt int).
            return [ 'count' => 0, 'limit_reached' => false ];
        }

        // Schema-Detection.
        $schema = self::get_uploads_table_schema( $uploads_table );
        $uploads_columns = $schema['columns'];
        $uploads_types   = $schema['types'];

        $data_col = in_array( 'data', $uploads_columns, true ) ? 'data' : null;
        $date_col = self::detect_uploads_date_column( $uploads_columns );
        $form_col = in_array( 'form_id', $uploads_columns, true ) ? 'form_id' : null;
        $id_col   = in_array( 'id', $uploads_columns, true ) ? 'id' : null;

        // NEUE ARCHITEKTUR: Wir löschen ALLE Uploads basierend auf form_id + cutoff_date.
        // Die submission_id ist für die Alters-Prüfung irrelevant!
        // Uploads werden unabhängig von Submissions gelöscht (klare Trennung der Verantwortlichkeiten).

        if ( ! $data_col || ! $date_col || ! $form_col || ! $id_col ) {
            // DEBUG-LOGGING (Code-Audit Fix): Logge welche Spalte fehlt für Troubleshooting.
            self::debug_log( 'Schema detection failed in dry-run', [
                'table' => $uploads_table,
                'columns_found' => $uploads_columns,
                'data_col' => $data_col,
                'date_col' => $date_col,
                'form_col' => $form_col,
                'id_col' => $id_col,
                'form_id' => $fid,
            ] );
            // BUGFIX: Richtige Return-Struktur (array statt int).
            return [ 'count' => 0, 'limit_reached' => false ];
        }

        // Datums-Typ erkennen.
        $date_is_int = self::is_int_timestamp_column( $date_col, $uploads_types );
        $date_placeholder = $date_is_int ? '%d' : '%s';
        $cutoff_value = $date_is_int ? (int) strtotime( $cutoff ) : $cutoff;

        // Deleted-Spalte erkennen (matching add-on UI logic).
        $deleted_col = self::detect_deleted_column( $uploads_columns );
        $deleted_sql = '';
        $deleted_params = array();

        if ( $deleted_col ) {
            if ( 'status' === $deleted_col ) {
                $deleted_sql = " AND {$deleted_col} NOT IN (%s,%s)";
                $deleted_params = array( 'deleted', 'trash' );
            } else {
                $deleted_sql = " AND ( {$deleted_col} = 0 OR {$deleted_col} IS NULL OR {$deleted_col} = '' )";
            }
        }

        // BUGFIX #3: Pagination implementieren (wie bei cleanup_uploads_for_form).
        // Batch-Size: 500, Maximum: 5.000 Zeilen (Performance-Schutz).
        // WICHTIG: Bei Limit-Erreichen wird flag gesetzt für UI-Kommunikation ("5.000+").
        $count = 0;
        $last_id = 0;
        $batch_size = 500;
        $max_rows = 5000;
        $processed = 0;
        $limit_reached = false;

        do {
            // Query mit Pagination (id > last_id).
            $sql = "SELECT {$id_col}, {$data_col} FROM {$uploads_table}
                    WHERE {$form_col} = %d
                    AND {$date_col} < {$date_placeholder}
                    {$deleted_sql}
                    AND {$id_col} > %d
                    ORDER BY {$id_col} ASC
                    LIMIT %d";

            $params = array_merge( array( $fid, $cutoff_value ), $deleted_params, array( $last_id, $batch_size ) );
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

            if ( empty( $rows ) ) {
                break; // Keine weiteren Zeilen
            }

            // Iteriere durch Upload-Daten.
            foreach ( $rows as $row ) {
                $row_id = isset( $row[ $id_col ] ) ? absint( $row[ $id_col ] ) : 0;
                if ( $row_id > $last_id ) {
                    $last_id = $row_id; // Pagination fortsetzen
                }

                $serialized_data = isset( $row[ $data_col ] ) ? $row[ $data_col ] : '';
                // SICHERHEIT: safe_unserialize() statt maybe_unserialize() (POI-Schutz).
                $upload_data = self::safe_unserialize( $serialized_data );

                if ( ! is_array( $upload_data ) ) {
                    continue;
                }

                // INTELLIGENTER Check (inspiriert von NF File Uploads Add-on).
                if ( ! self::smart_file_exists( $upload_data ) ) {
                    continue;
                }

                $count++;
            }

            $processed += count( $rows );

            // Performance-Schutz: Bei > 5.000 Zeilen abbrechen und Flag setzen.
            if ( $processed >= $max_rows ) {
                $limit_reached = true;
                self::debug_log( 'Dry-run: Performance limit reached', [
                    'form_id' => $fid,
                    'processed_rows' => $processed,
                    'max_rows' => $max_rows,
                    'counted_files' => $count,
                ] );
                break;
            }

            // Wenn weniger als batch_size zurückkam, sind wir am Ende.
            if ( count( $rows ) < $batch_size ) {
                break;
            }

        } while ( true );

        self::debug_log( 'Dry-run completed for form', [
            'form_id' => $fid,
            'counted_files' => $count,
            'processed_rows' => $processed,
            'limit_reached' => $limit_reached,
        ] );

        return [
            'count' => $count,
            'limit_reached' => $limit_reached,
        ];
    }

    // =============================================================================
    // BULK CLEANUP (EIGENSTÄNDIGER ENTRY-POINT)
    // =============================================================================

    /**
     * Eigenständiger Entry-Point für die Bulk-Bereinigung von Upload-Dateien.
     *
     * ARCHITEKTUR: Diese Methode ist der zentrale Entry-Point für Upload-Bereinigung.
     * Sie ist komplett unabhängig vom Submissions_Eraser und verwaltet:
     * - Eigenes Logging (Start/Ende des Runs)
     * - Eigene Zähler (deleted, errors)
     * - Eigene Zeitlimits
     *
     * WICHTIG: Der Submissions_Eraser ruft diese Methode NICHT mehr auf.
     * Stattdessen werden beide von einem Orchestrator (run_cleanup_logic) koordiniert.
     *
     * @param bool $is_cron True bei Cron-Ausführung, false bei manueller Ausführung.
     *
     * @return array{deleted:int,errors:int,has_more:bool}
     */
    public static function run_cleanup( $is_cron = false ) {
        $settings = class_exists( 'NF_AD_Dashboard' ) ? NF_AD_Dashboard::get_settings() : array();

        $file_action = $settings['file_handling'] ?? 'keep';

        // Wenn Dateien behalten werden sollen, gibt es nichts zu tun.
        if ( 'keep' === $file_action ) {
            return [ 'deleted' => 0, 'errors' => 0, 'has_more' => false ];
        }

        // Defensive: Wenn Ninja Forms nicht geladen ist, abbrechen.
        if ( ! function_exists( 'Ninja_Forms' ) ) {
            return [ 'deleted' => 0, 'errors' => 0, 'has_more' => false ];
        }

        $global = (int) ( $settings['global'] ?? 365 );
        $forms  = Ninja_Forms()->form()->get_forms();

        // DEFENSIVE: Prüfe ob get_forms() valide Daten zurückgibt.
        if ( ! is_array( $forms ) || empty( $forms ) ) {
            return [ 'deleted' => 0, 'errors' => 0, 'has_more' => false ];
        }

        $total_deleted = 0;
        $total_errors  = 0;
        $start         = time();
        $limit_reached = false;

        // Iteriere durch alle Formulare und lösche fällige Uploads.
        foreach ( $forms as $form ) {
            // Zeitlimit-Check: Abbrechen wenn TIME_LIMIT fast erreicht.
            if ( ( time() - $start ) >= self::TIME_LIMIT ) {
                $limit_reached = true;
                self::debug_log( 'Upload cleanup time limit reached', [
                    'elapsed' => ( time() - $start ),
                    'limit' => self::TIME_LIMIT,
                    'total_deleted' => $total_deleted,
                ] );
                break;
            }

            // DEFENSIVE: Prüfe ob Form-Objekt und ID valide sind.
            if ( ! is_object( $form ) || ! method_exists( $form, 'get_id' ) ) {
                continue;
            }

            $fid = $form->get_id();
            if ( ! $fid || $fid < 1 ) {
                continue;
            }

            // Formular-spezifische Regel laden.
            $rule = $settings['forms'][ $fid ] ?? array( 'mode' => 'global' );
            if ( isset( $rule['mode'] ) && 'never' === $rule['mode'] ) {
                continue;
            }

            $days = ( isset( $rule['mode'] ) && 'custom' === $rule['mode'] ) ? (int) $rule['days'] : $global;
            if ( $days < 1 ) {
                $days = 365;
            }

            // Cutoff-Datum berechnen (WordPress-Zeitzone).
            $cutoff_obj = current_datetime()->modify( '-' . max( 1, absint( $days ) ) . ' days' );
            $cutoff     = $cutoff_obj->format( 'Y-m-d H:i:s' );

            // Formular-Uploads bereinigen mit Batch-Processing.
            $form_result = self::cleanup_uploads_for_form_batched( $fid, $cutoff, $start );

            $total_deleted += $form_result['deleted'];
            $total_errors  += $form_result['errors'];

            // Wenn das Formular das Zeitlimit erreicht hat, abbrechen.
            if ( $form_result['limit_reached'] ) {
                $limit_reached = true;
                break;
            }
        }

        self::debug_log( 'Bulk upload cleanup completed', [
            'total_deleted' => $total_deleted,
            'total_errors' => $total_errors,
            'limit_reached' => $limit_reached,
            'elapsed' => ( time() - $start ),
        ] );

        return [
            'deleted'  => $total_deleted,
            'errors'   => $total_errors,
            'has_more' => $limit_reached,
        ];
    }

    /**
     * Bereinigt Uploads für ein Formular mit Batch-Processing und Zeitlimit.
     *
     * INTERN: Wird von run_cleanup() aufgerufen. Verarbeitet alle fälligen Uploads
     * eines Formulars in Batches, bis keine mehr vorhanden sind oder das Zeitlimit greift.
     *
     * LOGGING: Einzelne Log-Einträge pro gelöschter Datei werden direkt in
     * cleanup_uploads_for_form() geschrieben (nicht hier gebatcht).
     *
     * @param int    $fid    Formular-ID.
     * @param string $cutoff Cutoff-Datum im Format 'Y-m-d H:i:s'.
     * @param int    $start  Startzeitpunkt (Unix-Timestamp) für Zeitlimit-Berechnung.
     *
     * @return array{deleted:int,errors:int,limit_reached:bool}
     */
    private static function cleanup_uploads_for_form_batched( $fid, $cutoff, $start ) {
        $total_deleted   = 0;
        $total_errors    = 0;
        $limit_reached   = false;
        $last_id         = 0;
        $deadlock_detector = 0;

        do {
            $prev_id = $last_id;

            // Einzelnen Batch verarbeiten.
            $batch_result = self::cleanup_uploads_for_form( (int) $fid, (string) $cutoff, self::BATCH_LIMIT, $last_id );

            $total_deleted += (int) ( $batch_result['deleted'] ?? 0 );
            $total_errors  += (int) ( $batch_result['errors'] ?? 0 );
            $rows          = (int) ( $batch_result['rows'] ?? 0 );

            // Sichere Progression: Immer das Maximum nehmen, nie rückwärts.
            $returned_id = (int) ( $batch_result['last_id'] ?? 0 );
            $last_id     = max( $last_id, $returned_id );

            // Deadlock-Detection: Wenn last_id sich nicht ändert, ist kein Fortschritt möglich.
            if ( $rows > 0 && $last_id === $prev_id ) {
                $deadlock_detector++;
                if ( $deadlock_detector >= 3 ) {
                    self::debug_log( 'Deadlock detected in batched upload cleanup', [
                        'form_id' => $fid,
                        'last_id' => $last_id,
                        'rows' => $rows,
                    ] );
                    break;
                }
            } else {
                $deadlock_detector = 0;
            }

            // Abbruch wenn kein voller Batch mehr kam (alle Uploads abgearbeitet).
            if ( $rows < self::BATCH_LIMIT ) {
                break;
            }

            // Zeitlimit-Check: Abbrechen wenn TIME_LIMIT fast erreicht (3 Sekunden Puffer).
            if ( ( time() - $start ) >= ( self::TIME_LIMIT - 3 ) ) {
                $limit_reached = true;
                self::debug_log( 'Upload batch time limit reached', [
                    'form_id' => $fid,
                    'elapsed' => ( time() - $start ),
                    'total_deleted' => $total_deleted,
                ] );
                break;
            }

        } while ( true );

        // HINWEIS: Einzelne Log-Einträge werden direkt in cleanup_uploads_for_form() geschrieben.
        // Hier kein Sammel-Log mehr, da jede Datei einzeln protokolliert wird.

        return [
            'deleted'       => $total_deleted,
            'errors'        => $total_errors,
            'limit_reached' => $limit_reached,
        ];
    }

    /**
     * Zählt Uploads aus Submission Meta UND aus Upload-Tabelle (wenn submission_id vorhanden).
     * Nutzt dieselbe Logik wie cleanup_files() um Konsistenz zu gewährleisten.
     *
     * @param int    $fid      Formular-ID.
     * @param string $cutoff   Cutoff-Datum im Format 'Y-m-d H:i:s'.
     * @param array  $settings Plugin-Einstellungen.
     *
     * @return int
     */
    private static function count_from_submission_meta( $fid, $cutoff, $settings ) {
        global $wpdb;

        // Upload-Felder des Formulars ermitteln.
        $upload_field_ids = self::get_upload_field_ids( $fid );
        if ( empty( $upload_field_ids ) ) {
            return 0;
        }

        $sub_action = $settings['sub_handling'] ?? 'keep';

        // Prüfe ob Upload-Tabelle mit submission_id existiert (wie in cleanup_files).
        $uploads_table = $wpdb->prefix . 'ninja_forms_uploads';
        $uploads_table_like = $wpdb->esc_like( $uploads_table );
        $has_uploads_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $uploads_table_like ) ) === $uploads_table );

        $table_has_submission_link = false;
        $sub_col = null;
        $table_data_col = null;
        $form_col = null;
        $uploads_columns = array();

        if ( $has_uploads_table ) {
            $schema = self::get_uploads_table_schema( $uploads_table );
            $uploads_columns = $schema['columns'];

            // Prüfe ob submission_id Spalte existiert.
            foreach ( array( 'submission_id', 'sub_id', 'submission', 'nf_sub_id' ) as $c ) {
                if ( in_array( $c, $uploads_columns, true ) ) {
                    $sub_col = $c;
                    break;
                }
            }

            // Prüfe ob data Spalte existiert.
            foreach ( array( 'data', 'upload_data', 'meta', 'file' ) as $c ) {
                if ( in_array( $c, $uploads_columns, true ) ) {
                    $table_data_col = $c;
                    break;
                }
            }

            // Prüfe ob form_id Spalte existiert.
            if ( in_array( 'form_id', $uploads_columns, true ) ) {
                $form_col = 'form_id';
            }

            $table_has_submission_link = ( $sub_col && $table_data_col );
        }

        // Meta-Query: Nur Submissions mit Upload-Daten.
        $meta_or = array( 'relation' => 'OR' );
        foreach ( $upload_field_ids as $field_id ) {
            $meta_or[] = array( 'key' => '_field_' . $field_id, 'value' => '', 'compare' => '!=' );
        }

        // WP_Query mit Crash-Sicherheit (max 1000 Submissions).
        $args = array(
            'post_type'              => 'nf_sub',
            'posts_per_page'         => 1000,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'ignore_sticky_posts'    => true,
            'date_query'             => array(
                array( 'column' => 'post_date', 'before' => $cutoff, 'inclusive' => true ),
            ),
            'meta_query'             => array(
                array( 'key' => '_form_id', 'value' => $fid ),
                $meta_or,
            ),
            'post_status'            => ( 'delete' === $sub_action )
                ? array_keys( get_post_stati() )
                : array_values( array_diff( array_keys( get_post_stati() ), array( 'trash' ) ) ),
        );

        $q = new WP_Query( $args );
        $ids = $q->posts;
        wp_reset_postdata();

        if ( empty( $ids ) ) {
            return 0;
        }

        $count = 0;

        // Iteriere durch Submissions.
        foreach ( $ids as $sid ) {
            // ERST: Tabellen-basierte Uploads (wenn submission_id vorhanden) - wie cleanup_files().
            if ( $table_has_submission_link ) {
                if ( $form_col ) {
                    $sql = "SELECT {$table_data_col} FROM {$uploads_table} WHERE {$sub_col} = %d AND {$form_col} = %d";
                    $rows = $wpdb->get_col( $wpdb->prepare( $sql, $sid, (int) $fid ) );
                } else {
                    $sql = "SELECT {$table_data_col} FROM {$uploads_table} WHERE {$sub_col} = %d";
                    $rows = $wpdb->get_col( $wpdb->prepare( $sql, $sid ) );
                }

                if ( ! empty( $rows ) ) {
                    foreach ( $rows as $raw ) {
                        // SICHERHEIT: safe_unserialize() statt maybe_unserialize() (POI-Schutz).
                        $parsed = self::safe_unserialize( $raw );
                        if ( empty( $parsed ) || ! is_array( $parsed ) ) {
                            continue;
                        }

                        // Intelligenter Check - überspringe externe Storage.
                        // $parsed ist die unserialized upload_data aus der Tabelle.
                        if ( self::smart_file_exists( $parsed ) ) {
                            $count++;
                        }
                    }
                }
            }

            // DANN: Meta-basierte Uploads (legacy).
            foreach ( $upload_field_ids as $field_id ) {
                // Aus WordPress Cache gelesen (kein DB-Query da wir update_metadata_cache nicht aufrufen).
                $raw = get_post_meta( $sid, '_field_' . $field_id, true );

                if ( empty( $raw ) ) {
                    continue;
                }

                // SICHERHEIT: safe_unserialize() statt maybe_unserialize() (POI-Schutz).
                $val = self::safe_unserialize( $raw );

                // JSON-Decode wenn nötig.
                if ( is_string( $val ) ) {
                    $trim = trim( $val );
                    if ( '' !== $trim && ( 0 === strpos( $trim, '[' ) || 0 === strpos( $trim, '{' ) ) ) {
                        $json = json_decode( $trim, true );
                        if ( JSON_ERROR_NONE === json_last_error() ) {
                            $val = $json;
                        }
                    }
                }

                // Zähle Dateien (vereinfacht - zählt nur Array-Länge, nicht einzelne Existenz).
                if ( is_array( $val ) ) {
                    $count += count( $val );
                } elseif ( ! empty( $val ) ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Intelligenter file_exists Check - inspiriert vom NF File Uploads Add-on.
     * Vermeidet Disk-Checks für externe Storage (OneDrive, S3, etc.) und nutzt Flags.
     *
     * @param array $upload_data Upload-Daten aus der Tabelle.
     *
     * @return bool
     */
    private static function smart_file_exists( $upload_data ) {
        if ( ! is_array( $upload_data ) ) {
            return false;
        }

        // SCHNELL: Externe Storage (OneDrive, S3, etc.) = nicht auf Server.
        // Diese Uploads werden NICHT gezählt, da sie bereits verschoben wurden.
        if ( isset( $upload_data['upload_location'] ) && 'server' !== $upload_data['upload_location'] ) {
            return false;
        }

        // SCHNELL: Flag-Check (kein Disk-Check).
        if ( isset( $upload_data['removed_from_server'] ) && $upload_data['removed_from_server'] ) {
            return false;
        }

        // NUR HIER: Tatsächlicher Disk-Check für lokale Server-Uploads.
        if ( isset( $upload_data['file_path'] ) && '' !== $upload_data['file_path'] ) {
            // WordPress-Funktion bevorzugt (sicherer als direkter file_exists).
            return file_exists( $upload_data['file_path'] );
        }

        return false;
    }

    /**
     * Ermittelt die Upload-Feld-IDs eines Formulars.
     *
     * @param int $fid Formular-ID.
     *
     * @return array<int> Upload-Feld-IDs.
     */
    private static function get_upload_field_ids( $fid ) {
        $upload_field_ids = array();

        if ( ! function_exists( 'Ninja_Forms' ) ) {
            return $upload_field_ids;
        }

        $nf_form = Ninja_Forms()->form( $fid );
        $fields = $nf_form ? $nf_form->get_fields() : array();

        if ( ! is_iterable( $fields ) ) {
            return $upload_field_ids;
        }

        foreach ( $fields as $f ) {
            if ( is_object( $f ) && method_exists( $f, 'get_setting' ) && 'file_upload' === $f->get_setting( 'type' ) ) {
                $upload_field_ids[] = (int) $f->get_id();
            }
        }

        return $upload_field_ids;
    }

    // =============================================================================
    // PUBLIC API
    // =============================================================================

    /* --- Entry-Point: Submission Cleanup --- */

    /**
     * Bereinigt alle Upload-Dateien einer Submission.
     *
     * Liest die File-Upload-Felder des Formulars aus, normalisiert mögliche Speicherformate
     * (Pfad, URL, serialisiertes Array, JSON) und löscht anschließend die physischen Dateien.
     *
     * @param int $sid Submission-ID.
     *
     * @return array{deleted:int,errors:int}
     */
    public static function cleanup_files( $sid ) {
        $stats = [ 'deleted' => 0, 'errors' => 0 ];
        $fid = get_post_meta( $sid, '_form_id', true );
        if ( ! $fid ) {
            return $stats;
        }

        global $wpdb;

        // Prefer the official Ninja Forms File Uploads add-on table when available.
        $uploads_table = $wpdb->prefix . 'ninja_forms_uploads';
        $uploads_table_like = $wpdb->esc_like( $uploads_table );
        $has_uploads_table  = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $uploads_table_like ) ) === $uploads_table );

        if ( $has_uploads_table ) {
            $schema         = self::get_uploads_table_schema( $uploads_table );
            $uploads_columns = $schema['columns'];

            $sub_col  = null;
            foreach ( array( 'submission_id', 'sub_id', 'submission', 'nf_sub_id' ) as $c ) {
                if ( in_array( $c, $uploads_columns, true ) ) {
                    $sub_col = $c;
                    break;
                }
            }

            $form_col = in_array( 'form_id', $uploads_columns, true ) ? 'form_id' : null;
            $data_col = null;
            foreach ( array( 'data', 'upload_data', 'meta', 'file' ) as $c ) {
                if ( in_array( $c, $uploads_columns, true ) ) {
                    $data_col = $c;
                    break;
                }
            }

            if ( $data_col ) {
                // If the table links uploads to submissions, we can delete per-submission.
                if ( $sub_col ) {
                    // Fetch all upload records for this submission.
                    if ( $form_col ) {
                        $sql  = "SELECT {$data_col} FROM {$uploads_table} WHERE {$sub_col} = %d AND {$form_col} = %d";
                        $rows = $wpdb->get_col( $wpdb->prepare( $sql, $sid, (int) $fid ) );
                    } else {
                        $sql  = "SELECT {$data_col} FROM {$uploads_table} WHERE {$sub_col} = %d";
                        $rows = $wpdb->get_col( $wpdb->prepare( $sql, $sid ) );
                    }

                    if ( ! empty( $rows ) ) {
                        foreach ( $rows as $raw ) {
                            $parsed = self::normalize_upload_data( $raw );
                            if ( empty( $parsed ) ) {
                                continue;
                            }

                            if ( is_array( $parsed ) ) {
                                foreach ( $parsed as $ref ) {
                                    $res = self::delete_file_reference( $ref );
                                    $stats['deleted'] += $res['deleted'];
                                    $stats['errors']  += $res['errors'];
                                }
                            } else {
                                $res = self::delete_file_reference( $parsed );
                                $stats['deleted'] += $res['deleted'];
                                $stats['errors']  += $res['errors'];
                            }
                        }
                        // Do not return here; allow legacy meta-based deletion to proceed for mixed installations.
                    }
                }
            }
        }

        // Formular-Objekt laden, um File-Upload-Felder sicher zu identifizieren.
        if ( ! function_exists( 'Ninja_Forms' ) ) {
            return $stats;
        }
        $form   = Ninja_Forms()->form( $fid );
        $fields = $form ? $form->get_fields() : [];

        if ( ! is_iterable( $fields ) ) {
            return $stats;
        }

        foreach ( $fields as $f ) {
            if ( $f->get_setting( 'type' ) !== 'file_upload' ) {
                continue;
            }

            $raw_val = get_post_meta( $sid, '_field_' . $f->get_id(), true );
            // SICHERHEIT: safe_unserialize() statt maybe_unserialize() (POI-Schutz).
            $val = self::safe_unserialize( $raw_val );

            // JSON-Strings erkennen und decodieren (einige Setups speichern Upload-Meta als JSON).
            if (
                is_string( $val ) &&
                (
                    strpos( trim( $val ), '[' ) === 0 ||
                    strpos( trim( $val ), '{' ) === 0
                )
            ) {
                $json = json_decode( $val, true );
                if ( json_last_error() === JSON_ERROR_NONE ) {
                    $val = $json;
                }
            }

            // Attachment-ID support (e.g., when "Save to Media Library" is enabled).
            if ( is_numeric( $val ) ) {
                $file = get_attached_file( (int) $val );
                if ( $file ) {
                    $val = $file;
                }
            }

            if ( $val ) {
                $res = self::del( $val );
                $stats['deleted'] += $res['deleted'];
                $stats['errors'] += $res['errors'];
            }
        }
        return $stats;
    }

    /**
     * Bereinigt Uploads aus der offiziellen Ninja Forms File Uploads Add-on Tabelle.
     *
     * NEUE ARCHITEKTUR: Löscht ALLE Uploads basierend auf form_id + cutoff_date.
     * Die submission_id ist irrelevant - Uploads werden unabhängig von Submissions gelöscht.
     * Dies ermöglicht klare Trennung: Uploads Deleter = Files, Submissions Eraser = Submissions.
     *
     * BUGFIX: Unterstützt Pagination via $min_id um Endlosschleifen zu vermeiden,
     * wenn Dateien übersprungen werden (externe Storage).
     *
     * @param int    $fid    Formular-ID.
     * @param string $cutoff Cutoff-Datum im Format 'Y-m-d H:i:s' (WordPress-Zeitzone).
     * @param int    $limit  Maximale Anzahl Zeilen pro Batch.
     * @param int    $min_id Minimale ID für Pagination (Standard: 0).
     *
     * @return array{deleted:int,errors:int,rows:int,last_id:int}
     */
    public static function cleanup_uploads_for_form( $fid, $cutoff, $limit = 50, $min_id = 0 ) {
        $stats = [ 'deleted' => 0, 'errors' => 0, 'rows' => 0, 'last_id' => 0 ];

        $fid    = absint( $fid );
        $limit  = max( 1, absint( $limit ) );
        $min_id = max( 0, absint( $min_id ) );

        if ( ! $fid || ! is_string( $cutoff ) || '' === $cutoff ) {
            $stats['errors']++;
            return $stats;
        }

        global $wpdb;

        $uploads_table = $wpdb->prefix . 'ninja_forms_uploads';
        $uploads_table_like = $wpdb->esc_like( $uploads_table );
        $has_uploads_table  = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $uploads_table_like ) ) === $uploads_table );
        if ( ! $has_uploads_table ) {
            return $stats;
        }

        $schema          = self::get_uploads_table_schema( $uploads_table );
        $uploads_columns = $schema['columns'];
        $uploads_types   = $schema['types'];

        $data_col = in_array( 'data', $uploads_columns, true ) ? 'data' : null;
        $date_col = self::detect_uploads_date_column( $uploads_columns );
        $form_col = in_array( 'form_id', $uploads_columns, true ) ? 'form_id' : null;
        $id_col   = in_array( 'id', $uploads_columns, true ) ? 'id' : null;

        // BUGFIX: Ohne ID-Spalte ist keine Pagination möglich.
        if ( ! $data_col || ! $date_col || ! $form_col || ! $id_col ) {
            // DEBUG-LOGGING (Code-Audit Fix): Logge welche Spalte fehlt für Troubleshooting.
            self::debug_log( 'Schema detection failed for uploads table', [
                'table' => $uploads_table,
                'columns_found' => $uploads_columns,
                'data_col' => $data_col,
                'date_col' => $date_col,
                'form_col' => $form_col,
                'id_col' => $id_col,
                'form_id' => $fid,
            ] );
            $stats['errors']++;
            return $stats;
        }

        $date_is_int = self::is_int_timestamp_column( $date_col, $uploads_types );
        $date_placeholder = $date_is_int ? '%d' : '%s';
        $cutoff_value = $date_is_int ? (int) strtotime( $cutoff ) : $cutoff;

        $deleted_col = self::detect_deleted_column( $uploads_columns );
        $deleted_sql = '';
        $deleted_params = array();

        // Versuche, bereits als gelöscht markierte Uploads auszuschließen (matching mit Add-on UI).
        if ( $deleted_col ) {
            if ( 'status' === $deleted_col ) {
                $deleted_sql    = " AND {$deleted_col} NOT IN (%s,%s)";
                $deleted_params = array( 'deleted', 'trash' );
            } else {
                $deleted_sql    = " AND ( {$deleted_col} = 0 OR {$deleted_col} IS NULL OR {$deleted_col} = '' )";
            }
        }

        // Lade Kandidaten-Zeilen (id + data + date) für dieses Formular, die älter als Cutoff sind.
        // BUGFIX: Pagination via min_id um Endlosschleifen bei übersprungenen Dateien zu vermeiden.
        // BUGFIX: Verwende $id_col statt hardcoded "id" für Kompatibilität.
        // NEU: date_col wird mitgeladen für das Log ("Eintrag vom" = Datei-Erstellungsdatum).
        $sql  = "SELECT {$id_col}, {$data_col}, {$date_col} FROM {$uploads_table} WHERE {$form_col} = %d AND {$date_col} < {$date_placeholder}{$deleted_sql} AND {$id_col} > %d ORDER BY {$id_col} ASC LIMIT %d";
        $params = array_merge( array( $fid, $cutoff_value ), $deleted_params, array( $min_id, $limit ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        if ( empty( $rows ) ) {
            return $stats;
        }

        $stats['rows'] = count( $rows );

        foreach ( $rows as $row ) {
            // BUGFIX: Verwende $id_col statt hardcoded "id".
            $row_id = isset( $row[ $id_col ] ) ? absint( $row[ $id_col ] ) : 0;
            $raw    = isset( $row[ $data_col ] ) ? $row[ $data_col ] : '';

            // Datei-Datum für Log extrahieren ("Eintrag vom").
            // Bei INT-Timestamp in DATETIME konvertieren, sonst direkt verwenden.
            $row_date_raw = isset( $row[ $date_col ] ) ? $row[ $date_col ] : '';
            if ( $date_is_int && is_numeric( $row_date_raw ) && (int) $row_date_raw > 0 ) {
                $row_date = wp_date( 'Y-m-d H:i:s', (int) $row_date_raw );
            } else {
                $row_date = is_string( $row_date_raw ) ? $row_date_raw : '';
            }

            // BUGFIX: Tracke die höchste ID für Pagination - MUSS immer gesetzt werden für Fortschritt.
            if ( $row_id > $stats['last_id'] ) {
                $stats['last_id'] = $row_id;
            }

            $parsed = self::normalize_upload_data( $raw );
            if ( empty( $parsed ) ) {
                $stats['errors']++;
                continue;
            }

            // BUGFIX #4: Pre-Check entfernt, da del() bereits file_exists() prüft.
            // Der alte Pre-Check prüfte nur das ERSTE File im Array und übersprang
            // fälschlicherweise ALLE Files, auch wenn nachfolgende Files existierten.
            //
            // Neuer Ansatz: Lösche direkt. del() prüft selbst ob File existiert und
            // löscht nur existierende Files. Nicht-existente Files werden sauber ignoriert.

            $del_stats = array( 'deleted' => 0, 'errors' => 0 );

            // KRITISCH (Code-Audit Fix): Prüfe ob Dateien auf lokalem Server existieren BEVOR gelöscht wird.
            // Verhindert Datenverlust bei externem Storage (S3, OneDrive, etc.):
            // - Wenn Datei NICHT lokal existiert (weil auf S3/Cloud), wird DB-Eintrag NICHT gelöscht
            // - Die Cloud-Referenz bleibt erhalten für manuelles Cleanup oder offizielle Cloud-Integration
            // - Dry-Run und echter Lauf nutzen jetzt EXAKT dieselbe Logik (Konsistenz)
            if ( is_array( $parsed ) ) {
                // Bei Arrays: Prüfe ob MINDESTENS EINE Datei lokal existiert.
                // Nur dann löschen - sonst ist das ein externes Storage Szenario.
                $has_local_file = false;
                foreach ( $parsed as $ref ) {
                    if ( self::file_reference_exists_locally( $ref ) ) {
                        $has_local_file = true;
                        break;
                    }
                }

                // Wenn KEINE lokale Datei existiert: Überspringe KOMPLETT (DB-Eintrag behalten).
                // Das schützt vor Datenverlust bei S3/OneDrive/etc.
                if ( ! $has_local_file ) {
                    self::debug_log( 'Skipping external storage upload (no local files)', [
                        'row_id' => $row_id,
                        'form_id' => $fid,
                    ] );
                    continue; // Nächste Zeile, DB-Eintrag bleibt erhalten
                }

                // Jede Datei einzeln löschen und loggen.
                foreach ( $parsed as $ref ) {
                    $res = self::delete_file_reference( $ref );
                    $del_stats['deleted'] += $res['deleted'];
                    $del_stats['errors']  += $res['errors'];

                    // EINZELNER LOG-EINTRAG pro erfolgreich gelöschter Datei.
                    if ( $res['deleted'] > 0 && class_exists( 'NF_AD_Logger' ) ) {
                        $file_name = self::extract_file_name_from_reference( $ref );
                        NF_AD_Logger::log(
                            (int) $fid,
                            0, // Submission-ID 0 = nicht an Submission gebunden
                            $row_date, // Datei-Erstellungsdatum als "Eintrag vom"
                            'success',
                            '[FILE] ' . $file_name
                        );
                    }
                }
            } else {
                // Einzelne Referenz: Prüfe ob lokal vorhanden.
                if ( ! self::file_reference_exists_locally( $parsed ) ) {
                    self::debug_log( 'Skipping external storage upload (no local file)', [
                        'row_id' => $row_id,
                        'form_id' => $fid,
                    ] );
                    continue; // Nächste Zeile, DB-Eintrag bleibt erhalten
                }

                $res = self::delete_file_reference( $parsed );
                $del_stats['deleted'] += $res['deleted'];
                $del_stats['errors']  += $res['errors'];

                // EINZELNER LOG-EINTRAG pro erfolgreich gelöschter Datei.
                if ( $res['deleted'] > 0 && class_exists( 'NF_AD_Logger' ) ) {
                    $file_name = self::extract_file_name_from_reference( $parsed );
                    NF_AD_Logger::log(
                        (int) $fid,
                        0, // Submission-ID 0 = nicht an Submission gebunden
                        $row_date, // Datei-Erstellungsdatum als "Eintrag vom"
                        'success',
                        '[FILE] ' . $file_name
                    );
                }
            }

            $stats['deleted'] += $del_stats['deleted'];
            $stats['errors']  += $del_stats['errors'];

            // BUGFIX (Code-Audit): DB-Eintrag löschen wenn KEINE Fehler auftraten (unabhängig von deleted).
            //
            // WARUM diese Änderung?
            // Alte Logik: Nur löschen wenn `deleted > 0` UND `errors = 0`
            // Problem: Wenn Dateien bereits manuell gelöscht wurden (z.B. via FTP):
            //   - deleted = 0 (keine Datei wurde gelöscht, weil keine existierte)
            //   - errors = 0 (kein Fehler)
            //   - DB-Zeile blieb bestehen → "Zombie Row"
            //   - Bei jedem Cron-Run wurde dieselbe Zeile wieder geprüft (Performance-Problem)
            //
            // Neue Logik: Löschen wenn `errors = 0` (unabhängig von deleted-Wert)
            // Rationale:
            //   - errors = 0 bedeutet: "Alles okay, keine Probleme aufgetreten"
            //   - Entweder wurden Dateien gelöscht (deleted > 0) → Ziel erreicht
            //   - ODER Dateien existieren nicht mehr (deleted = 0) → Ziel bereits erreicht
            //   - In beiden Fällen ist der DB-Eintrag obsolet und sollte weg
            //
            // Sicherheit:
            //   - Wenn echte Fehler auftreten (Permission Denied, etc.): errors > 0
            //   - Dann wird DB-Zeile NICHT gelöscht (konservativ, sicher)
            //   - Beim nächsten Run wird erneut versucht
            if ( $row_id && 0 === $del_stats['errors'] ) {
                $result = $wpdb->delete( $uploads_table, [ 'id' => $row_id ], [ '%d' ] );

                // ERROR-LOGGING: DB-Fehler protokollieren für Debugging.
                if ( false === $result && ! empty( $wpdb->last_error ) ) {
                    error_log( '[NF Auto Delete] DB delete failed for upload ID ' . $row_id . ': ' . $wpdb->last_error );
                    $stats['errors']++;
                }
            }
        }

        self::debug_log( 'Cleanup batch completed for form', [
            'form_id' => $fid,
            'deleted' => $stats['deleted'],
            'errors' => $stats['errors'],
            'rows_processed' => $stats['rows'],
            'last_id' => $stats['last_id'],
        ] );

        return $stats;
    }

    // =============================================================================
    // DELETE ROUTINE
    // =============================================================================

    /* --- Rekursives Löschen (Datei oder Array) --- */

    /**
     * Maximale Rekursionstiefe für del() (Schutz vor Stack Overflow).
     *
     * SICHERHEIT (Code-Audit Fix): Verhindert Endlosrekursion bei fehlerhaften Daten.
     * Upload-Daten sollten maximal 3-4 Level tief sein. 10 Level bieten großzügigen Puffer.
     */
    const MAX_RECURSION_DEPTH = 10;

    /**
     * Löscht eine Datei oder eine Liste von Dateien (rekursiv mit Tiefenlimit).
     *
     * Unterstützte Eingaben:
     * - Absoluter Pfad
     * - URL (wird in Upload-Pfad umgerechnet)
     * - Array (mehrere Dateien)
     *
     * Sicherheitschecks:
     * - Keine Symlinks
     * - Nur innerhalb des WordPress Upload-Verzeichnisses
     * - Maximale Rekursionstiefe (verhindert Stack Overflow)
     *
     * @param mixed $val   Dateireferenz.
     * @param int   $depth Aktuelle Rekursionstiefe (intern, nicht manuell setzen).
     *
     * @return array{deleted:int,errors:int}
     */
    private static function del( $val, $depth = 0 ) {
        $stats = [ 'deleted' => 0, 'errors' => 0 ];

        // SICHERHEIT: Rekursionstiefe prüfen (verhindert Stack Overflow bei fehlerhaften Daten).
        if ( $depth > self::MAX_RECURSION_DEPTH ) {
            self::debug_log( 'Max recursion depth exceeded in del()', [
                'depth' => $depth,
                'max' => self::MAX_RECURSION_DEPTH,
            ] );
            $stats['errors']++;
            return $stats;
        }

        // Mehrfach-Uploads: Arrays rekursiv abarbeiten (mit Tiefenzähler).
        if ( is_array( $val ) ) {
            foreach ( $val as $v ) {
                $res = self::del( $v, $depth + 1 );
                $stats['deleted'] += $res['deleted'];
                $stats['errors'] += $res['errors'];
            }
            return $stats;
        }

        $path = '';
        if ( is_string( $val ) && $val !== '' ) {
            if ( file_exists( $val ) ) {
                $path = $val;
            } elseif ( strpos( $val, 'http' ) !== false ) {
                $clean_url = strtok( $val, '?' );
                $path = self::url_to_path( $clean_url );
            }
        }

        // Pfad auflösen und Existenz prüfen, bevor Sicherheitschecks starten.
        if ( $path && file_exists( $path ) ) {

            // Symlink-Schutz: Symlinks werden nie gelöscht. Prüfung erfolgt vor realpath().
            if ( is_link( $path ) ) {
                $stats['errors']++;
                // Keine Symlinks löschen (Angriffsvektor über Pfad-Manipulation).
                return $stats;
            }

            $real_path = realpath( $path );

            // Jail-Check: Löschen ist ausschließlich im Upload-Verzeichnis erlaubt.
            $upload_dir = wp_upload_dir();
            $real_base = realpath( $upload_dir['basedir'] );

            // Nach realpath, dann normalisieren für Vergleich
            $real_path = $real_path ? wp_normalize_path( $real_path ) : '';
            $real_base = $real_base ? trailingslashit( wp_normalize_path( $real_base ) ) : '';

            $check_path = trailingslashit( $real_path );
            if ( $real_base && $real_path && 0 === strpos( $check_path, $real_base ) ) {
                if ( '' === $real_path ) {
                    $stats['errors']++;
                    return $stats;
                }
                wp_delete_file( $real_path );

                if ( ! file_exists( $real_path ) ) {
                    $stats['deleted']++;
                } else {
                    $stats['errors']++;
                }
            } else {
                // Pfad liegt außerhalb des Upload-Verzeichnisses: aus Sicherheitsgründen abbrechen.
                $stats['errors']++;
            }
        }
        return $stats;
    }

    // =============================================================================
    // HELPER
    // =============================================================================

    /* --- URL zu Pfad --- */

    /**
     * Konvertiert eine Upload-URL in einen lokalen Pfad im Upload-Verzeichnis.
     *
     * @param string $url Upload-URL.
     *
     * @return string
     */
    private static function url_to_path( $url ) {
        $d = wp_upload_dir();
        $baseurl = $d['baseurl'];
        if ( is_string( $url ) && $baseurl && 0 === strpos( $url, $baseurl ) ) {
            return str_replace( $baseurl, $d['basedir'], $url );
        }
        return '';
    }

    /**
     * Extrahiert den Dateinamen aus einer Datei-Referenz für das Log.
     *
     * Unterstützt alle gängigen Referenz-Formate:
     * - Array mit file_name, file_path, path, file_url, url
     * - String (Pfad oder URL)
     * - Attachment-ID (lädt Dateiname aus Media Library)
     *
     * @param mixed $ref Datei-Referenz.
     *
     * @return string Dateiname (oder "Unbekannte Datei" als Fallback).
     */
    private static function extract_file_name_from_reference( $ref ) {
        // Array: Verschiedene Felder prüfen.
        if ( is_array( $ref ) ) {
            // Direkter file_name ist am besten.
            if ( isset( $ref['file_name'] ) && is_string( $ref['file_name'] ) && '' !== $ref['file_name'] ) {
                return basename( $ref['file_name'] );
            }

            // Aus Pfad extrahieren.
            if ( isset( $ref['file_path'] ) && is_string( $ref['file_path'] ) && '' !== $ref['file_path'] ) {
                return basename( $ref['file_path'] );
            }
            if ( isset( $ref['path'] ) && is_string( $ref['path'] ) && '' !== $ref['path'] ) {
                return basename( $ref['path'] );
            }

            // Aus URL extrahieren (ohne Query-String).
            if ( isset( $ref['file_url'] ) && is_string( $ref['file_url'] ) && '' !== $ref['file_url'] ) {
                return basename( strtok( $ref['file_url'], '?' ) );
            }
            if ( isset( $ref['url'] ) && is_string( $ref['url'] ) && '' !== $ref['url'] ) {
                return basename( strtok( $ref['url'], '?' ) );
            }

            // Attachment-ID: Aus Media Library laden.
            if ( isset( $ref['attachment_id'] ) && is_numeric( $ref['attachment_id'] ) ) {
                $file = get_attached_file( (int) $ref['attachment_id'] );
                if ( $file ) {
                    return basename( $file );
                }
            }

            return 'Unbekannte Datei';
        }

        // Attachment-ID (numerisch).
        if ( is_numeric( $ref ) ) {
            $file = get_attached_file( (int) $ref );
            if ( $file ) {
                return basename( $file );
            }
            return 'Attachment #' . (int) $ref;
        }

        // String: Pfad oder URL.
        if ( is_string( $ref ) && '' !== $ref ) {
            // URL: Query-String entfernen.
            if ( false !== strpos( $ref, 'http' ) ) {
                return basename( strtok( $ref, '?' ) );
            }
            return basename( $ref );
        }

        return 'Unbekannte Datei';
    }

    /**
     * Sichere Deserialisierung von Daten OHNE Objekt-Instanziierung.
     *
     * SICHERHEIT (Code-Audit Fix): Verhindert PHP Object Injection (POI).
     * `maybe_unserialize()` könnte bei bösartigen Payloads Objekte instanziieren,
     * die via __wakeup() oder __destruct() Code ausführen.
     *
     * Diese Funktion erlaubt NUR skalare Werte und Arrays (keine Objekte).
     *
     * @param mixed $data Rohe Daten (möglicherweise serialisiert).
     *
     * @return mixed Deserialisierte Daten (nur Arrays/Strings/Integers, niemals Objekte).
     */
    private static function safe_unserialize( $data ) {
        // Keine Deserialisierung nötig wenn nicht serialisiert.
        if ( ! is_string( $data ) ) {
            return $data;
        }

        // WordPress' is_serialized() Check.
        if ( ! is_serialized( $data ) ) {
            return $data;
        }

        // SICHERHEIT: allowed_classes => false verhindert Objekt-Instanziierung.
        // Selbst wenn ein Angreifer einen bösartigen Payload in die DB injiziert,
        // werden keine Objekte erstellt → kein __wakeup()/__destruct() Exploit möglich.
        $unserialized = @unserialize( $data, array( 'allowed_classes' => false ) );

        // Bei Fehler: Original zurückgeben (defensiv).
        if ( false === $unserialized && 'b:0;' !== $data ) {
            return $data;
        }

        return $unserialized;
    }

    /**
     * Normalize upload data from the File Uploads add-on table.
     * Supports serialized values, JSON, and mixed arrays.
     *
     * @param mixed $raw Raw DB value.
     *
     * @return mixed
     */
    private static function normalize_upload_data( $raw ) {
        // SICHERHEIT: Nutze safe_unserialize() statt maybe_unserialize() (POI-Schutz).
        $val = self::safe_unserialize( $raw );

        if ( is_string( $val ) ) {
            $trim = trim( $val );
            if ( '' !== $trim && ( 0 === strpos( $trim, '[' ) || 0 === strpos( $trim, '{' ) ) ) {
                $json = json_decode( $trim, true );
                if ( JSON_ERROR_NONE === json_last_error() ) {
                    $val = $json;
                }
            }
        }

        // Common structures: array of uploads, single upload array with url/path, or direct string.
        if ( is_array( $val ) ) {
            // If it looks like a single upload object.
            if ( isset( $val['file_url'] ) || isset( $val['url'] ) || isset( $val['path'] ) || isset( $val['file_path'] ) || isset( $val['attachment_id'] ) || isset( $val['file_name'] ) ) {
                return array( $val );
            }

            return $val;
        }

        return $val;
    }

    /**
     * Delete a single file reference coming from either submission meta or the uploads table.
     * Supported reference formats:
     * - string URL
     * - string absolute path
     * - numeric attachment ID
     * - array containing file_url/url/path/file_path/attachment_id
     *
     * @param mixed $ref File reference.
     *
     * @return array{deleted:int,errors:int}
     */
    private static function delete_file_reference( $ref ) {
        $stats = [ 'deleted' => 0, 'errors' => 0 ];

        if ( is_array( $ref ) ) {
            if ( isset( $ref['attachment_id'] ) && is_numeric( $ref['attachment_id'] ) ) {
                $ref = (int) $ref['attachment_id'];
            } elseif ( isset( $ref['file_path'] ) && is_string( $ref['file_path'] ) ) {
                $ref = $ref['file_path'];
            } elseif ( isset( $ref['path'] ) && is_string( $ref['path'] ) ) {
                $ref = $ref['path'];
            } elseif ( isset( $ref['file_url'] ) && is_string( $ref['file_url'] ) ) {
                $ref = $ref['file_url'];
            } elseif ( isset( $ref['url'] ) && is_string( $ref['url'] ) ) {
                $ref = $ref['url'];
            } elseif ( isset( $ref['file_name'] ) && is_string( $ref['file_name'] ) ) {
                // File Uploads add-on sometimes stores only a file name. Try common subdirectories.
                $uploads   = wp_upload_dir();
                $file_name = ltrim( $ref['file_name'], '/\\' );

                $candidates = array(
                    trailingslashit( $uploads['basedir'] ) . $file_name,
                    trailingslashit( $uploads['basedir'] ) . 'ninja-forms/' . $file_name,
                    trailingslashit( $uploads['basedir'] ) . 'ninja_forms/' . $file_name,
                    trailingslashit( $uploads['basedir'] ) . 'ninja-forms/uploads/' . $file_name,
                    trailingslashit( $uploads['basedir'] ) . 'nf-uploads/' . $file_name,
                );

                $picked = '';
                foreach ( $candidates as $candidate ) {
                    if ( file_exists( $candidate ) ) {
                        $picked = $candidate;
                        break;
                    }
                }

                // If nothing exists, keep the default basedir candidate (del() will fail safely).
                $ref = $picked ? $picked : $candidates[0];
            } else {
                // Unknown array shape.
                $stats['errors']++;
                return $stats;
            }
        }

        // Attachment ID.
        if ( is_numeric( $ref ) ) {
            $file = get_attached_file( (int) $ref );
            if ( $file ) {
                $res = self::del( $file );
                $stats['deleted'] += $res['deleted'];
                $stats['errors']  += $res['errors'];
                return $stats;
            }
            $stats['errors']++;
            return $stats;
        }

        // URL/path string.
        if ( is_string( $ref ) && '' !== $ref ) {
            $res = self::del( $ref );
            $stats['deleted'] += $res['deleted'];
            $stats['errors']  += $res['errors'];
            return $stats;
        }

        $stats['errors']++;
        return $stats;
    }

    /**
     * Count uploads in the File Uploads add-on table for a given form older than cutoff,
     * but ONLY if the referenced file still exists on disk.
     *
     * Optimized: uses paging, request-level caching, and deduplicated path existence checks.
     */
    public static function count_uploads_for_form( $fid, $cutoff, $field_ids = array(), $limit = 2000 ) {
        $fid   = absint( $fid );
        $limit = max( 1, absint( $limit ) );

        if ( ! $fid || ! is_string( $cutoff ) || '' === $cutoff ) {
            return 0;
        }

        global $wpdb;

        $uploads_table      = $wpdb->prefix . 'ninja_forms_uploads';
        $uploads_table_like = $wpdb->esc_like( $uploads_table );
        $has_uploads_table  = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $uploads_table_like ) ) === $uploads_table );
        if ( ! $has_uploads_table ) {
            return 0;
        }

        $schema          = self::get_uploads_table_schema( $uploads_table );
        $uploads_columns = $schema['columns'];
        $uploads_types   = $schema['types'];

        $id_col   = in_array( 'id', $uploads_columns, true ) ? 'id' : null;
        $form_col = in_array( 'form_id', $uploads_columns, true ) ? 'form_id' : null;
        $date_col = self::detect_uploads_date_column( $uploads_columns );

        // Data column can vary.
        $data_col = null;
        foreach ( array( 'data', 'upload_data', 'meta', 'file' ) as $c ) {
            if ( in_array( $c, $uploads_columns, true ) ) {
                $data_col = $c;
                break;
            }
        }

        if ( ! $id_col || ! $form_col || ! $date_col || ! $data_col ) {
            return 0;
        }

        $date_is_int      = self::is_int_timestamp_column( $date_col, $uploads_types );
        $date_placeholder = $date_is_int ? '%d' : '%s';
        $cutoff_value     = $date_is_int ? (int) strtotime( $cutoff ) : $cutoff;

        // Match the same deleted/status filtering as cleanup.
        $deleted_col    = self::detect_deleted_column( $uploads_columns );
        $deleted_sql    = '';
        $deleted_params = array();
        if ( $deleted_col ) {
            if ( 'status' === $deleted_col ) {
                $deleted_sql    = " AND {$deleted_col} NOT IN (%s,%s)";
                $deleted_params = array( 'deleted', 'trash' );
            } else {
                $deleted_sql = " AND ( {$deleted_col} = 0 OR {$deleted_col} IS NULL OR {$deleted_col} = '' )";
            }
        }

        // Optional restriction by field_id.
        $field_sql    = '';
        $field_params = array();
        if ( in_array( 'field_id', $uploads_columns, true ) && is_array( $field_ids ) && ! empty( $field_ids ) ) {
            $field_ids = array_map( 'absint', $field_ids );
            $field_ids = array_filter( $field_ids );
            if ( ! empty( $field_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $field_ids ), '%d' ) );
                $field_sql    = " AND field_id IN ({$placeholders})";
                $field_params = $field_ids;
            }
        }

        // Cache upload base directory (normalized) once per request.
        static $uploads_cache = null;
        if ( null === $uploads_cache ) {
            $u = wp_upload_dir();
            $basedir = isset( $u['basedir'] ) ? (string) $u['basedir'] : '';
            $real_base = $basedir ? realpath( $basedir ) : false;
            $uploads_cache = array(
                'basedir'   => $basedir,
                'real_base' => $real_base ? trailingslashit( wp_normalize_path( $real_base ) ) : '',
            );
        }

        $real_base = (string) $uploads_cache['real_base'];
        if ( '' === $real_base ) {
            // If uploads basedir can't be resolved, never count anything (safe default).
            return 0;
        }

        // We page through rows by id to avoid huge memory and to keep runtime predictable.
        // We also dedupe file existence checks per resolved path.
        $count      = 0;
        $remaining  = $limit;
        $last_id    = 0;
        $batch_size = min( 500, $remaining );

        // Per-request memoization: path => bool exists.
        $exists_cache = array();

        while ( $remaining > 0 ) {
            $batch_size = min( 500, $remaining );

            $sql = "SELECT {$id_col}, {$data_col} FROM {$uploads_table} WHERE {$form_col} = %d AND {$date_col} < {$date_placeholder}{$deleted_sql}{$field_sql} AND {$id_col} > %d ORDER BY {$id_col} ASC LIMIT %d";
            $params = array_merge( array( $fid, $cutoff_value ), $deleted_params, $field_params, array( $last_id, $batch_size ) );

            $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $row_id = isset( $row[ $id_col ] ) ? absint( $row[ $id_col ] ) : 0;
                if ( $row_id > $last_id ) {
                    $last_id = $row_id;
                }

                $raw    = isset( $row[ $data_col ] ) ? $row[ $data_col ] : '';
                $parsed = self::normalize_upload_data( $raw );
                if ( empty( $parsed ) ) {
                    continue;
                }

                $refs = is_array( $parsed ) ? $parsed : array( $parsed );
                foreach ( $refs as $ref ) {
                    $path = self::resolve_file_reference_to_path( $ref );
                    if ( '' === $path ) {
                        continue;
                    }

                    // Normalize and enforce jail check cheaply (no realpath unless needed).
                    $path_norm = wp_normalize_path( (string) $path );

                    // Quick guard: if it's clearly not inside uploads basedir, skip.
                    if ( 0 !== strpos( trailingslashit( $path_norm ), $real_base ) ) {
                        // If the string is an URL that did not map to basedir, resolve_file_reference_to_path may return ''.
                        // If it returned a relative-ish string, it is unsafe to count.
                        continue;
                    }

                    if ( array_key_exists( $path_norm, $exists_cache ) ) {
                        if ( $exists_cache[ $path_norm ] ) {
                            $count++;
                        }
                        continue;
                    }

                    // One disk hit per unique path.
                    $exists = file_exists( $path_norm );
                    $exists_cache[ $path_norm ] = $exists;
                    if ( $exists ) {
                        $count++;
                    }
                }
            }

            $remaining -= count( $rows );
            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        return (int) $count;
    }

    private static function file_reference_exists( $ref ) {
        $path = self::resolve_file_reference_to_path( $ref );
        if ( ! $path ) {
            return false;
        }

        static $uploads_cache = null;
        if ( null === $uploads_cache ) {
            $u = wp_upload_dir();
            $basedir = isset( $u['basedir'] ) ? (string) $u['basedir'] : '';
            $real_base = $basedir ? realpath( $basedir ) : false;
            $uploads_cache = array(
                'real_base' => $real_base ? trailingslashit( wp_normalize_path( $real_base ) ) : '',
            );
        }

        $real_base = (string) $uploads_cache['real_base'];
        if ( '' === $real_base ) {
            return false;
        }

        $path_norm = wp_normalize_path( (string) $path );
        if ( 0 !== strpos( trailingslashit( $path_norm ), $real_base ) ) {
            return false;
        }

        return file_exists( $path_norm );
    }

    /**
     * Prüft ob eine Datei-Referenz auf eine LOKAL existierende Datei zeigt.
     *
     * KRITISCH für externes Storage Support (S3, OneDrive, Google Cloud, etc.):
     * Diese Funktion kombiniert smart_file_exists() Logik (Flag-Checks) mit
     * resolve_file_reference_to_path() um zu bestimmen, ob eine Datei tatsächlich
     * auf dem lokalen Server liegt.
     *
     * Drei-Stufen-Check:
     * 1. SCHNELL: upload_location Flag prüfen (OneDrive Worker setzt dies)
     * 2. SCHNELL: removed_from_server Flag prüfen
     * 3. LANGSAM: file_exists() auf aufgelöstem Pfad
     *
     * @param mixed $ref Datei-Referenz (Array, String, oder Attachment-ID).
     *
     * @return bool True wenn Datei lokal existiert, false wenn extern oder nicht vorhanden.
     */
    private static function file_reference_exists_locally( $ref ) {
        // STUFE 1 & 2: Flag-basierte Checks für bekannte externe Storage.
        // Diese Flags werden vom OneDrive Worker oder offiziellen Cloud-Integrationen gesetzt.
        if ( is_array( $ref ) ) {
            // OneDrive/S3 Worker setzt upload_location != 'server'.
            if ( isset( $ref['upload_location'] ) && 'server' !== $ref['upload_location'] ) {
                return false; // Externe Storage → nicht lokal
            }

            // OneDrive Worker kann auch removed_from_server Flag setzen.
            if ( isset( $ref['removed_from_server'] ) && $ref['removed_from_server'] ) {
                return false; // Als entfernt markiert → nicht lokal
            }
        }

        // STUFE 3: Pfad auflösen und tatsächliche Existenz prüfen.
        $path = self::resolve_file_reference_to_path( $ref );
        if ( ! $path || '' === $path ) {
            return false;
        }

        // Jail-Check: Nur Dateien im Upload-Verzeichnis sind valide.
        static $uploads_cache = null;
        if ( null === $uploads_cache ) {
            $u = wp_upload_dir();
            $basedir = isset( $u['basedir'] ) ? (string) $u['basedir'] : '';
            $real_base = $basedir ? realpath( $basedir ) : false;
            $uploads_cache = array(
                'real_base' => $real_base ? trailingslashit( wp_normalize_path( $real_base ) ) : '',
            );
        }

        $real_base = (string) $uploads_cache['real_base'];
        if ( '' === $real_base ) {
            return false; // Upload-Verzeichnis nicht auflösbar
        }

        $path_norm = wp_normalize_path( (string) $path );

        // Pfad muss innerhalb des Upload-Verzeichnisses liegen (Jail-Check).
        if ( 0 !== strpos( trailingslashit( $path_norm ), $real_base ) ) {
            return false; // Außerhalb Upload-Verzeichnis → nicht verarbeiten
        }

        // Finale Disk-Prüfung.
        return file_exists( $path_norm );
    }

    /**
     * Resolve a file reference into an absolute local path (if possible).
     *
     * Supported:
     * - attachment id (int)
     * - absolute path
     * - upload URL
     * - structured array with file_path/path/file_url/url/file_name/attachment_id
     *
     * @param mixed $ref File reference.
     *
     * @return string
     */
    private static function resolve_file_reference_to_path( $ref ) {
        // Structured array.
        if ( is_array( $ref ) ) {
            if ( isset( $ref['attachment_id'] ) && is_numeric( $ref['attachment_id'] ) ) {
                $ref = (int) $ref['attachment_id'];
            } elseif ( isset( $ref['file_path'] ) && is_string( $ref['file_path'] ) ) {
                $ref = $ref['file_path'];
            } elseif ( isset( $ref['path'] ) && is_string( $ref['path'] ) ) {
                $ref = $ref['path'];
            } elseif ( isset( $ref['file_url'] ) && is_string( $ref['file_url'] ) ) {
                $ref = $ref['file_url'];
            } elseif ( isset( $ref['url'] ) && is_string( $ref['url'] ) ) {
                $ref = $ref['url'];
            } elseif ( isset( $ref['file_name'] ) && is_string( $ref['file_name'] ) ) {
                // Same candidate logic as delete_file_reference().
                $uploads   = wp_upload_dir();
                $file_name = ltrim( $ref['file_name'], '/\\' );

                $candidates = array(
                    trailingslashit( $uploads['basedir'] ) . $file_name,
                    trailingslashit( $uploads['basedir'] ) . 'ninja-forms/' . $file_name,
                    trailingslashit( $uploads['basedir'] ) . 'ninja_forms/' . $file_name,
                    trailingslashit( $uploads['basedir'] ) . 'ninja-forms/uploads/' . $file_name,
                    trailingslashit( $uploads['basedir'] ) . 'nf-uploads/' . $file_name,
                );

                foreach ( $candidates as $candidate ) {
                    if ( file_exists( $candidate ) ) {
                        return $candidate;
                    }
                }

                // If nothing exists, return the most likely candidate.
                return $candidates[0];
            } else {
                return '';
            }
        }

        // Attachment ID.
        if ( is_numeric( $ref ) ) {
            $file = get_attached_file( (int) $ref );
            return $file ? (string) $file : '';
        }

        // String path or URL.
        if ( is_string( $ref ) && '' !== $ref ) {
            if ( false !== strpos( $ref, 'http' ) ) {
                $clean_url = strtok( $ref, '?' );
                return self::url_to_path( $clean_url );
            }
            return $ref;
        }

        return '';
    }
}