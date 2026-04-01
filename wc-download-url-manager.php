<?php
/**
 * Plugin Name: SAWE Download URL Manager
 * Plugin URI:  https://sawe.org
 * Description: Manage and bulk-update base URLs for WooCommerce downloadable product files.
 * Version:     1.1.0
 * Author:      SAWE
 * License:     GPL-2.0+
 * Text Domain: wc-download-url-manager
 */

defined( 'ABSPATH' ) || exit;

class WC_Download_URL_Manager {

    /** Option key used to persist the last applied change for revert. */
    const LAST_CHANGE_OPTION = 'wcdlm_last_change';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_wcdlm_get_base_urls',  [ $this, 'ajax_get_base_urls' ] );
        add_action( 'wp_ajax_wcdlm_simulate',        [ $this, 'ajax_simulate' ] );
        add_action( 'wp_ajax_wcdlm_update',          [ $this, 'ajax_update' ] );
        add_action( 'wp_ajax_wcdlm_get_last_change', [ $this, 'ajax_get_last_change' ] );
        add_action( 'wp_ajax_wcdlm_revert',          [ $this, 'ajax_revert' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Admin Menu                                                          */
    /* ------------------------------------------------------------------ */

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'SAWE Download URL Manager',
            'Download URL Manager',
            'manage_woocommerce',
            'wc-download-url-manager',
            [ $this, 'render_page' ]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Assets                                                              */
    /* ------------------------------------------------------------------ */

    public function enqueue_assets( $hook ) {
        if ( 'woocommerce_page_wc-download-url-manager' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'wcdlm-style', false );
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function get_all_downloadable_product_ids(): array {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT DISTINCT post_id
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_downloadable_files'
               AND meta_value != ''
               AND meta_value != 'a:0:{}'
             ORDER BY post_id ASC"
        );
        return array_map( 'intval', $ids );
    }

    private function get_base_url( string $url ): string {
        return trailingslashit( dirname( $url ) );
    }

    private function collect_base_urls(): array {
        $ids   = $this->get_all_downloadable_product_ids();
        $bases = [];
        foreach ( $ids as $id ) {
            $files = get_post_meta( $id, '_downloadable_files', true );
            if ( ! is_array( $files ) ) continue;
            foreach ( $files as $file ) {
                $url = $file['file'] ?? '';
                if ( empty( $url ) ) continue;
                $base           = $this->get_base_url( $url );
                $bases[ $base ] = ( $bases[ $base ] ?? 0 ) + 1;
            }
        }
        arsort( $bases );
        return $bases;
    }

    private function build_change_rows( string $old_base, string $new_base ): array {
        $ids  = $this->get_all_downloadable_product_ids();
        $rows = [];
        foreach ( $ids as $id ) {
            $files = get_post_meta( $id, '_downloadable_files', true );
            if ( ! is_array( $files ) ) continue;

            $title     = get_the_title( $id ) ?: "Product #{$id}";
            $post_type = get_post_type( $id );
            if ( 'product_variation' === $post_type ) {
                $parent_id    = wp_get_post_parent_id( $id );
                $parent_title = get_the_title( $parent_id ) ?: "Product #{$parent_id}";
                $title        = $parent_title . ' — ' . $title . ' (variation)';
            }

            foreach ( $files as $file_key => $file ) {
                $old_url = $file['file'] ?? '';
                if ( empty( $old_url ) ) continue;

                $file_base = $this->get_base_url( $old_url );
                $changed   = ( $file_base === $old_base );
                $new_url   = $changed ? $new_base . basename( $old_url ) : $old_url;

                $rows[] = [
                    'id'       => $id,
                    'title'    => $title,
                    'file_key' => $file_key,
                    'old_url'  => $old_url,
                    'new_url'  => $new_url,
                    'changed'  => $changed,
                ];
            }
        }
        return $rows;
    }

    /**
     * Apply a set of rows to the DB and update customer permissions.
     * Returns [ products_updated, permissions_updated, errors ].
     */
    private function apply_rows( array $rows, string $old_base, string $new_base ): array {
        $updated = 0;
        $errors  = [];

        $changes_by_id = [];
        foreach ( $rows as $row ) {
            if ( $row['changed'] ) {
                $changes_by_id[ $row['id'] ][ $row['file_key'] ] = $row['new_url'];
            }
        }

        foreach ( $changes_by_id as $id => $file_updates ) {
            $files = get_post_meta( $id, '_downloadable_files', true );
            if ( ! is_array( $files ) ) {
                $errors[] = "ID {$id}: could not read _downloadable_files";
                continue;
            }
            foreach ( $file_updates as $file_key => $new_url ) {
                if ( isset( $files[ $file_key ] ) ) {
                    $files[ $file_key ]['file'] = $new_url;
                }
            }
            $result = update_post_meta( $id, '_downloadable_files', $files );
            if ( false === $result ) {
                $errors[] = "ID {$id}: update_post_meta failed";
            } else {
                $updated++;
            }
        }

        global $wpdb;
        $perm_updated = 0;
        $permissions  = $wpdb->get_results(
            "SELECT permission_id, file_path
             FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions"
        );
        foreach ( $permissions as $perm ) {
            $base = $this->get_base_url( $perm->file_path );
            if ( $base === $old_base ) {
                $new_path = $new_base . basename( $perm->file_path );
                $wpdb->update(
                    "{$wpdb->prefix}woocommerce_downloadable_product_permissions",
                    [ 'file_path' => $new_path ],
                    [ 'permission_id' => $perm->permission_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                $perm_updated++;
            }
        }

        return [
            'products_updated'    => $updated,
            'permissions_updated' => $perm_updated,
            'errors'              => $errors,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX Handlers                                                       */
    /* ------------------------------------------------------------------ */

    public function ajax_get_base_urls() {
        check_ajax_referer( 'wcdlm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );
        wp_send_json_success( $this->collect_base_urls() );
    }

    public function ajax_simulate() {
        check_ajax_referer( 'wcdlm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $old_base = trailingslashit( sanitize_text_field( wp_unslash( $_POST['old_base'] ?? '' ) ) );
        $new_base = trailingslashit( sanitize_text_field( wp_unslash( $_POST['new_base'] ?? '' ) ) );
        if ( empty( $old_base ) || empty( $new_base ) ) wp_send_json_error( 'Missing base URLs.' );

        wp_send_json_success( $this->build_change_rows( $old_base, $new_base ) );
    }

    public function ajax_update() {
        check_ajax_referer( 'wcdlm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $old_base = trailingslashit( sanitize_text_field( wp_unslash( $_POST['old_base'] ?? '' ) ) );
        $new_base = trailingslashit( sanitize_text_field( wp_unslash( $_POST['new_base'] ?? '' ) ) );
        if ( empty( $old_base ) || empty( $new_base ) ) wp_send_json_error( 'Missing base URLs.' );

        $rows   = $this->build_change_rows( $old_base, $new_base );
        $result = $this->apply_rows( $rows, $old_base, $new_base );

        // Store the inverse (revert) data in wp_options
        $revert_rows = array_values( array_map( function ( $row ) {
            return [
                'id'       => $row['id'],
                'title'    => $row['title'],
                'file_key' => $row['file_key'],
                'old_url'  => $row['new_url'],  // after apply, current = new_url
                'new_url'  => $row['old_url'],  // revert target        = old_url
                'changed'  => true,
            ];
        }, array_filter( $rows, fn( $r ) => $r['changed'] ) ) );

        update_option( self::LAST_CHANGE_OPTION, [
            'applied_at'  => current_time( 'mysql' ),
            'old_base'    => $old_base,
            'new_base'    => $new_base,
            'revert_rows' => $revert_rows,
            'stats'       => $result,
        ] );

        wp_send_json_success( $result );
    }

    public function ajax_get_last_change() {
        check_ajax_referer( 'wcdlm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );
        wp_send_json_success( get_option( self::LAST_CHANGE_OPTION, null ) );
    }

    public function ajax_revert() {
        check_ajax_referer( 'wcdlm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $last = get_option( self::LAST_CHANGE_OPTION, null );
        if ( ! $last || empty( $last['revert_rows'] ) ) {
            wp_send_json_error( 'No stored change to revert.' );
        }

        // Revert: swap old↔new — new_base becomes the "from", old_base becomes the "to"
        $result = $this->apply_rows( $last['revert_rows'], $last['new_base'], $last['old_base'] );

        // Clear the stored change — revert can only be run once
        delete_option( self::LAST_CHANGE_OPTION );

        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------ */
    /*  Page Render                                                         */
    /* ------------------------------------------------------------------ */

    public function render_page() {
        $nonce = wp_create_nonce( 'wcdlm_nonce' );
        ?>
        <div id="wcdlm-app" data-nonce="<?php echo esc_attr( $nonce ); ?>">

        <style>
        #wcdlm-app *{box-sizing:border-box;margin:0;padding:0}
        #wcdlm-app{
            font-family:'DM Mono',ui-monospace,monospace;
            background:#0d0f14;color:#c9d1e0;
            min-height:100vh;padding:0 0 60px;
        }
        #wcdlm-app .dlm-header{
            background:linear-gradient(135deg,#12161f 0%,#1a2035 100%);
            border-bottom:1px solid #2a3045;
            padding:28px 32px 24px;display:flex;align-items:center;gap:16px;
        }
        #wcdlm-app .dlm-header-icon{
            width:40px;height:40px;
            background:linear-gradient(135deg,#3b82f6,#6366f1);
            border-radius:10px;display:flex;align-items:center;
            justify-content:center;font-size:20px;flex-shrink:0;
            box-shadow:0 4px 14px rgba(99,102,241,.35);
        }
        #wcdlm-app .dlm-header h1{font-size:20px;font-weight:600;letter-spacing:.02em;color:#e8ecf4;}
        #wcdlm-app .dlm-header p{font-size:12px;color:#6b7a99;margin-top:3px;letter-spacing:.03em;}
        #wcdlm-app .dlm-body{padding:28px 32px;}
        #wcdlm-app .dlm-card{
            background:#12161f;border:1px solid #1e2639;
            border-radius:12px;padding:24px;margin-bottom:20px;
        }
        #wcdlm-app .dlm-card-title{
            font-size:11px;font-weight:600;letter-spacing:.12em;
            text-transform:uppercase;color:#4a5578;margin-bottom:16px;
        }
        /* Revert card accent */
        #wcdlm-app #card-revert{border-color:#2a2010;}
        #wcdlm-app #card-revert .dlm-card-title{color:#8a6a20;}
        /* Steps */
        #wcdlm-app .dlm-steps{display:flex;gap:0;margin-bottom:28px;}
        #wcdlm-app .dlm-step{
            flex:1;padding:12px 16px;background:#12161f;border:1px solid #1e2639;
            font-size:11px;letter-spacing:.08em;text-transform:uppercase;
            color:#3a4460;font-weight:600;display:flex;align-items:center;gap:8px;transition:all .2s;
        }
        #wcdlm-app .dlm-step:first-child{border-radius:8px 0 0 8px;}
        #wcdlm-app .dlm-step:last-child{border-radius:0 8px 8px 0;}
        #wcdlm-app .dlm-step+.dlm-step{border-left:none;}
        #wcdlm-app .dlm-step.active{background:linear-gradient(135deg,#1a2444,#1e2a50);border-color:#3b4f82;color:#7b96e8;}
        #wcdlm-app .dlm-step.done{border-color:#1e3a2a;color:#34d399;}
        #wcdlm-app .dlm-step-num{
            width:20px;height:20px;border-radius:50%;background:#1e2639;
            display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0;
        }
        #wcdlm-app .dlm-step.active .dlm-step-num{background:#2d3f6b;color:#7b96e8;}
        #wcdlm-app .dlm-step.done .dlm-step-num{background:#1a3d2b;color:#34d399;}
        /* Buttons */
        #wcdlm-app .dlm-btn{
            display:inline-flex;align-items:center;gap:8px;
            padding:10px 20px;border-radius:8px;border:none;
            font-family:inherit;font-size:12px;font-weight:600;
            letter-spacing:.06em;text-transform:uppercase;cursor:pointer;transition:all .18s;
        }
        #wcdlm-app .dlm-btn-primary{background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;box-shadow:0 3px 12px rgba(99,102,241,.3);}
        #wcdlm-app .dlm-btn-primary:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(99,102,241,.45);}
        #wcdlm-app .dlm-btn-secondary{background:#1e2639;color:#7b96e8;border:1px solid #2a3a5c;}
        #wcdlm-app .dlm-btn-secondary:hover{background:#253048;}
        #wcdlm-app .dlm-btn-cancel{background:#1a1e2a;color:#8898c0;border:1px solid #2a3045;}
        #wcdlm-app .dlm-btn-cancel:hover{background:#1f2435;color:#c9d1e0;}
        #wcdlm-app .dlm-btn-simulate{background:linear-gradient(135deg,#1a3a2b,#1e4535);color:#34d399;border:1px solid #1e4535;box-shadow:0 3px 12px rgba(52,211,153,.12);}
        #wcdlm-app .dlm-btn-simulate:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(52,211,153,.22);}
        #wcdlm-app .dlm-btn-danger{background:linear-gradient(135deg,#3a1a1a,#4a1f1f);color:#f87171;border:1px solid #5a2525;box-shadow:0 3px 12px rgba(248,113,113,.12);}
        #wcdlm-app .dlm-btn-danger:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(248,113,113,.22);}
        #wcdlm-app .dlm-btn-warn{background:linear-gradient(135deg,#3a2a0a,#4a3510);color:#fbbf24;border:1px solid #5a4015;box-shadow:0 3px 12px rgba(251,191,36,.12);}
        #wcdlm-app .dlm-btn-warn:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(251,191,36,.22);}
        #wcdlm-app .dlm-btn:disabled{opacity:.4;cursor:not-allowed;transform:none !important;}
        #wcdlm-app .dlm-btn-group{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
        /* URL list */
        #wcdlm-app .dlm-url-list{display:flex;flex-direction:column;gap:8px;}
        #wcdlm-app .dlm-url-item{
            display:flex;align-items:center;gap:12px;padding:12px 14px;
            border-radius:8px;border:1px solid #1e2639;background:#0d0f14;
            cursor:pointer;transition:all .15s;
        }
        #wcdlm-app .dlm-url-item:hover{border-color:#2a3a5c;background:#12161f;}
        #wcdlm-app .dlm-url-item.selected{border-color:#3b82f6;background:#111827;box-shadow:0 0 0 1px #3b82f640;}
        #wcdlm-app .dlm-url-radio{width:16px;height:16px;border-radius:50%;border:2px solid #2a3a5c;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all .15s;}
        #wcdlm-app .dlm-url-item.selected .dlm-url-radio{border-color:#3b82f6;background:#3b82f6;}
        #wcdlm-app .dlm-url-radio-dot{width:6px;height:6px;border-radius:50%;background:#fff;opacity:0;transition:opacity .15s;}
        #wcdlm-app .dlm-url-item.selected .dlm-url-radio-dot{opacity:1;}
        #wcdlm-app .dlm-url-path{flex:1;font-size:12px;color:#8898c0;word-break:break-all;}
        #wcdlm-app .dlm-url-count{background:#1e2639;color:#4a6090;font-size:10px;font-weight:600;letter-spacing:.06em;padding:3px 8px;border-radius:20px;flex-shrink:0;}
        /* Inputs */
        #wcdlm-app .dlm-input-group{margin-top:16px;}
        #wcdlm-app .dlm-label{display:block;font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#4a5578;margin-bottom:8px;}
        #wcdlm-app .dlm-input{width:100%;padding:10px 14px;background:#0d0f14;border:1px solid #1e2639;border-radius:8px;font-family:inherit;font-size:13px;color:#c9d1e0;transition:border-color .15s;outline:none;}
        #wcdlm-app .dlm-input:focus{border-color:#3b82f6;}
        #wcdlm-app .dlm-input-readonly{background:#0a0c10;color:#4a5578;cursor:default;}
        #wcdlm-app .dlm-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        /* Revert info */
        #wcdlm-app .dlm-revert-info{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;}
        #wcdlm-app .dlm-revert-field label{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#5a4a20;margin-bottom:5px;}
        #wcdlm-app .dlm-revert-field code{display:block;font-family:inherit;font-size:11px;color:#8a6a20;background:#1a1408;border:1px solid #2a2010;border-radius:6px;padding:7px 10px;word-break:break-all;line-height:1.5;}
        #wcdlm-app .dlm-revert-meta{font-size:11px;color:#5a4a20;margin-bottom:14px;}
        /* Table */
        #wcdlm-app .dlm-table-wrap{overflow-x:auto;border-radius:8px;border:1px solid #1e2639;margin-top:16px;}
        #wcdlm-app table{width:100%;border-collapse:collapse;font-size:12px;}
        #wcdlm-app thead tr{background:#0d0f14;border-bottom:1px solid #1e2639;}
        #wcdlm-app th{padding:10px 14px;text-align:left;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#3a4460;font-weight:600;white-space:nowrap;}
        #wcdlm-app tbody tr{border-bottom:1px solid #111520;transition:background .1s;}
        #wcdlm-app tbody tr:last-child{border-bottom:none;}
        #wcdlm-app tbody tr:hover{background:#0f1219;}
        #wcdlm-app tbody tr.changed{background:#0d1a12;}
        #wcdlm-app tbody tr.changed:hover{background:#0f1e15;}
        #wcdlm-app td{padding:10px 14px;color:#8898c0;word-break:break-all;vertical-align:top;}
        #wcdlm-app td.product-title{color:#c9d1e0;white-space:nowrap;}
        #wcdlm-app .url-old{color:#f87171;}
        #wcdlm-app .url-new{color:#34d399;}
        #wcdlm-app .url-unchanged{color:#3a4460;}
        #wcdlm-app .badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:600;letter-spacing:.06em;}
        #wcdlm-app .badge-change{background:#1a3d2b;color:#34d399;}
        #wcdlm-app .badge-same{background:#1a1e2a;color:#3a4460;}
        /* Summary */
        #wcdlm-app .dlm-summary{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:8px;}
        #wcdlm-app .dlm-stat{background:#12161f;border:1px solid #1e2639;border-radius:8px;padding:10px 16px;display:flex;align-items:center;gap:10px;transition:all .15s;}
        #wcdlm-app .dlm-stat.clickable{cursor:pointer;user-select:none;}
        #wcdlm-app .dlm-stat.clickable:hover{border-color:#3b4f82;background:#161b28;transform:translateY(-1px);}
        #wcdlm-app .dlm-stat.active-filter{border-color:#3b82f6;background:#111827;box-shadow:0 0 0 1px #3b82f640;}
        #wcdlm-app .dlm-stat-num{font-size:22px;font-weight:700;color:#e8ecf4;}
        #wcdlm-app .dlm-stat-label{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:#3a4460;}
        #wcdlm-app .dlm-filter-hint{font-size:10px;color:#3a4460;letter-spacing:.05em;margin-bottom:10px;}
        /* Notices */
        #wcdlm-app .dlm-notice{padding:12px 16px;border-radius:8px;font-size:12px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px;}
        #wcdlm-app .dlm-notice-success{background:#0d2018;border:1px solid #1a4030;color:#34d399;}
        #wcdlm-app .dlm-notice-error{background:#200d0d;border:1px solid #401a1a;color:#f87171;}
        #wcdlm-app .dlm-notice-info{background:#0d1220;border:1px solid #1a2a50;color:#7b96e8;}
        #wcdlm-app .dlm-notice-warn{background:#1a1408;border:1px solid #3a2a10;color:#fbbf24;}
        /* Spinner */
        #wcdlm-app .dlm-spinner{width:16px;height:16px;border-radius:50%;border:2px solid currentColor;border-top-color:transparent;animation:spin .7s linear infinite;flex-shrink:0;}
        @keyframes spin{to{transform:rotate(360deg)}}
        /* Confirm overlay */
        #wcdlm-confirm-overlay{display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);align-items:center;justify-content:center;}
        #wcdlm-confirm-overlay.visible{display:flex;}
        #wcdlm-confirm-box{background:#12161f;border:1px solid #2a3a5c;border-radius:14px;padding:28px;max-width:460px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6);}
        #wcdlm-confirm-box h3{font-size:16px;color:#e8ecf4;margin-bottom:8px;}
        #wcdlm-confirm-box p{font-size:13px;color:#6b7a99;margin-bottom:16px;line-height:1.6;}
        #wcdlm-confirm-box .dlm-confirm-detail{background:#0d0f14;border:1px solid #1e2639;border-radius:8px;padding:12px 14px;margin-bottom:20px;font-size:11px;color:#6b7a99;line-height:1.9;}
        #wcdlm-confirm-box .dlm-confirm-detail strong{color:#c9d1e0;}
        #wcdlm-confirm-box .dlm-btn-group{margin-top:0;}
        #wcdlm-app .dlm-empty{text-align:center;padding:40px;color:#3a4460;font-size:13px;}
        </style>

        <!-- Shared confirm overlay -->
        <div id="wcdlm-confirm-overlay">
            <div id="wcdlm-confirm-box">
                <h3 id="wcdlm-confirm-title">⚠️ Confirm Action</h3>
                <p id="wcdlm-confirm-text"></p>
                <div class="dlm-confirm-detail" id="wcdlm-confirm-detail" style="display:none"></div>
                <div class="dlm-btn-group">
                    <button class="dlm-btn dlm-btn-danger" id="wcdlm-confirm-yes">Confirm</button>
                    <button class="dlm-btn dlm-btn-cancel" id="wcdlm-confirm-no">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="dlm-header">
            <div class="dlm-header-icon">📦</div>
            <div>
                <h1>SAWE Download URL Manager</h1>
                <p>Scan, preview, and bulk-update base paths for WooCommerce downloadable files</p>
            </div>
        </div>

        <div class="dlm-body">

            <!-- Steps -->
            <div class="dlm-steps">
                <div class="dlm-step active" id="step-1"><span class="dlm-step-num">1</span> Scan Products</div>
                <div class="dlm-step" id="step-2"><span class="dlm-step-num">2</span> Select Base URL</div>
                <div class="dlm-step" id="step-3"><span class="dlm-step-num">3</span> Set New URL</div>
                <div class="dlm-step" id="step-4"><span class="dlm-step-num">4</span> Simulate / Apply</div>
            </div>

            <!-- Step 1: Scan -->
            <div class="dlm-card" id="card-scan">
                <div class="dlm-card-title">Step 1 — Scan all downloadable products</div>
                <p style="font-size:13px;color:#6b7a99;margin-bottom:16px;">
                    Scans every WooCommerce product and variation for downloadable files and extracts all unique base directory URLs.
                </p>
                <button class="dlm-btn dlm-btn-primary" id="btn-scan"><span>🔍</span> Scan Products</button>
            </div>

            <!-- Step 2: Select base URL -->
            <div class="dlm-card" id="card-select" style="display:none">
                <div class="dlm-card-title">Step 2 — Select a base URL to remap</div>
                <div id="url-list-container" class="dlm-url-list"></div>
            </div>

            <!-- Step 3: New URL input -->
            <div class="dlm-card" id="card-newurl" style="display:none">
                <div class="dlm-card-title">Step 3 — Enter new base URL</div>
                <div class="dlm-row">
                    <div class="dlm-input-group">
                        <label class="dlm-label">Current base URL</label>
                        <input type="text" class="dlm-input dlm-input-readonly" id="input-old-url" readonly>
                    </div>
                    <div class="dlm-input-group">
                        <label class="dlm-label">New base URL</label>
                        <input type="text" class="dlm-input" id="input-new-url"
                               placeholder="https://example.com/wp-content/uploads/woocommerce_uploads/">
                    </div>
                </div>
                <div class="dlm-btn-group">
                    <button class="dlm-btn dlm-btn-simulate" id="btn-simulate"><span>🧪</span> Simulate</button>
                    <button class="dlm-btn dlm-btn-danger"   id="btn-apply"    style="display:none"><span>⚡</span> Apply Changes</button>
                    <button class="dlm-btn dlm-btn-cancel"   id="btn-cancel"   style="display:none"><span>✕</span> Cancel</button>
                </div>
            </div>

            <!-- Step 4: Results -->
            <div class="dlm-card" id="card-results" style="display:none">
                <div class="dlm-card-title" id="results-title">Simulation Results</div>
                <div id="results-notice"></div>
                <div class="dlm-summary" id="results-summary"></div>
                <div class="dlm-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Product / Variation</th>
                                <th>Status</th>
                                <th>Current File URL</th>
                                <th>New File URL</th>
                            </tr>
                        </thead>
                        <tbody id="results-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Revert last change -->
            <div class="dlm-card" id="card-revert" style="display:none">
                <div class="dlm-card-title">↩ Revert Last Change</div>
                <div id="revert-notice"></div>
                <div class="dlm-revert-meta" id="revert-meta"></div>
                <div class="dlm-revert-info" id="revert-info"></div>
                <div class="dlm-btn-group" style="margin-top:0">
                    <button class="dlm-btn dlm-btn-warn" id="btn-revert"><span>↩</span> Revert This Change</button>
                </div>
            </div>

        </div>
        </div>

        <script>
        (function(){
            const app     = document.getElementById('wcdlm-app');
            const nonce   = app.dataset.nonce;
            const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            const steps = [
                document.getElementById('step-1'),
                document.getElementById('step-2'),
                document.getElementById('step-3'),
                document.getElementById('step-4'),
            ];
            function setStep(n) {
                steps.forEach((s,i) => {
                    s.classList.remove('active','done');
                    if (i+1 < n)  s.classList.add('done');
                    if (i+1 === n) s.classList.add('active');
                });
            }

            const cardSelect  = document.getElementById('card-select');
            const cardNewurl  = document.getElementById('card-newurl');
            const cardResults = document.getElementById('card-results');
            const cardRevert  = document.getElementById('card-revert');
            const btnScan     = document.getElementById('btn-scan');
            const urlListCont = document.getElementById('url-list-container');
            const inputOldUrl = document.getElementById('input-old-url');
            const inputNewUrl = document.getElementById('input-new-url');
            const btnSimulate = document.getElementById('btn-simulate');
            const btnApply    = document.getElementById('btn-apply');
            const btnCancel   = document.getElementById('btn-cancel');
            const btnRevert   = document.getElementById('btn-revert');
            const resultsTbody= document.getElementById('results-tbody');
            const resultsSumm = document.getElementById('results-summary');
            const resultsTitle= document.getElementById('results-title');
            const resultsNotice=document.getElementById('results-notice');
            const revertNotice= document.getElementById('revert-notice');
            const revertMeta  = document.getElementById('revert-meta');
            const revertInfo  = document.getElementById('revert-info');

            let selectedBase = '';
            let lastSimRows  = [];

            async function post(action, data) {
                const form = new URLSearchParams({ action, nonce, ...data });
                const res  = await fetch(ajaxUrl, {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: form.toString()
                });
                return res.json();
            }

            function btnLoading(btn, text) { btn.disabled=true; btn.innerHTML=`<span class="dlm-spinner"></span> ${text}`; }
            function btnReset(btn, icon, text) { btn.disabled=false; btn.innerHTML=`<span>${icon}</span> ${text}`; }

            /* ── Shared confirm dialog ── */
            function showConfirm({ title, text, detail, yesLabel, yesClass, onConfirm }) {
                document.getElementById('wcdlm-confirm-title').textContent = title;
                document.getElementById('wcdlm-confirm-text').textContent  = text;
                const detailEl = document.getElementById('wcdlm-confirm-detail');
                if (detail) { detailEl.innerHTML = detail; detailEl.style.display=''; }
                else          detailEl.style.display = 'none';
                const yesBtn = document.getElementById('wcdlm-confirm-yes');
                yesBtn.textContent = yesLabel || 'Confirm';
                yesBtn.className   = `dlm-btn ${yesClass||'dlm-btn-danger'}`;
                const overlay = document.getElementById('wcdlm-confirm-overlay');
                overlay.classList.add('visible');
                yesBtn.onclick = () => { overlay.classList.remove('visible'); onConfirm(); };
                document.getElementById('wcdlm-confirm-no').onclick = () => overlay.classList.remove('visible');
            }

            /* ── Load last change on page load ── */
            (async () => {
                try {
                    const r = await post('wcdlm_get_last_change', {});
                    if (r.success && r.data) renderRevertCard(r.data);
                } catch(e) {}
            })();

            /* ── Scan ── */
            btnScan.addEventListener('click', async () => {
                btnLoading(btnScan, 'Scanning…');
                try {
                    const r = await post('wcdlm_get_base_urls', {});
                    if (!r.success) throw new Error(r.data || 'Scan failed');
                    renderUrlList(r.data);
                    cardSelect.style.display  = '';
                    cardNewurl.style.display  = 'none';
                    cardResults.style.display = 'none';
                    setStep(2);
                } catch(e) { alert('Error: '+e.message); }
                finally    { btnReset(btnScan,'🔄','Re-Scan Products'); }
            });

            function renderUrlList(data) {
                const entries = Object.entries(data);
                if (!entries.length) { urlListCont.innerHTML='<div class="dlm-empty">No downloadable products found.</div>'; return; }
                urlListCont.innerHTML = '';
                entries.forEach(([base,count]) => {
                    const item = document.createElement('div');
                    item.className='dlm-url-item'; item.dataset.base=base;
                    item.innerHTML=`
                        <div class="dlm-url-radio"><div class="dlm-url-radio-dot"></div></div>
                        <div class="dlm-url-path">${escHtml(base)}</div>
                        <div class="dlm-url-count">${count} file${count!==1?'s':''}</div>`;
                    item.addEventListener('click', () => selectBase(base, item));
                    urlListCont.appendChild(item);
                });
            }

            function selectBase(base, el) {
                document.querySelectorAll('.dlm-url-item').forEach(i=>i.classList.remove('selected'));
                el.classList.add('selected');
                selectedBase=base; inputOldUrl.value=base; inputNewUrl.value=base;
                cardNewurl.style.display=''; cardResults.style.display='none';
                btnApply.style.display='none'; btnCancel.style.display='none';
                setStep(3); inputNewUrl.focus(); inputNewUrl.select();
            }

            /* ── Simulate ── */
            btnSimulate.addEventListener('click', async () => {
                const newBase = inputNewUrl.value.trim();
                if (!newBase) { alert('Please enter a new base URL.'); return; }
                btnLoading(btnSimulate,'Simulating…');
                try {
                    const r = await post('wcdlm_simulate', { old_base:selectedBase, new_base:newBase });
                    if (!r.success) throw new Error(r.data||'Simulation failed');
                    lastSimRows=r.data;
                    renderResults(r.data, false);
                    btnApply.style.display=''; btnCancel.style.display='';
                    setStep(4);
                } catch(e) { alert('Error: '+e.message); }
                finally    { btnReset(btnSimulate,'🧪','Simulate'); }
            });

            /* ── Cancel — resets to clean Step 3 state ── */
            btnCancel.addEventListener('click', () => {
                inputNewUrl.value=selectedBase;
                btnApply.style.display='none';
                btnCancel.style.display='none';
                cardResults.style.display='none';
                setStep(3);
                inputNewUrl.focus(); inputNewUrl.select();
            });

            /* ── Apply ── */
            btnApply.addEventListener('click', () => {
                const newBase = inputNewUrl.value.trim();
                const changed = lastSimRows.filter(r=>r.changed).length;
                showConfirm({
                    title:'⚠️ Confirm URL Update',
                    text:`This will update ${changed} file reference(s) across your products and customer download permissions.`,
                    detail:`<strong>From:</strong> ${escHtml(selectedBase)}<br><strong>To:</strong>&nbsp;&nbsp;&nbsp;${escHtml(newBase)}`,
                    yesLabel:'Apply Changes', yesClass:'dlm-btn-danger',
                    onConfirm: async () => {
                        btnLoading(btnApply,'Applying…');
                        try {
                            const r = await post('wcdlm_update', { old_base:selectedBase, new_base:newBase });
                            if (!r.success) throw new Error(r.data||'Update failed');
                            renderResults(lastSimRows, true, r.data);
                            btnApply.style.display='none'; btnCancel.style.display='none';
                            const lc = await post('wcdlm_get_last_change',{});
                            if (lc.success && lc.data) renderRevertCard(lc.data);
                        } catch(e) { alert('Error: '+e.message); }
                        finally    { btnReset(btnApply,'⚡','Apply Changes'); }
                    }
                });
            });

            /* ── Revert ── */
            btnRevert.addEventListener('click', async () => {
                const lc = await post('wcdlm_get_last_change',{});
                if (!lc.success || !lc.data) {
                    revertNotice.innerHTML=`<div class="dlm-notice dlm-notice-error">⚠️ No stored change found.</div>`;
                    return;
                }
                const d=lc.data;
                showConfirm({
                    title:'↩ Confirm Revert',
                    text:`This will reverse the last applied change, updating ${d.revert_rows.length} file reference(s). This cannot be undone.`,
                    detail:`<strong>Revert from:</strong> ${escHtml(d.new_base)}<br><strong>Back to:</strong>&nbsp;&nbsp;&nbsp;&nbsp;${escHtml(d.old_base)}<br><strong>Applied at:</strong>&nbsp;${escHtml(d.applied_at)}`,
                    yesLabel:'Revert Changes', yesClass:'dlm-btn-warn',
                    onConfirm: async () => {
                        btnLoading(btnRevert,'Reverting…');
                        try {
                            const r = await post('wcdlm_revert',{});
                            if (!r.success) throw new Error(r.data||'Revert failed');
                            revertNotice.innerHTML=`
                                <div class="dlm-notice dlm-notice-success">
                                    ✅ Reverted — updated <strong>${r.data.products_updated}</strong> product(s)
                                    and <strong>${r.data.permissions_updated}</strong> customer permission(s).
                                </div>`;
                            revertInfo.innerHTML=''; revertMeta.textContent='Revert complete. No further revert available.';
                            btnRevert.style.display='none';
                        } catch(e) {
                            revertNotice.innerHTML=`<div class="dlm-notice dlm-notice-error">⚠️ ${escHtml(e.message)}</div>`;
                        } finally { btnReset(btnRevert,'↩','Revert This Change'); }
                    }
                });
            });

            /* ── Render revert card ── */
            function renderRevertCard(data) {
                revertNotice.innerHTML='';
                btnRevert.style.display='';
                revertMeta.textContent=`Applied at: ${data.applied_at}  ·  ${data.revert_rows.length} file(s) affected`;
                revertInfo.innerHTML=`
                    <div class="dlm-revert-field">
                        <label>Changed from</label>
                        <code>${escHtml(data.old_base)}</code>
                    </div>
                    <div class="dlm-revert-field">
                        <label>Changed to</label>
                        <code>${escHtml(data.new_base)}</code>
                    </div>`;
                cardRevert.style.display='';
            }

            /* ── Render results table ── */
            let activeFilter = 'all'; // 'all' | 'changed' | 'unchanged'

            function applyFilter(rows) {
                Array.from(resultsTbody.rows).forEach((tr, i) => {
                    const row = rows[i];
                    if (!row) return;
                    const show = activeFilter === 'all'
                        || (activeFilter === 'changed'   &&  row.changed)
                        || (activeFilter === 'unchanged' && !row.changed);
                    tr.style.display = show ? '' : 'none';
                });
                // Update active-filter highlight on stat cards
                document.querySelectorAll('#results-summary .dlm-stat').forEach(s => {
                    s.classList.toggle('active-filter', s.dataset.filter === activeFilter);
                });
            }

            function renderResults(rows, applied, stats) {
                activeFilter = 'all';
                cardResults.style.display='';
                resultsTitle.textContent=applied ? 'Update Applied' : 'Simulation Preview';
                if (applied && stats) {
                    const errHtml=stats.errors.length ? `<br><strong>Errors:</strong> ${stats.errors.map(escHtml).join(', ')}` : '';
                    resultsNotice.innerHTML=`<div class="dlm-notice dlm-notice-success">✅ Updated <strong>${stats.products_updated}</strong> product(s) and <strong>${stats.permissions_updated}</strong> customer download permission(s).${errHtml}</div>`;
                } else {
                    resultsNotice.innerHTML=`<div class="dlm-notice dlm-notice-info">🧪 Simulation only — no changes made yet. Review below, then click <strong>Apply Changes</strong> or <strong>Cancel</strong> to start over.</div>`;
                }
                const total=rows.length, changed=rows.filter(r=>r.changed).length;

                resultsSumm.innerHTML=`
                    <div class="dlm-stat clickable active-filter" data-filter="all">
                        <div><div class="dlm-stat-num">${total}</div><div class="dlm-stat-label">Total Files</div></div>
                    </div>
                    <div class="dlm-stat clickable" data-filter="changed">
                        <div><div class="dlm-stat-num" style="color:#34d399">${changed}</div><div class="dlm-stat-label">Will Change</div></div>
                    </div>
                    <div class="dlm-stat clickable" data-filter="unchanged">
                        <div><div class="dlm-stat-num" style="color:#3a4460">${total-changed}</div><div class="dlm-stat-label">Unchanged</div></div>
                    </div>`;

                // Hint text
                resultsSumm.insertAdjacentHTML('afterend',
                    '<div class="dlm-filter-hint" id="filter-hint">Click a number to filter the list</div>');

                // Wire up filter clicks
                document.querySelectorAll('#results-summary .dlm-stat').forEach(stat => {
                    stat.addEventListener('click', () => {
                        activeFilter = stat.dataset.filter;
                        applyFilter(rows);
                        const hint = document.getElementById('filter-hint');
                        if (hint) {
                            hint.textContent = activeFilter === 'all'
                                ? 'Click a number to filter the list'
                                : `Showing ${activeFilter} files — click Total to reset`;
                        }
                    });
                });

                resultsTbody.innerHTML='';
                rows.forEach(row => {
                    const tr=document.createElement('tr');
                    if(row.changed) tr.classList.add('changed');
                    tr.innerHTML=`
                        <td class="product-title">${escHtml(row.title)}</td>
                        <td>${row.changed?'<span class="badge badge-change">CHANGE</span>':'<span class="badge badge-same">SAME</span>'}</td>
                        <td>${row.changed?`<span class="url-old">${escHtml(row.old_url)}</span>`:`<span class="url-unchanged">${escHtml(row.old_url)}</span>`}</td>
                        <td>${row.changed?`<span class="url-new">${escHtml(row.new_url)}</span>`:'<span class="url-unchanged">—</span>'}</td>`;
                    resultsTbody.appendChild(tr);
                });
                cardResults.scrollIntoView({behavior:'smooth',block:'start'});
            }

            function escHtml(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
        })();
        </script>
        <?php
    }
}

new WC_Download_URL_Manager();
