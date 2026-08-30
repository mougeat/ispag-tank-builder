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

    public function plan_viewer(){
        $drawing_id = isset($_GET['drawing_id']) ? (int) $_GET['drawing_id'] : 0;
        if (!$drawing_id) return "Aucun plan trouvé.";

        $article_id = isset($_GET['article_id']) ? (int) $_GET['article_id'] : 0;
        if (!$article_id) return "Aucun article trouvé.";

        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        $url = $article->last_drawing_url;
        if (!$url) return "PDF introuvable.";

        $user = wp_get_current_user();
        $prenom = $user->user_firstname;
        $nom = $user->user_lastname;
        $display_name = trim("{$prenom} {$nom}");
        $date = date('d/m/Y');

        $btn = "
            <div style='margin-top:20px; text-align:center;'>
                <button id='btn-validate-plan' class='ispag-btn' data-id='{$drawing_id}' data-article='{$article_id}' data-user='{$display_name}' data-date='{$date}'>
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
            throw new Exception("Erreur lors de la décompression du PDF via Ghostscript.");
        }

        return $outputPdf;
    }

    public function ispag_validate_pdf_plan_callback() {
        global $wpdb;

        // 1. Définition du chemin vers la librairie dans l'AUTRE plugin
        $fpdi_path = WP_PLUGIN_DIR . '/ispag-project-manager/libs/fpdi/autoload.php';
        $fpdf_path = WP_PLUGIN_DIR . '/ispag-project-manager/libs/fpdf/fpdf.php'; // FPDI a besoin de FPDF
        
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
        $user = sanitize_text_field($_POST['user'] ?? '');
        $date = sanitize_text_field($_POST['date'] ?? '');

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