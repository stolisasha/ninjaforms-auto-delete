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

    /**
     * Cache für Upload-Meta-Keys je Formular (Field IDs), um wiederholte Field-Iterationen zu vermeiden.
     *
     * @var array<int, array<int, string>>
     */
    static $upload_keys_cache = [];

    // =============================================================================
    // HILFSMETHODEN
    // =============================================================================

    /**
     * Build a deterministic cutoff datetime string (site timezone) for date_query.
     *
     * @param int $days Cutoff in days.
     *
     * @return string
     */
    private static function get_cutoff_datetime( $days ) {
        $days      = max( 1, absint( $days ) );
        $threshold = current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS );
        return wp_date( 'Y-m-d H:i:s', $threshold );
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
     * Berechnet die Anzahl der betroffenen Submissions oder Upload-Dateien, ohne Änderungen vorzunehmen.
     *
     * @param string $type "subs" für Submissions, "files" für Uploads.
     *
     * @return int
     */
    public static function calculate_dry_run( $type = 'subs' ) {
        $settings = NF_AD_Dashboard::get_settings();

        // Bei "delete" müssen auch bereits im Papierkorb befindliche Submissions berücksichtigt werden (GDPR/DSGVO).
        $sub_action = $settings['sub_handling'] ?? 'keep';

        $global     = (int) ( $settings['global'] ?? 365 );
        $forms      = Ninja_Forms()->form()->get_forms();
        $total_count = 0;

        foreach ( $forms as $form ) {
            $rule = $settings['forms'][ $form->get_id() ] ?? [ 'mode' => 'global' ];
            if ( $rule['mode'] === 'never' ) {
                continue;
            }
            $days = ( $rule['mode'] === 'custom' ) ? (int) $rule['days'] : $global;
            if ( $days < 1 ) {
                $days = 365;
            }
            $fid   = $form->get_id();
            $cutoff = self::get_cutoff_datetime( $days );

            $args = [
                'post_type'              => 'nf_sub',
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'ignore_sticky_posts'    => true,
                'suppress_filters'       => false,
                'date_query'             => [
                    [
                        'column'    => 'post_date',
                        'before'    => $cutoff,
                        'inclusive' => true,
                    ],
                ],
                'meta_query'             => [
                    [
                        'key'     => '_form_id',
                        'value'   => $fid,
                        'compare' => '=',
                    ],
                ],
                'post_status'            => ( $sub_action === 'delete' )
                    ? array_keys( get_post_stati() )
                    : array_values( array_diff( array_keys( get_post_stati() ), array( 'trash' ) ) ),
            ];

            if ( $type === 'files' ) {
                // Upload-Field-Keys einmalig pro Formular ermitteln und cachen.
                if ( ! isset( self::$upload_keys_cache[ $fid ] ) ) {
                    self::$upload_keys_cache[ $fid ] = [];
                    foreach ( Ninja_Forms()->form( $fid )->get_fields() as $f ) {
                        if ( $f->get_setting( 'type' ) === 'file_upload' ) {
                            self::$upload_keys_cache[ $fid ][] = '_field_' . $f->get_id();
                        }
                    }
                }
                $upload_keys = self::$upload_keys_cache[ $fid ];
                if ( empty( $upload_keys ) ) {
                    continue;
                }
                // OR-Filter: mindestens ein Upload-Feld darf nicht leer sein.
                $meta_or = [ 'relation' => 'OR' ];
                foreach ( $upload_keys as $k ) {
                    $meta_or[] = [ 'key' => $k, 'value' => '', 'compare' => '!=' ];
                }
                $args['meta_query'][] = $meta_or;
            }

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
            return $response; 
        }
        
        // Sicherheits-Exit: Keine Aktion konfiguriert (Submissions und Dateien werden behalten).
        if( $sub_action === 'keep' && $file_action === 'keep' ) { 
            NF_AD_Logger::finish_run($run_id, 'skipped', 'Einstellungen auf "Behalten" gesetzt.'); 
            return $response; 
        }

        $global = (int)($settings['global'] ?? 365);
        $forms = Ninja_Forms()->form()->get_forms();
        $total = 0; $errors = 0; $warnings = 0;
        $start = time(); $limit_reached = false;

        // Mehrere Durchläufe: pro Formular Batches abarbeiten, bis keine Treffer mehr existieren oder TIME_LIMIT greift.
        do {
            $pass = 0;
            foreach($forms as $form) {
                // Harte Laufzeitgrenze: Verarbeitung abbrechen, damit Cron/Request nicht in Timeouts läuft.
                if((time()-$start) >= self::TIME_LIMIT) { $limit_reached = true; break; }
                $rule = $settings['forms'][$form->get_id()] ?? ['mode'=>'global'];
                if($rule['mode'] === 'never') continue;
                $days = ($rule['mode'] === 'custom') ? (int)$rule['days'] : $global;
                if($days < 1) $days = 365;
              
                // Formularweise Verarbeitung: Batch selektieren und je Submission Aktionen ausführen.
                $res = self::process_form($form->get_id(), $days, $sub_action, $file_action);
                $pass += $res['count'];
                $errors += $res['errors'];
                $warnings += $res['warnings'];
            }
            $total += $pass;
            if((time()-$start) >= self::TIME_LIMIT) { $limit_reached = true; break; }
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

        if ( $sub_action === 'keep' && $file_action === 'delete' ) {
            // Sonderfall: Submissions bleiben, aber Uploads sollen gelöscht werden. Dafür nur Submissions mit Upload-Meta selektieren.
            if ( ! isset( self::$upload_keys_cache[ $fid ] ) ) {
                self::$upload_keys_cache[ $fid ] = [];
                foreach ( Ninja_Forms()->form( $fid )->get_fields() as $f ) {
                    if ( $f->get_setting( 'type' ) === 'file_upload' ) {
                        self::$upload_keys_cache[ $fid ][] = '_field_' . $f->get_id();
                    }
                }
            }
            $upload_keys = self::$upload_keys_cache[ $fid ];

            if ( empty( $upload_keys ) ) {
                return [ 'count' => 0, 'errors' => 0, 'warnings' => 0 ];
            }

            // OR-Filter: mindestens ein Upload-Feld darf nicht leer sein.
            $meta_or = [ 'relation' => 'OR' ];
            foreach ( $upload_keys as $k ) {
                $meta_or[] = [ 'key' => $k, 'value' => '', 'compare' => '!=' ];
            }
            $query_args['meta_query'][] = $meta_or;
        }

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

        // Pro Submission: optional Dateien bereinigen, dann Submission-Aktion ausführen und Ergebnis loggen.
        foreach ( $ids as $id ) {
            $stat        = 'success';
            $sub_date    = get_post_field( 'post_date', $id );
            $files_deleted = 0;
            $file_errors   = 0;

            // 1) Datei-Bereinigung (optional, abhängig von file_action).
            if ( $file_action === 'delete' && class_exists( 'NF_AD_Uploads_Deleter' ) ) {
                $f_res        = NF_AD_Uploads_Deleter::cleanup_files( $id );
                $files_deleted = (int) ( $f_res['deleted'] ?? 0 );
                $file_errors   = (int) ( $f_res['errors'] ?? 0 );

                if ( $file_errors > 0 ) {
                    $stat = 'warning';
                    $result['warnings']++;
                }
            }

            // 2) Submission-Handling gemäß sub_action (delete, trash, keep).
            $action_tag = '';
            $msg        = '';

            if ( $sub_action === 'delete' ) {
                $action_tag = '[DELETE]';

                // Use core wp_delete_post only.
                $deleted = (bool) wp_delete_post( $id, true );

                if ( $deleted ) {
                    if ( $file_action === 'delete' ) {
                        if ( $files_deleted > 0 && $file_errors === 0 ) {
                            $msg = "{$action_tag} Eintrag endgültig gelöscht + {$files_deleted} Datei(en) gelöscht.";
                        } elseif ( $files_deleted > 0 && $file_errors > 0 ) {
                            $msg  = "{$action_tag} Eintrag endgültig gelöscht + {$files_deleted} Datei(en) gelöscht.";
                            $msg .= " [FILES][WARNING] {$file_errors} Datei-Fehler.";
                            $stat = ( $stat === 'error' ) ? 'error' : 'warning';
                        } elseif ( $files_deleted === 0 && $file_errors > 0 ) {
                            $msg  = "{$action_tag} Eintrag endgültig gelöscht.";
                            $msg .= " [FILES][WARNING] {$file_errors} Datei-Fehler.";
                            $stat = ( $stat === 'error' ) ? 'error' : 'warning';
                        } else {
                            $msg = "{$action_tag} Eintrag endgültig gelöscht.";
                        }
                    } else {
                        $msg = "{$action_tag} Eintrag endgültig gelöscht.";
                    }
                } else {
                    $stat = 'error';
                    $result['errors']++;
                    $msg = "{$action_tag} DB Fehler (Eintrag konnte nicht endgültig gelöscht werden).";

                    // Datei-Warnungen zusätzlich anfügen, falls beim Cleanup Fehler aufgetreten sind.
                    if ( $file_action === 'delete' && $file_errors > 0 ) {
                        $msg .= " [FILES][WARNING] {$file_errors} Datei-Fehler.";
                    }
                }

            } elseif ( $sub_action === 'trash' ) {
                $action_tag = '[TRASH]';

                if ( get_post_status( $id ) !== 'trash' ) {
                    if ( wp_trash_post( $id ) ) {
                        $msg = "{$action_tag} Eintrag in den Papierkorb verschoben.";
                    } else {
                        $stat = 'error';
                        $result['errors']++;
                        $msg = "{$action_tag} Fehler (Eintrag konnte nicht in den Papierkorb verschoben werden).";
                    }
                } else {
                    $msg = "{$action_tag} Eintrag war bereits im Papierkorb.";
                }

                // Im Trash-Modus werden Dateien normalerweise nicht gelöscht. Falls dennoch aktiviert, wird das Ergebnis mitprotokolliert.
                if ( $file_action === 'delete' ) {
                    if ( $files_deleted > 0 ) {
                        $msg .= " [FILES] {$files_deleted} Datei(en) gelöscht.";
                    }
                    if ( $file_errors > 0 ) {
                        $msg .= " [FILES][WARNING] {$file_errors} Datei-Fehler.";
                        $stat = ( $stat === 'error' ) ? 'error' : 'warning';
                    }
                }

            } else {
                // Submissions behalten (optional nur Datei-Cleanup).
                if ( $file_action === 'delete' ) {
                    $action_tag = '[FILES]';

                    if ( $files_deleted > 0 && $file_errors === 0 ) {
                        $msg = "{$action_tag} {$files_deleted} Datei(en) gelöscht (Eintrag behalten).";
                    } elseif ( $files_deleted > 0 && $file_errors > 0 ) {
                        $msg  = "{$action_tag} {$files_deleted} Datei(en) gelöscht (Eintrag behalten).";
                        $msg .= " [WARNING] {$file_errors} Datei-Fehler.";
                        $stat = ( $stat === 'error' ) ? 'error' : 'warning';
                    } elseif ( $files_deleted === 0 && $file_errors > 0 ) {
                        $msg  = "{$action_tag} Keine Dateien gelöscht (Eintrag behalten).";
                        $msg .= " [WARNING] {$file_errors} Datei-Fehler.";
                        $stat = ( $stat === 'error' ) ? 'error' : 'warning';
                    } else {
                        $msg = "{$action_tag} Keine Dateien gefunden (Eintrag behalten).";
                    }
                } else {
                    // Sollte nicht auftreten (keep/keep wird vorher abgefangen). Trotzdem defensiv protokollieren.
                    $action_tag = '[SKIP]';
                    $msg = "{$action_tag} Keine Aktion (Eintrag und Dateien behalten).";
                }
            }

            NF_AD_Logger::log( $fid, $id, $sub_date, $stat, $msg );
            $result['count']++;
        }
        return $result;
    }
}