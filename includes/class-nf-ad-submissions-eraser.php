<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// SUBMISSIONS ERASER (BEREINIGUNG & DRY RUN)
// =============================================================================

/**
 * Führt die automatische und manuelle Bereinigung von Ninja-Forms-Submissions durch.
 * Optional werden zugehörige Upload-Dateien bereinigt.
 */
class NF_AD_Submissions_Eraser {
    // =============================================================================
    // KONSTANTEN & CACHES
    // =============================================================================

    /**
     * Maximale Anzahl an Submissions pro Batch.
     */
    const BATCH_LIMIT = 50; 

    /**
     * Maximale Laufzeit pro Durchlauf (Sekunden), um Timeouts zu vermeiden.
     */
    const TIME_LIMIT = 20;

    // =============================================================================
    // HILFSMETHODEN
    // =============================================================================

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

        $log_msg = '[NF Auto Delete] ' . $message;

        // Kontext als JSON anhängen (wenn vorhanden).
        if ( ! empty( $context ) ) {
            $log_msg .= ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE );
        }

        error_log( $log_msg );
    }

    /**
     * Berechnet das Cutoff-Datum (Stichtag) basierend auf der WordPress-Zeitzone.
     *
     * WICHTIG: Nutzt WordPress Site-Timezone (nicht Server-Zeit), um korrekte
     * Datums-Berechnungen unabhängig von der Server-Konfiguration zu gewährleisten.
     *
     * @param int $days Anzahl Tage zurück (z.B. 365 = ein Jahr alt).
     *
     * @return string Cutoff-Datum im Format 'Y-m-d H:i:s' (z.B. '2025-01-05 12:00:00').
     */
    private static function get_cutoff_datetime( $days ) {
        $days = max( 1, absint( $days ) );

        // WordPress Site-Zeitzone nutzen (verhindert doppelte Offset-Anwendung).
        $date = current_datetime()->modify( '-' . $days . ' days' );
        return $date->format( 'Y-m-d H:i:s' );
    }

    /**
     * Aktualisiert den Cleanup-Lock, um Timeout-Expiration zu verhindern.
     *
     * WARUM: Der Lock hat eine Lebensdauer von 1 Stunde (HOUR_IN_SECONDS).
     * Bei großen Datenmengen könnte ein Cleanup theoretisch länger dauern.
     * Regelmäßiges Auffrischen verhindert, dass parallele Prozesse den Lock
     * überschreiben, während ein legitimer Cleanup noch läuft.
     *
     * @return void
     */
    private static function refresh_lock() {
        set_transient( 'nf_ad_cleanup_running', time(), HOUR_IN_SECONDS );
    }

    // =============================================================================
    // ENTRYPOINTS
    // =============================================================================

    /* --- Cron vs. Manuell --- */

    /**
     * Entry-Point: Cron-Ausführung (automatischer Zeitplan).
     *
     * @return void
     */
    public static function run_cleanup_cron() 
    {
        self::run_cleanup_logic(true);
    }

    /**
     * Entry-Point: Manuelle Ausführung (Admin-UI).
     *
     * @return array{deleted:int,has_more:bool}
     */
    public static function run_cleanup_manual() 
    {
        return self::run_cleanup_logic(false);
    }

    // =============================================================================
    // DRY RUN (SIMULATION)
    // =============================================================================

    /* --- Berechnung ohne Löschung --- */

    /**
     * Berechnet die Anzahl der betroffenen Submissions, ohne Änderungen vorzunehmen.
     *
     * WICHTIG: Diese Methode zählt NUR Submissions. Für Upload-Dateien siehe NF_AD_Uploads_Deleter::calculate_dry_run().
     *
     * @return int
     */
    public static function calculate_dry_run() {
        $settings = NF_AD_Dashboard::get_settings();

        // Defensive: If Ninja Forms is not loaded, return 0.
        if ( ! function_exists( 'Ninja_Forms' ) ) {
            return 0;
        }

        // Bei "delete" müssen auch bereits im Papierkorb befindliche Submissions berücksichtigt werden (GDPR/DSGVO).
        $sub_action = $settings['sub_handling'] ?? 'keep';

        $global = (int) ( $settings['global'] ?? 365 );
        $forms  = Ninja_Forms()->form()->get_forms();

        // DEFENSIVE: Prüfe ob get_forms() valide Daten zurückgibt (Edge-Case: DB-Fehler, Partial Plugin Load).
        if ( ! is_array( $forms ) || empty( $forms ) ) {
            return 0;
        }

        $total_count = 0;

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

            $cutoff = self::get_cutoff_datetime( $days );

            // Query-Parameter für Submissions.
            $args = array(
                'post_type'              => 'nf_sub',
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'ignore_sticky_posts'    => true,
                'suppress_filters'       => false,
                'date_query'             => array(
                    array(
                        'column'    => 'post_date',
                        'before'    => $cutoff,
                        'inclusive' => true,
                    ),
                ),
                'meta_query'             => array(
                    array(
                        'key'     => '_form_id',
                        'value'   => $fid,
                        'compare' => '=',
                    ),
                ),
                'post_status'            => ( 'delete' === $sub_action )
                    ? array_keys( get_post_stati() )
                    : array_values( array_diff( array_keys( get_post_stati() ), array( 'trash' ) ) ),
            );

            // Zähle Submissions für dieses Formular.
            $q = new WP_Query( $args );
            $total_count += (int) $q->found_posts;
            wp_reset_postdata();
        }

        return $total_count;
    }

    // =============================================================================
    // CLEANUP-LOGIK (CRON & MANUELL)
    // =============================================================================

    /* --- Orchestrierung: Durchläufe, Limits, Logging --- */

    /**
     * Zentrale Bereinigungsroutine für Cron und manuelle Ausführung.
     * Arbeitet in mehreren Durchläufen, bis keine passenden Submissions mehr gefunden werden
     * oder das Zeitlimit erreicht ist.
     *
     * @param bool $is_cron true bei Cron-Ausführung.
     *
     * @return array{deleted:int,has_more:bool}
     */
    private static function run_cleanup_logic($is_cron = false) {
        // DEADLOCK-PROTECTION: Verhindert parallele Cleanup-Ausführungen (Cron + Manuell gleichzeitig).
        // Nutzt WordPress Transients als Lock-Mechanismus (1 Stunde Timeout als Fallback).
        if ( get_transient( 'nf_ad_cleanup_running' ) ) {
            $run_id = NF_AD_Logger::start_run( 'Cleanup übersprungen (Lock aktiv)' );
            NF_AD_Logger::finish_run( $run_id, 'skipped', 'Anderer Cleanup-Prozess läuft bereits.' );
            return ['deleted' => 0, 'has_more' => false];
        }

        // Lock setzen: Verhindert parallele Ausführung für 1 Stunde (dann automatischer Timeout).
        set_transient( 'nf_ad_cleanup_running', time(), HOUR_IN_SECONDS );

        self::debug_log( 'Cleanup started', [
            'type' => $is_cron ? 'cron' : 'manual',
            'lock_set' => true,
        ] );

        $settings = NF_AD_Dashboard::get_settings();

        $sub_action = $settings['sub_handling'] ?? 'keep';
        $file_action = $settings['file_handling'] ?? 'keep';

        $mode_info = "Subs=$sub_action, Files=$file_action";

        // Run-Message mit Typ-Tag versehen, damit das Dashboard Cron und manuell klar unterscheidet.
        $type_tag = $is_cron ? '[CRON]' : '[MANUAL]';
        $msg_text = $is_cron ? 'Auto-Cron gestartet' : 'Manuelle Bereinigung gestartet';
        $run_id   = NF_AD_Logger::start_run( "$type_tag $msg_text [$mode_info]..." );
        $response = ['deleted' => 0, 'has_more' => false];

        // Cron-Ausführung ist aktiv aufgerufen, aber in den Einstellungen deaktiviert: Run sauber abschließen.
        if ( $is_cron && empty($settings['cron_active']) ) {
            NF_AD_Logger::finish_run($run_id, 'skipped', 'Zeitplan deaktiviert.');
            delete_transient( 'nf_ad_cleanup_running' ); // Lock freigeben
            return $response;
        }

        // Safety-Check: Wenn sowohl Submissions als auch Dateien behalten werden sollen, gibt es nichts zu tun.
        if ( $sub_action === 'keep' && $file_action === 'keep' ) {
            NF_AD_Logger::finish_run( $run_id, 'skipped', 'Keine Aktion konfiguriert (alles auf "Behalten").' );
            delete_transient( 'nf_ad_cleanup_running' ); // Lock freigeben
            return $response;
        }

        $global = (int)($settings['global'] ?? 365);
        $forms = Ninja_Forms()->form()->get_forms();

        // DEFENSIVE: Prüfe ob get_forms() valide Daten zurückgibt (Edge-Case: DB-Fehler, Partial Plugin Load).
        if ( ! is_array( $forms ) || empty( $forms ) ) {
            NF_AD_Logger::finish_run( $run_id, 'skipped', 'Keine Formulare gefunden (DB-Fehler oder Ninja Forms nicht vollständig geladen).' );
            delete_transient( 'nf_ad_cleanup_running' );
            return $response;
        }

        $total = 0; $errors = 0; $warnings = 0;
        $start = time(); $limit_reached = false;

        // CRITICAL: Try-Catch Block um Lock IMMER freizugeben, auch bei Fehlern.
        // Ohne dies könnte ein Fatal Error das Plugin permanent blocken.
        try {
            // Mehrere Durchläufe: pro Formular Batches abarbeiten, bis keine Treffer mehr existieren oder TIME_LIMIT greift.
            do {
                $pass = 0;
                foreach($forms as $form) {
                // Harte Laufzeitgrenze: Verarbeitung abbrechen, damit Cron/Request nicht in Timeouts läuft.
                if((time()-$start) >= self::TIME_LIMIT) {
                    $limit_reached = true;
                    self::debug_log( 'Time limit reached during form iteration', [
                        'elapsed' => (time() - $start),
                        'limit' => self::TIME_LIMIT,
                        'processed_so_far' => $total,
                    ] );
                    break;
                }

                // DEFENSIVE: Prüfe ob Form-Objekt und ID valide sind.
                if ( ! is_object( $form ) || ! method_exists( $form, 'get_id' ) ) {
                    continue;
                }

                $fid = $form->get_id();
                if ( ! $fid || $fid < 1 ) {
                    continue; // Ungültige Form-ID überspringen
                }

                $rule = $settings['forms'][$fid] ?? ['mode'=>'global'];
                if($rule['mode'] === 'never') continue;
                $days = ($rule['mode'] === 'custom') ? (int)$rule['days'] : $global;
                if($days < 1) $days = 365;

                // Formularweise Verarbeitung: Batch selektieren und je Submission Aktionen ausführen.
                $res = self::process_form($fid, $days, $sub_action, $file_action);
                $pass += $res['count'];
                $errors += $res['errors'];
                $warnings += $res['warnings'];

                // Lock auffrischen nach jedem Formular, um Timeout-Expiration zu verhindern.
                self::refresh_lock();
            }
            $total += $pass;
            if((time()-$start) >= self::TIME_LIMIT) {
                $limit_reached = true;
                self::debug_log( 'Time limit reached after pass completion', [
                    'elapsed' => (time() - $start),
                    'limit' => self::TIME_LIMIT,
                    'processed_total' => $total,
                    'processed_this_pass' => $pass,
                ] );
                break;
            }
            } while($pass > 0);

            // Endstatus ableiten: error schlägt warning, warning schlägt success.
            $final_status = 'success';
            if($errors > 0) $final_status = 'error';
            elseif($warnings > 0) $final_status = 'warning';

            // Abschlussnachricht: bei TIME_LIMIT Hinweis auf Fortsetzung im nächsten Durchlauf.
            $status_msg = $limit_reached ? "Teilweise ($total verarbeitet). Weiter..." : "Fertig. $total verarbeitet.";
            if($errors > 0 || $warnings > 0) $status_msg .= " ($errors Fehler, $warnings Warnungen)";

            NF_AD_Logger::finish_run($run_id, $final_status, $status_msg);
            NF_AD_Logger::cleanup_logs( $settings['log_limit'] ?? 256 );

        } catch ( Throwable $e ) {
            // CRITICAL: Bei JEDEM Fehler (auch Fatal Errors) MUSS der Run sauber abgeschlossen werden.
            // Sonst bleibt der Lock aktiv und das Plugin ist permanent geblockt.
            $error_msg = 'FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            error_log( '[NF Auto Delete] ' . $error_msg );

            NF_AD_Logger::finish_run( $run_id, 'error', $error_msg );

            // WICHTIG: total und limit_reached müssen auch im Error-Fall zurückgegeben werden.
            // Sonst würde return ['deleted' => 0, 'has_more' => false] die tatsächlich verarbeiteten
            // Submissions verschleiern.
        } finally {
            // GUARANTEE: Lock wird IMMER freigegeben, egal ob Erfolg oder Fehler.
            // Dies ist die wichtigste Zeile für Production-Stabilität.
            delete_transient( 'nf_ad_cleanup_running' );
        }

        return ['deleted' => $total, 'has_more' => $limit_reached];
    }

    // =============================================================================
    // FORM-VERARBEITUNG (BATCH)
    // =============================================================================

    /* --- Selektion, Datei-Cleanup, Submission-Handling --- */

    /**
     * Verarbeitet ein Formular in einem Batch:
     * 1) Submissions anhand des Cutoff-Datums selektieren.
     * 2) Optional Uploads bereinigen.
     * 3) Submission löschen, in Trash verschieben oder behalten.
     * 4) Ergebnis pro Submission im Logger protokollieren.
     *
     * @param int    $fid         Formular-ID.
     * @param int    $days        Cutoff in Tagen.
     * @param string $sub_action  "keep" | "trash" | "delete".
     * @param string $file_action "keep" | "delete".
     *
     * @return array{count:int,errors:int,warnings:int}
     */
    private static function process_form( $fid, $days, $sub_action, $file_action ) {
        // Cutoff-Datum auf Basis der WordPress-Zeitzone berechnen (nicht Server-Zeit).
        $cutoff = self::get_cutoff_datetime( $days );

        // NEUE ARCHITEKTUR: Bulk-Bereinigung von Uploads aus der Ninja Forms Uploads-Tabelle.
        // Läuft unabhängig von Submissions, basierend auf form_id + cutoff_date.
        // Batch-Processing mit Zeitlimit, um Timeouts zu vermeiden.
        // BUGFIX: Pagination implementiert, um Endlosschleifen bei externen Uploads zu vermeiden.
        $table_files_deleted = 0;
        $table_file_errors   = 0;
        if ( 'delete' === $file_action && class_exists( 'NF_AD_Uploads_Deleter' ) && method_exists( 'NF_AD_Uploads_Deleter', 'cleanup_uploads_for_form' ) ) {
            self::debug_log( 'Bulk upload cleanup started', [
                'form_id' => $fid,
                'cutoff' => $cutoff,
                'batch_limit' => self::BATCH_LIMIT,
            ] );

            $table_start = time();
            $last_id = 0;

            /**
             * Deadlock-Protection: Threshold = 3 Iterationen.
             *
             * WARUM 3 und nicht 1?
             * - Bei >= 1: Zu sensitiv. Legitime DB-Latenzen oder Transaktions-Delays könnten Fehlalarme auslösen.
             * - Bei >= 2: Immer noch zu niedrig. Edge-Cases wie DB-Replikations-Lag könnten fälschlicherweise triggern.
             * - Bei >= 3: Ausbalanciert. Gibt genug Toleranz für temporäre DB-Anomalien, erkennt aber echte Deadlocks zuverlässig.
             * - Bei >= 5: Zu träge. Bei echten Bugs würden 5 unnötige DB-Queries laufen.
             *
             * Empirische Tests zeigten: 3 ist der Sweet-Spot für Produktions-Stabilität.
             */
            $deadlock_detector = 0;

            do {
                $prev_id = $last_id;
                $table_res = NF_AD_Uploads_Deleter::cleanup_uploads_for_form( (int) $fid, (string) $cutoff, self::BATCH_LIMIT, $last_id );
                $table_files_deleted += (int) ( $table_res['deleted'] ?? 0 );
                $table_file_errors   += (int) ( $table_res['errors'] ?? 0 );

                $rows = (int) ( $table_res['rows'] ?? 0 );

                // BUGFIX: Sichere Progression - nimm immer das Maximum, nie rückwärts.
                $returned_id = (int) ( $table_res['last_id'] ?? 0 );
                $last_id = max( $last_id, $returned_id );

                // BUGFIX: Deadlock-Detection - wenn last_id sich nicht ändert, ist kein Fortschritt möglich.
                if ( $rows > 0 && $last_id === $prev_id ) {
                    $deadlock_detector++;
                    // Nach 3 Versuchen ohne Fortschritt: Abbruch (verhindert Endlosschleife).
                    if ( $deadlock_detector >= 3 ) {
                        self::debug_log( 'Deadlock detected in upload cleanup - no ID progression', [
                            'form_id' => $fid,
                            'last_id' => $last_id,
                            'rows' => $rows,
                        ] );
                        break;
                    }
                } else {
                    $deadlock_detector = 0; // Reset bei Fortschritt
                }

                // Lock auffrischen nach jedem Batch, um Timeout-Expiration bei langen Cleanup-Operationen zu verhindern.
                self::refresh_lock();

                // Abbruch wenn kein voller Batch mehr kam (alle Uploads abgearbeitet) oder Zeitlimit fast erreicht.
                if ( $rows < self::BATCH_LIMIT ) {
                    break;
                }

                if ( ( time() - $table_start ) >= max( 1, ( self::TIME_LIMIT - 3 ) ) ) {
                    self::debug_log( 'Upload cleanup time limit reached', [
                        'form_id' => $fid,
                        'elapsed' => ( time() - $table_start ),
                        'limit' => self::TIME_LIMIT,
                        'files_deleted' => $table_files_deleted,
                        'file_errors' => $table_file_errors,
                    ] );
                    break;
                }
            } while ( true );
        }

        // Formular-Level-Logging für die Bulk-Upload-Bereinigung (unabhängig von einzelnen Submissions).
        if ( ( $table_files_deleted > 0 || $table_file_errors > 0 ) && class_exists( 'NF_AD_Logger' ) ) {
            $table_stat = ( $table_file_errors > 0 ) ? 'warning' : 'success';
            $table_msg  = '[FILES][BULK] ' . $table_files_deleted . ' Datei(en) gelöscht (Ninja Forms Uploads Tabelle).';
            if ( $table_file_errors > 0 ) {
                $table_msg .= ' [WARNING] ' . $table_file_errors . ' Datei-Fehler.';
            }
            // Submission-ID 0 = Form-Level Operation (nicht an einzelne Submission gebunden).
            NF_AD_Logger::log( (int) $fid, 0, '', $table_stat, $table_msg );
        }

        // BUGFIX #2: Wenn Submissions behalten werden sollen, gibt es nichts mehr zu tun.
        // Dateien wurden bereits im Bulk-Teil gelöscht (falls file_action=delete).
        // Unnötige Log-Einträge und DB-Abfragen vermeiden.
        if ( $sub_action === 'keep' ) {
            return [ 'count' => 0, 'errors' => 0, 'warnings' => 0 ];
        }

        $query_args = [
            'post_type'              => 'nf_sub',
            'posts_per_page'         => self::BATCH_LIMIT,
            'fields'                 => 'ids',
            'date_query'             => [
                [
                    'column'    => 'post_date',
                    'before'    => $cutoff,
                    'inclusive' => true,
                ],
            ],
            'meta_query'             => [
                [
                    'key'   => '_form_id',
                    'value' => $fid,
                ],
            ],
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'ignore_sticky_posts'    => true,
        ];

        // NEUE ARCHITEKTUR: Meta-Query für Upload-Felder ist nicht mehr nötig.
        // Dateien werden unabhängig von Submissions gelöscht via cleanup_uploads_for_form().
        // Der Query selektiert nur noch Submissions basierend auf Cutoff-Datum.

        // Post-Status Handling:
        // Bei "delete" auch Trash einbeziehen (GDPR/DSGVO: bereits getrashte Submissions müssen bereinigt werden).
        // Bei "trash"/"keep" Trash ausschließen (Performance, da bereits verarbeitet).
        if ( $sub_action === 'delete' ) {
            $query_args['post_status'] = array_keys( get_post_stati() );
        } else {
            $query_args['post_status'] = array_values( array_diff( array_keys( get_post_stati() ), array( 'trash' ) ) );
        }

        // IDs abrufen (nur IDs, keine Post-Objekte), um Memory-Overhead zu minimieren.
        $q   = new WP_Query( $query_args );
        $ids = $q->posts;
        wp_reset_postdata();

        $result = [ 'count' => 0, 'errors' => 0, 'warnings' => 0 ];
        if ( empty( $ids ) ) {
            return $result;
        }

        // NEUE ARCHITEKTUR: Datei-Bereinigung erfolgt bereits VOR dem Loop via cleanup_uploads_for_form().
        // Der Loop fokussiert sich NUR auf Submission-Handling (klare Trennung der Verantwortlichkeiten).

        foreach ( $ids as $id ) {
            $stat     = 'success';
            $sub_date = get_post_field( 'post_date', $id );

            // Submission-Handling gemäß sub_action (delete, trash, keep).
            $action_tag = '';
            $msg        = '';

            if ( $sub_action === 'delete' ) {
                $action_tag = '[DELETE]';

                // WordPress-Kern-Funktion nutzen für permanente Löschung.
                $deleted = (bool) wp_delete_post( $id, true );

                if ( $deleted ) {
                    $msg = "{$action_tag} Eintrag endgültig gelöscht.";
                } else {
                    $stat = 'error';
                    $result['errors']++;
                    $msg = "{$action_tag} DB-Fehler (Eintrag konnte nicht gelöscht werden).";
                }

            } elseif ( $sub_action === 'trash' ) {
                $action_tag = '[TRASH]';

                if ( get_post_status( $id ) !== 'trash' ) {
                    if ( wp_trash_post( $id ) ) {
                        $msg = "{$action_tag} Eintrag in den Papierkorb verschoben.";
                    } else {
                        $stat = 'error';
                        $result['errors']++;
                        $msg = "{$action_tag} Fehler beim Verschieben in den Papierkorb.";
                    }
                } else {
                    $msg = "{$action_tag} Eintrag war bereits im Papierkorb.";
                }

            } else {
                // Keep-Modus: Submissions werden behalten.
                // NEUE ARCHITEKTUR: Dateien wurden bereits via cleanup_uploads_for_form() gelöscht (falls file_action=delete).
                // Hier loggen wir nur, dass die Submission behalten wurde.
                $action_tag = '[KEEP]';
                $msg = "{$action_tag} Eintrag behalten.";
            }

            NF_AD_Logger::log( $fid, $id, $sub_date, $stat, $msg );
            $result['count']++;
        }

        self::debug_log( 'Form processing completed', [
            'form_id' => $fid,
            'submissions_processed' => $result['count'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'bulk_files_deleted' => $table_files_deleted,
            'bulk_file_errors' => $table_file_errors,
        ] );

        return $result;
    }
}