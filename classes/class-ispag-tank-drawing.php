<?php

class ISPAG_Tank_Drawing {
    protected $wpdb;
    protected $table_article;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_article = $wpdb->prefix . 'achats_details_commande';
    }

    public static function init(){
        if (self::$instance === null) {
            self::$instance = new self();
        }
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);

        add_filter('ispag_get_last_drawing_url', [self::$instance, 'get_last_tank_plan_for_article'], 10, 2);
        add_filter('ispag_get_last_drawing_id', [self::$instance, 'get_last_drawing_id'], 10, 2);
        add_action('wp_ajax_ispag_validate_pdf_plan', [self::$instance, 'ispag_validate_pdf_plan_callback']);

        add_shortcode('ispag_plan_viewer', [self::$instance, 'plan_viewer']);

        // Page de consultation/validation autonome (ne dépend d'aucune page WordPress ni des règles de réécriture)
        add_action('wp_ajax_ispag_plan_viewer_page', [self::$instance, 'render_plan_viewer_page']);
        add_action('wp_ajax_ispag_plan_request_changes', [self::$instance, 'ajax_request_plan_changes']);
        add_filter('ispag_plan_validation_url', [self::$instance, 'plan_validation_url'], 10, 3);

    }
    public static function enqueue_assets() {

        wp_enqueue_script('ispag-drawing-validation', plugin_dir_url(__FILE__) . '../assets/js/drawing-validation.js', ['jquery'], false, true);

        wp_localize_script('ispag-drawing-validation', 'ispag_validation', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'jsonUrl' => plugins_url('../assets/json/tank_data.json', __FILE__),
            'nonce'    => wp_create_nonce('ispag_tank_nonce'),
            'confirmMessage' => __('Would you really validate this drawing', 'creation-reservoir'),
            'validatingMessage' => __('Validating', 'creation-reservoir'),
            'drawingValidatedMessage' => __('Drawing successfully validated', 'creation-reservoir'),
            'validateDrawingButton' => __('Validate drawing', 'creation-reservoir'),
        ]);
    }

    public function get_last_tank_plan_for_article($title, $article_id) {
        global $wpdb;

        if (empty($article_id) || !is_numeric($article_id)) {
            return null;
        }

        $media_id = $this->get_last_drawing_id(null, $article_id);
        if ($media_id && is_numeric($media_id)) {
            return wp_get_attachment_url($media_id);
        }

        return null;
    }

    public function get_last_drawing_id($title, $article_id) {
        global $wpdb;

        if (empty($article_id) || !is_numeric($article_id)) {
            return null;
        }

        $allowed_types = ['product_drawing', 'drawingApproval', 'drawingModification', 'sketch'];
        $placeholders = implode(',', array_fill(0, count($allowed_types), '%s'));

        $sql = "
            SELECT IdMedia 
            FROM {$wpdb->prefix}achats_historique
            WHERE Historique = %d
            AND ClassCss IN ($placeholders)
            ORDER BY dateReadable DESC
            LIMIT 1
        ";

        // article_id doit être en premier
        $query_args = array_merge([$article_id], $allowed_types);
        $prepared_sql = $wpdb->prepare($sql, ...$query_args);

        if ($prepared_sql === false) {
            return null;
        }

        $media_id = $wpdb->get_var($prepared_sql);
        if ($media_id && is_numeric($media_id)) {
            return $media_id;
        }

        return null;
    }

    /** URL de la page de validation d'un plan. */
    public function plan_validation_url($default, $article_id, $drawing_id) {
        return add_query_arg([
            'action'     => 'ispag_plan_viewer_page',
            'drawing_id' => (int) $drawing_id,
            'article_id' => (int) $article_id,
        ], admin_url('admin-ajax.php'));
    }

    /** Droit de modifier / valider le plan d'un article : gestionnaire de commandes ou personne concernée par le projet (AssociatedContactIDs). */
    private function can_validate_plan($article) {
        if (current_user_can('manage_order')) return true;
        return $article && class_exists('ISPAG_Projet_Repository')
            && ISPAG_Projet_Repository::is_user_project_owner($article->hubspot_deal_id);
    }

    /** Page autonome : le PDF à consulter, annotable (crayon / texte), avec « Valider » ou « Demander des modifications ». */
    public function render_plan_viewer_page() {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(add_query_arg($_GET, admin_url('admin-ajax.php'))));
            exit;
        }
        $drawing_id = (int) ($_GET['drawing_id'] ?? 0);
        $article_id = (int) ($_GET['article_id'] ?? 0);
        $article    = $article_id ? apply_filters('ispag_get_article_by_id', null, $article_id) : null;
        $url        = $drawing_id ? wp_get_attachment_url($drawing_id) : '';

        if (!$article || !$url || !$this->can_validate_plan($article)) {
            wp_die(esc_html__('Drawing not found or access denied.', 'creation-reservoir'), '', ['response' => 404]);
        }

        $user = wp_get_current_user();
        $name = trim($user->user_firstname . ' ' . $user->user_lastname) ?: $user->display_name;
        $cfg  = [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('ispag_plan_validation'),
            'drawingId'  => $drawing_id,
            'articleId'  => $article_id,
            'pdfUrl'     => $url,
            'workerSrc'  => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js',
            'i18n'       => [
                'busy'             => __('Sending', 'creation-reservoir'),
                'validateLabel'    => '✅ ' . __('Validate drawing', 'creation-reservoir'),
                'changesLabel'     => '✏️ ' . __('Request modifications', 'creation-reservoir'),
                'validateDisabled' => __('You added annotations: send them as a modification request instead.', 'creation-reservoir'),
                'hintClean'        => __('Draw or write on the plan to request modifications, or validate it as it is.', 'creation-reservoir'),
                'hintModified'     => __('Annotations added: the drawing can no longer be validated, send your modification request.', 'creation-reservoir'),
                'confirmValidate'  => sprintf(__('Do you really want to validate this drawing? It will be marked "Validated by %s" with today\'s date.', 'creation-reservoir'), $name),
                'confirmChanges'   => __('Send your annotations as a modification request? A new version of the drawing will be created and they cannot be edited afterwards.', 'creation-reservoir'),
                'confirmClear'     => __('Remove all annotations?', 'creation-reservoir'),
            ],
        ];
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e('Validation plan', 'creation-reservoir'); ?></title>
            <style>
                html, body { margin: 0; font-family: system-ui, sans-serif; background: #e9ebee; }
                .bar { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 8px 16px; background: #f6f7f7; border-bottom: 1px solid #ccc; }
                .bar .grow { flex: 1 1 auto; }
                .bar button { border: 1px solid #bbb; background: #fff; border-radius: 6px; padding: 7px 12px; font-size: 14px; cursor: pointer; }
                .bar button.is-active { background: #dbeafe; border-color: #2271b1; }
                .bar button:disabled { opacity: .45; cursor: not-allowed; }
                .bar #btn-validate-plan { background: #00a32a; border-color: #00a32a; color: #fff; }
                .bar #btn-request-changes { background: #d97706; border-color: #d97706; color: #fff; }
                #plan-hint { font-size: 13px; color: #555; flex-basis: 100%; }
                #plan-pages { max-width: 1100px; margin: 16px auto; padding: 0 8px; }
                .plan-page { position: relative; margin: 0 auto 16px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
                .plan-page canvas { position: absolute; inset: 0; width: 100%; height: 100%; }
                .plan-overlay { touch-action: none; }
                #plan-pages[data-tool="pen"] .plan-overlay { cursor: crosshair; }
                #plan-pages[data-tool="text"] .plan-overlay { cursor: text; }
                .plan-text-input { position: absolute; z-index: 5; min-width: 120px; background: rgba(255,255,255,.85); border: 1px dashed #2271b1; font-family: Arial, sans-serif; font-weight: bold; resize: none; padding: 0; line-height: 1.2; }
            </style>
        </head>
        <body>
            <div class="bar">
                <button type="button" data-tool="pen" class="is-active">✏️ <?php esc_html_e('Pen', 'creation-reservoir'); ?></button>
                <button type="button" data-tool="text">🔤 <?php esc_html_e('Text', 'creation-reservoir'); ?></button>
                <input type="color" id="plan-color" value="#d63638" title="<?php esc_attr_e('Color', 'creation-reservoir'); ?>">
                <select id="plan-width" title="<?php esc_attr_e('Thickness', 'creation-reservoir'); ?>">
                    <option value="2">2</option><option value="4" selected>4</option><option value="8">8</option>
                </select>
                <button type="button" id="plan-undo">↩️ <?php esc_html_e('Undo', 'creation-reservoir'); ?></button>
                <button type="button" id="plan-clear">🗑️ <?php esc_html_e('Clear', 'creation-reservoir'); ?></button>
                <span class="grow"></span>
                <button type="button" id="btn-request-changes" disabled>✏️ <?php esc_html_e('Request modifications', 'creation-reservoir'); ?></button>
                <button type="button" id="btn-validate-plan">✅ <?php esc_html_e('Validate drawing', 'creation-reservoir'); ?></button>
                <span id="plan-hint"></span>
            </div>
            <div id="plan-pages" data-tool="pen"></div>

            <script>window.ispagPlanCfg = <?php echo wp_json_encode($cfg); ?>;</script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
            <script src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/js/plan-annotator.js'); ?>?v=<?php echo esc_attr(@filemtime(plugin_dir_path(__FILE__) . '../assets/js/plan-annotator.js')); ?>"></script>
        </body>
        </html>
        <?php
        exit;
    }

    /** Charge FPDF + FPDI (fournis par le plugin Project Manager). */
    private function load_fpdi() {
        if (class_exists('\setasign\Fpdi\Fpdi')) return;
        $dir = ispag_project_manager_dir() . 'libs/';
        if (file_exists($dir . 'fpdf/fpdf.php')) require_once $dir . 'fpdf/fpdf.php';
        if (!file_exists($dir . 'fpdi/autoload.php')) {
            if (ob_get_length()) ob_end_clean();
            wp_send_json_error('Librairie FPDI introuvable dans : ' . $dir . 'fpdi/autoload.php');
        }
        require_once $dir . 'fpdi/autoload.php';
    }

    /**
     * Demande de modifications : le client a annoté le plan. Les annotations (un PNG transparent par page) sont
     * incrustées dans une copie du PDF, enregistrée comme nouveau document « drawingModification » de l'article.
     * Le plan n'est alors plus validable tant qu'une nouvelle version n'a pas été déposée.
     */
    public function ajax_request_plan_changes() {
        global $wpdb;
        $drawing_id = (int) ($_POST['drawing_id'] ?? 0);
        $article_id = (int) ($_POST['article_id'] ?? 0);
        $article    = $article_id ? apply_filters('ispag_get_article_by_id', null, $article_id) : null;
        $nonce_ok   = isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ispag_plan_validation');
        if (!$nonce_ok || !is_user_logged_in() || !$article || !$this->can_validate_plan($article)) {
            wp_send_json_error('Not authorized.');
        }
        $original_path = $drawing_id ? get_attached_file($drawing_id) : '';
        if (!$original_path || !file_exists($original_path)) {
            wp_send_json_error('PDF file not found.');
        }

        // Annotations reçues : overlay_<n° de page> (PNG)
        $overlays = [];
        foreach ($_FILES as $key => $f) {
            if (!preg_match('/^overlay_(\d+)$/', $key, $m) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) continue;
            $info = @getimagesize($f['tmp_name']);
            if (!$info || $info[2] !== IMAGETYPE_PNG || $f['size'] > 15 * 1024 * 1024) continue;
            $overlays[(int) $m[1]] = $f['tmp_name'];
        }
        if (!$overlays) {
            wp_send_json_error('No annotation received.');
        }

        $this->load_fpdi();
        if (ob_get_length()) ob_clean();

        try {
            $pdf = new \setasign\Fpdi\Fpdi();
            $source = $this->decompress_pdf_for_fpdi($original_path);
            $pageCount = $pdf->setSourceFile($source);
            for ($i = 1; $i <= $pageCount; $i++) {
                $tpl  = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
                if (isset($overlays[$i])) {
                    $pdf->Image($overlays[$i], 0, 0, $size['width'], $size['height'], 'PNG');
                }
            }

            $upload   = wp_upload_dir();
            $filename = 'modif_' . time() . '_' . basename($original_path);
            $target   = trailingslashit($upload['path']) . $filename;
            $pdf->Output($target, 'F');

            $attach_id = wp_insert_attachment([
                'guid'           => trailingslashit($upload['url']) . $filename,
                'post_mime_type' => 'application/pdf',
                'post_title'     => 'Modification request - ' . $article_id,
                'post_content'   => '',
                'post_status'    => 'inherit',
            ], $target);
            if (is_wp_error($attach_id) || !$attach_id) {
                throw new Exception('Attachment creation failed.');
            }

            $article_achat = apply_filters('ispag_get_achat_article_by_project_article_id', null, $article_id);
            $deal_id  = (int) $article->hubspot_deal_id;
            $achat_id = $article_achat ? (int) $article_achat->IdCommande : 0;
            $user_id  = get_current_user_id();

            $wpdb->insert($wpdb->prefix . 'achats_historique', [
                'hubspot_deal_id' => $deal_id,
                'purchase_order'  => $achat_id,
                'Date'            => time(),
                'dateReadable'    => current_time('mysql'),
                'IdUser'          => $user_id,
                'Historique'      => $article_id,
                'IdMedia'         => $attach_id,
                'is_task'         => 0,
                'is_done'         => 0,
                'ClassCss'        => 'drawingModification',
            ], ['%d', '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s']);

            if (class_exists('ISPAG_Notifications_Manager')) {
                $who = get_userdata($user_id);
                $deal_creator = class_exists('ISPAG_Project_Details_Repository') ? (new ISPAG_Project_Details_Repository())->get_deal_created_by($deal_id) : 0;
                ISPAG_Notifications_Manager::send(
                    array_filter([$deal_creator, 1]),
                    'product_manager',
                    sprintf(esc_html__('✏️ Modifications requested: %s', 'ispag-crm'), esc_html($article_id)),
                    sprintf(esc_html__('<strong>%1$s</strong> annotated the drawing and requested modifications.<br>- <strong>Article ID</strong>: %2$s<br>- <strong>Deal ID</strong>: %3$s', 'ispag-crm'),
                        esc_html($who ? $who->display_name : ''), esc_html($article_id), esc_html($deal_id)),
                    'project-detail/' . $deal_id . '/',
                    $deal_id
                );
            }

            if (ob_get_length()) ob_clean();
            wp_send_json_success('Modification request saved.');
        } catch (Exception $e) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error('PDF error: ' . $e->getMessage());
        }
    }

    public function plan_viewer(){
        $drawing_id = isset($_GET['drawing_id']) ? (int) $_GET['drawing_id'] : 0;
        if (!$drawing_id) return "No drawing found.";

        $article_id = isset($_GET['article_id']) ? (int) $_GET['article_id'] : 0;
        if (!$article_id) return "No article found.";

        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        // Validation réservée au gestionnaire de commandes et aux personnes concernées par le projet
        if (!is_user_logged_in()) {
            $login = wp_login_url(add_query_arg(['drawing_id' => $drawing_id, 'article_id' => $article_id], get_permalink()));
            return '<p><a href="' . esc_url($login) . '">' . esc_html__('Log in to view and validate this drawing.', 'creation-reservoir') . '</a></p>';
        }
        if (!$article || !$this->can_validate_plan($article)) {
            return esc_html__('Drawing not found or access denied.', 'creation-reservoir');
        }
        $url = $article->last_drawing_url;
        if (!$url) return "PDF introuvable.";

        $user = wp_get_current_user();
        $prenom = $user->user_firstname;
        $nom = $user->user_lastname;
        $display_name = trim("{$prenom} {$nom}");
        $date = date('d/m/Y');

        $btn = "
            <div style='margin-top:20px; text-align:center;'>
                <button id='btn-validate-plan' class='ispag-btn' data-id='{$drawing_id}' data-article='{$article_id}' data-nonce='" . esc_attr(wp_create_nonce('ispag_plan_validation')) . "'>
                    ✅ " . __('Validate drawing', 'creation-reservoir') . "
                </button>
            </div>";
        $script = "
            <script>
                
            </script>
        ";
        return $btn . "<iframe src='$url' width='100%' height='800px' style='border:none;'></iframe>" . $btn . $script;

    }

    private function decompress_pdf_for_fpdi($sourcePdf, $outputPdf = null) {
        if (!file_exists($sourcePdf)) {
            throw new Exception("Fichier PDF introuvable : $sourcePdf");
        }

        // Définir le fichier de sortie
        if (!$outputPdf) {
            $outputPdf = sys_get_temp_dir() . '/' . uniqid('decompressed_') . '.pdf';
        }

        // Commande Ghostscript (attention à la sécurité si chemins dynamiques)
        $command = "gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dNOPAUSE -dQUIET -dBATCH "
                . "-sOutputFile=" . escapeshellarg($outputPdf) . " "
                . escapeshellarg($sourcePdf);

        // Exécuter la commande
        exec($command, $output, $resultCode);

        if ($resultCode !== 0 || !file_exists($outputPdf)) {
            throw new Exception("Error while decompressing the PDF with Ghostscript.");
        }

        return $outputPdf;
    }

    public function ispag_validate_pdf_plan_callback() {
        global $wpdb;

        // 1. Définition du chemin vers la librairie dans l'AUTRE plugin
        $fpdi_path = ispag_project_manager_dir() . 'libs/fpdi/autoload.php';
        $fpdf_path = ispag_project_manager_dir() . 'libs/fpdf/fpdf.php'; // FPDI a besoin de FPDF
        
        // 2. Chargement manuel des fichiers si la classe n'existe pas
        if ( ! class_exists( '\setasign\Fpdi\Fpdi' ) ) {
            if ( file_exists( $fpdf_path ) ) {
                require_once( $fpdf_path );
            }
            if ( file_exists( $fpdi_path ) ) {
                require_once( $fpdi_path );
            } else {
                // Si le fichier n'est pas trouvé, on arrête proprement avant le Fatal Error
                if (ob_get_length()) ob_end_clean();
                wp_send_json_error( "Librairie FPDI introuvable dans : " . $fpdi_path );
            }
        }

        // 3. Nettoyage du tampon pour éviter les erreurs JSON
        if (ob_get_length()) ob_clean();;

        $drawing_id = isset($_POST['drawing_id']) ? (int) $_POST['drawing_id'] : 0;
        $article_id = isset($_POST['article_id']) ? (int) $_POST['article_id'] : 0;

        $nonce_ok = isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ispag_plan_validation');
        $article_check = $article_id ? apply_filters('ispag_get_article_by_id', null, $article_id) : null;
        if (!$nonce_ok || !is_user_logged_in() || !$this->can_validate_plan($article_check)) {
            ob_end_clean();
            wp_send_json_error('Not authorized.');
        }
        // Nom et date calculés côté serveur (jamais pris dans la requête)
        $current = wp_get_current_user();
        $user = trim($current->user_firstname . ' ' . $current->user_lastname) ?: $current->display_name;
        $date = date_i18n('d/m/Y', current_time('timestamp'));

        if (!$drawing_id || !$article_id || !$user || !$date) {
            ob_end_clean();
            wp_send_json_error("Missing data.");
        }

        $original_path = get_attached_file($drawing_id);
        if (!file_exists($original_path)) {
            ob_end_clean();
            wp_send_json_error("PDF file not found.");
        }

        try {
            $pdf = new \setasign\Fpdi\Fpdi();
            // Attention : cette méthode doit être robuste !
            $original_path = $this->decompress_pdf_for_fpdi($original_path);
            $pageCount = $pdf->setSourceFile($original_path);

            for ($i = 1; $i <= $pageCount; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
                $pdf->SetFont('Arial', '', 10);
                $pdf->SetTextColor(0, 102, 0);
                $pdf->SetXY(10, $size['height'] - 30); // Remonté un peu pour être sûr qu'il soit visible
                
                $text = "Validated by : $user on : $date";
                // Nettoyage UTF-8 vers ISO pour FPDF
                $pdf->MultiCell(0, 8, iconv('UTF-8', 'windows-1252', $text));
            }

            $wp_upload_dir = wp_upload_dir();
            $ulpoadedFileName = 'valid_' . time() . '_' . basename($original_path);
            $uploadedfile = trailingslashit($wp_upload_dir['path']) . $ulpoadedFileName;
            
            $pdf->Output($uploadedfile, 'F');

            // Création de l'attachement
            $attachment = array(
                'guid'           => trailingslashit($wp_upload_dir['url']) . $ulpoadedFileName,
                'post_mime_type' => 'application/pdf',
                'post_title'     => 'Validated Plan - ' . $article_id,
                'post_content'   => '',
                'post_status'    => 'inherit'
            );

            $attach_id = wp_insert_attachment($attachment, $uploadedfile);

            if (is_wp_error($attach_id) || !$attach_id) {
                throw new Exception("Attachment creation failed.");
            }

            // Récupération des données liées
            $article = apply_filters('ispag_get_article_by_id', null, $article_id);
            $article_achat = apply_filters('ispag_get_achat_article_by_project_article_id', null, $article_id);

            if (!$article || !$article_achat) {
                throw new Exception("Article or Purchase data not found.");
            }

            $deal_id = $article->hubspot_deal_id;
            $achat_id = $article_achat->IdCommande;
            $userId = get_current_user_id();

            // Insertion Historique
            $wpdb->insert(
                $wpdb->prefix . 'achats_historique',
                [
                    'hubspot_deal_id' => $deal_id,
                    'purchase_order'  => $achat_id,
                    'Date'            => time(),
                    'dateReadable'    => current_time('mysql'), // Synchro avec l'heure du site WP
                    'IdUser'          => $userId,
                    'Historique'      => $article_id,
                    'IdMedia'         => $attach_id,
                    'is_task'         => 0, // Obligatoire (NOT NULL)
                    'is_done'         => 0, // Obligatoire (NOT NULL)
                    'ClassCss'        => 'drawingApproval'
                ],
                [
                    '%d', // hubspot_deal_id
                    '%d', // purchase_order
                    '%d', // Date
                    '%s', // dateReadable
                    '%d', // IdUser
                    '%s', // Historique
                    '%d', // IdMedia
                    '%d', // is_task
                    '%d', // is_done
                    '%s'  // ClassCss
                ]
            );

            // Update Statut Commande
            $wpdb->update(
                $wpdb->prefix . 'achats_details_commande',
                ['DrawingApproved' => '1'],
                ['Id' => $article_achat->Id] // Utilisation de l'ID de la commande, pas de l'article projet !
            );

            // // Telegram : On notifie seulement si l'utilisateur actuel n'est PAS un gestionnaire
            // if ( ! current_user_can( 'manage_order' ) ) {
                // do_action('ispag_send_telegram_notification', null, 'drawing_validated', true, true, $deal_id, true);
            // }

            // --- NOTIFICATION À L'ADMIN QU'UN PLAN A ÉTÉ VALIDÉ ---
            if (class_exists('ISPAG_Notifications_Manager')) {
                $current_user = get_userdata($userId);
                $deal_repo = new ISPAG_Project_Details_Repository();
                $deal_creator = $deal_repo->get_deal_created_by($deal_id);
                $user_display_name = $current_user ? $current_user->display_name : $user;

                $title = sprintf(
                    esc_html(__('✅ Plan Validated: %s', 'ispag-crm')),
                    esc_html($article_id)
                );

                $message = sprintf(
                    esc_html(__(
                        'A plan has been validated by <strong>%1$s</strong> on %2$s.<br>
                        - <strong>Article ID</strong>: %3$s<br>
                        - <strong>Deal ID</strong>: %4$s<br>
                        - <strong>Purchase Order</strong>: %5$s',
                        'ispag-crm'
                    )),
                    esc_html($user_display_name),
                    esc_html($date),
                    esc_html($article_id),
                    esc_html($deal_id),
                    esc_html($achat_id)
                );

                $project_url = 'project-detail/' . $deal_id . '/';

                ISPAG_Notifications_Manager::send(
                    [$deal_creator, 1], // Destinataire : admin (ID = 1)
                    'product_manager', // Type de notification (à adapter si besoin)
                    $title,
                    $message,
                    $project_url, // URL vers le projet
                    $deal_id // ID du deal
                );
            }

            // Nettoyage final

            // Nettoyage final avant envoi JSON
            if (ob_get_length()) ob_clean();
            wp_send_json_success("Drawing validated successfully.");

        } catch (Exception $e) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error("PDF error: " . $e->getMessage());
        }
    }
    
}