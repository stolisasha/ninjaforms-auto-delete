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
            .nf-ad-modal { background: #fff; width: 500px; max-width: 90%; padding: 30px; border-radius: 4px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
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
            $tab_settings_url = add_query_arg( [ 'tab' => 'settings' ], $base_url );
            ?>
            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( $tab_logs_url ); ?>" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">Log</a>
                <a href="<?php echo esc_url( $tab_rules_url ); ?>" class="nav-tab <?php echo $active_tab === 'rules' ? 'nav-tab-active' : ''; ?>">Fristen definieren</a>
                <a href="<?php echo esc_url( $tab_settings_url ); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Einstellungen</a>
            </nav>

            <div id="batch-modal" class="nf-ad-modal-overlay">
                <div class="nf-ad-modal">
                    <h2>Bereinigung läuft...</h2>
                    <ul id="batch-log" class="nf-ad-progress-list"></ul>
                    <button type="button" id="close-modal" class="button button-primary" style="display:none;" onclick="location.reload()">Fertig - Seite neu laden</button>
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
                            var btn = $(this); var type = btn.data('type'); var label = btn.text();
                            btn.prop('disabled', true).text('Rechne...');
                            $('#calc-output').text('Analysiere Datenbank ...');
                            $.post(ajaxurl, {action: 'nf_ad_calculate', security: NF_AD_Config.nonce, type: type}, function(res) {
                                btn.prop('disabled', false).text(label);
                                if(res.success) { 
                                    var txt = (res.data.type === 'subs') ? 'Einträge' : 'Uploads';
                                    $('#calc-output').text('Simulation Ergebnis: ' + res.data.count + ' ' + txt + ' sind älter als die Frist.'); 
                                } else { $('#calc-output').text('Fehler bei der Berechnung.'); }
                            });
                        });
                    });
                    </script>

                <?php elseif ( $active_tab === 'settings' ) : ?>
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
                        function runBatch() {
                            var li = $('<li class="loading"><span class="dashicons dashicons-update"></span> Batch ' + batchCounter + ' läuft...</li>');
                            $('#batch-log').append(li);
                            var list = document.getElementById('batch-log'); list.scrollTop = list.scrollHeight;

                            $.post(ajaxurl, {action: 'nf_ad_force_cleanup', security: NF_AD_Config.nonce}, function(res) {
                                li.removeClass('loading').addClass('done');
                                li.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-yes');
                                if(res.success) {
                                    li.html('<span class="dashicons dashicons-yes"></span> Batch ' + batchCounter + ': ' + res.data.deleted + ' verarbeitet.');
                                    if(res.data.has_more) { batchCounter++; runBatch(); } 
                                    else { $('#batch-log').append('<li style="color:green; font-weight:bold;">Fertig!</li>'); $('#close-modal').show(); }
                                } else { li.html('<span class="dashicons dashicons-warning"></span> ' + res.data); $('#close-modal').show(); }
                            }).fail(function() { li.html('Fehler (500).'); $('#close-modal').show(); });
                        }
                        $('#force-run').click(function(e) {
                            e.preventDefault();
                            if(!confirm('Soll die Bereinigung jetzt starten?')) return;
                            $('#batch-modal').css('display', 'flex'); $('#batch-log').html(''); $('#close-modal').hide();
                            batchCounter = 1; runBatch(); 
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
            <div class="alignleft actions"><button type="button" class="button nf-ad-clear-all-logs">Log leeren</button></div>
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
            <div class="alignleft actions"><button type="button" class="button action nf-ad-clear-all-logs">Log leeren</button></div>
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
                                '[CRON]'   => '<strong style="color:#2563eb">[CRON]</strong>',
                                '[MANUAL]' => '<strong style="color:#86198f">[MANUAL]</strong>',
                                '[TRASH]'   => '<strong style="color:#d97706">[TRASH]</strong>',
                                '[DELETE]'  => '<strong style="color:#dc2626">[DELETE]</strong>',
                                '[FILES]'   => '<strong style="color:#2563eb">[FILES]</strong>',
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
            $('.nf-ad-clear-all-logs').click(function(){ 
                if(confirm('Sicher?')){ $.post(ajaxurl,{action:'nf_ad_clear_logs', security: NF_AD_Config.nonce},function(){ location.reload(); }); } 
            }); 
        });</script>
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
        $context = isset( $_POST['nf_ad_context'] ) ? $_POST['nf_ad_context'] : '';

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
     * AJAX: Leert alle Logs.
     *
     * @return void
     */
    public static function ajax_clear_logs() {
        check_ajax_referer( 'nf_ad_security', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }
        NF_AD_Logger::truncate();
        wp_send_json_success();
    }

    /**
     * AJAX: Berechnet eine Simulation (Dry Run) für Einträge oder Uploads.
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
        // Simulation durchführen (ohne Löschung).
        $count = NF_AD_Submissions_Eraser::calculate_dry_run( $type );
        wp_send_json_success( [ 'count' => $count, 'type' => $type ] );
    }
}