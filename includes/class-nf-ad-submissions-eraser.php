<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// SUBMISSIONS ERASER (BEREINIGUNG & DRY RUN)
// =============================================================================

/**
 * Führt die automatische und manuelle Bereinigung von Ninja-Forms-Submissions durch.
 *
 * ARCHITEKTUR (seit v2.2):
 * - Diese Klasse kümmert sich NUR um Submissions (Single Responsibility)
 * - Upload-Bereinigung erfolgt über NF_AD_Uploads_Deleter (eigene Klasse)
 * - run_cleanup_logic() fungiert als ORCHESTRATOR und koordiniert beide Prozesse
 * - Beide Zähler (Submissions + Dateien) werden separat getracked und im Log angezeigt
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
     * SEIT v2.2.1: Gibt ein Array mit detaillierter Aufschlüsselung zurück:
     * - total: Gesamtzahl der betroffenen Submissions
     * - active: Submissions die noch aktiv sind (nicht im Papierkorb)
     * - trashed: Submissions die bereits im Papierkorb sind
     *
     * @return array{total:int,active:int,trashed:int}
     */
    public static function calculate_dry_run() {
        $settings = NF_AD_Dashboard::get_settings();

        $result = [ 'total' => 0, 'active' => 0, 'trashed' => 0 ];

        // Defensive: If Ninja Forms is not loaded, return empty result.
        if ( ! function_exists( 'Ninja_Forms' ) ) {
            return $result;
        }

        // Bei "keep" werden keine Submissions verarbeitet - direkt 0 zurückgeben.
        $sub_action = $settings['sub_handling'] ?? 'keep';
        if ( 'keep' === $sub_action ) {
            return $result;
        }

        $global = (int) ( $settings['global'] ?? 365 );
        $forms  = Ninja_Forms()->form()->get_forms();

        // DEFENSIVE: Prüfe ob get_forms() valide Daten zurückgibt (Edge-Case: DB-Fehler, Partial Plugin Load).
        if ( ! is_array( $forms ) || empty( $forms ) ) {
            return $result;
        }

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

            // Basis-Query-Parameter für Submissions.
            $base_args = array(
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
            );

            // Query 1: Aktive Submissions (nicht im Papierkorb).
            $args_active = $base_args;
            $args_active['post_status'] = array_values( array_diff( array_keys( get_post_stati() ), array( 'trash' ) ) );
            $q_active = new WP_Query( $args_active );
            $result['active'] += (int) $q_active->found_posts;
            wp_reset_postdata();

            // Query 2: Submissions im Papierkorb (nur bei sub_action = 'delete').
            if ( 'delete' === $sub_action ) {
                $args_trashed = $base_args;
                $args_trashed['post_status'] = array( 'trash' );
                $q_trashed = new WP_Query( $args_trashed );
                $result['trashed'] += (int) $q_trashed->found_posts;
                wp_reset_postdata();
            }
        }

        // Total berechnen.
        $result['total'] = $result['active'] + $result['trashed'];

        return $result;
    }

    // =============================================================================
    // CLEANUP-LOGIK (CRON & MANUELL)
    // =============================================================================

    /* --- Orchestrierung: Durchläufe, Limits, Logging --- */

    /**
     * Zentrale Bereinigungsroutine für Cron und manuelle Ausführung.
     *
     * ARCHITEKTUR: Diese Methode fungiert als ORCHESTRATOR und koordiniert:
     * 1) NF_AD_Uploads_Deleter::run_cleanup() - Bereinigt Upload-Dateien
     * 2) NF_AD_Submissions_Eraser::process_form() - Bereinigt Submissions
     *
     * Beide Prozesse laufen unabhängig voneinander und haben eigene Zähler.
     * Die finale Status-Nachricht zeigt beide Werte separat an.
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

        $sub_action  = $settings['sub_handling'] ?? 'keep';
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

        // =========================================================================
        // ORCHESTRATOR: Koordiniert beide Cleanup-Prozesse
        // =========================================================================

        $total_subs    = 0;  // Zähler für verarbeitete Submissions
        $total_files   = 0;  // Zähler für gelöschte Dateien
        $errors        = 0;
        $warnings      = 0;
        $limit_reached = false;

        // CRITICAL: Try-Catch Block um Lock IMMER freizugeben, auch bei Fehlern.
        // Ohne dies könnte ein Fatal Error das Plugin permanent blocken.
        try {
            // =====================================================================
            // PHASE 1: Upload-Bereinigung (unabhängig von Submissions)
            // =====================================================================
            // Der Uploads_Deleter kümmert sich komplett eigenständig um:
            // - Iteration durch alle Formulare
            // - Anwendung der Fristen-Regeln
            // - Batch-Processing mit Zeitlimit
            // - Eigenes Logging pro Formular
            if ( 'delete' === $file_action && class_exists( 'NF_AD_Uploads_Deleter' ) && method_exists( 'NF_AD_Uploads_Deleter', 'run_cleanup' ) ) {
                self::debug_log( 'Starting uploads cleanup phase' );

                $upload_result = NF_AD_Uploads_Deleter::run_cleanup( $is_cron );

                $total_files += (int) ( $upload_result['deleted'] ?? 0 );
                $errors      += (int) ( $upload_result['errors'] ?? 0 );

                // Wenn Upload-Cleanup das Zeitlimit erreicht hat, trotzdem mit Submissions weitermachen.
                // Das Zeitlimit gilt pro Phase, nicht global.
                if ( ! empty( $upload_result['has_more'] ) ) {
                    $limit_reached = true;
                    self::debug_log( 'Upload cleanup hit time limit', [
                        'files_deleted' => $total_files,
                    ] );
                }

                // Lock auffrischen nach Phase 1.
                self::refresh_lock();
            }

            // =====================================================================
            // PHASE 2: Submission-Bereinigung (nur wenn sub_action != 'keep')
            // =====================================================================
            if ( 'keep' !== $sub_action ) {
                self::debug_log( 'Starting submissions cleanup phase' );

                $global = (int) ( $settings['global'] ?? 365 );
                $forms  = Ninja_Forms()->form()->get_forms();

                // DEFENSIVE: Prüfe ob get_forms() valide Daten zurückgibt.
                if ( ! is_array( $forms ) || empty( $forms ) ) {
                    self::debug_log( 'No forms found, skipping submissions cleanup' );
                } else {
                    $start = time();

                    // Mehrere Durchläufe: pro Formular Batches abarbeiten.
                    do {
                        $pass = 0;
                        foreach ( $forms as $form ) {
                            // Zeitlimit-Check: Abbrechen wenn TIME_LIMIT erreicht.
                            if ( ( time() - $start ) >= self::TIME_LIMIT ) {
                                $limit_reached = true;
                                self::debug_log( 'Submissions cleanup time limit reached', [
                                    'elapsed' => ( time() - $start ),
                                    'processed_so_far' => $total_subs,
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
                            $rule = $settings['forms'][ $fid ] ?? [ 'mode' => 'global' ];
                            if ( 'never' === ( $rule['mode'] ?? 'global' ) ) {
                                continue;
                            }

                            $days = ( 'custom' === ( $rule['mode'] ?? 'global' ) ) ? (int) $rule['days'] : $global;
                            if ( $days < 1 ) {
                                $days = 365;
                            }

                            // Formularweise Verarbeitung: NUR Submissions, keine Uploads.
                            $res = self::process_form( $fid, $days, $sub_action );
                            $pass     += $res['count'];
                            $errors   += $res['errors'];
                            $warnings += $res['warnings'];

                            // Lock auffrischen nach jedem Formular.
                            self::refresh_lock();
                        }

                        $total_subs += $pass;

                        // Zeitlimit-Check nach vollständigem Durchlauf.
                        if ( ( time() - $start ) >= self::TIME_LIMIT ) {
                            $limit_reached = true;
                            break;
                        }

                    } while ( $pass > 0 );
                }
            }

            // =====================================================================
            // ABSCHLUSS: Status-Nachricht zusammenbauen
            // =====================================================================
            // Endstatus ableiten: error schlägt warning, warning schlägt success.
            $final_status = 'success';
            if ( $errors > 0 ) {
                $final_status = 'error';
            } elseif ( $warnings > 0 ) {
                $final_status = 'warning';
            }

            // Status-Nachricht: Zeigt beide Werte separat an (Einträge + Dateien).
            // Das war der ursprüngliche Bug: Nur Submissions wurden angezeigt.
            //
            // LOGIK: Zeige nur relevante Teile an:
            // - Einträge: Nur wenn sub_action != 'keep' (sonst irrelevant)
            // - Dateien: Nur wenn file_action == 'delete' (sonst irrelevant)
            $status_parts = [];
            if ( 'keep' !== $sub_action ) {
                $status_parts[] = $total_subs . ' Einträge';
            }
            if ( 'delete' === $file_action ) {
                $status_parts[] = $total_files . ' Dateien';
            }

            $status_summary = implode( ', ', $status_parts );
            if ( empty( $status_summary ) ) {
                $status_summary = '0 verarbeitet';
            }

            $status_msg = $limit_reached
                ? "Teilweise ({$status_summary}). Weiter..."
                : "Fertig. {$status_summary}.";

            if ( $errors > 0 || $warnings > 0 ) {
                $status_msg .= " ({$errors} Fehler, {$warnings} Warnungen)";
            }

            NF_AD_Logger::finish_run( $run_id, $final_status, $status_msg );
            NF_AD_Logger::cleanup_logs( $settings['log_limit'] ?? 256 );

        } catch ( Throwable $e ) {
            // CRITICAL: Bei JEDEM Fehler (auch Fatal Errors) MUSS der Run sauber abgeschlossen werden.
            // Sonst bleibt der Lock aktiv und das Plugin ist permanent geblockt.
            $error_msg = 'FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            error_log( '[NF Auto Delete] ' . $error_msg );

            NF_AD_Logger::finish_run( $run_id, 'error', $error_msg );

        } finally {
            // GUARANTEE: Lock wird IMMER freigegeben, egal ob Erfolg oder Fehler.
            // Dies ist die wichtigste Zeile für Production-Stabilität.
            delete_transient( 'nf_ad_cleanup_running' );
        }

        // Return-Wert: Kombiniert beide Zähler für die AJAX-Response.
        // Das Modal zeigt "X verarbeitet", was jetzt Submissions + Dateien summiert.
        return [
            'deleted'  => $total_subs + $total_files,
            'has_more' => $limit_reached,
        ];
    }

    // =============================================================================
    // FORM-VERARBEITUNG (BATCH)
    // =============================================================================

    /* --- Selektion und Submission-Handling --- */

    /**
     * Verarbeitet ein Formular in einem Batch:
     * 1) Submissions anhand des Cutoff-Datums selektieren.
     * 2) Submission löschen oder in Trash verschieben.
     * 3) Ergebnis pro Submission im Logger protokollieren.
     *
     * ARCHITEKTUR: Diese Methode kümmert sich NUR um Submissions.
     * Upload-Bereinigung erfolgt separat über NF_AD_Uploads_Deleter::run_cleanup().
     * Die Koordination beider Prozesse übernimmt run_cleanup_logic().
     *
     * @param int    $fid        Formular-ID.
     * @param int    $days       Cutoff in Tagen.
     * @param string $sub_action "trash" | "delete".
     *
     * @return array{count:int,errors:int,warnings:int}
     */
    private static function process_form( $fid, $days, $sub_action ) {
        // Cutoff-Datum auf Basis der WordPress-Zeitzone berechnen (nicht Server-Zeit).
        $cutoff = self::get_cutoff_datetime( $days );

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

        // Post-Status Handling:
        // Bei "delete" auch Trash einbeziehen (GDPR/DSGVO: bereits getrashte Submissions müssen bereinigt werden).
        // Bei "trash" Trash ausschließen (Performance, da bereits verarbeitet).
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

        // Submission-Loop: Fokussiert sich NUR auf Submission-Handling.
        foreach ( $ids as $id ) {
            $stat     = 'success';
            $sub_date = get_post_field( 'post_date', $id );

            // Submission-Handling gemäß sub_action (delete oder trash).
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
            }

            NF_AD_Logger::log( $fid, $id, $sub_date, $stat, $msg );
            $result['count']++;
        }

        self::debug_log( 'Form processing completed', [
            'form_id' => $fid,
            'submissions_processed' => $result['count'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
        ] );

        return $result;
    }
}