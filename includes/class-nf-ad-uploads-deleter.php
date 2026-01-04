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

        global $wpdb;

        // Prefer the official Ninja Forms File Uploads add-on table when available.
        $uploads_table = $wpdb->prefix . 'ninja_forms_uploads';
        $has_uploads_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $uploads_table ) ) === $uploads_table );

        if ( $has_uploads_table ) {
            static $uploads_columns = null;
            if ( null === $uploads_columns ) {
                $uploads_columns = $wpdb->get_col( "DESCRIBE {$uploads_table}", 0 );
            }

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

            if ( $sub_col && $data_col ) {
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

                        // Parsed can be a list of references or a single reference.
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

                    return $stats;
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
     * Normalize upload data from the File Uploads add-on table.
     * Supports serialized values, JSON, and mixed arrays.
     *
     * @param mixed $raw Raw DB value.
     *
     * @return mixed
     */
    private static function normalize_upload_data( $raw ) {
        $val = maybe_unserialize( $raw );

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
            if ( isset( $val['file_url'] ) || isset( $val['url'] ) || isset( $val['path'] ) || isset( $val['file_path'] ) || isset( $val['attachment_id'] ) ) {
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
}