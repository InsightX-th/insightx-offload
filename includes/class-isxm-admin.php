<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Admin — Admin page for InsightX Offload (top-level menu).
 *
 * Single-page app-style UI: sidebar navigation (Media / Connections /
 * Assets / Tools / Migrate / Support), card layout, toggle switches,
 * live URL preview and AJAX save — no full-page reloads.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Admin {

    const PAGE_SLUG = 'insightx-offload';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() {
        add_menu_page(
            __( 'InsightX Offload', 'insightx-offload' ),
            __( 'InsightX Offload', 'insightx-offload' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            'dashicons-cloud-upload',
            77 // Right after InsightX Backup (76) — keeps the InsightX tools together.
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_' . self::PAGE_SLUG ) {
            return;
        }

        // filemtime() instead of the static ISXM_PLUGIN_VERSION constant so
        // browsers pick up admin.css/admin.js changes immediately on the
        // next load instead of serving a stale cached copy forever (the
        // version query string only changes when the file's content does).
        wp_enqueue_style( 'isxs-admin', ISXM_PLUGIN_URL . 'assets/css/admin.css', [], filemtime( ISXM_PLUGIN_DIR . 'assets/css/admin.css' ) );
        wp_enqueue_script( 'isxs-admin', ISXM_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], filemtime( ISXM_PLUGIN_DIR . 'assets/js/admin.js' ), true );

        // An upgraded site still has its tracking records only in postmeta;
        // this kicks off the one-time copy into the ledger the first time an
        // admin opens the page. Everything keeps working on the old path
        // until it finishes, so there is nothing to wait for.
        ISXM_Tools::maybe_start_backfill();

        $settings = ISXM_Settings::all();
        $stats    = ISXM_Tools::get_stats();

        wp_localize_script( 'isxs-admin', 'isxsAdmin', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( ISXM_Tools::NONCE_ACTION ),
            'stats'       => $stats,
            'configured'  => ISXM_Settings::is_configured(),
            'hasSecret'   => $settings['secret_key'] !== '',
            'siteHost'    => wp_parse_url( site_url(), PHP_URL_HOST ),
            // Where a run's failures can be inspected one by one.
            'mediaFailedUrl' => admin_url( 'upload.php?mode=list&isxs_status=failed' ),
            'connections' => ISXM_Connections::js_data(),
            // slug => inline SVG brand marks, so the JS picker dropdown can
            // render provider logos without duplicating the SVG source.
            'providerLogos' => $this->provider_logo_map(),
            'i18n'       => [
                'saving'        => 'กำลังบันทึก…',
                'saved'         => 'บันทึกแล้ว ✓',
                'save'          => 'บันทึกการตั้งค่า',
                'connSave'      => 'บันทึก',
                'testing'       => 'กำลังทดสอบ…',
                'confirmRemove' => "ลบไฟล์ทั้งหมดออกจาก bucket?\n\nระบบจะดาวน์โหลดไฟล์ที่ไม่มีบนเซิร์ฟเวอร์กลับมาก่อนลบ แต่ควรมี backup ไว้ก่อนเสมอ",
                'working'       => 'กำลังทำงาน…',
                'done'          => 'เสร็จสิ้น ✓',
                'error'         => 'เกิดข้อผิดพลาด',
                'stop'          => 'หยุด',
                'stopping'      => 'กำลังหยุด…',
                'stopped'       => 'หยุดแล้ว',
                'cancel'        => 'ยกเลิก',
                'cancelling'    => 'กำลังยกเลิก…',
                // Cancel throws the resume cursor away, so it asks first —
                // "หยุด" is the one that is always safe.
                'confirmCancel' => "ยกเลิกงานนี้?\n\nงานที่ทำไปแล้วยังอยู่ครบ แต่จุดที่ค้างไว้จะถูกลบ — เริ่มใหม่ครั้งหน้าจะไล่หาตั้งแต่ต้น",
                // A run whose driver went quiet (loopback killed, PHP fatal,
                // server restart). Nothing is lost — the cursor is in the
                // database and the healthcheck picks it back up.
                'stalled'       => 'สะดุด — กำลังเริ่มทำต่อให้อัตโนมัติ',
                'resume'        => 'ทำต่อ',
                'connecting'    => 'กำลังเชื่อมต่อ…',
                'retrying'      => 'การเชื่อมต่อสะดุด — กำลังลองใหม่',
                'connectionLost' => 'การเชื่อมต่อขาดหลายครั้งติดกัน — หยุดไว้ตรงนี้ งานที่ทำไปแล้วถูกบันทึกครบ กด "ทำต่อ" เพื่อไปต่อจากจุดเดิม',
                // Bulk tools now run server-side; these cover the states
                // only the job records can be in.
                'counting'      => 'กำลังนับไฟล์ใน source bucket…',
                // The final one-pass DB URL rewrite at the end of an offload
                // run — uploads are all done, the bar sits near 100% while
                // this phase rewrites stored URLs table by table.
                'rewriting'     => 'กำลังเขียน URL ลงฐานข้อมูล…',
                'keepTabOpen'   => 'เว็บนี้เรียกตัวเองไม่ได้ — เปิดหน้านี้ทิ้งไว้จนกว่างานจะเสร็จ',
                'sessionExpired' => 'เซสชันหมดอายุหรือถูก logout — refresh หน้านี้แล้วลองใหม่ (งานที่ทำไปแล้วถูกบันทึกครบ)',
            ],
        ] );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s     = ISXM_Settings::all();
        $stats = ISXM_Tools::get_stats();

        // Destination provider facts for the Storage/Delivery card heads.
        $isxm_providers = ISXM_Connections::providers();
        $isxm_pmeta     = isset( $isxm_providers[ $s['provider'] ] ) ? $isxm_providers[ $s['provider'] ] : $isxm_providers['custom'];
        $isxm_pconn     = ISXM_Connections::get( $s['provider'] );
        $isxm_pcfg      = ISXM_Connections::is_configured( $s['provider'] );
        $isxm_pst       = ISXM_Connections::status( $s['provider'] );
        $isxm_pok       = $isxm_pcfg && $isxm_pst['state'] === 'ok';

        $isxm_storage_alert = '';
        if ( ! $isxm_pok ) {
            $isxm_storage_alert = $isxm_pcfg
                ? ( $isxm_pst['message'] !== '' ? $isxm_pst['message'] : 'เชื่อมต่อ storage ไม่สำเร็จ' )
                : 'ยังตั้งค่าการเชื่อมต่อไม่ครบ — media จะยังไม่ถูก offload ไปที่ storage ไปที่แท็บ “การเชื่อมต่อ” เพื่อกรอก Endpoint / Bucket / Access Key / Secret Key';
        }

        $isxm_delivery_alert = '';
        if ( $s['deliver_enabled'] && ! $isxm_pok ) {
            $isxm_delivery_alert = 'ไม่สามารถทดสอบการเสิร์ฟ media ได้จนกว่าจะเชื่อมต่อ storage สำเร็จ — ดูหัวข้อ "การตั้งค่าจัดเก็บไฟล์" ด้านซ้าย';
        }
        ?>
        <div class="isxs-wrap" id="isxs-app">

            <header class="isxs-header">
                <div class="isxs-header-top">
                    <div class="isxs-header-brand">
                        <div class="isxs-logo" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 16.6A5 5 0 0 0 18 7h-1.3A7 7 0 1 0 5 15.7"/><path d="M12 12v9"/><path d="m8.5 17.5 3.5 3.5 3.5-3.5"/></svg>
                        </div>
                        <div>
                            <h1><?php esc_html_e( 'InsightX Offload', 'insightx-offload' ); ?></h1>
                            <p class="isxs-tagline">Offload media ไปยัง S3-compatible storage (Minio · S3 · R2 · Spaces) + ย้าย provider</p>
                        </div>
                    </div>

                    <div class="isxs-status-wrap">
                        <button type="button" class="isxs-status-widget" aria-expanded="false">
                            <span class="isxs-status-text"><strong id="isxs-status-percent">0%</strong> Offloaded</span>
                            <span class="isxs-status-bar"><span class="isxs-status-bar-fill" id="isxs-status-fill"></span></span>
                            <span class="isxs-status-chev" aria-hidden="true">▾</span>
                        </button>
                        <div class="isxs-status-panel" id="isxs-status-panel" hidden>
                            <div class="isxs-status-panel-head">
                                <strong>สถานะ Offload</strong>
                                <button type="button" class="isxs-status-refresh">
                                    <svg class="isxs-status-refresh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
                                    รีเฟรช
                                </button>
                            </div>
                            <table class="isxs-status-table">
                                <thead>
                                    <tr><th>แหล่งที่มา</th><th>Offloaded</th><th>เหลือ</th></tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Media Library</td>
                                        <td class="isxs-status-num" id="isxs-status-offloaded">0</td>
                                        <td class="isxs-status-num" id="isxs-status-remaining">0</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="isxs-status-action">
                                <button type="button" class="isxs-btn isxs-btn-primary isxs-status-offload-btn">Offload Remaining <span id="isxs-status-remaining-num">0</span> รายการ</button>
                            </div>
                            <div class="isxs-status-foot">รวม <span id="isxs-status-total">0</span> รายการ · ขึ้น bucket แล้ว <span id="isxs-status-onbucket">0</span></div>
                        </div>
                    </div>
                </div>

                <nav class="isxs-nav" role="tablist">
                    <button type="button" class="isxs-nav-item is-active" data-tab="media" role="tab">สื่อ</button>
                    <button type="button" class="isxs-nav-item" data-tab="assets" role="tab">ทรัพยากร</button>
                    <button type="button" class="isxs-nav-item" data-tab="tools" role="tab">เครื่องมือ</button>
                    <button type="button" class="isxs-nav-item" data-tab="migrate" role="tab">ย้ายข้อมูล</button>
                    <button type="button" class="isxs-nav-item" data-tab="connections" role="tab">การเชื่อมต่อ</button>
                    <button type="button" class="isxs-nav-item" data-tab="support" role="tab">ช่วยเหลือ</button>
                </nav>
            </header>

            <main class="isxs-main">

                    <!-- ============ MEDIA: Storage + Delivery settings ============ -->
                    <section class="isxs-tab is-active" data-tab-panel="media">

                        <div class="isxs-settings-grid">

                            <!-- Storage Settings card -->
                            <div class="isxs-card isxs-storage-card">
                                <div class="isxs-card-head">
                                    <div class="isxs-card-head-title" id="isxs-storage-head-title">
                                        <span class="isxs-provider-icon is-logo" id="isxs-storage-head-logo"><?php echo $this->provider_logo_svg( $s['provider'] ); ?></span>
                                        <div>
                                            <h2>การตั้งค่าจัดเก็บไฟล์</h2>
                                            <p class="isxs-card-sub" id="isxs-storage-head-sub"><?php echo esc_html( $isxm_pmeta['label'] ); ?><?php echo $isxm_pconn['bucket'] !== '' ? ' · ' . esc_html( $isxm_pconn['bucket'] ) : ''; ?><?php echo $isxm_pconn['region'] !== '' ? ' · ' . esc_html( $isxm_pconn['region'] ) : ''; ?></p>
                                        </div>
                                    </div>
                                    <div class="isxs-card-head-actions">
                                        <?php $this->connection_status_line( $s['provider'], 'isxs-dest-status-badge' ); ?>
                                        <div class="isxs-picker" id="isxs-dest-provider-picker">
                                            <button type="button" class="isxs-picker-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="isxs-dest-provider-menu">
                                                <span class="isxs-picker-selected">
                                                    <span class="isxs-provider-icon is-logo" id="isxs-dest-provider-selected-logo"><?php echo $this->provider_logo_svg( $s['provider'] ); ?></span>
                                                    <span class="isxs-picker-label" id="isxs-dest-provider-selected-label"><?php echo esc_html( $isxm_pmeta['label'] ); ?></span>
                                                </span>
                                                <svg class="isxs-picker-caret" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M4.5 6l3.5 3.5L11.5 6l1 1-4.5 4.5L3.5 7z"/></svg>
                                            </button>
                                            <ul class="isxs-picker-menu" id="isxs-dest-provider-menu" role="listbox" aria-label="เลือก Storage Provider" hidden>
                                                <?php foreach ( $isxm_providers as $isxm_slug => $isxm_meta ) :
                                                    $isxm_sel_verified = ISXM_Connections::is_configured( $isxm_slug ) && ISXM_Connections::status( $isxm_slug )['state'] === 'ok';
                                                    ?>
                                                    <li role="option" data-provider="<?php echo esc_attr( $isxm_slug ); ?>" aria-selected="<?php echo $s['provider'] === $isxm_slug ? 'true' : 'false'; ?>" aria-disabled="<?php echo $isxm_sel_verified ? 'false' : 'true'; ?>" class="isxs-picker-option<?php echo $s['provider'] === $isxm_slug ? ' is-selected' : ''; ?><?php echo $isxm_sel_verified ? '' : ' is-disabled'; ?>">
                                                        <span class="isxs-provider-icon is-logo"><?php echo $this->provider_logo_svg( $isxm_slug ); ?></span>
                                                        <span class="isxs-picker-label"><?php echo esc_html( $isxm_meta['label'] ); ?></span>
                                                        <span class="isxs-picker-check">✓</span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <?php if ( $isxm_storage_alert !== '' ) : ?>
                                    <div class="isxs-alert isxs-alert-error isxs-storage-alert"><?php echo esc_html( $isxm_storage_alert ); ?></div>
                                <?php endif; ?>
                                <div class="isxs-card-body">
                                    <?php $this->toggle( 'offload_enabled', $s['offload_enabled'], 'Offload Media', 'คัดลอกไฟล์ media ขึ้น storage อัตโนมัติหลังอัปโหลด' ); ?>
                                    <?php $this->toggle( 'remove_local', $s['remove_local'], 'Remove Local Media', 'ลบไฟล์บนเซิร์ฟเวอร์หลัง offload สำเร็จ เพื่อประหยัดพื้นที่ (ระวัง: ปลั๊กอินแก้รูปบางตัวต้องใช้ไฟล์ local)', 'warn' ); ?>
                                    <?php $this->toggle( 'persist_urls', $s['persist_urls'], 'เขียน URL ถาวรลงฐานข้อมูล', 'เขียน URL ใหม่ (remote) ลงใน post_content / postmeta / options / guid โดยตรงหลัง offload — Bulk Offload เขียนแบบหนึ่ง-pass ตอนท้ายงาน (สแกนแต่ละตารางครั้งเดียว) ไม่สแกนทั้งตารางทุก batch (URL ยังถูก rewrite ตอนแสดงผลอยู่ดีถ้าเปิด “Deliver Offloaded Media”)' ); ?>
                                    <?php $this->toggle( 'use_prefix', $s['use_prefix'], 'Add Prefix to Bucket Path', 'จัดกลุ่มไฟล์ของเว็บนี้ด้วย prefix ใน bucket' ); ?>
                                    <div class="isxs-field isxs-indent" id="isxs-prefix-field">
                                        <input type="text" id="isxs-prefix" value="<?php echo esc_attr( $s['prefix'] ); ?>" placeholder="wp-content/uploads/">
                                    </div>
                                    <?php $this->toggle( 'use_year_month', $s['use_year_month'], 'Add Year & Month to Bucket Path', 'ใส่ปี/เดือนที่อัปโหลดใน path เพื่อจัดระเบียบอีกชั้น' ); ?>
                                    <?php $this->toggle( 'use_object_version', $s['use_object_version'], 'Add Object Version to Bucket Path', 'ใส่เลข version ใน path เพื่อให้ CDN เสิร์ฟไฟล์เวอร์ชันล่าสุดเสมอ' ); ?>
                                </div>
                            </div>

                            <!-- Delivery Settings card -->
                            <div class="isxs-card isxs-delivery-card">
                                <div class="isxs-card-head">
                                    <div class="isxs-card-head-title" id="isxs-delivery-head-title">
                                        <span class="isxs-provider-icon is-logo" id="isxs-delivery-head-logo"><?php echo $this->provider_logo_svg( $s['provider'] ); ?></span>
                                        <div>
                                            <h2>การเสิร์ฟไฟล์ (Delivery)</h2>
                                            <p class="isxs-card-sub" id="isxs-delivery-head-sub"><?php echo esc_html( $isxm_pmeta['label'] ); ?> · <?php echo esc_html( $isxm_pconn['bucket'] !== '' ? $isxm_pconn['bucket'] : '(ยังไม่ตั้ง bucket)' ); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php if ( $isxm_delivery_alert !== '' ) : ?>
                                    <div class="isxs-alert isxs-alert-warn isxs-delivery-alert"><?php echo esc_html( $isxm_delivery_alert ); ?></div>
                                <?php endif; ?>
                                <div class="isxs-card-body">
                                    <?php $this->toggle( 'deliver_enabled', $s['deliver_enabled'], 'Deliver Offloaded Media', 'rewrite URL ของ media ที่ offload แล้วให้ชี้ไปที่ storage/CDN' ); ?>
                                    <?php $this->toggle( 'force_https', $s['force_https'], 'Force HTTPS', 'ใช้ https กับทุก URL ที่ rewrite เสมอ' ); ?>
                                    <div class="isxs-field">
                                        <label for="isxs-cdn-domain">Custom Delivery Domain (CDN)</label>
                                        <input type="text" id="isxs-cdn-domain" placeholder="cdn.example.com" value="<?php echo esc_attr( $s['cdn_domain'] ); ?>">
                                        <p class="isxs-hint">ถ้ามี CDN ชี้ที่ bucket อยู่แล้ว ใส่โดเมนที่นี่ — เว้นว่างเพื่อใช้ URL ของ bucket ตรงๆ</p>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <?php $this->url_preview_card(); ?>
                    </section>

                    <!-- ============ ASSETS ============ -->
                    <section class="isxs-tab" data-tab-panel="assets">
                        <div class="isxs-card isxs-card-half">
                            <div class="isxs-card-head">
                                <div class="isxs-card-head-title">
                                    <span class="isxs-icon-box" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/></svg>
                                    </span>
                                    <div>
                                        <h2>Assets Pull</h2>
                                        <p class="isxs-card-sub">เสิร์ฟไฟล์ CSS / JS ของธีมและปลั๊กอินผ่าน CDN</p>
                                    </div>
                                </div>
                            </div>
                            <?php if ( ! $s['assets_enabled'] ) : ?>
                                <div class="isxs-alert isxs-alert-warn isxs-assets-alert">Asset ยังไม่ได้เสิร์ฟจาก CDN จนกว่าจะเปิด "Rewrite Asset URLs" — ตอนนี้ไฟล์ CSS/JS ยังเสิร์ฟจากเซิร์ฟเวอร์ของเว็บตามปกติ</div>
                            <?php endif; ?>
                            <div class="isxs-card-body">
                                <?php $this->toggle( 'assets_enabled', $s['assets_enabled'], 'Rewrite Asset URLs', 'เปลี่ยน URL ของไฟล์ asset ที่ enqueue (style/script) ของธีมและปลั๊กอินให้ชี้ไปที่โดเมน CDN' ); ?>
                                <?php $this->toggle( 'assets_force_https', $s['assets_force_https'], 'Force HTTPS', 'ใช้ https กับทุก URL ของ asset ที่ rewrite เสมอ' ); ?>
                                <div class="isxs-field">
                                    <label for="isxs-assets-cdn-domain">CDN Domain (Assets)</label>
                                    <input type="text" id="isxs-assets-cdn-domain" placeholder="cdn.example.com" value="<?php echo esc_attr( $s['assets_cdn_domain'] ); ?>">
                                    <p class="isxs-hint">ต้องเป็น CDN ที่อยู่หน้าเว็บนี้ (เช่น CloudFront ชี้ origin ที่เว็บ) — ไฟล์ asset ไม่ได้อยู่ใน bucket หมายเหตุ: font/รูปที่อ้างอิงภายในไฟล์ CSS จะยังเสิร์ฟจากเซิร์ฟเวอร์ตามปกติ</p>
                                </div>
                                <p class="isxs-hint">ตัวอย่าง URL ของ asset หลัง rewrite (อัปเดตสด):</p>
                                <div class="isxs-url-preview" data-url-preview="assets">
                                    <span class="isxs-url-part" data-part="ascheme"><em>Scheme</em><code>https://</code></span>
                                    <span class="isxs-url-part" data-part="adomain"><em>Domain</em><code>—</code></span>
                                    <span class="isxs-url-part" data-part="apath"><em>Path</em><code>wp-content/themes/…/style.css</code></span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ============ TOOLS ============ -->
                    <section class="isxs-tab" data-tab-panel="tools">

                        <div class="isxs-tools-grid">
                            <?php
                            $this->sync_card();
                            $this->tool_card(
                                'offload',
                                'Offload media ที่เหลือ',
                                'อัปโหลด media ทั้งหมดที่ยังไม่ได้ offload ขึ้น bucket ทีละชุด — งานทำงานฝั่งเซิร์ฟเวอร์ ปิดแท็บได้ กลับมากด "ทำต่อ" ได้จากจุดเดิม (ถ้าไฟล์ถูกลบออกจาก bucket นอกปลั๊กอิน ให้ใช้เครื่องมือ Sync ตรวจก่อน)',
                                'เริ่ม Offload',
                                '',
                                false
                            );

                            // Only worth showing when something actually failed —
                            // the card is also toggled live from JS after a run.
                            $this->tool_card(
                                'retry_failed',
                                'ลองใหม่เฉพาะที่ Offload ไม่ผ่าน',
                                'ไล่อัปโหลดซ้ำเฉพาะไฟล์ที่ครั้งก่อนขึ้น bucket ไม่สำเร็จ — ดูรายตัวได้ในหน้าสื่อ (กรอง “Offload ไม่ผ่าน”)',
                                'ลองใหม่',
                                '',
                                empty( $stats['failed'] )
                            );
                            $this->tool_card( 'download', 'ดาวน์โหลดไฟล์กลับจาก bucket', 'ถ้าเคยเปิด "Remove Local Media" ไฟล์บนเซิร์ฟเวอร์อาจหายไป — ใช้เครื่องมือนี้ดึงไฟล์ที่ขาดกลับมา', 'ดาวน์โหลดไฟล์' );
                            $this->tool_card( 'remove', 'ลบไฟล์ทั้งหมดออกจาก bucket', 'ลบไฟล์ของทุก media ออกจาก bucket — ถ้าไฟล์ไม่มีบนเซิร์ฟเวอร์ ระบบจะดาวน์โหลดกลับมาก่อนลบ', 'ลบออกจาก Bucket', 'danger' );
                            if ( class_exists( 'WC_Product' ) ) {
                                $this->tool_card( 'wc_downloads', 'ตรวจสอบและอัปเดต WooCommerce Downloadable Products', 'ตรวจสอบสินค้าที่ดาวน์โหลดได้ทั้งหมด ตั้งไฟล์ในบัคเก็ตให้เป็น private และอัปเดต URL ให้ถูกต้อง', 'ตรวจสอบและอัปเดต' );
                            }
                            ?>
                        </div>
                    </section>

                    <!-- ============ MIGRATE ============ -->
                    <section class="isxs-tab" data-tab-panel="migrate">

                        <div class="isxs-migrate-content">

                        <div class="isxs-card">
                            <div class="isxs-card-head"><h2>เลือก Provider สำหรับ Migrate</h2></div>
                            <div class="isxs-card-body">
                                <p class="isxs-hint">เลือกว่าจะดึงไฟล์มาจาก provider ไหน (ซ้าย) แล้วอัปโหลดไปยัง provider ไหน (ขวา) — ทั้งสองฝั่งเลือกจาก connection ที่ตั้งค่าไว้แล้วในแท็บ “การเชื่อมต่อ” ระบบจะ<strong>ดึงไฟล์มาเท่านั้น ไม่ลบไฟล์ที่ต้นทาง</strong></p>
                                <div class="isxs-migrate-columns">
                                    <div class="isxs-migrate-col">
                                        <h3><span class="isxs-migrate-col-dot isxs-migrate-col-dot-from"></span>จาก (Source)</h3>
                                        <?php $this->provider_grid( 'isxs-source-provider', 'isxs-source-provider-card', $s['source_provider'] ); ?>
                                    </div>
                                    <div class="isxs-migrate-arrow" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                                    </div>
                                    <div class="isxs-migrate-col">
                                        <h3><span class="isxs-migrate-col-dot isxs-migrate-col-dot-to"></span>ไป (ปลายทาง)</h3>
                                        <?php $this->provider_grid( 'isxs-provider-migrate-mirror', 'isxs-dest-provider-card', $s['provider'] ); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="isxs-card">
                            <div class="isxs-card-head"><h2>โครงสร้าง Path บน Source</h2></div>
                            <div class="isxs-card-body">
                                <p class="isxs-hint">ต้องตรงกับโครงสร้าง key จริงบน source bucket ระบบจะลองดึงตาม path นี้ ถ้าผิดจะเห็น error พร้อม key ที่ลองในรายการด้านล่างหลังกด Migrate</p>
                                <div class="isxs-field">
                                    <label for="isxs-source-prefix">Source Prefix</label>
                                    <input type="text" id="isxs-source-prefix" value="<?php echo esc_attr( $s['source_prefix'] ); ?>" placeholder="wp-content/uploads/">
                                </div>
                                <?php $this->toggle( 'source_use_year_month', $s['source_use_year_month'], 'มี Year & Month ใน Path', 'เช่น wp-content/uploads/2025/08/ไฟล์.jpg' ); ?>
                            </div>
                        </div>

                        <div class="isxs-card">
                            <div class="isxs-card-head"><h2>เปลี่ยน URL ในฐานข้อมูล</h2></div>
                            <div class="isxs-card-body">
                                <p class="isxs-hint">URL ที่ media เดิม<strong>ถูกเสิร์ฟอยู่จริงตอนนี้</strong> (ที่ฝังอยู่ใน post, ACF field, widget ฯลฯ) — หลัง Migrate ระบบจะแทนที่ URL นี้ด้วย URL ปลายทางใหม่ทั่วทั้งฐานข้อมูลให้อัตโนมัติ ไม่ใช่แค่ rewrite ตอนแสดงผล</p>
                                <div class="isxs-field">
                                    <label for="isxs-source-public-url">Source Public URL (เดิม)</label>
                                    <input type="url" id="isxs-source-public-url" placeholder="เว้นว่างเพื่อเดาจาก Endpoint/Bucket ด้านบน" value="<?php echo esc_attr( $s['source_public_base_url'] ); ?>">
                                    <p class="isxs-hint">เช่น https://old-bucket.s3.amazonaws.com หรือ https://cdn.เดิม.com — เว้นว่างถ้า media เดิมยังเป็น URL local (wp-content/uploads) ของเว็บนี้</p>
                                </div>
                            </div>
                        </div>

                        <?php $this->tool_card(
                            'migrate',
                            'Migrate จาก Source มา Destination',
                            'ดึงไฟล์ทั้งหมดที่มีอยู่จริงใน source bucket มา upload ขึ้น storage ปลายทางที่ตั้งค่าไว้ในแท็บ “การเชื่อมต่อ” แล้วเปลี่ยน URL ในฐานข้อมูลให้ทันที — ไม่ลบไฟล์จาก source (ตรวจสอบกับ bucket จริงก่อนข้ามรายการที่ขึ้นไว้แล้วเสมอ)',
                            'เริ่ม Migrate',
                            '',
                            false
                        ); ?>

                        </div>
                    </section>

                    <!-- ============ CONNECTIONS ============ -->
                    <section class="isxs-tab" data-tab-panel="connections">

                        <!-- Selected destination provider lives in this hidden
                             input — set from the Media-tab dropdown (or the
                             Migrate tab), never re-entered here. -->
                        <input type="hidden" id="isxs-provider" value="<?php echo esc_attr( $s['provider'] ); ?>">

                        <div class="isxs-conn-cards">
                            <?php foreach ( $isxm_providers as $isxm_slug => $isxm_meta ) : ?>
                                <?php $this->connection_card( $isxm_slug, $isxm_meta ); ?>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- ============ SUPPORT ============ -->
                    <section class="isxs-tab" data-tab-panel="support">

                        <div class="isxs-card">
                            <div class="isxs-card-head"><h2>ข้อมูลวินิจฉัย (Diagnostic)</h2></div>
                            <div class="isxs-card-body">
                                <pre class="isxs-diagnostic"><?php echo esc_html( ISXM_Tools::diagnostic_text() ); ?></pre>
                            </div>
                        </div>
                    </section>
                </main>

            <div class="isxs-toast" id="isxs-toast"></div>
        </div>
        <?php
    }

    /**
     * Render one toggle switch row.
     *
     * @param string $key     Setting key (becomes data-setting).
     * @param bool   $checked Current value.
     * @param string $label   Row label.
     * @param string $desc    Description line.
     * @param string $variant '' or 'warn'.
     */
    private function toggle( $key, $checked, $label, $desc, $variant = '' ) {
        ?>
        <div class="isxs-toggle <?php echo $variant ? 'isxs-toggle-' . esc_attr( $variant ) : ''; ?>">
            <button type="button" class="isxs-switch <?php echo $checked ? 'is-on' : ''; ?>" data-setting="<?php echo esc_attr( $key ); ?>" role="switch" aria-checked="<?php echo $checked ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( $label ); ?>">
                <span class="isxs-switch-knob"></span>
            </button>
            <div class="isxs-toggle-text">
                <strong><?php echo esc_html( $label ); ?></strong>
                <p><?php echo esc_html( $desc ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render one full connection card (Connections tab) — a provider's own
     * endpoint/region/bucket/keys form with its own Save button, which
     * saves + tests in one action. Modelled on InsightX Backup's per-
     * provider destination cards.
     *
     * @param string $slug Provider slug.
     * @param array  $meta Provider metadata from ISXM_Connections::providers().
     */
    private function connection_card( $slug, $meta ) {
        $config     = ISXM_Connections::get( $slug );
        $status     = ISXM_Connections::status( $slug );
        $has_secret = $config['secret_key'] !== '';
        $configured = ISXM_Connections::is_configured( $slug );
        $badge_state = $configured ? $status['state'] : 'unknown';
        ?>
        <div class="isxs-card isxs-connection-card <?php echo $configured ? '' : 'is-unconfigured'; ?>" data-provider="<?php echo esc_attr( $slug ); ?>">
            <div class="isxs-card-head">
                <div class="isxs-card-head-title">
                    <span class="isxs-provider-icon is-logo"><?php echo $this->provider_logo_svg( $slug ); ?></span>
                    <h2><?php echo esc_html( $meta['label'] ); ?></h2>
                </div>
                <span class="isxs-conn-badge" data-state="<?php echo esc_attr( $badge_state ); ?>" data-conn-badge>
                    <span class="isxs-conn-dot"></span>
                    <span data-conn-badge-text><?php echo esc_html( $this->conn_badge_text( $configured, $status ) ); ?></span>
                </span>
            </div>
            <div class="isxs-card-body">
                <div class="isxs-grid-2">
                    <div class="isxs-field">
                        <label>Endpoint URL</label>
                        <input type="url" class="isxs-conn-endpoint" placeholder="<?php echo esc_attr( $meta['endpoint_placeholder'] ); ?>" value="<?php echo esc_attr( $config['endpoint'] ); ?>" <?php disabled( $meta['endpoint_locked'] ); ?>>
                        <p class="isxs-hint"><?php echo esc_html( $meta['endpoint_hint'] ); ?></p>
                    </div>
                    <div class="isxs-field">
                        <label>Region</label>
                        <input type="text" class="isxs-conn-region" placeholder="<?php echo esc_attr( $meta['region_default'] ); ?>" value="<?php echo esc_attr( $config['region'] ); ?>">
                        <p class="isxs-hint"><?php echo esc_html( $meta['region_hint'] ); ?></p>
                    </div>
                    <div class="isxs-field">
                        <label>Bucket</label>
                        <input type="text" class="isxs-conn-bucket" placeholder="<?php echo esc_attr( $meta['bucket_placeholder'] ); ?>" value="<?php echo esc_attr( $config['bucket'] ); ?>">
                    </div>
                    <div class="isxs-field">
                        <label>Access Key</label>
                        <input type="text" class="isxs-conn-access-key" autocomplete="off" placeholder="<?php echo esc_attr( $meta['access_key_placeholder'] ); ?>" value="<?php echo esc_attr( $config['access_key'] ); ?>">
                    </div>
                    <div class="isxs-field isxs-col-span">
                        <label>Secret Key</label>
                        <input type="password" class="isxs-conn-secret-key" autocomplete="new-password" data-has-secret="<?php echo $has_secret ? '1' : '0'; ?>" value="<?php echo $has_secret ? esc_attr( str_repeat( '•', 16 ) ) : ''; ?>" placeholder="<?php echo esc_attr( $meta['secret_key_placeholder'] ); ?>">
                        <p class="isxs-hint">🔐 เข้ารหัส AES-256-GCM (มี Auth tag ป้องกันการแก้ค่า) ก่อนบันทึกลงฐานข้อมูล — ค่าที่โชว์เป็นจุดคือตัวยึดตำแหน่ง ไม่ใช่ Secret Key จริง คัดลอกไปใช้ไม่ได้</p>
                    </div>
                </div>
                <div class="isxs-toggle-row">
                    <?php $this->toggle( 'path_style', $config['path_style'], 'Path-style URL', 'จำเป็นสำหรับ Minio/Garage — ปิดสำหรับ AWS S3 (virtual-hosted)' ); ?>
                    <?php $this->toggle( 'send_public_acl', $config['send_public_acl'], 'ส่ง ACL public-read', 'เปิดเมื่อ bucket รองรับ ACL — ถ้า bucket ใช้ policy สาธารณะอยู่แล้วให้ปิดไว้' ); ?>
                </div>
                <div class="isxs-card-foot">
                    <button type="button" class="isxs-btn isxs-btn-primary isxs-conn-save-btn">บันทึก</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Status text for a connection badge.
     *
     * @param bool  $configured
     * @param array $status {state,message}
     */
    private function conn_badge_text( $configured, $status ) {
        if ( ! $configured ) {
            return 'ยังไม่ได้ตั้งค่า';
        }
        if ( $status['state'] === 'ok' ) {
            return 'เชื่อมต่อสำเร็จ';
        }
        if ( $status['state'] === 'error' ) {
            return $status['message'] !== '' ? $status['message'] : 'เชื่อมต่อไม่สำเร็จ';
        }
        return 'ยังไม่ได้ทดสอบการเชื่อมต่อ';
    }

    /**
     * Small inline status line under a Storage/Migrate tab provider picker,
     * reflecting the selected provider's saved connection status (read-only
     * here — testing/editing happens on the Connections tab).
     *
     * @param string $slug      Selected provider slug.
     * @param string $badge_id  Element id for the badge (JS updates it on picker clicks).
     */
    private function connection_status_line( $slug, $badge_id ) {
        $configured = ISXM_Connections::is_configured( $slug );
        $status     = ISXM_Connections::status( $slug );
        $state      = $configured ? $status['state'] : 'unknown';
        $text       = $configured ? $this->conn_badge_text( true, $status ) : 'ยังไม่ได้ตั้งค่า — ไปที่แท็บ “การเชื่อมต่อ”';
        ?>
        <span class="isxs-conn-badge" data-state="<?php echo esc_attr( $state ); ?>" id="<?php echo esc_attr( $badge_id ); ?>">
            <span class="isxs-conn-dot"></span>
            <span><?php echo esc_html( $text ); ?></span>
        </span>
        <?php
    }

    /**
     * Render the provider picker grid (hidden input + clickable cards),
     * shared by the Storage tab (destination) and the Migrate tab (source).
     *
     * @param string $input_id Hidden input id/name that holds the selected provider key.
     * @param string $card_class CSS class for each card button (used by admin.js click handlers).
     * @param string $active_provider Currently selected provider key.
     */
    private function provider_grid( $input_id, $card_class, $active_provider ) {
        $providers = [
            'aws'    => [ 'Amazon S3', 'logo' ],
            'minio'  => [ 'Minio', 'logo' ],
            'garage' => [ 'Garage', 'logo' ],
            'r2'     => [ 'Cloudflare R2', 'logo' ],
            'spaces' => [ 'DigitalOcean Spaces', 'logo' ],
            'gcs'    => [ 'Google Cloud Storage', 'logo' ],
            'custom' => [ 'Other (S3-compatible)', 'badge', '#64748b', '⋯' ],
        ];
        ?>
        <input type="hidden" id="<?php echo esc_attr( $input_id ); ?>" value="<?php echo esc_attr( $active_provider ); ?>">
        <div class="isxs-provider-grid">
            <?php foreach ( $providers as $key => $meta ) :
                $active      = ( $active_provider === $key );
                $configured  = ISXM_Connections::is_configured( $key );
                // "Selectable" means actually verified working, not just
                // filled in — a saved-but-failing connection (wrong keys,
                // 401, etc.) shouldn't be pickable as a live destination/source.
                $verified     = $configured && ISXM_Connections::status( $key )['state'] === 'ok';
                $card_classes = trim( $card_class . ( $active ? ' is-active' : '' ) . ( $verified ? '' : ' is-unconfigured' ) );
                $title        = $verified ? '' : ( $configured ? 'เชื่อมต่อ provider นี้ยังไม่สำเร็จ — ไปตรวจสอบที่แท็บ “การเชื่อมต่อ” ก่อน' : 'ยังไม่ได้ตั้งค่า provider นี้ — ไปตั้งค่าที่แท็บ “การเชื่อมต่อ” ก่อน' );
                ?>
                <button type="button" class="<?php echo esc_attr( $card_classes ); ?>" data-provider="<?php echo esc_attr( $key ); ?>" <?php disabled( ! $verified ); ?> title="<?php echo esc_attr( $title ); ?>">
                    <?php if ( 'logo' === $meta[1] ) : ?>
                        <span class="isxs-provider-icon is-logo"><?php echo $this->provider_logo_svg( $key ); ?></span>
                    <?php else : ?>
                        <span class="isxs-provider-icon is-badge" style="--badge-color:<?php echo esc_attr( $meta[2] ); ?>"><?php echo esc_html( $meta[3] ); ?></span>
                    <?php endif; ?>
                    <span class="isxs-provider-name"><?php echo esc_html( $meta[0] ); ?></span>
                    <span class="isxs-provider-check">✓</span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Inline brand-mark SVG for a storage provider (static markup, no user input).
     *
     * @param string $key Provider key (aws|minio|r2|spaces|gcs).
     */
    private function provider_logo_svg( $key ) {
        switch ( $key ) {
            case 'aws':
                return '<svg viewBox="0 0 512 512" xmlns:xlink="http://www.w3.org/1999/xlink"><path fill="#e05243" d="M260 348l-137 33V131l137 32z"/><path fill="#8c3123" d="M256 349l133 32V131l-133 32v186"/><g fill="#e05243"><path id="isxs-s3-a" d="M256 64v97l58 14V93zm133 67v250l26-13V143zm-133 77v97l58-8v-82zm58 129l-58 14v97l58-29z"/></g><use fill="#8c3123" transform="rotate(180 256 256)" xlink:href="#isxs-s3-a"/><path fill="#5e1f18" d="M314 175l-58 11-58-11 58-15 58 15"/><path fill="#f2b0a9" d="M314 337l-58-11-58 11 58 16 58-16"/></svg>';
            case 'minio':
                return '<svg viewBox="0 0 24 24"><path fill="#C6234A" d="M12 2 21 7v10l-9 5-9-5V7l9-5Z"/><path fill="#E4344F" d="M12 2 21 7l-9 5-9-5 9-5Z"/></svg>';
            case 'garage':
                return '<svg viewBox="0 0 30 30"><style>.isxs-gar-a{fill:#4E4E4E}.isxs-gar-b{fill:#45C8FF}.isxs-gar-c{fill:#FF9329}</style><g transform="translate(-47.531142,-150.58196)"><g transform="matrix(0.26458333,0,0,0.26458333,27.649536,132.01223)"><g transform="matrix(0.92473907,0,0,0.92473907,11.032718,11.165159)"><g transform="matrix(1.0300991,0,0,1.0300991,3.770254,-1.2763086)"><path d="m 136.06214,99.13643 c -0.8681,0.09646 -1.83266,0 -2.70078,-0.289369 L 99.794436,89.780144 c -0.868109,-0.28937 -1.736218,-0.675196 -2.507872,-1.157479 z"/><path class="isxs-gar-a" d="m 85.036565,156.14226 c 1.919127,0.0226 3.842264,-0.048 5.758577,0.0407 1.109916,0.0647 2.081695,0.96893 2.125517,2.09821 0.05763,2.83895 0.0096,5.68171 0.02535,8.52216 0.0387,0.72125 -1.165534,0.55433 -1.656924,0.86227 -2.84639,0.78316 -5.867198,1.08468 -8.793555,0.62567 -2.484003,-0.4206 -4.607002,-2.18507 -5.651194,-4.45399 -1.332604,-2.83308 -1.546544,-6.07759 -1.21852,-9.15366 0.293175,-2.57048 1.448442,-5.0874 3.473195,-6.74732 2.184175,-1.91934 5.23662,-2.62252 8.078891,-2.19703 2.061965,0.25939 4.063024,1.01333 5.768107,2.20419 -0.194486,1.20116 -0.887464,2.34273 -1.929135,2.99015 -1.865545,-1.36891 -4.253598,-2.12198 -6.568068,-1.87184 -2.02236,0.3166 -3.762605,1.87404 -4.283558,3.85841 -0.666251,2.35645 -0.668458,4.88015 -0.252316,7.28143 0.337055,1.92315 1.48217,3.89047 3.44592,4.49149 1.860151,0.60901 3.846702,0.22762 5.72889,-0.0627 0.02323,-1.64043 -0.05713,-3.28547 0.06461,-4.92211 0.04478,-0.38456 -0.694745,-0.10524 -1.004029,-0.19009 -1.009365,-0.0553 -2.115945,0.1939 -3.015314,-0.38583 -0.860219,-0.80391 -0.327291,-2.03804 -0.09646,-2.99015 z"/><path class="isxs-gar-a" d="m 109.82594,166.17374 c -0.0965,0.38583 -0.28937,0.77165 -0.57875,1.15748 -0.19291,0.38583 -0.48228,0.6752 -0.77164,0.86811 -1.25394,-0.0965 -2.31497,-0.77165 -2.99017,-1.92913 -1.15748,1.25393 -2.89369,2.02559 -4.62991,2.02559 -1.639774,0 -2.893709,-0.48229 -3.76182,-1.44685 -0.771653,-0.96457 -1.253937,-2.12205 -1.253937,-3.37598 0,-1.83268 0.57874,-3.18307 1.736221,-4.05118 1.350393,-0.96456 2.893706,-1.44685 4.533456,-1.35039 0.96458,0 1.92914,0 2.79726,0.0965 v -0.96457 c 0,-1.73622 -0.77166,-2.50787 -2.41143,-2.50787 -1.15747,0 -2.797241,0.38583 -4.919286,1.15748 -0.675197,-0.77165 -1.061024,-1.83268 -1.061024,-2.8937 2.218503,-0.96456 4.53347,-1.44685 6.94488,-1.44685 1.44686,-0.0965 2.79725,0.38583 3.95473,1.3504 0.96457,0.86811 1.5433,2.2185 1.5433,4.05117 v 6.55905 c -0.0965,1.44685 0.19291,2.2185 0.86812,2.70078 z m -8.10237,-0.77165 c 1.25394,-0.0965 2.41142,-0.57874 3.18308,-1.54331 v -2.79724 c -0.77166,-0.0965 -1.63977,-0.0965 -2.41143,-0.0965 -0.77165,-0.0965 -1.44684,0.19291 -2.02558,0.67519 -0.482291,0.48229 -0.675204,1.06103 -0.675204,1.73622 0,0.57874 0.192913,1.15748 0.578744,1.63976 0.38583,0.19292 0.86811,0.38583 1.35039,0.38583 z"/><path class="isxs-gar-a" d="m 112.43026,153.92376 c 0.0965,-0.38583 0.28937,-0.77165 0.57874,-1.15748 0.19292,-0.38583 0.48229,-0.6752 0.77165,-0.86811 1.63976,0.19291 2.8937,1.35039 3.37599,2.8937 0.86811,-1.92913 2.2185,-2.8937 4.14764,-2.8937 0.57874,0 1.25392,0.0965 1.83267,0.19291 0,1.3504 -0.28937,2.60433 -0.96456,3.76181 -0.48229,-0.0965 -0.96457,-0.19291 -1.44685,-0.19291 -1.3504,0 -2.31496,0.67519 -3.18308,2.12204 v 10.2244 c -0.67519,0.0965 -1.35039,0.19291 -1.92914,0.19291 -0.67518,0 -1.35038,-0.0965 -2.02558,-0.19291 v -10.80314 c 0,-1.5433 -0.38582,-2.60433 -1.15748,-3.27952 z"/><path class="isxs-gar-a" d="m 138.08774,166.17374 c -0.0965,0.38583 -0.28937,0.77165 -0.57874,1.15748 -0.19291,0.38583 -0.48228,0.6752 -0.77165,0.86811 -1.25394,-0.0965 -2.31496,-0.77165 -2.99017,-1.92913 -1.15747,1.25393 -2.89369,2.02559 -4.62992,2.02559 -1.63975,0 -2.8937,-0.48229 -3.7618,-1.44685 -0.77166,-0.96457 -1.25394,-2.12205 -1.25394,-3.37598 0,-1.83268 0.57874,-3.18307 1.73622,-4.05118 1.25393,-0.96456 2.8937,-1.44685 4.43701,-1.35039 0.96456,0 1.92914,0 2.79724,0.0965 v -0.96457 c 0,-1.73622 -0.77164,-2.50787 -2.41142,-2.50787 -1.15748,0 -2.79724,0.38583 -4.91929,1.15748 -0.6752,-0.77165 -1.06102,-1.83268 -1.06102,-2.8937 2.2185,-0.96456 4.53346,-1.44685 6.94488,-1.44685 1.44685,-0.0965 2.79725,0.38583 3.95473,1.3504 0.96456,0.86811 1.5433,2.2185 1.5433,4.05117 v 6.55905 c 0,1.44685 0.38583,2.2185 0.96457,2.70078 z m -8.10236,-0.77165 c 1.25393,-0.0965 2.41142,-0.57874 3.18307,-1.54331 v -2.79724 c -0.77165,-0.0965 -1.63977,-0.0965 -2.41142,-0.0965 -0.77165,-0.0965 -1.44686,0.19291 -2.02559,0.67519 -0.48228,0.48229 -0.67519,1.06103 -0.67519,1.73622 0,0.57874 0.19291,1.15748 0.57874,1.63976 0.38582,0.19292 0.8681,0.38583 1.35039,0.38583 z"/><path class="isxs-gar-a" d="m 142.04247,166.07729 c -0.96457,-1.44685 -1.44686,-3.47244 -1.44686,-6.07677 0,-2.60433 0.57875,-4.62991 1.83268,-6.07676 1.06103,-1.35039 2.70079,-2.2185 4.43701,-2.2185 1.63977,0 3.18307,0.57874 4.34055,1.63976 0.57874,-0.77165 1.54332,-1.25394 2.50787,-1.35039 0.38583,0.19291 0.6752,0.57874 0.86812,0.86811 0.19291,0.38582 0.38583,0.67519 0.57874,1.15747 -0.57874,0.48229 -0.86812,1.44685 -0.86812,2.79725 v 9.06691 c 0,3.37598 -0.57874,5.7874 -1.63975,7.23424 -1.06103,1.44685 -2.99017,2.12205 -5.49804,2.12205 -1.92914,0 -3.95472,-0.38583 -5.7874,-1.06102 0,-1.06103 0.28937,-2.12205 0.96457,-2.8937 1.35039,0.6752 2.79724,0.96457 4.34054,0.96457 1.44686,0 2.41143,-0.38583 2.89371,-1.06103 0.57874,-0.86811 0.86811,-1.92913 0.77165,-2.99015 v -1.25394 c -1.15748,0.96457 -2.50787,1.54331 -4.05118,1.54331 -1.73622,-0.0965 -3.37599,-0.96457 -4.24409,-2.41141 z m 8.19882,-2.60433 v -7.42716 c -0.6752,-0.77165 -1.73622,-1.25393 -2.79725,-1.35039 -0.86811,0 -1.73621,0.57874 -2.12205,1.35039 -0.57874,1.25394 -0.86811,2.60433 -0.77165,3.95472 0,1.73622 0.19291,2.99016 0.67519,3.76181 0.28938,0.67519 1.06103,1.15748 1.83268,1.25393 1.3504,0 2.50788,-0.57874 3.18308,-1.5433 z"/><path class="isxs-gar-b" d="m 136.73735,113.02618 18.42323,-7.42716 c 0.38583,-0.19291 0.57874,-0.57874 0.48228,-1.06102 -0.0965,-0.19292 -0.19291,-0.38583 -0.48228,-0.48229 -2.12204,-0.8681 -4.82284,-1.92913 -7.42716,-2.99015 -0.4823,-0.19291 -5.01576,3.08661 -5.40158,3.37598 l -7.90945,6.36613 c -1.83268,1.73622 -0.19291,3.27953 2.31496,2.21851 z"/><ellipse class="isxs-gar-b" cx="123.42634" cy="120.26041" rx="9.645668" ry="9.6456566"/><path d="m 136.06214,99.13643 c -0.8681,0.09646 -1.83266,0 -2.70078,-0.289369 L 99.794436,89.780144 c -0.868109,-0.28937 -1.736218,-0.675196 -2.507872,-1.157479 z"/><path class="isxs-gar-a" d="m 170.6901,161.35091 h -8.97047 c 0,1.06103 0.28937,2.02559 0.86811,2.8937 0.48228,0.6752 1.35039,1.06102 2.60432,1.06102 1.44686,-0.0965 2.89371,-0.48228 4.2441,-1.15748 0.6752,0.6752 1.06102,1.54331 1.15748,2.41142 -1.83267,1.25393 -3.95472,1.92913 -6.17323,1.83267 -2.41141,0 -4.14764,-0.77165 -5.20865,-2.31495 -1.06104,-1.54331 -1.54331,-3.5689 -1.54331,-6.07677 0,-2.50787 0.57873,-4.53346 1.73622,-6.07676 1.15747,-1.54331 2.99015,-2.41142 4.91928,-2.31496 2.12206,0 3.76182,0.6752 4.9193,1.92913 1.15748,1.35039 1.83267,3.08661 1.73622,4.91929 0,0.96456 -0.0965,1.92913 -0.28937,2.89369 z m -6.17323,-6.84841 c -1.73622,0 -2.70079,1.35039 -2.79724,3.95472 h 5.59448 v -0.38583 c 0,-0.86811 -0.19292,-1.83267 -0.67519,-2.60433 -0.48228,-0.67519 -1.3504,-0.96456 -2.12205,-0.96456 z"/><path class="isxs-gar-c" d="m 123.0405,70.199461 c -1.44685,0 -2.89371,0.28937 -4.14765,0.868109 L 76.259006,89.973057 c -0.771652,0.289369 -1.157479,1.253935 -0.868109,2.025588 0,0 0,0 0,0 0,0.09646 0,0.09646 0.09646,0.192913 l 6.848424,13.503922 h 5.980314 l -0.86811,-4.72638 c -0.09646,-0.38582 -0.675197,-3.086605 -1.253937,-5.015736 l 19.966532,6.269676 c 0.28937,1.25394 0.57874,2.41141 1.06103,3.47244 h 32.31298 c 0.38582,-1.06103 0.67519,-2.2185 0.86811,-3.47244 l 19.87007,-6.17322 c -0.57873,1.929131 -1.15747,4.62992 -1.25393,5.01574 l -0.86812,4.72637 h 5.98032 l 6.75197,-13.407459 0.0965,-0.09646 0.0965,-0.192913 c 0,0 0,0 0,0 0.0965,-0.192913 0.0965,-0.28937 0.0965,-0.482283 0,-0.675196 -0.38583,-1.253935 -0.96457,-1.543305 l -42.6339,-18.905486 c -1.54332,-0.675196 -2.99017,-1.061022 -4.53347,-0.964566 z"/><path class="isxs-gar-a" d="m 123.0405,79.073465 c -1.44685,0 -2.89371,0.28937 -4.14765,0.868109 L 76.259006,98.847061 c -0.771652,0.289369 -1.157479,1.253939 -0.868109,2.025589 0,0 0,0 0,0 0,0.0965 0,0.0965 0.09646,0.19291 l 3.665353,7.3307 h 7.909449 c -0.289371,-1.06102 -0.578742,-2.31496 -0.964568,-3.56889 l 11.285433,3.56889 h 51.507866 l 11.28542,-3.56889 c -0.38581,1.15748 -0.67518,2.50787 -0.96455,3.56889 h 7.90943 l 3.66536,-7.23424 0.0965,-0.0965 0.0965,-0.19291 c 0,0 0,0 0,0 0.0965,-0.19291 0.0965,-0.28937 0.0965,-0.48228 0,-0.6752 -0.38582,-1.25394 -0.96457,-1.543309 L 127.47751,79.941574 c -1.44686,-0.578739 -2.89371,-0.868109 -4.43701,-0.868109 z"/><path class="isxs-gar-c" d="m 171.07592,109.45728 c 0,0.19292 0,0.28937 -0.0965,0.48229 0,0 0,0 0,0 l -0.0965,0.19291 v 0 l -0.0965,0.0965 -10.32087,20.44879 c -1.44684,2.79724 -4.05116,2.70078 -3.66533,-0.0965 l 2.12203,-11.57479 c 0.0965,-0.38582 0.6752,-3.08661 1.25394,-5.01574 l -19.87014,6.17322 c -3.08661,20.35234 -29.90156,20.64171 -34.24212,0 L 86.0974,113.89428 c 0.578741,1.92914 1.157481,4.62992 1.253938,5.01575 l 2.122046,11.57478 c 0.482284,2.8937 -2.218503,2.99016 -3.665353,0.0965 L 75.390897,110.03602 c 0,-0.0964 -0.09646,-0.0964 -0.09646,-0.19291 -0.385827,-0.77165 0,-1.73622 0.771653,-2.02559 0,0 0,0 0,0 l 42.63386,-18.905486 c 2.70078,-1.157478 5.88385,-1.157478 8.58464,0 l 42.63385,18.905486 c 0.77166,0.38583 1.15748,0.96457 1.15748,1.63976 z"/><path class="isxs-gar-a" d="m 136.73735,113.02618 18.42323,-7.42716 c 0.38583,-0.19291 0.57874,-0.57874 0.48228,-1.06102 -0.0965,-0.19292 -0.19291,-0.38583 -0.48228,-0.48229 -2.12204,-0.8681 -4.82284,-1.92913 -7.42716,-2.99015 -0.4823,-0.19291 -5.01576,3.08661 -5.40158,3.37598 l -7.90945,6.36613 c -1.83268,1.73622 -0.19291,3.27953 2.31496,2.21851 z"/><ellipse class="isxs-gar-a" cx="123.42634" cy="120.26041" rx="9.645668" ry="9.6456566"/></g></g></g></g></svg>';
            case 'r2':
                return '<svg viewBox="0 0 24 24"><path fill="#F38020" d="M17.5 10.1a5.5 5.5 0 0 0-10.5-1.5A4 4 0 0 0 4 12.4 4 4 0 0 0 8 16.4h9.3a3.3 3.3 0 0 0 .2-6.3Z"/></svg>';
            case 'spaces':
                return '<svg viewBox="0 0 24 24"><path fill="#0080FF" d="M12 2a10 10 0 1 0 0 20v-4a6 6 0 1 1 0-12V2Z"/></svg>';
            case 'gcs':
                return '<svg viewBox="0 0 24 24"><path fill="#4285F4" d="M15.7 9.2a5.5 5.5 0 0 0-10.4 1.9A4 4 0 0 0 6 19h9.3a4.3 4.3 0 0 0 .4-9.8Z"/><circle cx="6.2" cy="20.6" r="1.1" fill="#EA4335"/><circle cx="9.4" cy="20.6" r="1.1" fill="#FBBC05"/><circle cx="12.6" cy="20.6" r="1.1" fill="#34A853"/></svg>';
            case 'custom':
                // Generic S3-compatible storage mark (no single vendor to brand),
                // matching the grey badge tone used for this entry everywhere else.
                return '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="7" ry="2.5" fill="#64748b"/><path fill="#64748b" fill-opacity="0.55" d="M5 5.5v13c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5v-13C19 6.9 15.9 8 12 8S5 6.9 5 5.5Z"/><path fill="#64748b" d="M5 11.2c0 1.4 3.1 2.5 7 2.5s7-1.1 7-2.5V8.7c-1.3 1-4 1.6-7 1.6s-5.7-.6-7-1.6v2.5Z"/></svg>';
        }
        return '';
    }

    /**
     * slug => inline SVG brand mark for every known provider.
     *
     * @return array<string,string>
     */
    private function provider_logo_map() {
        $map = [];
        foreach ( array_keys( ISXM_Connections::providers() ) as $slug ) {
            $map[ $slug ] = $this->provider_logo_svg( $slug );
        }
        return $map;
    }

    /**
     * Render the live URL preview card (used on both Storage and Delivery tabs).
     */
    private function url_preview_card() {
        ?>
        <div class="isxs-card">
            <div class="isxs-card-head"><h2>URL Preview</h2></div>
            <div class="isxs-card-body">
                <p class="isxs-hint">โครงสร้าง URL ของ media ตามการตั้งค่าปัจจุบัน (อัปเดตสด):</p>
                <div class="isxs-url-preview" data-url-preview>
                    <span class="isxs-url-part" data-part="scheme"><em>Scheme</em><code>https://</code></span>
                    <span class="isxs-url-part" data-part="domain"><em>Domain</em><code>—</code></span>
                    <span class="isxs-url-part" data-part="prefix"><em>Prefix</em><code>—</code></span>
                    <span class="isxs-url-part" data-part="yearmonth"><em>Year &amp; Month</em><code>—</code></span>
                    <span class="isxs-url-part" data-part="version"><em>Version</em><code>—</code></span>
                    <span class="isxs-url-part" data-part="file"><em>Filename</em><code>example.jpg</code></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Inline icon for a bulk-tool card (static markup, no user input).
     *
     * @param string $id Tool id.
     */
    private function tool_icon_svg( $id ) {
        switch ( $id ) {
            case 'offload':
                // Upload arrow into a picture frame.
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M12 3v9"/><path d="m8.5 8.5 3.5-3.5 3.5 3.5"/></svg>';
            case 'retry_failed':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>';
            case 'download':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>';
            case 'remove':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h10l1-14"/></svg>';
            case 'wc_downloads':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4.5 4.5 0 0 0-6 6L3 18l3 3 5.7-5.7a4.5 4.5 0 0 0 6-6L14 13l-3-3 3.7-3.7Z"/></svg>';
            case 'migrate':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h4v4"/><path d="m20 3-8 8"/><path d="M8 21H4v-4"/><path d="m4 21 8-8"/></svg>';
            case 'sync':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
        }
        return '';
    }

    /**
     * Render one bulk-tool card (Bulk Management Tools style: icon + title
     * + action button on top, divider, then description + progress).
     *
     * @param string $id      Tool id (offload|download|remove).
     * @param string $title   Card title.
     * @param string $desc    Description.
     * @param string $button  Button label.
     * @param string $variant '' or 'danger'.
     * @param bool   $hidden  Render the card collapsed (JS un-hides it when
     *                        the stats it depends on become non-zero).
     * @param string $extra   Optional extra HTML rendered after the description.
     */
    private function tool_card( $id, $title, $desc, $button, $variant = '', $hidden = false, $extra = '' ) {
        ?>
        <div class="isxs-card isxs-tool" data-tool="<?php echo esc_attr( $id ); ?>"<?php echo $hidden ? ' hidden' : ''; ?>>
            <div class="isxs-tool-top">
                <span class="isxs-tool-icon"><?php echo $this->tool_icon_svg( $id ); ?></span>
                <h2><?php echo esc_html( $title ); ?></h2>
                <div class="isxs-tool-actions">
                    <button type="button" class="isxs-btn isxs-btn-ghost isxs-tool-stop" hidden>หยุด</button>
                    <button type="button" class="isxs-btn isxs-btn-danger isxs-tool-cancel" hidden>ยกเลิก</button>
                    <button type="button" class="isxs-btn <?php echo $variant === 'danger' ? 'isxs-btn-danger' : 'isxs-btn-primary'; ?> isxs-tool-run"><?php echo esc_html( $button ); ?></button>
                </div>
            </div>
            <div class="isxs-tool-body">
                <p><?php echo esc_html( $desc ); ?></p>
                <?php echo $extra; ?>
                <div class="isxs-tool-progress" hidden>
                    <div class="isxs-progress-track"><div class="isxs-progress-fill"></div></div>
                    <span class="isxs-tool-percent"></span>
                    <span class="isxs-tool-count">0 รายการ</span>
                </div>
                <p class="isxs-tool-eta"></p>
                <ul class="isxs-tool-errors" hidden></ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Sync/Verify card — reconciles `_isxs_offload` tracking
     * meta against what's actually in the bucket (see ISXM_Sync). This is
     * NOT a tool_card(): it's driven entirely by its own client-side batch
     * loop in admin.js (isxs_sync_scan / isxs_sync_apply /
     * isxs_sync_orphan_cleanup), not the generic server-side job engine —
     * so its run button deliberately does NOT use the `isxs-tool-run`
     * class or an `isxs-tool` wrapper, which would otherwise get picked up
     * by the generic job-engine click handlers (`.isxs-tool .isxs-tool-run`)
     * bound elsewhere in admin.js.
     */
    private function sync_card() {
        $last_run = ISXM_Sync::last_run();
        // The badge carries the verdict, not just "when" — green when the
        // last scan found everything in sync, red when it found
        // differences. `last_clean()` is null for a scan from a version
        // before the verdict was recorded, which falls back to the old
        // "checked N ago" wording rather than claiming either outcome.
        $last_clean = ISXM_Sync::last_clean();
        if ( $last_run === null ) {
            $badge_state = 'unknown';
            $badge_text  = 'ยังไม่เคยตรวจสอบกับ bucket จริง';
        } elseif ( $last_clean === true ) {
            $badge_state = 'ok';
            $badge_text  = 'ตรงกันทั้งหมดแล้ว';
        } elseif ( $last_clean === false ) {
            $badge_state = 'error';
            $badge_text  = 'พบรายการไม่ตรงกัน';
        } else {
            $badge_state = 'ok';
            $badge_text  = 'ตรวจล่าสุดเมื่อ ' . human_time_diff( $last_run, time() ) . 'ที่แล้ว';
        }
        // When the verdict replaces the timestamp in the label, the
        // timestamp still has to be reachable — hence the tooltip.
        $badge_title = $last_run === null
            ? ''
            : 'ตรวจล่าสุดเมื่อ ' . human_time_diff( $last_run, time() ) . 'ที่แล้ว';
        ?>
        <div class="isxs-card isxs-sync-card">
            <div class="isxs-tool-top">
                <span class="isxs-tool-icon"><?php echo $this->tool_icon_svg( 'sync' ); ?></span>
                <h2>ซิงก์ให้ตรงกับ bucket</h2>
                <div class="isxs-tool-actions">
                    <span class="isxs-conn-badge isxs-sync-status" data-state="<?php echo esc_attr( $badge_state ); ?>" title="<?php echo esc_attr( $badge_title ); ?>">
                        <span class="isxs-conn-dot"></span>
                        <span class="isxs-sync-status-text"><?php echo esc_html( $badge_text ); ?></span>
                    </span>
                    <button type="button" class="isxs-btn isxs-btn-primary isxs-sync-run">ซิงก์ให้ตรงกับ bucket</button>
                </div>
            </div>
            <div class="isxs-tool-body">
                <p>ตรวจ bucket จริงเทียบกับข้อมูลที่ปลั๊กอินติดตามไว้ — เจอไฟล์ที่ถูกลบออกจาก bucket นอกปลั๊กอิน (console, CLI, สคริปต์ลบไฟล์) จะล้าง meta ค้างให้ Offload/ย้ายข้อมูลอัปโหลดใหม่ให้เอง</p>
                <div class="isxs-tool-progress" hidden>
                    <div class="isxs-progress-track"><div class="isxs-progress-fill"></div></div>
                    <span class="isxs-tool-percent"></span>
                    <span class="isxs-tool-count">0 รายการ</span>
                </div>
                <p class="isxs-tool-eta"></p>
                <ul class="isxs-tool-errors" hidden></ul>
                <div class="isxs-sync-result" hidden>
                    <div class="isxs-sync-summary"></div>
                    <div class="isxs-sync-sample"></div>
                    <button type="button" class="isxs-btn isxs-btn-danger isxs-sync-apply" hidden>ล้าง meta ค้าง</button>
                    <div class="isxs-sync-orphan-actions" hidden>
                        <button type="button" class="isxs-btn isxs-btn-danger isxs-sync-orphan-run">ลบ orphan objects</button>
                        <p class="isxs-hint">object ใน prefix ปัจจุบันที่ไม่มี media ใน WordPress ตรงกัน</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
