<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class NF_AD_Uploads_Deleter
 * Handhabt das physische Löschen von Dateien.
 * Enthält Jail-Checks und Symlink-Protection.
 */
class NF_AD_Uploads_Deleter {
    
    public static function cleanup_files( $sid ) {
        $stats = ['deleted' => 0, 'errors' => 0];
        $fid = get_post_meta( $sid, '_form_id', true );
        if ( !$fid ) return $stats;
        
        // Safety: Ninja Forms Form Objekt holen
        if ( ! function_exists( 'Ninja_Forms' ) ) return $stats;
        $form = Ninja_Forms()->form( $fid );
        
        foreach ( $form->get_fields() as $f ) {
            if ( $f->get_setting( 'type' ) !== 'file_upload' ) continue;
            
            $raw_val = get_post_meta( $sid, '_field_' . $f->get_id(), true );
            $val = maybe_unserialize($raw_val); 

            // Robustere JSON Erkennung
            if ( is_string($val) && (strpos(trim($val), '[') === 0 || strpos(trim($val), '{') === 0) ) {
                $json = json_decode($val, true);
                if ( json_last_error() === JSON_ERROR_NONE ) $val = $json;
            }

            if($val) {
                $res = self::del($val);
                $stats['deleted'] += $res['deleted'];
                $stats['errors'] += $res['errors'];
            }
        }
        return $stats;
    }
    
    private static function del($val) {
        $stats = ['deleted' => 0, 'errors' => 0];
        
        // Rekursiv bei Arrays (Multiple Files Upload)
        if(is_array($val)) { 
            foreach($val as $v) {
                $res = self::del($v);
                $stats['deleted'] += $res['deleted'];
                $stats['errors'] += $res['errors'];
            }
            return $stats; 
        }
        
        $path = '';
        if ( file_exists( $val ) ) {
            $path = $val;
        } elseif ( strpos( $val, 'http' ) !== false ) {
            $clean_url = strtok($val, '?');
            $path = self::url_to_path($clean_url);
        }
        
        // Existenz prüfen
        if ( $path && file_exists( $path ) ) {
            
            // SECURITY: Symlink Check (Feedback Fix)
            // Wir prüfen is_link() auf den Pfad BEVOR wir realpath machen.
            if ( is_link( $path ) ) {
                $stats['errors']++; // Wir löschen keine Symlinks, das ist unsicher.
                return $stats;
            }

            $real_path = realpath($path);
            
            // Jail Check: Darf nur im Upload Verzeichnis löschen
            $upload_dir = wp_upload_dir();
            $real_base = realpath($upload_dir['basedir']);
            
            if ( $real_base && $real_path && strpos( $real_path, $real_base ) === 0 ) {
                if ( is_writable($real_path) ) {
                    if ( unlink($real_path) ) {
                        $stats['deleted']++;
                    } else {
                        $stats['errors']++;
                    }
                } else {
                    $stats['errors']++; // Keine Rechte
                }
            } else {
                // Pfad liegt außerhalb des Upload-Verzeichnisses -> Abbruch
                $stats['errors']++;
            }
        }
        return $stats;
    }

    private static function url_to_path( $url ) {
        $d = wp_upload_dir();
        return str_replace( $d['baseurl'], $d['basedir'], $url );
    }
}