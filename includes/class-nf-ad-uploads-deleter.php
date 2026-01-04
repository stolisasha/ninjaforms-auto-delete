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

        // Formular-Objekt laden, um File-Upload-Felder sicher zu identifizieren.
        if ( ! function_exists( 'Ninja_Forms' ) ) {
            return $stats;
        }
        $form = Ninja_Forms()->form( $fid );

        foreach ( $form->get_fields() as $f ) {
            if ( $f->get_setting( 'type' ) !== 'file_upload' ) {
                continue;
            }

            $raw_val = get_post_meta( $sid, '_field_' . $f->get_id(), true );
            $val = maybe_unserialize( $raw_val );

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

            if ( $val ) {
                $res = self::del( $val );
                $stats['deleted'] += $res['deleted'];
                $stats['errors'] += $res['errors'];
            }
        }
        return $stats;
    }

    // =============================================================================
    // DELETE ROUTINE
    // =============================================================================

    /* --- Rekursives Löschen (Datei oder Array) --- */

    /**
     * Löscht eine Datei oder eine Liste von Dateien (rekursiv).
     *
     * Unterstützte Eingaben:
     * - Absoluter Pfad
     * - URL (wird in Upload-Pfad umgerechnet)
     * - Array (mehrere Dateien)
     *
     * Sicherheitschecks:
     * - Keine Symlinks
     * - Nur innerhalb des WordPress Upload-Verzeichnisses
     *
     * @param mixed $val Dateireferenz.
     *
     * @return array{deleted:int,errors:int}
     */
    private static function del( $val ) {
        $stats = [ 'deleted' => 0, 'errors' => 0 ];

        // Mehrfach-Uploads: Arrays rekursiv abarbeiten.
        if ( is_array( $val ) ) {
            foreach ( $val as $v ) {
                $res = self::del( $v );
                $stats['deleted'] += $res['deleted'];
                $stats['errors'] += $res['errors'];
            }
            return $stats;
        }

        $path = '';
        if ( file_exists( $val ) ) {
            $path = $val;
        } elseif ( strpos( $val, 'http' ) !== false ) {
            $clean_url = strtok( $val, '?' );
            $path = self::url_to_path( $clean_url );
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

            if ( $real_base && $real_path && strpos( $real_path, $real_base ) === 0 ) {
                if ( is_writable( $real_path ) ) {
                    if ( unlink( $real_path ) ) {
                        $stats['deleted']++;
                    } else {
                        $stats['errors']++;
                    }
                } else {
                    $stats['errors']++;
                    // Keine Schreibrechte auf die Datei.
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
        return str_replace( $d['baseurl'], $d['basedir'], $url );
    }
}