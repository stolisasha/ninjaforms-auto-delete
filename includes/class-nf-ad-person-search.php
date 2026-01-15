<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// PERSON SEARCH (DSGVO LOOKUP)
// =============================================================================

/**
 * Person Search - DSGVO-konforme Personen-Suche.
 *
 * Ermöglicht formularübergreifende Suche nach Personen-Daten
 * (E-Mail, Name, etc.) und Batch-Löschung der gefundenen Einträge.
 *
 * ARCHITEKTUR-ENTSCHEIDUNG: Wir nutzen $wpdb statt WP_Query, weil
 * WP_Query keine LIKE-Suche auf meta_key unterstützt. Alle anderen
 * Operationen nutzen WordPress-Standard-Funktionen.
 *
 * @since 2.4.0
 */
class NF_AD_Person_Search {

	// =============================================================================
	// KONSTANTEN
	// =============================================================================

	/**
	 * Mindestlänge für Suchbegriffe (Schutz vor zu breiten Suchen).
	 */
	const MIN_SEARCH_LENGTH = 3;

	/**
	 * Ergebnisse pro Seite (konsistent mit Log-Tab).
	 */
	const PER_PAGE = 20;

	/**
	 * Maximale Suchergebnisse (Performance-Schutz).
	 */
	const MAX_SEARCH_RESULTS = 5000;

	// =============================================================================
	// SUCHE
	// =============================================================================

	/* --- Hauptsuche mit Pagination --- */

	/**
	 * Durchsucht alle Submissions nach einem Begriff.
	 *
	 * @param string $term     Suchbegriff (E-Mail, Name, etc.).
	 * @param int    $page     Aktuelle Seite (1-basiert).
	 * @param int    $per_page Ergebnisse pro Seite.
	 *
	 * @return array{results: array, total: int, page: int, pages: int}|array{error: string}
	 */
	public static function search( $term, $page = 1, $per_page = self::PER_PAGE ) {
		global $wpdb;

		// Validierung.
		$term = sanitize_text_field( trim( $term ) );
		if ( strlen( $term ) < self::MIN_SEARCH_LENGTH ) {
			return [ 'error' => 'Suchbegriff muss mindestens 3 Zeichen haben.' ];
		}

		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// LIKE-Term sicher escapen (WordPress-Standard).
		$like_term = '%' . $wpdb->esc_like( $term ) . '%';

		// Zähle Gesamtergebnisse (für Pagination).
		$count_sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.post_type = 'nf_sub'
			   AND p.post_status != 'trash'
			   AND pm.meta_key LIKE %s
			   AND pm.meta_value LIKE %s",
			'_field_%',
			$like_term
		);
		$total = (int) $wpdb->get_var( $count_sql );

		// Performance-Schutz: Bei zu vielen Ergebnissen abbrechen.
		if ( $total > self::MAX_SEARCH_RESULTS ) {
			return [
				'error' => sprintf(
					'Zu viele Ergebnisse (%d). Bitte einen spezifischeren Suchbegriff verwenden.',
					$total
				),
			];
		}

		if ( 0 === $total ) {
			return [
				'results' => [],
				'total'   => 0,
				'page'    => $page,
				'pages'   => 0,
			];
		}

		// Haupt-Query mit Pagination.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_date, pm_form.meta_value AS form_id
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 INNER JOIN {$wpdb->postmeta} pm_form ON p.ID = pm_form.post_id
			     AND pm_form.meta_key = '_form_id'
			 WHERE p.post_type = 'nf_sub'
			   AND p.post_status != 'trash'
			   AND pm.meta_key LIKE %s
			   AND pm.meta_value LIKE %s
			 ORDER BY p.post_date DESC
			 LIMIT %d OFFSET %d",
			'_field_%',
			$like_term,
			$per_page,
			$offset
		);

		$results = $wpdb->get_results( $sql, ARRAY_A );

		// Ergebnisse anreichern (nutzt WordPress/NF APIs).
		$submissions = [];
		foreach ( $results as $row ) {
			$form_id    = absint( $row['form_id'] );
			$form_title = self::get_form_title( $form_id );
			$matches    = self::get_match_context( $row['ID'], $term );

			$submissions[] = [
				'id'         => absint( $row['ID'] ),
				'form_id'    => $form_id,
				'form_title' => $form_title,
				'date'       => $row['post_date'],
				'matches'    => $matches,
			];
		}

		return [
			'results' => $submissions,
			'total'   => $total,
			'page'    => $page,
			'pages'   => (int) ceil( $total / $per_page ),
		];
	}

	// =============================================================================
	// KONTEXT-ERMITTLUNG
	// =============================================================================

	/* --- Welche Felder enthalten den Treffer? --- */

	/**
	 * Ermittelt, in welchen Feldern der Suchbegriff gefunden wurde.
	 *
	 * Gibt sowohl das Feld-Label als auch den Wert zurück, damit der Admin
	 * verifizieren kann, dass es sich um die richtige Person handelt.
	 *
	 * @since 2.4.1 Gibt jetzt Label UND Wert zurück (vorher nur Label).
	 *
	 * @param int    $sub_id Submission-ID.
	 * @param string $term   Suchbegriff.
	 *
	 * @return array Array von {label: string, value: string} Objekten.
	 */
	private static function get_match_context( $sub_id, $term ) {
		global $wpdb;

		$like_term = '%' . $wpdb->esc_like( $term ) . '%';

		// Alle Felder finden, die den Begriff enthalten (mit Wert).
		$sql = $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id = %d
			   AND meta_key LIKE %s
			   AND meta_value LIKE %s",
			$sub_id,
			'_field_%',
			$like_term
		);

		$fields = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $fields ) ) {
			return [];
		}

		// Form-ID via WordPress get_post_meta() (Standard).
		$form_id = get_post_meta( $sub_id, '_form_id', true );
		$matches = [];

		foreach ( $fields as $field ) {
			// "_field_123" → "123"
			$field_id = absint( str_replace( '_field_', '', $field['meta_key'] ) );
			$label    = self::get_field_label( $form_id, $field_id );
			$value    = $field['meta_value'];

			// Wert kürzen falls zu lang (max 200 Zeichen).
			// WICHTIG: mb_* für UTF-8-Sicherheit (verhindert kaputte Multi-Byte-Zeichen).
			if ( mb_strlen( $value, 'UTF-8' ) > 200 ) {
				$value = mb_substr( $value, 0, 197, 'UTF-8' ) . '...';
			}

			$matches[] = [
				'label' => $label ? $label : 'Feld #' . $field_id,
				'value' => $value,
			];
		}

		return $matches;
	}

	// =============================================================================
	// NINJA FORMS API WRAPPER
	// =============================================================================

	/* --- Formular- und Feld-Namen abrufen --- */

	/**
	 * Holt den Formularnamen via Ninja Forms API.
	 *
	 * @param int $form_id Form-ID.
	 *
	 * @return string Form-Titel oder Fallback.
	 */
	private static function get_form_title( $form_id ) {
		static $cache = [];

		if ( isset( $cache[ $form_id ] ) ) {
			return $cache[ $form_id ];
		}

		// Ninja Forms API nutzen (WordPress-konform).
		if ( function_exists( 'Ninja_Forms' ) ) {
			$form = Ninja_Forms()->form( $form_id )->get();
			if ( $form && method_exists( $form, 'get_setting' ) ) {
				$title = $form->get_setting( 'title' );
				if ( $title ) {
					$cache[ $form_id ] = $title;
					return $title;
				}
			}
		}

		$cache[ $form_id ] = 'Formular #' . $form_id;
		return $cache[ $form_id ];
	}

	/**
	 * Holt das Feld-Label via Ninja Forms API.
	 *
	 * @param int $form_id  Form-ID.
	 * @param int $field_id Feld-ID.
	 *
	 * @return string Feld-Label oder Fallback.
	 */
	private static function get_field_label( $form_id, $field_id ) {
		static $cache = [];

		$cache_key = $form_id . '_' . $field_id;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		// Ninja Forms API nutzen (WordPress-konform).
		if ( function_exists( 'Ninja_Forms' ) ) {
			$fields = Ninja_Forms()->form( $form_id )->get_fields();
			if ( is_iterable( $fields ) ) {
				foreach ( $fields as $field ) {
					if ( is_object( $field ) && method_exists( $field, 'get_id' ) ) {
						if ( (int) $field->get_id() === $field_id ) {
							$label                 = $field->get_setting( 'label' );
							$cache[ $cache_key ] = $label ? $label : 'Feld #' . $field_id;
							return $cache[ $cache_key ];
						}
					}
				}
			}
		}

		$cache[ $cache_key ] = 'Feld #' . $field_id;
		return $cache[ $cache_key ];
	}

	// =============================================================================
	// LÖSCHUNG
	// =============================================================================

	/* --- Batch-Löschung mit Audit-Log --- */

	/**
	 * Löscht ausgewählte Submissions inkl. zugehöriger Dateien.
	 *
	 * WICHTIG: Nutzt WordPress-Standard wp_delete_post() für Löschung.
	 * Admin-ID wird für DSGVO-Audit-Log gespeichert.
	 *
	 * @param array $sub_ids Array von Submission-IDs.
	 *
	 * @return array{deleted: int, failed: int, files: int}
	 */
	public static function delete_submissions( $sub_ids ) {
		if ( ! is_array( $sub_ids ) || empty( $sub_ids ) ) {
			return [ 'deleted' => 0, 'failed' => 0, 'files' => 0 ];
		}

		// Admin-ID für DSGVO-Audit-Log.
		$current_user = wp_get_current_user();
		$admin_info   = $current_user->ID > 0
			? sprintf( 'Admin: %s (ID:%d)', $current_user->user_login, $current_user->ID )
			: 'Admin: unbekannt';

		$stats = [
			'deleted' => 0,
			'failed'  => 0,
			'files'   => 0,
		];

		foreach ( $sub_ids as $sub_id ) {
			$sub_id = absint( $sub_id );

			// SCHUTZ: Existenz prüfen (Race-Condition mit Cron).
			if ( ! get_post( $sub_id ) ) {
				continue; // Bereits gelöscht - kein Fehler.
			}

			// Validierung: Ist das eine Ninja Forms Submission? (Yoda Condition)
			if ( 'nf_sub' !== get_post_type( $sub_id ) ) {
				$stats['failed']++;
				continue;
			}

			// Form-ID VOR Löschung holen (für Log).
			$form_id  = get_post_meta( $sub_id, '_form_id', true );
			$sub_date = get_post_field( 'post_date', $sub_id );

			// Dateien löschen via bestehenden Uploads-Deleter.
			// HINWEIS: cleanup_files() existiert bereits und löscht alle Uploads einer Submission.
			// Return: array{deleted:int,errors:int}
			if ( class_exists( 'NF_AD_Uploads_Deleter' )
			     && method_exists( 'NF_AD_Uploads_Deleter', 'cleanup_files' ) ) {
				$file_stats     = NF_AD_Uploads_Deleter::cleanup_files( $sub_id );
				$stats['files'] += (int) ( $file_stats['deleted'] ?? 0 );
			}

			// Submission löschen via WordPress-Standard.
			$deleted = wp_delete_post( $sub_id, true );

			if ( $deleted ) {
				$stats['deleted']++;

				// DSGVO-Audit-Log mit Admin-Info.
				if ( class_exists( 'NF_AD_Logger' ) ) {
					NF_AD_Logger::log(
						absint( $form_id ),
						$sub_id,
						$sub_date,
						'success',
						sprintf( '[MANUAL] [DELETE] DSGVO-Löschung via Personen-Suche. %s', $admin_info )
					);
				}
			} else {
				$stats['failed']++;
			}
		}

		return $stats;
	}
}
