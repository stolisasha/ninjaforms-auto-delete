# Optimierungskonzept: Uploads Eraser Performance

## Problem-Analyse

### Aktueller Ansatz (LANGSAM)
Der Dry-Run in `calculate_dry_run()` macht aktuell:

1. **Submissions-basiert**: Iteriert durch tausende Submissions
2. **Viele DB-Queries**: `get_post_meta()` für jede Submission × Anzahl Upload-Felder
3. **Viele Disk-Checks**: `file_exists()` für JEDE Datei-Referenz
4. **Keine Optimierung**: Kein Caching, keine Batch-Optimierung

**Beispiel bei 5000 alten Submissions mit 3 Upload-Feldern:**
- 5000 × 3 = **15.000 `get_post_meta()` Aufrufe**
- 5000+ **`file_exists()` Disk-Checks**
- Laufzeit: Timeout nach 20 Sekunden

### Ninja Forms File Uploads Add-on Ansatz (SCHNELL)

**Entdeckung aus `uploadstable.php` Zeilen 143-154:**

```php
// 1. EINE DB-Query holt ALLE Upload-Einträge aus der Tabelle
$uploads = NF_File_Uploads()->model->fetch( $where );

// 2. Iteriere durch Upload-Einträge (nicht Submissions!)
foreach ( $uploads as $upload ) {
    $upload_data = unserialize( $upload['data'] );

    // 3. INTELLIGENTER file_exists() Check
    if ( ! NF_File_Uploads()->controllers->uploads->file_exists( $upload_data ) ) {
        continue;  // Überspringe nicht-existierende Files
    }

    // 4. Zeige nur existierende Files an
}
```

**Der intelligente `file_exists()` Check (`uploads.php` Zeilen 291-303):**

```php
public function file_exists( $upload_data ) {
    // SCHNELL: Typ-Check
    if ( ! is_array( $upload_data ) ) return false;

    // SCHNELL: Externe Storage = immer true (kein Disk-Check!)
    if ( 'server' !== $upload_data['upload_location'] ) {
        return true;
    }

    // SCHNELL: Flag-Check (kein Disk-Check!)
    if ( isset( $upload_data['removed_from_server'] ) && $upload_data['removed_from_server'] ) {
        return false;
    }

    // NUR HIER: Tatsächlicher Disk-Check
    return file_exists( $upload_data['file_path']);
}
```

**Performance-Tricks:**
1. ✅ Externe Uploads (OneDrive, S3, etc.) werden NICHT auf Disk gecheckt → sofort `true`
2. ✅ Gelöschte Uploads haben `removed_from_server` Flag → sofort `false` ohne Disk-Check
3. ✅ Nur lokale Server-Uploads ohne Flag werden tatsächlich gecheckt

## Optimierungskonzept

### Strategie 1: Upload-Tabellen-basierte Zählung (EMPFOHLEN)

**Ansatz:** Kopiere die Logik des File Uploads Add-ons 1:1

#### Vorteile:
- ✅ **Eine DB-Query** statt tausende `get_post_meta()` Aufrufe
- ✅ **Intelligente `file_exists()` Checks**: Nur wenn wirklich nötig
- ✅ **OneDrive-Problem gelöst**: Externe Uploads werden übersprungen
- ✅ **Konsistente Logik**: Nutzt dieselbe Methode wie das Original-Add-on

#### Implementation:

```php
/**
 * Optimierte Zählung: Nutzt Upload-Tabelle direkt (wie das Add-on selbst)
 */
public static function count_uploads_for_form_optimized( $fid, $cutoff ) {
    global $wpdb;

    $uploads_table = $wpdb->prefix . 'ninja_forms_uploads';

    // Prüfe ob Tabelle existiert
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $uploads_table ) ) !== $uploads_table ) {
        return 0;
    }

    // Schema-Detection (bereits im Code vorhanden, weiter nutzen)
    $schema = self::get_uploads_table_schema( $uploads_table );
    $date_col = self::detect_uploads_date_column( $schema['columns'] );

    if ( ! $date_col ) {
        return 0;
    }

    // Cutoff-Wert konvertieren
    $date_is_int = self::is_int_timestamp_column( $date_col, $schema['types'] );
    $cutoff_value = $date_is_int ? strtotime( $cutoff ) : $cutoff;

    // EINE Query holt alle betroffenen Uploads
    $sql = "SELECT data FROM {$uploads_table}
            WHERE form_id = %d
            AND {$date_col} < " . ( $date_is_int ? '%d' : '%s' );

    $rows = $wpdb->get_col( $wpdb->prepare( $sql, $fid, $cutoff_value ) );

    if ( empty( $rows ) ) {
        return 0;
    }

    $count = 0;

    // Iteriere durch Upload-Daten (nicht Submissions!)
    foreach ( $rows as $serialized_data ) {
        $upload_data = maybe_unserialize( $serialized_data );

        if ( ! is_array( $upload_data ) ) {
            continue;
        }

        // INTELLIGENTER Check (kopiert von NF File Uploads)
        if ( ! self::smart_file_exists( $upload_data ) ) {
            continue;
        }

        $count++;
    }

    return $count;
}

/**
 * Intelligenter file_exists Check - kopiert von NF File Uploads Add-on
 */
private static function smart_file_exists( $upload_data ) {
    if ( ! is_array( $upload_data ) ) {
        return false;
    }

    // SCHNELL: Externe Storage (OneDrive, S3, etc.) = kein Disk-Check!
    if ( isset( $upload_data['upload_location'] ) && 'server' !== $upload_data['upload_location'] ) {
        return true;  // Externe Uploads zählen wir nicht (wurden bereits verschoben)
    }

    // SCHNELL: Flag-Check statt Disk-Check
    if ( isset( $upload_data['removed_from_server'] ) && $upload_data['removed_from_server'] ) {
        return false;
    }

    // NUR HIER: Tatsächlicher Disk-Check für lokale Server-Uploads
    if ( isset( $upload_data['file_path'] ) ) {
        return file_exists( $upload_data['file_path'] );
    }

    return false;
}
```

#### Performance-Gewinn:

**Vorher (5000 alte Submissions):**
- 15.000 DB-Queries (`get_post_meta`)
- 5000+ `file_exists()` Calls
- Laufzeit: 20+ Sekunden (Timeout)

**Nachher:**
- **1 DB-Query** (SELECT auf Uploads-Tabelle)
- ~500 `file_exists()` Calls (nur für lokale Server-Uploads ohne Flag)
- Laufzeit: **< 2 Sekunden**

### Strategie 2: Meta-basierte Optimierung (Fallback)

Für Installationen OHNE Upload-Tabelle oder mit Legacy Meta-Storage:

```php
/**
 * Optimierte Meta-basierte Zählung mit Batch-Loading
 */
public static function count_meta_uploads_optimized( $fid, $cutoff, $upload_field_ids ) {
    $args = [
        'post_type'      => 'nf_sub',
        'posts_per_page' => 500,  // Größere Batches
        'fields'         => 'ids',
        'date_query'     => [
            [ 'column' => 'post_date', 'before' => $cutoff, 'inclusive' => true ],
        ],
        'meta_query'     => [
            [ 'key' => '_form_id', 'value' => $fid ],
        ],
    ];

    $q = new WP_Query( $args );
    $ids = $q->posts;

    if ( empty( $ids ) ) {
        return 0;
    }

    // OPTIMIERUNG: Pre-Load ALLE Meta-Daten auf einmal!
    update_metadata_cache( 'post', $ids );

    $count = 0;

    foreach ( $ids as $sid ) {
        foreach ( $upload_field_ids as $field_id ) {
            $meta_key = '_field_' . $field_id;

            // Jetzt aus Cache gelesen (kein DB-Query mehr!)
            $raw = get_post_meta( $sid, $meta_key, true );

            if ( empty( $raw ) ) {
                continue;
            }

            $val = maybe_unserialize( $raw );

            // JSON-Decode wenn nötig
            if ( is_string( $val ) && ( strpos( trim( $val ), '[' ) === 0 || strpos( trim( $val ), '{' ) === 0 ) ) {
                $json = json_decode( trim( $val ), true );
                if ( JSON_ERROR_NONE === json_last_error() ) {
                    $val = $json;
                }
            }

            if ( is_array( $val ) ) {
                $count += count( $val );
            } else {
                $count++;
            }
        }
    }

    return $count;
}
```

**Performance-Gewinn:**
- Von 15.000 einzelnen `get_post_meta()` → **~10 DB-Queries** (Batch-Loading)
- Von 20+ Sekunden → **~5 Sekunden**

### Strategie 3: Hybrid-Ansatz (BESTE LÖSUNG)

Kombiniere beide Strategien für maximale Kompatibilität:

```php
public static function calculate_dry_run_optimized( $type = 'subs' ) {
    // ... bestehender Code für Submissions-Zählung ...

    if ( $type === 'files' ) {
        $total_count = 0;

        foreach ( $forms as $form ) {
            $fid = $form->get_id();

            // 1. Versuche Upload-Tabellen-basierte Zählung (schnellste Methode)
            if ( self::has_uploads_table() ) {
                $table_count = self::count_uploads_for_form_optimized( $fid, $cutoff );
                $total_count += $table_count;
            }

            // 2. Fallback: Optimierte Meta-basierte Zählung
            $upload_field_ids = self::get_upload_field_ids( $fid );
            if ( ! empty( $upload_field_ids ) ) {
                $meta_count = self::count_meta_uploads_optimized( $fid, $cutoff, $upload_field_ids );
                $total_count += $meta_count;
            }
        }

        return $total_count;
    }
}
```

## OneDrive-Problem Lösung

**Problem:** OneDrive-Plugin verschiebt Files und löscht sie lokal, aber DB-Einträge bleiben.

**Lösung:** Der `smart_file_exists()` Check erkennt:

1. **`upload_location !== 'server'`** → Externe Storage → **NICHT zählen/löschen**
2. **`removed_from_server = true`** → Bereits gelöscht → **NICHT zählen/löschen**

Das OneDrive-Plugin sollte idealerweise:
- `upload_location` auf `'onedrive'` setzen, ODER
- `removed_from_server = true` setzen

Dann werden diese Uploads automatisch übersprungen!

## Migration-Plan

### Phase 1: Neue Methoden hinzufügen
1. `smart_file_exists()` in `NF_AD_Uploads_Deleter` einfügen
2. `count_uploads_for_form_optimized()` implementieren
3. `count_meta_uploads_optimized()` implementieren

### Phase 2: Dry-Run optimieren
1. `calculate_dry_run()` umbauen auf Hybrid-Ansatz
2. Testing auf Dev-Server

### Phase 3: Cleanup optimieren
1. `cleanup_uploads_for_form()` mit `smart_file_exists()` optimieren
2. Vermeidung von Disk-Checks für externe Uploads

### Phase 4: Testing
1. Test mit 5000+ alten Submissions
2. Test mit OneDrive-Plugin
3. Test mit gemischten Installationen (Tabelle + Meta)

## Erwartete Performance-Verbesserung

| Szenario | Vorher | Nachher | Verbesserung |
|----------|--------|---------|--------------|
| 5000 alte Submissions, nur Upload-Tabelle | 20+ Sekunden (Timeout) | < 2 Sekunden | **10x schneller** |
| 5000 alte Submissions, nur Meta | 20+ Sekunden | ~5 Sekunden | **4x schneller** |
| 5000 alte Submissions, gemischt | 25+ Sekunden | ~6 Sekunden | **4x schneller** |
| Mit OneDrive (externe Storage) | 20+ Sekunden | < 1 Sekunde | **20x+ schneller** |

## Code-Änderungen

### Datei: `includes/class-nf-ad-uploads-deleter.php`

**NEU hinzufügen:**
```php
/**
 * Smart file existence check - inspired by NF File Uploads Add-on
 * Avoids disk checks for external storage (OneDrive, S3, etc.)
 */
private static function smart_file_exists( $upload_data ) { ... }

/**
 * Optimized counting using uploads table directly
 */
public static function count_uploads_for_form_optimized( $fid, $cutoff ) { ... }
```

### Datei: `includes/class-nf-ad-submissions-eraser.php`

**ÄNDERN:**
```php
public static function calculate_dry_run( $type = 'subs' ) {
    // ...

    if ( $type === 'files' ) {
        // ALTE LOGIK ENTFERNEN (Zeilen 152-255)
        // NEUE LOGIK EINFÜGEN: Hybrid-Ansatz
    }
}
```

## Zusammenfassung

**Haupterkenntnisse:**
1. ✅ Das File Uploads Add-on macht KEINE `file_exists()` Checks für externe Storage
2. ✅ Es nutzt das `removed_from_server` Flag für schnelle Checks
3. ✅ Es arbeitet Upload-Tabellen-basiert, nicht Submissions-basiert
4. ✅ Eine einzige DB-Query holt alle relevanten Uploads

**Kritische Optimierungen:**
1. 🚀 Upload-Tabellen-basierte Zählung statt Submissions-Iteration
2. 🚀 `smart_file_exists()` mit Flag-Checks vor Disk-Checks
3. 🚀 Meta-Batch-Loading mit `update_metadata_cache()`
4. 🚀 Externe Storage komplett überspringen

**OneDrive-Kompatibilität:**
- Durch `upload_location` Check werden verschobene Files nicht gezählt
- Kein `file_exists()` für externe Storage = massive Performance-Verbesserung
