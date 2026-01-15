<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// DASHBOARD (ADMIN UI)
// =============================================================================

/**
 * Dashboard-Controller für die Admin-Oberfläche.
 */
class NF_AD_Dashboard {

    // =============================================================================
    // KONSTANTEN
    // =============================================================================
    const OPTION_KEY = 'nf_ad_settings';

    // =============================================================================
    // ADMIN MENU
    // =============================================================================

    /* --- Menüregistrierung --- */
    /**
     * Registriert das Submenü im Ninja-Forms-Admin-Menü.
     *
     * @return void
     */
    public static function register_menu() {
        $hook = add_submenu_page( 'ninja-forms', 'Auto Delete', 'Auto Delete', 'manage_options', 'nf-auto-delete', [ __CLASS__, 'render_page' ] );
        add_action( "admin_print_styles-$hook", [ __CLASS__, 'enqueue_assets' ] );
    }

    // =============================================================================
    // ASSETS (INLINE CSS/JS)
    // =============================================================================

    /* --- Styles & Skripte (Inline) --- */
    /**
     * Gibt Inline-CSS/JS für die Admin-Seite aus.
     *
     * @return void
     */
    public static function enqueue_assets() {
        $nonce = wp_create_nonce( 'nf_ad_security' );
        ?>
        <script>window.NF_AD_Config = { nonce: '<?php echo esc_js($nonce); ?>' };</script>
        <style>
            /* --- Badges & Utilities --- */
            .nf-ad-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
            .nf-ad-badge.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
            .nf-ad-badge.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
            .nf-ad-badge.warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
            .nf-ad-badge.skipped { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }
            .nf-ad-badge.running { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; animation: pulse 2s infinite; }
            @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
            .hidden { display: none !important; }
            .nf-ad-danger { color: #d63638; font-weight: 600; }
            .nf-ad-error { color: #d63638; font-weight: 600; }

            /* --- Person Search: Sub-Tables --- */
            .nf-ad-table-person { border-collapse: collapse; }
            .nf-ad-table-person .nf-ad-submission-row { background: #f6f7f7; border-top: 1px solid #c3c4c7; }
            .nf-ad-table-person .nf-ad-submission-row td { padding: 12px 10px; vertical-align: middle; }
            .nf-ad-table-person .nf-ad-fields-row { background: #fff; }
            .nf-ad-table-person .nf-ad-fields-row > td { padding: 0 10px 15px 10px; border-bottom: 1px solid #e0e0e0; }
            .nf-ad-fields-table { width: 100%; margin: 5px 0 0 0; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; }
            .nf-ad-fields-table td { padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
            .nf-ad-fields-table tr:last-child td { border-bottom: none; }
            .nf-ad-field-label { width: 150px; font-weight: 600; color: #50575e; background: #f0f0f1; }
            .nf-ad-field-value { color: #1d2327; word-break: break-word; }
            
            /* --- Layout Grundlagen --- */
            .wrap h1 { margin-bottom: 20px; }
            .nav-tab-wrapper { margin-bottom: 20px; border-bottom: 1px solid #c3c4c7; }
            .nav-tab { margin-right: 4px; padding: 6px 10px; } 
            .nf-ad-tab-content { background: #fff; border: 1px solid #c3c4c7; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top: 10px; }
            .nf-ad-headline-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; min-height: 30px; }
            .nf-ad-headline-row h2 { margin: 0; font-size: 1.3em; color: #1d2327; font-weight: 600; padding: 0; }
            
            /* --- Tabellen: Allgemein --- */
            .nf-ad-settings-table { margin-top: 0; }
            .nf-ad-settings-table th { width: 220px; padding: 15px 10px 15px 0; vertical-align: top; }
            .nf-ad-settings-table td { padding: 15px 10px; }
            
            /* --- Tab: Regeln (Grid-System) --- */
            .nf-ad-global-row { display: grid; grid-template-columns: minmax(320px, 1fr) minmax(280px, auto); column-gap: 24px; align-items: start; }
            .nf-ad-global-left { min-width: 0; }
            .nf-ad-global-left-controls { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; }
            .nf-ad-global-left .description { margin: 8px 0 0; }
            .nf-ad-global-right { justify-self: end; text-align: right; min-width: 0; }
            .nf-ad-global-right-actions { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; }
            .nf-ad-calc-result { margin: 8px 0 0; font-size: 14px; line-height: 1.5; font-weight: 700; color: #d63638; word-break: break-word; min-height: 21px; }

            @media (max-width: 900px) {
                .nf-ad-global-row { grid-template-columns: 1fr; row-gap: 14px; }
                .nf-ad-global-right { justify-self: start; text-align: left; }
            }

            /* --- Tabelle: Regeln (Spezifika) --- */
            .nf-ad-table-rules { table-layout: fixed; width: 100%; border-collapse: collapse; }
            .nf-ad-table-rules th.column-id, .nf-ad-table-rules td.column-id { width: 60px; text-align: left; vertical-align: middle; padding-right: 6px; white-space: nowrap; }
            .nf-ad-table-rules th.column-name, .nf-ad-table-rules td.column-name { width: 320px; text-align: left !important; vertical-align: middle; padding-left: 0; padding-right: 10px; }
            .nf-ad-table-rules th.column-rule, .nf-ad-table-rules td.column-rule { width: auto; text-align: left !important; vertical-align: middle; }
            
            .nf-ad-rule-cell-content { display: flex; align-items: center; gap: 10px; justify-content: flex-start; }
            .nf-ad-rule-cell-content select { max-width: 250px; }
            .custom-days-wrapper { display: inline-flex; align-items: center; gap: 5px; }
            .custom-days-input { width: 70px !important; }

            /* --- Modals & Listen --- */
            .nf-ad-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: none; justify-content: center; align-items: center; }
            .nf-ad-modal { background: #fff; width: 500px; max-width: 90%; padding: 0; border-radius: 4px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); display: flex; flex-direction: column; }
            .nf-ad-modal-header { padding: 20px 25px; border-bottom: 1px solid #dcdcde; }
            .nf-ad-modal-header h2 { margin: 0; font-size: 1.3em; }
            .nf-ad-modal-body { padding: 25px; flex: 1; max-height: 400px; overflow-y: auto; }
            .nf-ad-modal-footer { padding: 15px 25px; border-top: 1px solid #dcdcde; display: flex; justify-content: space-between; align-items: center; background: #f6f7f7; }
            .nf-ad-modal-footer.end { justify-content: flex-end; }
            .nf-ad-preview-item { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f0f0f1; }
            .nf-ad-preview-item:last-child { border-bottom: none; }
            .nf-ad-preview-icon { font-size: 20px; width: 30px; text-align: center; }
            .nf-ad-preview-text { flex: 1; }
            .nf-ad-preview-text strong { display: block; color: #1d2327; }
            .nf-ad-preview-text span { font-size: 12px; color: #646970; }
            .nf-ad-preview-warning { background: #fcf0f1; border: 1px solid #d63638; border-radius: 4px; padding: 12px 15px; margin-top: 15px; color: #8a2424; font-size: 13px; }
            .nf-ad-progress-list { max-height: 300px; overflow-y: auto; margin: 20px 0; border: 1px solid #ddd; background: #f9f9f9; padding: 10px; list-style: none; }
            .nf-ad-progress-list li { padding: 5px; border-bottom: 1px solid #eee; font-size: 13px; display: flex; align-items: center; }

            /* --- Modal Spinner Animation --- */
            #batch-log .loading .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                vertical-align: text-bottom;
                animation: nf-ad-spin 1.5s infinite linear;
            }

            @keyframes nf-ad-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            /* --- Einstellungen & Logs --- */
            .nf-ad-radio-group label { display: inline-flex; align-items: center; gap: 6px; margin-right: 30px; margin-bottom: 8px; font-weight: 400; }
            /* Nur ID-Spalte der Fristen-Tabelle */
			.nf-ad-table-rules thead th:nth-child(1), 
			.nf-ad-table-rules tbody td:nth-child(1) { width: 20px !important; min-width: 20px !important; }
            /* Spaltenbreiten & Ausrichtung */
            
            /* Status-Spalte (beide Tabellen) */
            .wp-list-table thead th:nth-child(1), 
            .wp-list-table tbody td:nth-child(1) { width: 90px; } 
            
            /* Zeitpunkt-Spalte */
            /* Header: links ausrichten (verhindert Icon-Verschiebung) */
            .wp-list-table thead th:last-child { width: 160px; text-align: left; }
            
            /* Body: rechts ausrichten (für Datum/Zahlen) */
            .wp-list-table tbody td:last-child { width: 160px; text-align: left!important; }
            
            /* Header-Stil */
            th.sortable a { color: #3c434a; text-decoration: none; display: block; width: 100%; }
            th.sortable a:hover { color: #2271b1; }
            
            /* Font-Weight vereinheitlichen */
            th.sorted a { color: #000; font-weight: 400 !important; } 
            
            /* Icon-Abstand (Sorting) */
            th.sorted a:after { 
                content: "\f140"; 
                font-family: dashicons; 
                float: none !important; /* Kein Floating! */
                display: inline-block;  /* Fließt im Text mit */
                margin-left: 4px;       /* Kleiner Abstand */
                vertical-align: text-bottom;
            }
            th.sorted.asc a:after { content: "\f142"; }
        </style>
        <?php
    }
    // =============================================================================
    // DASHBOARD PAGE (TABS)
    // =============================================================================

    /* --- Renderer: Seite & Tabs --- */
    /**
     * Rendert die Dashboard-Seite inkl. Tabs und Formulare.
     *
     * @return void
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'nf-auto-delete' ) );
        }
        self::handle_save();
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'logs';
        $settings = get_option( self::OPTION_KEY, [] );
        
        $sub_handling  = sanitize_key( $settings['sub_handling'] ?? 'keep' );
        $file_handling = sanitize_key( $settings['file_handling'] ?? 'keep' );
        $log_limit     = isset( $settings['log_limit'] ) ? (int) $settings['log_limit'] : 256;
        $cron_active   = ! empty( $settings['cron_active'] );
        $cron_hour     = isset( $settings['cron_hour'] ) ? (int) $settings['cron_hour'] : 3;
        $global_days   = isset( $settings['global'] ) ? (int) $settings['global'] : 365;
        $form_rules    = isset( $settings['forms'] ) && is_array( $settings['forms'] ) ? $settings['forms'] : [];
        ?>
        <div class="wrap">
            <h1>Auto Delete for Ninja Forms</h1>
            <?php settings_errors('nf_ad'); ?>
            <?php
            $base_url = add_query_arg( [ 'page' => 'nf-auto-delete' ], admin_url( 'admin.php' ) );
            $tab_logs_url = add_query_arg( [ 'tab' => 'logs' ], $base_url );
            $tab_rules_url = add_query_arg( [ 'tab' => 'rules' ], $base_url );
            $tab_person_url = add_query_arg( [ 'tab' => 'person' ], $base_url );
            $tab_settings_url = add_query_arg( [ 'tab' => 'settings' ], $base_url );
            ?>
            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( $tab_logs_url ); ?>" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">Log</a>
                <a href="<?php echo esc_url( $tab_rules_url ); ?>" class="nav-tab <?php echo 'rules' === $active_tab ? 'nav-tab-active' : ''; ?>">Fristen definieren</a>
                <a href="<?php echo esc_url( $tab_person_url ); ?>" class="nav-tab <?php echo 'person' === $active_tab ? 'nav-tab-active' : ''; ?>">Suche in Formularen</a>
                <a href="<?php echo esc_url( $tab_settings_url ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">Einstellungen</a>
            </nav>

            <!-- Preview Modal (Dry-Run Ergebnisse) -->
            <div id="preview-modal" class="nf-ad-modal-overlay">
                <div class="nf-ad-modal">
                    <div class="nf-ad-modal-header">
                        <h2>Bereinigung starten?</h2>
                    </div>
                    <div class="nf-ad-modal-body">
                        <div id="preview-content"></div>
                        <div class="nf-ad-preview-warning">
                            <strong>Achtung:</strong> Dieser Vorgang kann nicht rückgängig gemacht werden.
                        </div>
                    </div>
                    <div class="nf-ad-modal-footer">
                        <button type="button" id="preview-cancel" class="button">Abbrechen</button>
                        <button type="button" id="preview-start" class="button button-primary">Bereinigung starten</button>
                    </div>
                </div>
            </div>

            <!-- Batch Modal (Fortschritt) -->
            <div id="batch-modal" class="nf-ad-modal-overlay">
                <div class="nf-ad-modal">
                    <div class="nf-ad-modal-header">
                        <h2 id="batch-title">Bereinigung läuft...</h2>
                    </div>
                    <div class="nf-ad-modal-body">
                        <ul id="batch-log" class="nf-ad-progress-list"></ul>
                    </div>
                    <div class="nf-ad-modal-footer" id="batch-footer">
                        <button type="button" id="batch-cancel" class="button">Abbrechen</button>
                        <span></span>
                    </div>
                </div>
            </div>

            <!-- Error Details Modal -->
            <div id="error-details-modal" class="nf-ad-modal-overlay">
                <div class="nf-ad-modal">
                    <div class="nf-ad-modal-header">
                        <h2>Fehlerdetails</h2>
                    </div>
                    <div class="nf-ad-modal-body">
                        <p style="margin-bottom:10px;">Folgende Fehler sind während der Bereinigung aufgetreten:</p>
                        <ul id="error-details-list" style="list-style:disc; margin-left:20px; max-height:300px; overflow-y:auto;"></ul>
                    </div>
                    <div class="nf-ad-modal-footer" style="justify-content:flex-end;">
                        <button type="button" id="error-details-close" class="button button-primary">Schließen</button>
                    </div>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'nf_ad_save_settings', 'nf_ad_nonce' ); ?>
                
                <?php if ( $active_tab === 'logs' ) : ?>
                    <div class="nf-ad-tab-content">
                        <?php self::render_logs_tab(); ?>
                    </div>
                
                <?php elseif ( $active_tab === 'rules' ) : ?>
                    <div class="nf-ad-tab-content">
                        <input type="hidden" name="nf_ad_context" value="rules">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="global_days">Globaler Standard (Tage)</label></th>
                                <td>
                                    <div class="nf-ad-global-row">
                                        <div class="nf-ad-global-left">
                                            <div class="nf-ad-global-left-controls">
                                                <input name="global_days" type="number" id="global_days" value="<?php echo esc_attr( $global_days ); ?>" class="small-text" min="1">
                                                <button type="submit" class="button button-secondary">Speichern</button>
                                            </div>
                                            <p class="description">Gilt für alle Formulare, die auf "Global" eingestellt sind.</p>
                                        </div>

                                        <div class="nf-ad-global-right">
                                            <div class="nf-ad-global-right-actions">
                                                <button type="button" class="button button-secondary calc-btn" data-type="subs">Einträge simulieren</button>
                                                <button type="button" class="button button-secondary calc-btn" data-type="files">Uploads simulieren</button>
                                            </div>
                                            <p id="calc-output" class="nf-ad-calc-result"></p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <div class="nf-ad-headline-row" style="margin-top:20px;">
                            <h3>Fristen pro Formular</h3>
                        </div>

                        <table class="wp-list-table widefat striped table-view-list nf-ad-table-rules">
                            <thead>
                                <tr>
                                    <th class="column-id">ID</th>
                                    <th class="column-name">Formular Name</th>
                                    <th class="column-rule">Lösch-Frist</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $forms = Ninja_Forms()->form()->get_forms();
                                if ( empty( $forms ) ) : ?><tr><td colspan="3">Keine Formulare.</td></tr>
                                <?php else : foreach ( $forms as $form ) :
                                        $id = $form->get_id();
                                        $rule = isset( $form_rules[$id] ) ? $form_rules[$id] : [ 'mode' => 'global', 'days' => '' ];
                                ?>
                                    <tr>
                                        <td class="column-id">#<?php echo esc_html( $id ); ?></td>
                                        <td class="column-name"><strong><?php echo esc_html( $form->get_setting( 'title' ) ); ?></strong></td>
                                        <td class="column-rule">
                                            <div class="nf-ad-rule-cell-content">
                                                <select name="forms[<?php echo esc_attr( $id ); ?>][mode]" onchange="toggleCustomInput(this, 'custom-container-<?php echo esc_attr( $id ); ?>')">
                                                    <option value="global" <?php selected( $rule['mode'] ?? 'global', 'global' ); ?>>Globaler Standard (<?php echo esc_html( absint( $global_days ) ); ?> Tage)</option>
                                                    <option value="never" <?php selected( $rule['mode'] ?? 'global', 'never' ); ?>>Niemals löschen</option>
                                                    <option value="custom" <?php selected( $rule['mode'] ?? 'global', 'custom' ); ?>>Individuell</option>
                                                </select>
                                                <span id="custom-container-<?php echo esc_attr( $id ); ?>" class="custom-days-wrapper <?php echo ( ( $rule['mode'] ?? 'global' ) !== 'custom' ? 'hidden' : '' ); ?>">
                                                    <input type="number" name="forms[<?php echo esc_attr( $id ); ?>][days]" value="<?php echo esc_attr( $rule['days'] ); ?>" class="custom-days-input" placeholder="365"> Tage
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                        <div style="margin-top: 20px;">
                            <?php submit_button('Fristen speichern', 'primary', 'submit', false); ?>
                        </div>
                    </div>
                    
                    <script>
                    function toggleCustomInput(select, containerId) {
                        var container = document.getElementById(containerId);
                        if(select.value === 'custom') { container.classList.remove('hidden'); var input = container.querySelector('input'); if(input) input.focus(); } else { container.classList.add('hidden'); }
                    }
                    jQuery(document).ready(function($) {
                        $('.calc-btn').click(function() {
                            var btn   = $(this);
                            var type  = btn.data('type');
                            var label = btn.text();

                            btn.prop('disabled', true).text('Rechne...');
                            $('#calc-output').text('Analysiere Datenbank ...');

                            $.post(
                                ajaxurl,
                                { action: 'nf_ad_calculate', security: NF_AD_Config.nonce, type: type }
                            )
                                .done(function(res) {
                                    if (res && res.success && res.data && typeof res.data.count !== 'undefined') {
                                        var txt = (res.data.type === 'subs') ? 'Einträge' : 'Uploads';
                                        // BUGFIX: Zeige "5000+" wenn Performance-Limit erreicht wurde.
                                        // BUGFIX #2: Nur "+" anzeigen wenn count > 0 (sonst verwirrend "0+").
                                        var count_display = res.data.count;
                                        if (res.data.limit_reached && res.data.count > 0) {
                                            count_display = count_display + '+';
                                        }

                                        // NEU: Bei Submissions auch Trash-Info anzeigen (seit v2.2.1).
                                        var output = 'Simulation Ergebnis: ' + count_display + ' ' + txt + ' sind älter als die Frist.';
                                        if (res.data.type === 'subs' && res.data.trashed > 0) {
                                            output += ' (' + res.data.trashed + ' davon im Papierkorb)';
                                        }
                                        $('#calc-output').text(output);
                                    } else if (res && res.data) {
                                        // WP/AJAX returned a structured error.
                                        $('#calc-output').text('Fehler: ' + res.data);
                                    } else {
                                        $('#calc-output').text('Fehler bei der Berechnung.');
                                    }
                                })
                                .fail(function(xhr) {
                                    var msg = 'Fehler (Server).';
                                    if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                                        msg = 'Fehler: ' + xhr.responseJSON.data;
                                    }
                                    $('#calc-output').text(msg);
                                })
                                .always(function() {
                                    btn.prop('disabled', false).text(label);
                                });
                        });
                    });
                    </script>

                <?php elseif ( 'person' === $active_tab ) : ?>
                    <div class="nf-ad-tab-content">
                        <?php self::render_person_tab(); ?>
                    </div>

                <?php elseif ( 'settings' === $active_tab ) : ?>
                    <div class="nf-ad-tab-content">
                        <input type="hidden" name="nf_ad_context" value="settings">
                        <div class="nf-ad-headline-row"><h2>Lösch-Umfang</h2></div>
                        <table class="form-table nf-ad-settings-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Umgang mit Einträgen</th>
                                    <td>
                                        <div class="nf-ad-radio-group">
                                            <label><input type="radio" name="sub_handling" value="keep" <?php checked( $sub_handling, 'keep' ); ?>> Behalten (Standard)</label>
                                            <label><input type="radio" name="sub_handling" value="trash" <?php checked( $sub_handling, 'trash' ); ?>> In den Papierkorb legen</label>
                                            <label class="nf-ad-danger"><input type="radio" name="sub_handling" value="delete" <?php checked( $sub_handling, 'delete' ); ?>> Endgültig löschen</label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Umgang mit Dateien</th>
                                    <td>
                                        <div class="nf-ad-radio-group">
                                            <label><input type="radio" name="file_handling" value="keep" <?php checked( $file_handling, 'keep' ); ?>> Behalten</label>
                                            <label class="nf-ad-danger"><input type="radio" name="file_handling" value="delete" <?php checked( $file_handling, 'delete' ); ?>> Vom Server löschen</label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Manuell triggern</th>
                                    <td class="nf-ad-actions-row">
                                        <button type="button" id="force-run" class="button button-secondary">Jetzt ausführen</button>
                                        <p class="description">Wendet die oben gewählten Regeln sofort auf alle fälligen Einträge an.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="log_limit">Log-Einträge</label></th>
                                    <td><input name="log_limit" type="number" value="<?php echo esc_attr( $log_limit ); ?>" class="small-text" min="10">
                                    <p class="description">Wie viele Zeilen im Protokoll behalten?</p></td>
                                </tr>
                            </tbody>
                        </table>
                        <hr>
                        <div class="nf-ad-headline-row"><h2>Automatisierung (Cronjob)</h2></div>
                        <table class="form-table nf-ad-settings-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Zeitplaner</th>
                                    <td><fieldset>
                                        <label for="cron_active">
                                            <input name="cron_active" type="checkbox" id="cron_active" value="1" <?php checked( $cron_active, true ); ?>> 
                                            Tägliche automatische Bereinigung aktivieren
                                        </label>
                                    </fieldset></td>
                                </tr>
                                <tr>
                                    <th scope="row">Uhrzeit</th>
                                    <td>
                                        <select name="cron_hour">
                                            <?php for($i=0; $i<24; $i++): $h = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                                <option value="<?php echo $i; ?>" <?php selected($cron_hour, $i); ?>><?php echo $h; ?>:00 Uhr</option>
                                            <?php endfor; ?>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <?php submit_button('Einstellungen speichern'); ?>
                    </div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        var batchCounter = 1;
                        var batchCancelled = false;
                        var currentXhr = null;
                        var collectedErrors = [];  // Sammelt Fehlerdetails über alle Batches
                        var totalErrorCount = 0;   // Gesamtzahl der Fehler

                        // Zeigt den "Fertig" Zustand im Batch-Modal.
                        function showBatchFinished(message, isError) {
                            $('#batch-title').text(isError ? 'Fehler' : 'Bereinigung abgeschlossen');
                            if (!isError) {
                                // Wenn Fehler gesammelt wurden, als klickbaren Link anzeigen
                                if (totalErrorCount > 0) {
                                    var errorLink = '<a href="#" id="show-error-details" style="color:#d63638; text-decoration:underline; cursor:pointer;">' + totalErrorCount + ' Fehler</a>';
                                    $('#batch-log').append('<li style="font-weight:bold;">' + message + ' (' + errorLink + ')</li>');
                                } else {
                                    $('#batch-log').append('<li style="color:green; font-weight:bold;">' + message + '</li>');
                                }
                            }
                            // Footer: Nur "Fertig" Button rechtsbündig.
                            $('#batch-footer').addClass('end').html(
                                '<button type="button" class="button button-primary" onclick="location.reload()">Fertig - Seite neu laden</button>'
                            );

                            // Event-Handler für Error-Details Link
                            $('#show-error-details').click(function(e) {
                                e.preventDefault();
                                showErrorDetailsModal();
                            });
                        }

                        // Zeigt das Error-Details-Modal an.
                        function showErrorDetailsModal() {
                            var list = $('#error-details-list');
                            list.empty();
                            if (collectedErrors.length === 0) {
                                list.append('<li>Keine Details verfügbar.</li>');
                            } else {
                                collectedErrors.forEach(function(err) {
                                    list.append('<li>' + $('<div>').text(err).html() + '</li>');
                                });
                            }
                            $('#error-details-modal').css('display', 'flex');
                        }

                        // Error-Details-Modal schließen.
                        $('#error-details-close').click(function() {
                            $('#error-details-modal').css('display', 'none');
                        });

                        // =============================================================
                        // UX: ESC-Taste und Backdrop-Klick zum Schließen von Modals
                        // =============================================================

                        // ESC-Taste schließt offene Modals (außer laufender Batch).
                        $(document).on('keydown', function(e) {
                            if (e.keyCode === 27) { // ESC
                                // Batch-Modal NICHT schließen während Verarbeitung läuft.
                                if ($('#batch-modal').is(':visible') && !$('#batch-footer').hasClass('end')) {
                                    return;
                                }
                                $('.nf-ad-modal-overlay:visible').css('display', 'none');
                            }
                        });

                        // Klick auf Backdrop (außerhalb Modal) schließt Modal.
                        $('.nf-ad-modal-overlay').on('click', function(e) {
                            // Nur schließen wenn direkt auf Overlay geklickt (nicht auf Modal-Inhalt).
                            if ($(e.target).hasClass('nf-ad-modal-overlay')) {
                                // Batch-Modal NICHT schließen während Verarbeitung läuft.
                                if ($(this).attr('id') === 'batch-modal' && !$('#batch-footer').hasClass('end')) {
                                    return;
                                }
                                $(this).css('display', 'none');
                            }
                        });

                        // Zeigt den "Abgebrochen" Zustand.
                        function showBatchCancelled() {
                            $('#batch-title').text('Bereinigung abgebrochen');
                            $('#batch-log').append('<li style="color:#996800; font-weight:bold;">Abgebrochen durch Benutzer.</li>');
                            $('#batch-footer').addClass('end').html(
                                '<button type="button" class="button button-primary" onclick="location.reload()">Seite neu laden</button>'
                            );
                        }

                        // Batch-Verarbeitung.
                        function runBatch() {
                            if (batchCancelled) {
                                showBatchCancelled();
                                return;
                            }

                            var li = $('<li class="loading"><span class="dashicons dashicons-update"></span> Batch ' + batchCounter + ' läuft...</li>');
                            $('#batch-log').append(li);
                            var list = document.getElementById('batch-log'); list.scrollTop = list.scrollHeight;

                            currentXhr = $.post(ajaxurl, {action: 'nf_ad_force_cleanup', security: NF_AD_Config.nonce}, function(res) {
                                currentXhr = null;
                                if (batchCancelled) {
                                    li.removeClass('loading');
                                    li.html('<span class="dashicons dashicons-marker"></span> Batch ' + batchCounter + ' abgebrochen.');
                                    showBatchCancelled();
                                    return;
                                }

                                li.removeClass('loading').addClass('done');
                                li.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-yes');
                                if(res.success) {
                                    // Fehler aus diesem Batch sammeln
                                    if (res.data.errors && res.data.errors > 0) {
                                        totalErrorCount += res.data.errors;
                                    }
                                    if (res.data.error_details && res.data.error_details.length > 0) {
                                        collectedErrors = collectedErrors.concat(res.data.error_details);
                                    }

                                    li.html('<span class="dashicons dashicons-yes"></span> Batch ' + batchCounter + ': ' + res.data.deleted + ' Löschungen.');
                                    if(res.data.has_more) {
                                        batchCounter++;
                                        runBatch();
                                    } else {
                                        showBatchFinished('Fertig!', false);
                                    }
                                } else {
                                    li.html('<span class="dashicons dashicons-warning"></span> ' + res.data);
                                    showBatchFinished(res.data, true);
                                }
                            }).fail(function() {
                                currentXhr = null;
                                if (!batchCancelled) {
                                    li.html('Fehler (500).');
                                    showBatchFinished('Server-Fehler aufgetreten.', true);
                                }
                            });
                        }

                        // Preview-Modal schließen.
                        $('#preview-cancel').click(function() {
                            $('#preview-modal').css('display', 'none');
                        });

                        // Von Preview zu Batch wechseln.
                        $('#preview-start').click(function() {
                            $('#preview-modal').css('display', 'none');
                            // Batch-Modal initialisieren und starten.
                            $('#batch-modal').css('display', 'flex');
                            $('#batch-log').html('');
                            $('#batch-title').text('Bereinigung läuft...');
                            $('#batch-footer').removeClass('end').html(
                                '<button type="button" id="batch-cancel" class="button">Abbrechen</button><span></span>'
                            );
                            // Event-Handler für neuen Cancel-Button.
                            $('#batch-cancel').click(function() {
                                batchCancelled = true;
                                $(this).prop('disabled', true).text('Wird abgebrochen...');
                                if (currentXhr) {
                                    currentXhr.abort();
                                }
                            });
                            batchCounter = 1;
                            batchCancelled = false;
                            collectedErrors = [];
                            totalErrorCount = 0;
                            runBatch();
                        });

                        // Bereinigung starten Button.
                        $('#force-run').click(function(e) {
                            e.preventDefault();
                            var btn = $(this);
                            btn.prop('disabled', true).text('Berechne...');

                            // Beide Dry-Runs parallel ausführen.
                            var subsDone = $.Deferred();
                            var filesDone = $.Deferred();

                            $.post(ajaxurl, {action: 'nf_ad_calculate', security: NF_AD_Config.nonce, type: 'subs'})
                                .done(function(res) { subsDone.resolve(res.success ? res.data : null); })
                                .fail(function() { subsDone.resolve(null); });

                            $.post(ajaxurl, {action: 'nf_ad_calculate', security: NF_AD_Config.nonce, type: 'files'})
                                .done(function(res) { filesDone.resolve(res.success ? res.data : null); })
                                .fail(function() { filesDone.resolve(null); });

                            // Warten auf beide Ergebnisse.
                            $.when(subsDone, filesDone).done(function(subs, files) {
                                btn.prop('disabled', false).text('Jetzt ausführen');

                                // Preview-Content aufbauen.
                                var html = '';
                                if (subs && subs.count > 0) {
                                    html += '<div class="nf-ad-preview-item">';
                                    html += '<span class="nf-ad-preview-icon dashicons dashicons-list-view"></span>';
                                    html += '<div class="nf-ad-preview-text">';
                                    html += '<strong>' + subs.count + ' Einträge</strong>';
                                    if (subs.active > 0 && subs.trashed > 0) {
                                        html += '<span>' + subs.active + ' aktiv, ' + subs.trashed + ' im Papierkorb</span>';
                                    } else if (subs.active > 0) {
                                        html += '<span>' + subs.active + ' aktive Einträge</span>';
                                    } else if (subs.trashed > 0) {
                                        html += '<span>' + subs.trashed + ' im Papierkorb</span>';
                                    }
                                    html += '</div></div>';
                                } else {
                                    html += '<div class="nf-ad-preview-item">';
                                    html += '<span class="nf-ad-preview-icon dashicons dashicons-yes" style="color:#00a32a;"></span>';
                                    html += '<div class="nf-ad-preview-text">';
                                    html += '<strong>Keine Einträge</strong>';
                                    html += '<span>Keine Einträge zum Verarbeiten</span>';
                                    html += '</div></div>';
                                }

                                if (files && files.count > 0) {
                                    html += '<div class="nf-ad-preview-item">';
                                    html += '<span class="nf-ad-preview-icon dashicons dashicons-media-default"></span>';
                                    html += '<div class="nf-ad-preview-text">';
                                    html += '<strong>' + files.count + ' Dateien</strong>';
                                    if (files.limit_reached) {
                                        html += '<span>Limit erreicht, evtl. mehr vorhanden</span>';
                                    } else {
                                        html += '<span>werden gelöscht</span>';
                                    }
                                    html += '</div></div>';
                                } else {
                                    html += '<div class="nf-ad-preview-item">';
                                    html += '<span class="nf-ad-preview-icon dashicons dashicons-yes" style="color:#00a32a;"></span>';
                                    html += '<div class="nf-ad-preview-text">';
                                    html += '<strong>Keine Dateien</strong>';
                                    html += '<span>Keine Dateien zum Löschen</span>';
                                    html += '</div></div>';
                                }

                                $('#preview-content').html(html);
                                $('#preview-modal').css('display', 'flex');
                            });
                        });
                    });
                    </script>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    // =============================================================================
    // TAB: LOG
    // =============================================================================

    /* --- Renderer: Log-Tab --- */
    /**
     * Rendert den Log-Tab (Cron-Monitor und Löschprotokoll).
     *
     * @return void
     */
    private static function render_logs_tab() {
        $paged_logs = isset( $_GET['paged_logs'] ) ? max( 1, intval( $_GET['paged_logs'] ) ) : 1;
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'time';
        $order = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'DESC';
        $logs = NF_AD_Logger::get_logs( 20, $paged_logs, $orderby, $order );
        $total_logs = NF_AD_Logger::count_logs();
        $total_pages = ceil( $total_logs / 20 );

        $paged_cron = isset( $_GET['paged_cron'] ) ? max( 1, intval( $_GET['paged_cron'] ) ) : 1;
        $cron_runs = NF_AD_Logger::get_cron_logs( 10, $paged_cron, $orderby, $order );
        $total_runs = NF_AD_Logger::count_cron_logs();
        $total_pages_cron = ceil( $total_runs / 10 );

        $base_logs_url = add_query_arg( [ 'page' => 'nf-auto-delete', 'tab' => 'logs' ], admin_url( 'admin.php' ) );
        ?>
        <div class="nf-ad-headline-row"><h2>Ausführungen (Cron Monitor)</h2></div>
        <div class="tablenav top">
            <div class="alignleft actions"><button type="button" class="button nf-ad-clear-runs">Log leeren</button></div>
            <div class="tablenav-pages"><span class="displaying-num"><?php echo esc_html( $total_runs ); ?> Einträge</span>
                <?php if($total_pages_cron > 1): ?>
                    <span class="pagination-links">
                        <?php
                        // Prev and next links for cron runs
                        $prev_cron_url = add_query_arg( [
                            'paged_cron' => max( 1, $paged_cron - 1 ),
                            'paged_logs' => $paged_logs,
                            'orderby'    => $orderby,
                            'order'      => $order,
                        ], $base_logs_url );
                        $next_cron_url = add_query_arg( [
                            'paged_cron' => min( $total_pages_cron, $paged_cron + 1 ),
                            'paged_logs' => $paged_logs,
                            'orderby'    => $orderby,
                            'order'      => $order,
                        ], $base_logs_url );
                        ?>
                        <a class="button" href="<?php echo esc_url( $prev_cron_url ); ?>">&lsaquo;</a>
                        <a class="button" href="<?php echo esc_url( $next_cron_url ); ?>">&rsaquo;</a>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <table class="wp-list-table widefat fixed striped nf-ad-table-logs" style="margin-bottom: 40px;">
            <thead>
                <tr>
                    <?php 
                    $cron_headers = [ 'status' => 'Status', 'message' => 'Nachricht', 'time' => 'Zeitpunkt' ];
                    foreach($cron_headers as $key => $label): 
                        $class = ($orderby === $key) ? 'sorted ' . strtolower($order) : 'sortable';
                        // Build header sort URL
                        $header_url = add_query_arg( [
                            'orderby'    => $key,
                            'order'      => ( $order === 'ASC' ? 'DESC' : 'ASC' ),
                            'paged_cron' => $paged_cron,
                            'paged_logs' => $paged_logs,
                        ], $base_logs_url );
                    ?>
                        <th class="<?php echo esc_attr( $class ); ?>">
                            <a href="<?php echo esc_url( $header_url ); ?>"><span><?php echo esc_html( $label ); ?></span></a>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $cron_runs ) ): ?><tr><td colspan="3">Leer.</td></tr><?php else: foreach ( $cron_runs as $run ): ?>
                    <tr>
                        <td><span class="nf-ad-badge <?php echo esc_attr( $run['status'] ); ?>"><?php echo esc_html( ucfirst( $run['status'] ) ); ?></span></td>
                        <td>
                            <?php
                            $msg = esc_html( $run['message'] );
                            $replacements = [
                                '[CRON]'   => '<strong style="color:#2563eb">[CRON]</strong>',
                                '[MANUAL]' => '<strong style="color:#86198f">[MANUAL]</strong>',
                                '[TRASH]'   => '<strong style="color:#d97706">[TRASH]</strong>',
                                '[DELETE]'  => '<strong style="color:#dc2626">[DELETE]</strong>',
                                '[FILES]'   => '<strong style="color:#2563eb">[FILES]</strong>',
                                '[WARNING]' => '<strong style="color:#b45309">[WARNING]</strong>',
                                '[SKIP]'    => '<strong style="color:#6b7280">[SKIP]</strong>',
                            ];
                            // Fehlerdetails inline verlinken (WordPress-native Blau).
                            // Ersetzt "(X Fehler, Y Warnungen)" durch "(X Fehler [Link], Y Warnungen)".
                            if ( ! empty( $run['error_details'] ) ) {
                                $error_arr = json_decode( $run['error_details'], true );
                                if ( is_array( $error_arr ) && count( $error_arr ) > 0 ) {
                                    $run_id = (int) $run['id'];
                                    // Pattern: "(N Fehler" → "(N Fehler [als Link]"
                                    $msg = preg_replace_callback(
                                        '/\((\d+) Fehler/',
                                        function( $matches ) use ( $run_id ) {
                                            $link = '<a href="#" class="nf-ad-show-run-errors" data-run-id="' . esc_attr( $run_id ) . '">' . $matches[1] . ' Fehler</a>';
                                            return '(' . $link;
                                        },
                                        $msg
                                    );
                                }
                            }

                            echo wp_kses_post( strtr( $msg, $replacements ) );
                            ?>
                        </td>
                        <td style="text-align:right;"><?php echo esc_html( $run['time'] ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        
        <hr>
        <div class="nf-ad-headline-row" style="margin-bottom: 5px;"><h2>Löschungen</h2></div>
        <div class="tablenav top">
            <div class="alignleft actions"><button type="button" class="button action nf-ad-clear-logs">Log leeren</button></div>
            <div class="tablenav-pages"><span class="displaying-num"><?php echo esc_html( $total_logs ); ?> Einträge</span>
                <?php if($total_pages > 1): ?>
                    <span class="pagination-links">
                        <?php
                        // Prev and next links for logs
                        $prev_logs_url = add_query_arg( [
                            'paged_logs' => max( 1, $paged_logs - 1 ),
                            'paged_cron' => $paged_cron,
                            'orderby'    => $orderby,
                            'order'      => $order,
                        ], $base_logs_url );
                        $next_logs_url = add_query_arg( [
                            'paged_logs' => min( $total_pages, $paged_logs + 1 ),
                            'paged_cron' => $paged_cron,
                            'orderby'    => $orderby,
                            'order'      => $order,
                        ], $base_logs_url );
                        ?>
                        <a class="button" href="<?php echo esc_url( $prev_logs_url ); ?>">&lsaquo;</a>
                        <a class="button" href="<?php echo esc_url( $next_logs_url ); ?>">&rsaquo;</a>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <table class="wp-list-table widefat fixed striped nf-ad-table-logs">
            <thead>
                <tr>
                    <?php 
                    $log_headers = [
                        'status' => 'Status',
                        'form_title' => 'Formular',
                        'submission_date' => 'Eintrag vom',
                        'message' => 'Details',
                        'time' => 'Zeitpunkt',
                    ];
                    foreach($log_headers as $key => $label): 
                        $class = ($orderby === $key) ? 'sorted ' . strtolower($order) : 'sortable';
                        $header_url = add_query_arg( [
                            'orderby'    => $key,
                            'order'      => ( $order === 'ASC' ? 'DESC' : 'ASC' ),
                            'paged_logs' => $paged_logs,
                            'paged_cron' => $paged_cron,
                        ], $base_logs_url );
                    ?>
                        <th class="<?php echo esc_attr( $class ); ?>">
                            <a href="<?php echo esc_url( $header_url ); ?>"><span><?php echo esc_html( $label ); ?></span></a>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $logs ) ): ?><tr><td colspan="5">Keine Logs.</td></tr><?php else: foreach ( $logs as $log ): ?>
                    <tr>
                        <td><span class="nf-ad-badge <?php echo esc_attr( $log['status'] ); ?>"><?php echo esc_html( ucfirst( $log['status'] ) ); ?></span></td>
                        <td><?php echo esc_html( $log['form_title'] ); ?></td>
                        <td><?php echo esc_html( $log['submission_date'] ); ?></td>
                        <td>
                            <?php
                            $msg = esc_html( $log['message'] );
                            // Hervorhebung bekannter Status-Tags (sicher, da zuvor escaped wird).
                            $replacements = [
                                '[CRON]'    => '<strong style="color:#2563eb">[CRON]</strong>',
                                '[MANUAL]'  => '<strong style="color:#86198f">[MANUAL]</strong>',
                                '[TRASH]'   => '<strong style="color:#d97706">[TRASH]</strong>',
                                '[DELETE]'  => '<strong style="color:#dc2626">[DELETE]</strong>',
                                '[FILES]'   => '<strong style="color:#2563eb">[FILES]</strong>',
                                '[FILE]'    => '<strong style="color:#dc2626">[DELETE]</strong>',
                                '[WARNING]' => '<strong style="color:#b45309">[WARNING]</strong>',
                                '[SKIP]'    => '<strong style="color:#6b7280">[SKIP]</strong>',
                            ];
                            echo wp_kses_post( strtr( $msg, $replacements ) );
                            ?>
                        </td>
                        <td style="text-align:right;"><?php echo esc_html( $log['time'] ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <script>jQuery(document).ready(function($){
            // Löschungen leeren (nur nf_ad_logs Tabelle)
            $('.nf-ad-clear-logs').click(function(){
                if(confirm('Alle Löschungs-Einträge löschen?')){
                    $.post(ajaxurl,{action:'nf_ad_clear_logs', security: NF_AD_Config.nonce},function(){ location.reload(); });
                }
            });
            // Ausführungen leeren (nur nf_ad_cron_runs Tabelle)
            $('.nf-ad-clear-runs').click(function(){
                if(confirm('Alle Ausführungs-Einträge löschen?')){
                    $.post(ajaxurl,{action:'nf_ad_clear_runs', security: NF_AD_Config.nonce},function(){ location.reload(); });
                }
            });

            // Fehlerdetails für Cron-Runs laden und anzeigen.
            $('.nf-ad-show-run-errors').on('click', function(e) {
                e.preventDefault();
                var runId = $(this).data('run-id');
                var list = $('#error-details-list');

                list.html('<li>Lade Fehlerdetails...</li>');
                $('#error-details-modal').css('display', 'flex');

                $.post(ajaxurl, {
                    action: 'nf_ad_get_run_errors',
                    security: window.NF_AD_Config.nonce,
                    run_id: runId
                }).done(function(response) {
                    list.empty();
                    if (response.success && response.data.errors.length > 0) {
                        response.data.errors.forEach(function(err) {
                            list.append('<li>' + $('<div>').text(err).html() + '</li>');
                        });
                    } else {
                        list.append('<li>Keine Fehlerdetails verfügbar.</li>');
                    }
                }).fail(function() {
                    list.html('<li style="color:#d63638;">Fehler beim Laden der Details.</li>');
                });
            });

            // Error-Details-Modal schließen (für Logs-Tab).
            $('#error-details-close').on('click', function() {
                $('#error-details-modal').css('display', 'none');
            });

            // ESC-Taste schließt Modal (für Logs-Tab).
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27 && $('#error-details-modal').is(':visible')) {
                    $('#error-details-modal').css('display', 'none');
                }
            });

            // Backdrop-Klick schließt Modal (für Logs-Tab).
            $('#error-details-modal').on('click', function(e) {
                if ($(e.target).hasClass('nf-ad-modal-overlay')) {
                    $(this).css('display', 'none');
                }
            });
        });</script>
        <?php
    }

    // =============================================================================
    // TAB: PERSON SEARCH (DSGVO)
    // =============================================================================

    /* --- Renderer: Personen-Such-Tab --- */
    /**
     * Rendert den Personen-Such-Tab (DSGVO-Lookup).
     *
     * @since 2.4.0
     * @return void
     */
    private static function render_person_tab() {
        ?>
        <div class="nf-ad-headline-row"><h2>Suche in allen Formularen</h2></div>

        <p class="description" style="margin-bottom: 15px;">
            Durchsucht alle Ninja Forms Einträge nach dem eingegebenen Begriff (z.B. E-Mail-Adresse).
            Gefundene Einträge können ausgewählt und endgültig gelöscht werden.
        </p>

        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px;">
            <input type="text"
                   id="nf-ad-person-search-input"
                   class="regular-text"
                   placeholder="E-Mail, Name oder Suchbegriff..."
                   style="max-width: 400px;">
            <button type="button" id="nf-ad-person-search-btn" class="button button-primary">Suchen</button>
        </div>

        <div id="nf-ad-person-results">
            <p class="description">Gib einen Suchbegriff ein (mindestens 3 Zeichen, nur ein Wort).</p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var currentPage = 1;
            var currentTerm = '';

            // Suche ausführen
            $('#nf-ad-person-search-btn').on('click', function() {
                currentTerm = $('#nf-ad-person-search-input').val().trim();
                currentPage = 1;
                doSearch();
            });

            // Enter-Taste für Suche
            $('#nf-ad-person-search-input').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#nf-ad-person-search-btn').click();
                }
            });

            function doSearch() {
                var btn = $('#nf-ad-person-search-btn');
                var resultsDiv = $('#nf-ad-person-results');

                if (currentTerm.length < 3) {
                    resultsDiv.html('<p class="nf-ad-error">Bitte mindestens 3 Zeichen eingeben.</p>');
                    return;
                }

                btn.prop('disabled', true).text('Suche läuft...');
                resultsDiv.html('<p>Durchsuche alle Formulare...</p>');

                $.post(ajaxurl, {
                    action: 'nf_ad_person_search',
                    security: NF_AD_Config.nonce,
                    term: currentTerm,
                    page: currentPage
                })
                .done(function(res) {
                    if (res.success) {
                        renderResults(res.data);
                    } else {
                        resultsDiv.html('<p class="nf-ad-error">' + escapeHtml(res.data) + '</p>');
                    }
                })
                .fail(function() {
                    resultsDiv.html('<p class="nf-ad-error">Fehler bei der Suche.</p>');
                })
                .always(function() {
                    btn.prop('disabled', false).text('Suchen');
                });
            }

            // Ergebnisse rendern (mit Sub-Tables für Feld-Details)
            function renderResults(data) {
                var resultsDiv = $('#nf-ad-person-results');
                var results = data.results;

                if (!results || results.length === 0) {
                    resultsDiv.html('<p>Keine Einträge gefunden für "<strong>' + escapeHtml(currentTerm) + '</strong>".</p>');
                    return;
                }

                var html = '';

                // Tablenav oben (wie Log-Tab)
                html += '<div class="tablenav top">';
                html += '<div class="alignleft actions" style="display: flex; align-items: center; gap: 10px;">';
                html += '<label style="display: flex; align-items: center; gap: 5px;"><input type="checkbox" id="nf-ad-select-all"> Alle markieren</label>';
                html += '<button type="button" id="nf-ad-delete-selected" class="button action">Ausgewählte löschen</button>';
                html += '</div>';
                html += '<div class="tablenav-pages">';
                html += '<span class="displaying-num">' + data.total + ' Einträge</span>';
                if (data.pages > 1) {
                    html += '<span class="pagination-links">';
                    html += '<a class="button nf-ad-page-btn" data-page="' + Math.max(1, data.page - 1) + '"' + (data.page <= 1 ? ' disabled="disabled"' : '') + '>&lsaquo;</a>';
                    html += ' Seite ' + data.page + ' von ' + data.pages + ' ';
                    html += '<a class="button nf-ad-page-btn" data-page="' + Math.min(data.pages, data.page + 1) + '"' + (data.page >= data.pages ? ' disabled="disabled"' : '') + '>&rsaquo;</a>';
                    html += '</span>';
                }
                html += '</div></div>';

                // Tabelle mit Sub-Tables
                html += '<table class="wp-list-table widefat fixed nf-ad-table-person">';
                html += '<thead><tr><th style="width:40px;"></th><th style="width:60px;">ID</th><th>Formular</th><th style="width:120px;">Datum</th></tr></thead>';
                html += '<tbody>';

                results.forEach(function(item) {
                    var dateFormatted = new Date(item.date).toLocaleDateString('de-DE');

                    // Haupt-Zeile (Submission)
                    html += '<tr class="nf-ad-submission-row">';
                    html += '<td><input type="checkbox" class="nf-ad-sub-checkbox" value="' + item.id + '"></td>';
                    html += '<td><code>#' + item.id + '</code></td>';
                    html += '<td><strong>' + escapeHtml(item.form_title) + '</strong></td>';
                    html += '<td>' + dateFormatted + '</td>';
                    html += '</tr>';

                    // Sub-Table mit Feld-Details (immer sichtbar)
                    if (item.matches && item.matches.length > 0) {
                        html += '<tr class="nf-ad-fields-row">';
                        html += '<td></td>';
                        html += '<td colspan="3">';
                        html += '<table class="nf-ad-fields-table">';
                        item.matches.forEach(function(match) {
                            html += '<tr>';
                            html += '<td class="nf-ad-field-label">' + escapeHtml(match.label) + '</td>';
                            html += '<td class="nf-ad-field-value">' + escapeHtml(match.value) + '</td>';
                            html += '</tr>';
                        });
                        html += '</table>';
                        html += '</td>';
                        html += '</tr>';
                    }
                });

                html += '</tbody></table>';

                // Warnung
                html += '<p class="description nf-ad-danger" style="margin-top:15px;"><strong>Achtung:</strong> Löschen ist endgültig und kann nicht rückgängig gemacht werden. Zugehörige Dateien werden ebenfalls gelöscht.</p>';

                resultsDiv.html(html);
                bindResultEvents();
            }

            // Event-Handler für Ergebnis-Tabelle
            function bindResultEvents() {
                // Pagination
                $('.nf-ad-page-btn').on('click', function() {
                    if ($(this).attr('disabled')) return;
                    currentPage = parseInt($(this).data('page'), 10);
                    doSearch();
                });

                // Alle markieren
                $('#nf-ad-select-all').on('change', function() {
                    $('.nf-ad-sub-checkbox').prop('checked', $(this).is(':checked'));
                });

                // Löschen-Button
                $('#nf-ad-delete-selected').on('click', function() {
                    var selectedIds = [];
                    $('.nf-ad-sub-checkbox:checked').each(function() {
                        selectedIds.push($(this).val());
                    });

                    if (selectedIds.length === 0) {
                        alert('Bitte mindestens einen Eintrag auswählen.');
                        return;
                    }

                    if (!confirm('Wirklich ' + selectedIds.length + ' Einträge ENDGÜLTIG löschen?\n\nDies kann nicht rückgängig gemacht werden!')) {
                        return;
                    }

                    deleteSubmissions(selectedIds);
                });
            }

            // Löschung durchführen
            function deleteSubmissions(ids) {
                var btn = $('#nf-ad-delete-selected');
                btn.prop('disabled', true).text('Lösche...');

                $.post(ajaxurl, {
                    action: 'nf_ad_person_delete',
                    security: NF_AD_Config.nonce,
                    ids: ids
                })
                .done(function(res) {
                    if (res.success) {
                        var msg = res.data.deleted + ' Einträge gelöscht';
                        if (res.data.files > 0) {
                            msg += ', ' + res.data.files + ' Dateien entfernt';
                        }
                        if (res.data.failed > 0) {
                            msg += ' (' + res.data.failed + ' fehlgeschlagen)';
                        }
                        alert(msg);

                        // Suche neu ausführen um Tabelle zu aktualisieren
                        doSearch();
                    } else {
                        alert('Fehler: ' + res.data);
                    }
                })
                .fail(function() {
                    alert('Server-Fehler beim Löschen.');
                })
                .always(function() {
                    btn.prop('disabled', false).text('Ausgewählte löschen');
                });
            }

            // HTML escapen (XSS-Schutz)
            function escapeHtml(text) {
                if (!text) return '';
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        });
        </script>
        <?php
    }

    // =============================================================================
    // SETTINGS PERSISTENZ (POST HANDLER)
    // =============================================================================

    /* --- Persistenz: Einstellungen speichern --- */
    /**
     * Persistiert Einstellungen und Regeln aus POST-Requests.
     *
     * @return void
     */
    private static function handle_save() {
        if ( ! isset( $_POST['nf_ad_nonce'] ) || ! wp_verify_nonce( $_POST['nf_ad_nonce'], 'nf_ad_save_settings' ) ) {
            return;
        }
        $settings = get_option( self::OPTION_KEY, [] );
        $context = isset( $_POST['nf_ad_context'] ) ? sanitize_key( $_POST['nf_ad_context'] ) : '';

        if ( $context === 'settings' ) {
            $settings['sub_handling']   = sanitize_key( $_POST['sub_handling'] ?? 'keep' );
            $settings['file_handling']  = sanitize_key( $_POST['file_handling'] ?? 'keep' );
            $settings['log_limit']      = max( 10, absint( $_POST['log_limit'] ) );

            $cron_active = isset( $_POST['cron_active'] ) ? 1 : 0;
            $cron_hour   = absint( $_POST['cron_hour'] );
            $settings['cron_active'] = $cron_active;
            $settings['cron_hour']   = $cron_hour;

            wp_clear_scheduled_hook( 'nf_ad_daily_event' );
            if ( $cron_active ) {
                $tz  = wp_timezone();
                $now = new DateTimeImmutable( 'now', $tz );

                $target = $now->setTime( (int) $cron_hour, 0, 0 );

                // Wenn Zielzeit schon vorbei ist (oder exakt jetzt), dann morgen.
                if ( $target->getTimestamp() <= $now->getTimestamp() ) {
                    $target = $target->modify( '+1 day' );
                }

                $timestamp = $target->getTimestamp();
                wp_schedule_event( $timestamp, 'daily', 'nf_ad_daily_event' );
            }
        }

        if ( $context === 'rules' ) {
            if ( isset( $_POST['global_days'] ) ) {
                $settings['global'] = absint( $_POST['global_days'] );
                $settings['global'] = max( 1, (int) $settings['global'] );
                if ( isset( $_POST['forms'] ) && is_array( $_POST['forms'] ) ) {
                    foreach ( $_POST['forms'] as $id => $d ) {
                        $form_id = absint( $id );
                        if ( ! $form_id || ! is_array( $d ) ) {
                            continue;
                        }
                        $settings['forms'][ $form_id ] = [
                            'mode' => sanitize_key( $d['mode'] ?? 'global' ),
                            'days' => absint( $d['days'] ?? 0 ),
                        ];
                    }
                }
            }
        }

        update_option( self::OPTION_KEY, $settings );
        add_settings_error( 'nf_ad', 'saved', 'Einstellungen gespeichert.', 'updated' );
    }

    // =============================================================================
    // SETTINGS PERSISTENZ (POST HANDLER)
    // =============================================================================

    /* --- Getter --- */
    /**
     * Liefert die Plugin-Einstellungen.
     *
     * @return array
     */
    public static function get_settings() {
        return get_option( self::OPTION_KEY, [] );
    }

    // =============================================================================
    // AJAX HANDLER
    // =============================================================================

    /* --- AJAX Endpunkte --- */
    /**
     * AJAX: Erneuter Löschversuch für einen Eintrag inkl. optionaler Datei-Bereinigung.
     *
     * @return void
     */
    public static function ajax_retry_delete() {
        check_ajax_referer( 'nf_ad_security', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        $id = absint( $_POST['id'] );
        if ( ! $id ) {
            wp_send_json_error( 'No ID' );
        }
        if ( get_post_type( $id ) !== 'nf_sub' ) {
            wp_send_json_error( 'Invalid Post Type' );
        }

        // Datei-Bereinigung über den Upload-Deleter ausführen.
        $files_deleted = NF_AD_Uploads_Deleter::cleanup_files( $id );
        $deleted_files = (int) ( $files_deleted['deleted'] ?? 0 );
        $msg = $deleted_files > 0 ? "Files: {$deleted_files} " : '';

        $deleted = (bool) wp_delete_post( $id, true );
        if ( $deleted ) {
            $msg .= '(WP)';
        }
        $deleted ? wp_send_json_success( 'Gelöscht ' . $msg ) : wp_send_json_error( 'Fehler' );
    }

    /**
     * AJAX: Startet die manuelle Bereinigung in Batches.
     *
     * @return void
     */
    public static function ajax_force_cleanup() {
        check_ajax_referer( 'nf_ad_security', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        $result = NF_AD_Submissions_Eraser::run_cleanup_manual();
        wp_send_json_success( $result );
    }

    /**
     * AJAX: Leert nur die Löschungs-Logs (nf_ad_logs Tabelle).
     *
     * @return void
     */
    public static function ajax_clear_logs() {
        check_ajax_referer( 'nf_ad_security', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        NF_AD_Logger::truncate_logs();
        wp_send_json_success();
    }

    /**
     * AJAX: Leert nur die Ausführungs-Logs (nf_ad_cron_runs Tabelle).
     *
     * @return void
     */
    public static function ajax_clear_runs() {
        check_ajax_referer( 'nf_ad_security', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        NF_AD_Logger::truncate_runs();
        wp_send_json_success();
    }

    /**
     * AJAX: Lädt die Fehlerdetails für einen bestimmten Run.
     *
     * @since 3.0.0
     * @return void
     */
    public static function ajax_get_run_errors() {
        check_ajax_referer( 'nf_ad_security', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        $run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
        if ( ! $run_id ) {
            wp_send_json_error( 'Ungültige Run-ID.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'nf_ad_cron_runs';
        $error_details = $wpdb->get_var( $wpdb->prepare(
            "SELECT error_details FROM $table WHERE id = %d",
            $run_id
        ) );

        if ( empty( $error_details ) ) {
            wp_send_json_success( [ 'errors' => [] ] );
        }

        $errors = json_decode( $error_details, true );
        if ( ! is_array( $errors ) ) {
            wp_send_json_success( [ 'errors' => [] ] );
        }

        wp_send_json_success( [ 'errors' => $errors ] );
    }

    /**
     * AJAX: Berechnet eine Simulation (Dry Run) für Einträge oder Uploads.
     *
     * Ruft je nach Typ die zuständige Klasse auf:
     * - "subs": NF_AD_Submissions_Eraser::calculate_dry_run()
     * - "files": NF_AD_Uploads_Deleter::calculate_dry_run()
     *
     * Return-Format (seit v2.2.1):
     * Für Submissions:
     * - count: Gesamtzahl (active + trashed)
     * - active: Aktive Submissions (nicht im Papierkorb)
     * - trashed: Submissions im Papierkorb
     * - limit_reached: true, wenn Performance-Limit erreicht wurde
     *
     * Für Files:
     * - count: Anzahl der gefundenen Uploads
     * - limit_reached: true, wenn Performance-Limit (5.000) erreicht wurde
     *
     * @return void
     */
    public static function ajax_calculate() {
        check_ajax_referer( 'nf_ad_security', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        // Berechnungstyp: "subs" (Einträge) oder "files" (Uploads).
        $type = sanitize_key( $_POST['type'] ?? 'subs' );

        try {
            // Delegation an die zuständige Klasse (saubere Architektur).
            if ( 'files' === $type ) {
                $result = class_exists( 'NF_AD_Uploads_Deleter' ) && method_exists( 'NF_AD_Uploads_Deleter', 'calculate_dry_run' )
                    ? NF_AD_Uploads_Deleter::calculate_dry_run()
                    : [ 'count' => 0, 'limit_reached' => false ];

                // DEFENSIVE: Fallback für Legacy-Return (falls Methode noch int zurückgibt).
                if ( is_int( $result ) ) {
                    $result = [ 'count' => $result, 'limit_reached' => false ];
                }

                wp_send_json_success( [
                    'count'         => (int) ( $result['count'] ?? 0 ),
                    'limit_reached' => (bool) ( $result['limit_reached'] ?? false ),
                    'type'          => $type,
                ] );
            } else {
                // Submissions: Neues Format mit active/trashed Unterscheidung.
                $result = NF_AD_Submissions_Eraser::calculate_dry_run();

                // DEFENSIVE: Fallback für Legacy-Return (falls Methode noch int zurückgibt).
                if ( is_int( $result ) ) {
                    $result = [ 'total' => $result, 'active' => $result, 'trashed' => 0 ];
                }

                wp_send_json_success( [
                    'count'         => (int) ( $result['total'] ?? 0 ),
                    'active'        => (int) ( $result['active'] ?? 0 ),
                    'trashed'       => (int) ( $result['trashed'] ?? 0 ),
                    'limit_reached' => false,
                    'type'          => $type,
                ] );
            }
        } catch ( Throwable $e ) {
            // Log error for debugging (WordPress error_log).
            error_log( 'NF Auto Delete - Dry run failed: ' . $e->getMessage() );
            wp_send_json_error( 'Calculation failed.' );
        }
    }

    // =============================================================================
    // AJAX: PERSON SEARCH (DSGVO)
    // =============================================================================

    /* --- AJAX: Suche und Löschung für DSGVO-Anfragen --- */

    /**
     * AJAX: Personen-Suche durchführen.
     *
     * @since 2.4.0
     * @return void
     */
    public static function ajax_person_search() {
        check_ajax_referer( 'nf_ad_security', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        $term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';
        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;

        if ( strlen( $term ) < 3 ) {
            wp_send_json_error( 'Suchbegriff muss mindestens 3 Zeichen haben.' );
        }

        // Delegation an Person Search Klasse.
        if ( ! class_exists( 'NF_AD_Person_Search' ) ) {
            wp_send_json_error( 'Person Search Klasse nicht geladen.' );
        }

        $result = NF_AD_Person_Search::search( $term, $page );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Ausgewählte Submissions löschen (DSGVO).
     *
     * @since 2.4.0
     * @return void
     */
    public static function ajax_person_delete() {
        check_ajax_referer( 'nf_ad_security', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : [];
        $ids = array_filter( $ids ); // Entferne 0-Werte.

        if ( empty( $ids ) ) {
            wp_send_json_error( 'Keine Einträge ausgewählt.' );
        }

        // Delegation an Person Search Klasse.
        if ( ! class_exists( 'NF_AD_Person_Search' ) ) {
            wp_send_json_error( 'Person Search Klasse nicht geladen.' );
        }

        $stats = NF_AD_Person_Search::delete_submissions( $ids );

        wp_send_json_success( $stats );
    }
}