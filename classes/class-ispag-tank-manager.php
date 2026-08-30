<?php

class ISPAG_Tank_Manager {
    public static function init() {

        require_once __DIR__ . '/class-ispag-tank-designer.php';
        require_once __DIR__ . '/class-ispag-plate-heat-exchanger-designer.php';
        require_once __DIR__ . '/class-ispag-tank-description.php';
        require_once __DIR__ . '/class-ispag-tank-fittings.php';
        require_once __DIR__ . '/class-ispag-tank-svg-generator.php';
        require_once __DIR__ . '/class-ispag-tank-svg-top-view-generator.php';
        require_once __DIR__ . '/class-ispag-tank-drawing.php';
        require_once __DIR__ . '/class-ispag-tank-welding.php';
        require_once __DIR__ . '/class-ispag-tank-welding-certificat.php';
        require_once __DIR__ . '/class-ispag-tank-welding-auto-saver.php';
        require_once __DIR__ . '/class-ispag-tank-insulation.php';
        require_once __DIR__ . '/class-ispag-tank-insulation-auto-saver.php';
        require_once __DIR__ . '/class-ispag-tank-exchanger.php';
        require_once __DIR__ . '/class-ispag-existing-tanks-table.php';
        require_once __DIR__ . '/class-ispag-tank-3d-renderer.php';
        require_once __DIR__ . '/class-ispag-tank-dxf-exporter.php';
        require_once __DIR__ . '/class-ispag-tank-repository.php';
        global $ispag_tank_designer;
        $ispag_tank_designer = new ISPAG_Tank_Designer();
        ISPAG_Plate_Heat_exchanger_Designer::init();
        // $ispag_tank_description = new ISPAG_Tank_Description();

        // Charge tous les assets nécessaires
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        // Charge le script conditionnellement
        add_action('wp_enqueue_scripts', [self::class, 'conditionally_enqueue_restrictions']);
        add_action('ispag_validate_drawing', [self::class, 'validate_drawing'], 10, 5);
        add_action('ispag_save_drawing', [self::class, 'save_drawing'], 10, 5);
        add_action('wp_ajax_ispag_generate_technical_sheet_pdf', [self::class, 'ispag_ajax_generate_technical_sheet']);
        add_action('wp_ajax_ispag_ajax_generate_sketch', [self::class, 'ispag_ajax_generate_sketch']);
        add_filter('ispag_get_technical_sheet_btn', [self::class, 'get_technical_sheet_btn'], 10, 3);
        add_filter('ispag_get_sketch_btn', [self::class, 'get_sketch_btn'], 10, 3);
        add_action('ispag_delete_tank_with_article_id', [self::class, 'delete_tank_with_article_id'],10,2);
        // add_filter('ispag_get_sketch_btn', [self::class, 'get_sketch_btn'], 10, 2);
        add_filter('ispag_get_related_tank', [self::class, 'get_related_tank'], 10, 3);


        add_action('admin_post_generate_ispag_nameplate', [self::class, 'handle_nameplate_generation']);
        add_filter('ispag_get_namesplate_btn', [self::class, 'get_namesplate_btn'], 10, 2);
        
        
    }

    public static function enqueue_assets() {
        // Styles
        wp_enqueue_style('ispag-tank-builder', plugin_dir_url(__FILE__) . '../assets/css/tank-builder.css');

        // Scripts principaux
        wp_enqueue_script('ispag-tank-builder', plugin_dir_url(__FILE__) . '../assets/js/tank-builder.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-tank-dynamic-fields', plugin_dir_url(__FILE__) . '../assets/js/tank-dynamic-fields.js', ['jquery'], false, true);
        wp_enqueue_script('ispag-exchanger-builder', plugin_dir_url(__FILE__) . '../assets/js/exchanger-builder.js', ['jquery', 'ispag-tank-builder'], false, true);
        // wp_enqueue_script('ispag-tank-pricing', plugin_dir_url(__FILE__) . '../assets/js/tank-pricing.js', ['jquery'], false, true);
        // wp_enqueue_script('ispag-tank-fitting', plugin_dir_url(__FILE__) . '../assets/js/fittings-pricing.js', ['jquery'], false, true);

        // 👇 Ajouter le script de vérification de transport
        wp_enqueue_script(
            'ispag-tank-transport-checker',
            plugin_dir_url(__FILE__) . '../assets/js/tank-transport-checker.js',
            ['jquery', 'ispag-tank-builder'], // Dépend de jQuery et tank-builder.js
            false,
            true
        );

        // Scripts DXF
        wp_enqueue_script('ispag-dxf-utils', plugin_dir_url(__FILE__) . '../assets/dxf_engine/ispag-dxf-utils.js', [], false, true);
        wp_enqueue_script('ispag-dxf-layout', plugin_dir_url(__FILE__) . '../assets/dxf_engine/ispag-dxf-layout.js', ['ispag-dxf-utils'], false, true);
        wp_enqueue_script('ispag-dxf-geometry', plugin_dir_url(__FILE__) . '../assets/dxf_engine/ispag-tank-geometry.js', ['ispag-dxf-layout'], false, true);
        wp_enqueue_script('ispag-dxf-engine', plugin_dir_url(__FILE__) . '../assets/dxf_engine/ispag-engine-main.js', ['ispag-dxf-geometry'], false, true);

        // Localisation pour tank-builder.js
        wp_localize_script('ispag-tank-builder', 'ISPAG_TANK', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'jsonUrl' => plugins_url('../assets/json/tank_data.json', __FILE__),
            'nonce'    => wp_create_nonce('ispag_tank_nonce'),
            'text_error_saving_fitting' => __('error while saving fitting', 'creation-reservoir'),
        ]);

        // 👇 Localisation pour tank-transport-checker.js
        wp_localize_script('ispag-tank-transport-checker', 'ISPAG_TRANSPORT', [
            'transportRulesUrl' => plugins_url('../assets/json/transport-rules.json', __FILE__),
            'messages' => [
                'standard' => __('Standard shipping available in Switzerland.', 'creation-reservoir'),
                'exceptional_simple' => __('Oversized transport requiring standard authorization.', 'creation-reservoir'),
                'exceptional_complex' => __('Oversized load requiring special authorization (escort may be required).', 'creation-reservoir'),
            ],
        ]);

        // Localisation pour le pricing
        wp_localize_script('ispag-tank-pricing', 'ispag_vars', [
            'plugin_url'        => plugins_url('', dirname(__FILE__, 1)),
            'custom_fee'        => get_option('wpcb_custom_fee'),
            'default_coef'      => floatval(get_option('wpcb_sales_coef')),
            'coef_revendeur'    => floatval(get_option('wpcb_sales_coef_offre_revendeur')),
            'coef_low'          => floatval(get_option('wpcb_sales_coef_low')),
        ]);
    }

    public static function get_namesplate_btn($html, $article_id) {

        if(!current_user_can('manage_order')){ return; }

        // On génère l'URL proprement en utilisant l'ID passé en paramètre
        $url = admin_url('admin-post.php?action=generate_ispag_nameplate&article_id=' . $article_id);

        return '
        
            <a href="' . esc_url($url) . '" 
            class="ispag-btn ispag-btn-grey-outlined" 
            target="_blank">
                <span class="dashicons dashicons-tag"></span>
                ' . esc_html__('Generate namesplate', 'creation-reservoir') . '
            </a>
        ';
    }

    public static function handle_nameplate_generation() {
        if (!isset($_GET['article_id'])) wp_die('ID Article manquant');

        $article_id = intval($_GET['article_id']);

        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        $deal_id = $article->hubspot_deal_id;
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $tank_datas = ISPAG_Tank_Repository::get_tank_details($article_id);

        // 2. Générer le PDF
        $generator = new ISPAG_Nameplate_Generator();
        $generator->generate_nameplate($project, $article, $tank_datas);
        exit;
    } 

    public static function delete_tank_with_article_id($html, $article_id){
        global $wpdb;
        $table_tanks = $wpdb->prefix . 'achats_tank_dimensions';

        // 1. Récupérer les cuves liées au projet
        $tanks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_tanks WHERE customerTankId = %d",
            $article_id
        ));

        // 2. Log des cuves (ou traitement)
        foreach ($tanks as $tank) {
            // error_log("Appel du hook ispag_delete_exchanger_with_tank_id avec juste l'ID article {$tank->Id}");
            do_action('ispag_delete_exchanger_with_tank_id', null, $tank->Id);
            
            // error_log("Appel du hook ispag_delete_fittings_with_tank_id avec juste l'ID article {$tank->Id}");
            do_action('ispag_delete_fittings_with_tank_id', null, $tank->Id);
            
            // error_log("Cuve supprimée : ID {$tank->Id}, Volume : {$tank->Volume} L, Diamètre : {$tank->Diameter} mm, Hauteur : {$tank->Height} mm");
        }

        // 3. Suppression en base
        $wpdb->delete($table_tanks, ['customerTankId' => $article_id]);

    }

    public static function conditionally_enqueue_restrictions() {
        // if (!current_user_can('generate_tank')) {
        //     return;
        // }
        wp_enqueue_script(
            'ispag-tank-restrictions',
            plugin_dir_url(__FILE__) . '../assets/js/array_default_restrictions.js',
            ['jquery'],
            '1.0',
            true
        );
    }
 
    public static function validate_drawing($html, $article_id, $attach_id, $user_id, $doc_type) {
        global $wpdb;
        $article_id = intval($article_id);
        if ($article_id <= 0) return;


        // Mise à jour DrawingApproved
        $table_details = $wpdb->prefix.'achats_details_commande';
        $wpdb->update(
            $table_details,
            ['DrawingApproved' => 1],
            ['Id' => $article_id],
            ['%d'],
            ['%d']
        );
        self::save_drawing($html, $article_id, $attach_id, $user_id, $doc_type);
        
    }
    public static function save_drawing($html, $article_id, $attach_id, $user_id, $doc_type) {
        global $wpdb;
        $article_id = intval($article_id);
        if ($article_id <= 0) return;

        // Récupérer IdCommande depuis achats_articles_cmd_fournisseurs
        $table_articles = $wpdb->prefix.'achats_articles_cmd_fournisseurs';
        $poid = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT IdCommande FROM $table_articles WHERE IdCommandeClient = %d LIMIT 1",
                $article_id
            )
        );
        // Insertion dans la table historique
        $table_historique = $wpdb->prefix . 'achats_historique';
        $timestamp        = current_time('timestamp');
        $now              = current_time('mysql');

        $wpdb->insert(
            $table_historique, 
            [
                'hubspot_deal_id' => 0, // Ajouté car absent et NOT NULL en BDD
                'purchase_order'  => $poid,
                'Date'            => (int) $timestamp, // Sécurisation du BIGINT
                'dateReadable'    => $now,
                'IdUser'          => $user_id,
                'Historique'      => $article_id,
                'IdMedia'         => $attach_id,
                'is_task'         => 0,
                'is_done'         => 0,
                'ClassCss'        => $doc_type,
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

        
    }
 
    public static function get_technical_sheet_btn($html, $article, $deal_id){
        
        if($article->Type == 1){

            return '<button id="technical-sheet-pdf" 
                        class="ispag-btn ispag-btn-secondary-outlined" 
                        style="margin-top: 1rem;"
                        data-article-id="' . intval($article->Id) . '"
                        data-deal-id="' . $deal_id . '">
                            <span class="dashicons dashicons-list-view"></span>
                            ' . __('Technical sheet', 'creation-reservoir') . '
                    </button>' . self::getScript();
            // return '        <script>
            //         document.getElementById(\'technical-sheet-pdf\').addEventListener(\'click\', function () {
            //             // alert(\'yes\');
            //             const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
            //             url.searchParams.set(\'action\', \'ispag_generate_technical_sheet_pdf\');
            //             url.searchParams.set(\'deal_id\', getUrlParam(\'deal_id\'));
            //             url.searchParams.set(\'article_id\', ' . intval($article->Id) . ');

            //             window.open(url.toString(), \'_blank\');
            //         });
            //         </script>';
        }
    }

    private static function getScript(){
        return '<script>
                // Votre script JavaScript existant devrait maintenant fonctionner avec les data-attributs
                // Assurez-vous que ce script est chargé après que le bouton soit présent dans le DOM
                document.addEventListener(\'click\', function (event) {
                    if (event.target.matches(\'#technical-sheet-pdf\') || event.target.closest(\'#technical-sheet-pdf\')) {
                        const button = event.target.closest(\'#technical-sheet-pdf\');
                        const articleId = button.dataset.articleId;
                        const dealId = button.dataset.dealId;

                        if (articleId) {
                            const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
                            url.searchParams.set(\'action\', \'ispag_generate_technical_sheet_pdf\');
                            if (dealId) {
                                url.searchParams.set(\'deal_id\', dealId);
                            }
                            url.searchParams.set(\'article_id\', articleId);

                            window.open(url.toString(), \'_blank\');
                        } else {
                            console.error(\'Article ID non trouvé sur le bouton.\');
                        }
                    }
                });
                </script>';
    }


    public static function ispag_ajax_generate_technical_sheet() {
        

        $article_id = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;
        $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);

        if (!$deal_id) {
            wp_die('Missing article ID');
        }
        if (!$article_id) {
            wp_die('Missing deal ID');
        }

        global $wpdb;


        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $svg_path = apply_filters('ispag_get_tank_svg', null, $article_id, false);
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);

        // echo'<pre>';
        // var_dump($article);
        // echo'</pre>';


        if (!$article) {
            wp_die('No data found for article');
        }
        if (!$project) {
            wp_die('No data found for project');
        }

        // $specs = [
        //     'Volume' => $article->Volume . ' L',
        //     'Hauteur' => $article->Hauteur . ' mm',
        //     'Diamètre' => $article->Diametre . ' mm',
        //     'Isolation' => $article->Insulation,
        //     'Pression de service' => $article->Pression . ' bar',
        // ];

        $notes = [
            'Fiche générée automatiquement.',
            'Veuillez vérifier les données avant envoi au client.',
        ];

        require_once plugin_dir_path(__FILE__) . '/class-ispag-tank-pdf-generator.php';

        $pdf = new ISPAG_Tank_TechSheet_Generator();
        $title = __('Technical specifications', 'creation-reservoir') . ' - ' . $article->Article;
        $file_name = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $title));
        $pdf->generate_tech_sheet($project, $article, $tank_datas, $svg_path, $raccords = []);
        $pdf->Output('I', $file_name.'.pdf');
        exit;
    }

    public static function get_sketch_btn($html, $article, $deal_id = null){
        
        if($article->Type == 1){

            $script = '<script>
                    document.getElementById(\'sketch-pdf\').addEventListener(\'click\', function () {
                        // alert(\'yes\');
                        const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
                        url.searchParams.set(\'action\', \'ispag_ajax_generate_sketch\');
                        url.searchParams.set(\'deal_id\', getUrlParam(\'deal_id\'));
                        url.searchParams.set(\'article_id\', ' . intval($article->Id) . ');

                        window.open(url.toString(), \'_blank\');
                    });
                    </script>';
            return '<button id="sketch-pdf" class="ispag-btn ispag-btn-secondary-outlined" style="margin-top: 1rem;" data-tank-sketch="' . intval($article->Id) . '" data-deal-id="' . intval($deal_id) . '" data-ajax-action="tank_data_extractor">
                        <span class="dashicons dashicons-hammer"></span>
                        ' .  __('Sketch', 'creation-reservoir') . '
                    </button>
                    ';
        }
    }

    public static function ispag_ajax_generate_sketch() {
        

        $article_id = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;
        $deal_id = get_query_var('deal_id') ?: ($_GET['deal_id'] ?? null);

        if (!$deal_id) {
            wp_die('Missing article ID');
        }
        if (!$article_id) {
            wp_die('Missing deal ID');
        }

        global $wpdb;


        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $svg_path = apply_filters('ispag_get_tank_svg', null, $article_id, true);
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article_id);

        // echo'<pre>';
        // var_dump($article);
        // echo'</pre>';


        if (!$article) {
            wp_die('No data found for article');
        }
        if (!$project) {
            wp_die('No data found for project');
        }

        $notes = [
            'Fiche générée automatiquement.',
            'Veuillez vérifier les données avant envoi au client.',
        ];

        require_once plugin_dir_path(__FILE__) . '/class-ispag-tank-drawing-generator.php';

        $pdf = new ISPAG_Tank_Drawing_Generator();
        $title = __('Sketch', 'creation-reservoir') . ' - ' . $article->Article;
        $file_name = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $title));
        $pdf->generate_drawing($article, $tank_datas, $title);
        $pdf->Output('I', $file_name.'.pdf');
        exit;
    }

    public static function get_related_tank($html, $articleId = null, $hubspot_deal_id = null){
        global $wpdb;

        
        if(empty($articleId)){
            return null;
        }
        if(empty($hubspot_deal_id)){
            return null;

        }

        // error_log('get_related_tank articleId : ' .$articleId );
        // error_log('get_related_tank hubspot_deal_id : ' .$hubspot_deal_id );

        $sql = "
            SELECT Id
            FROM {$wpdb->prefix}achats_details_commande
            WHERE
                hubspot_deal_id = %d
                AND Type  = 1
                AND Groupe = (
                    SELECT Groupe
                    FROM {$wpdb->prefix}achats_details_commande
                    WHERE Id = %d
                    -- Optionnel : Vous pourriez aussi ajouter hubspot_deal_id ici si vous savez que l'Id 123
                    -- est lié au même deal, mais la consigne initiale ne le spécifiait pas.
                );
        ";
        // error_log($sql);
        $linked_tank = $wpdb->get_var($wpdb->prepare($sql, $hubspot_deal_id, $articleId));
        if ($linked_tank) {
            return $linked_tank;
        }
    }
}
