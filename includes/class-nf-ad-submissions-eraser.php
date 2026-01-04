<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NF_AD_Submissions_Eraser {
    const BATCH_LIMIT = 50; 
    const TIME_LIMIT = 20;
    static $upload_keys_cache = [];

    public static function run_cleanup_cron() { self::run_cleanup_logic(true); }
    public static function run_cleanup_manual() { return self::run_cleanup_logic(false); }

    public static function calculate_dry_run($type = 'subs') {
        global $wpdb;
        $settings = NF_AD_Dashboard::get_settings();
        // When permanently deleting submissions, we must also consider items already in Trash (GDPR)
        $sub_action = $settings['sub_handling'] ?? 'keep';
        
        $global = (int)($settings['global'] ?? 365);
        $forms = Ninja_Forms()->form()->get_forms();
        $total_count = 0;

        foreach($forms as $form) {
            $rule = $settings['forms'][$form->get_id()] ?? ['mode'=>'global'];
            if($rule['mode'] === 'never') continue;
            $days = ($rule['mode'] === 'custom') ? (int)$rule['days'] : $global;
            if($days < 1) $days = 365;

            // Use WordPress site timezone (not server timezone) for cutoff calculation
            $threshold = current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS );
            $date = date( 'Y-m-d H:i:s', $threshold );
            $fid = $form->get_id();
            
            $sql = "SELECT COUNT(1)
                    FROM {$wpdb->posts} p
                    WHERE p.post_type = 'nf_sub'
                    AND p.post_date < %s
                    AND EXISTS (
                        SELECT 1
                        FROM {$wpdb->postmeta} pm
                        WHERE pm.post_id = p.ID
                        AND pm.meta_key = '_form_id'
                        AND pm.meta_value = %d
                    )";
            // Only exclude Trash when we are not doing permanent deletions.
            // For permanent delete, include Trash so already-trashed submissions are found.
            if ( $sub_action !== 'delete' ) {
                $sql .= " AND p.post_status != 'trash'";
            }

            if ( $type === 'files' ) {
                if(!isset(self::$upload_keys_cache[$fid])) {
                    self::$upload_keys_cache[$fid] = [];
                    foreach ( Ninja_Forms()->form($fid)->get_fields() as $f ) {
                        if ( $f->get_setting('type') === 'file_upload' ) {
                            self::$upload_keys_cache[$fid][] = '_field_' . $f->get_id();
                        }
                    }
                }
                $upload_meta_keys = self::$upload_keys_cache[$fid];

                if ( empty($upload_meta_keys) ) continue; 

                $placeholders = implode(',', array_fill(0, count($upload_meta_keys), '%s'));
                $sql .= " AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm2
                    WHERE pm2.post_id = p.ID
                    AND pm2.meta_key IN ($placeholders)
                    AND pm2.meta_value != ''
                )";

                $params = array_merge( [$date, $fid], $upload_meta_keys );
                $count = $wpdb->get_var( $wpdb->prepare($sql, ...$params) );
            } else {
                $count = $wpdb->get_var( $wpdb->prepare($sql, $date, $fid) );
            }
            
            $total_count += (int)$count;
        }
        return $total_count;
    }

    private static function run_cleanup_logic($is_cron = false) {
        $settings = NF_AD_Dashboard::get_settings();
        
        $sub_action = $settings['sub_handling'] ?? 'keep';
        $file_action = $settings['file_handling'] ?? 'keep';
        
        $mode_info = "Subs=$sub_action, Files=$file_action";
        
        $run_id = NF_AD_Logger::start_run($is_cron ? "Auto-Cron gestartet [$mode_info]..." : "Manuelle Bereinigung gestartet [$mode_info]...");
        $response = ['deleted' => 0, 'has_more' => false];

        if ( $is_cron && empty($settings['cron_active']) ) {
            NF_AD_Logger::finish_run($run_id, 'skipped', 'Zeitplan deaktiviert.');
            return $response; 
        }
        
        if( $sub_action === 'keep' && $file_action === 'keep' ) { 
            NF_AD_Logger::finish_run($run_id, 'skipped', 'Einstellungen auf "Behalten" gesetzt.'); 
            return $response; 
        }

        $global = (int)($settings['global'] ?? 365);
        $forms = Ninja_Forms()->form()->get_forms();
        $total = 0; $errors = 0; $warnings = 0;
        $start = time(); $limit_reached = false;

        do {
            $pass = 0;
            foreach($forms as $form) {
                if((time()-$start) >= self::TIME_LIMIT) { $limit_reached = true; break; }
                $rule = $settings['forms'][$form->get_id()] ?? ['mode'=>'global'];
                if($rule['mode'] === 'never') continue;
                $days = ($rule['mode'] === 'custom') ? (int)$rule['days'] : $global;
                if($days < 1) $days = 365;
              
                $res = self::process_form($form->get_id(), $days, $sub_action, $file_action);
                $pass += $res['count'];
                $errors += $res['errors'];
                $warnings += $res['warnings'];
            }
            $total += $pass;
            if((time()-$start) >= self::TIME_LIMIT) { $limit_reached = true; break; }
        } while($pass > 0);

        $final_status = 'success';
        if($errors > 0) $final_status = 'error';
        elseif($warnings > 0) $final_status = 'warning';

        $status_msg = $limit_reached ? "Teilweise ($total verarbeitet). Weiter..." : "Fertig. $total verarbeitet.";
        if($errors > 0 || $warnings > 0) $status_msg .= " ($errors Fehler, $warnings Warnungen)";

        NF_AD_Logger::finish_run($run_id, $final_status, $status_msg);
        NF_AD_Logger::cleanup_logs( $settings['log_limit'] ?? 256 );

        return ['deleted' => $total, 'has_more' => $limit_reached];
    }

    private static function process_form( $fid, $days, $sub_action, $file_action ) {
        // Use WordPress site timezone (not server timezone) for cutoff calculation
        $threshold = current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS );
        $date = date( 'Y-m-d H:i:s', $threshold );
        
        $query_args = [
            'post_type' => 'nf_sub',
            'posts_per_page' => self::BATCH_LIMIT,
            'fields' => 'ids',
            'date_query' => [['column'=>'post_date','before'=>$date]],
            'meta_query' => [['key'=>'_form_id','value'=>$fid]]
        ];

        if ( $sub_action === 'keep' && $file_action === 'delete' ) {
            if(!isset(self::$upload_keys_cache[$fid])) {
                self::$upload_keys_cache[$fid] = [];
                foreach ( Ninja_Forms()->form($fid)->get_fields() as $f ) {
                    if ( $f->get_setting('type') === 'file_upload' ) self::$upload_keys_cache[$fid][] = '_field_' . $f->get_id();
                }
            }
            $upload_keys = self::$upload_keys_cache[$fid];

            if(empty($upload_keys)) return ['count'=>0,'errors'=>0,'warnings'=>0]; 

            $meta_or = ['relation' => 'OR'];
            foreach($upload_keys as $k) {
                $meta_or[] = [ 'key' => $k, 'value' => '', 'compare' => '!=' ];
            }
            $query_args['meta_query'][] = $meta_or;
        }

        $all_statuses = array_keys( get_post_stati() );

        // LOGIC FIX:
        // If we permanently delete submissions, we must also search in Trash (GDPR requirement).
        // If we only move to Trash, we can exclude Trash for performance (already handled).
        if ( $sub_action === 'delete' ) {
            $query_args['post_status'] = $all_statuses; // include trash
        } else {
            $query_args['post_status'] = array_values( array_diff( $all_statuses, ['trash'] ) );
        }

        $ids = get_posts($query_args);
        
        $result = ['count'=>0, 'errors'=>0, 'warnings'=>0];
        if(empty($ids)) return $result;

        foreach ( $ids as $id ) {
            $stat = 'success';
            $sub_date = get_post_field( 'post_date', $id );

            $files_deleted = 0;
            $file_errors   = 0;

            // 1) Files cleanup (optional)
            if ( $file_action === 'delete' && class_exists( 'NF_AD_Uploads_Deleter' ) ) {
                $f_res = NF_AD_Uploads_Deleter::cleanup_files( $id );
                $files_deleted = (int) ( $f_res['deleted'] ?? 0 );
                $file_errors   = (int) ( $f_res['errors'] ?? 0 );

                if ( $file_errors > 0 ) {
                    $stat = 'warning';
                    $result['warnings']++;
                }
            }

            // 2) Submission handling
            $action_tag = '';
            $msg        = '';

            if ( $sub_action === 'delete' ) {
                $action_tag = '[DELETE]';

                $deleted = false;
                if ( class_exists( 'Ninja_Forms' ) ) {
                    $nf_form = Ninja_Forms()->form();
                    if ( method_exists( $nf_form, 'get_sub' ) ) {
                        $sub_obj = $nf_form->get_sub( $id );
                        if ( $sub_obj ) {
                            $sub_obj->delete();
                            $deleted = true;
                        }
                    }
                }

                if ( ! $deleted ) {
                    if ( wp_delete_post( $id, true ) ) {
                        $deleted = true;
                    }
                }

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

                    // Still surface file warnings if they happened
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

                // In Trash mode we normally don't delete files, but if user enabled files delete anyway,
                // we still log what happened.
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
                // keep submissions
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
                    // Shouldn't happen because run_cleanup_logic skips keep/keep, but keep a safe log.
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