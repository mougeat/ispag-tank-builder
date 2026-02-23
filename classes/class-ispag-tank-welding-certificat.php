<?php

class ISPAG_Tank_Welding_Certificat extends ISPAG_PDF_Generator{

    protected $y_image_bottom;
    private $wpdb;
    private $table_flange_dimension;
    private $table_conception;
    private $table_article;
    private $table_project_article;
    private $table_connections;
    protected static $instance = null;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_article = $wpdb->prefix . 'achats_articles';
        $this->table_project_article = $wpdb->prefix . 'achats_details_commande';
        $this->table_flange_dimension = $wpdb->prefix . 'achats_flange_dimensions';
        $this->table_conception = $wpdb->prefix . 'achats_tank_conception';
        $this->table_connections = $wpdb->prefix . 'achats_tank_connection';
        
    }

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        
        add_action('wp_ajax_ispag_generate_welding_certificat_pdf', [self::$instance, 'ispag_ajax_generate_welding_certificat']);
        add_filter('ispag_get_welding_certificat_btn', [self::$instance, 'get_welding_certificat_btn'], 10, 3);
        
    }

    public static function ispag_ajax_generate_welding_certificat() {
        

        $article_id = isset($_GET['article_id']) ? intval($_GET['article_id']) : 0;
        $deal_id = isset($_GET['deal_id']) ? intval($_GET['deal_id']) : 0;

        if (!$deal_id) {
            wp_die('Missing article ID');
        }
        if (!$article_id) {
            wp_die('Missing deal ID');
        }

        global $wpdb;

        $tank_id = apply_filters('ispag_get_related_tank', null, $article_id, $deal_id);
        $article = apply_filters('ispag_get_article_by_id', null, $tank_id);
        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        $svg_path = apply_filters('ispag_get_tank_svg', null, $tank_id, false);
        $tank_datas = apply_filters('ispag_get_tank_datas', null, $tank_id);

//         error_log('tank_id : ' . $tank_id);
        // error_log('ispag_get_article_by_id : ' . print_r($article, true));

        // echo'<pre>';
        // var_dump($article);
        // echo'</pre>';


        // if (!$article) {
        //     wp_die('No data found for article');
        // }
        if (!$project) {
            wp_die('No data found for project');
        }

        //Choix du type de baguette utilisé pour soudé
        //Si Inox : Baguette SMT-316LSi Inox
        //Si Acier : Baguette ST-50
        if($tank_datas['conception']->Material == 1 OR $tank_datas['conception']->Material == 3 ){
            $baguette = 'SMT-316LSi';
        }
        elseif($tank_datas['conception']->Material == 2){
            $baguette = 'Baguette ST-50';
        }
        else{
            $baguette = '';
        }

        $weld_control = [
            __('Base Material', 'creation-reservoir') => $baguette,
            __('Welding Process', 'creation-reservoir') => __('TIG', 'creation-reservoir'),
            __('Controlled Area', 'creation-reservoir') => __('tank welding', 'creation-reservoir'),
            __('Anomalies Detected', 'creation-reservoir') => __('none', 'creation-reservoir'),
            __('Conclusion', 'creation-reservoir') =>  __('conform', 'creation-reservoir'),
            
        ];

        $notes = [
            'Fiche générée automatiquement.',
            'Veuillez vérifier les données avant envoi au client.',
        ];

        require_once plugin_dir_path(__FILE__) . '/class-ispag-tank-pdf-generator.php';

        $pdf = new ISPAG_Tank_Welding_Certificat();
        // $title = __('Welding certificat', 'creation-reservoir') . ' - ' . $article->Article;
        $title = __('Welding certificat', 'creation-reservoir') . ' - ' . $article->Article;
        $file_name = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $title));
        // $pdf->generate_weld_certificat($project, $svg_path, $article, $tank_datas, $weld_control );
        $pdf->Output('I', $file_name.'.pdf');
        // exit;
    }

    public static function get_welding_certificat_btn($html, $article, $deal_id){
        
        if($article->Type == 3 AND $article->Livre){
            // $deal_id = isset($_GET['deal_id']) ? intval($_GET['deal_id']) : '';
            return '<button id="welding-certificat-pdf" 
                        class="ispag-btn ispag-btn-secondary-outlined" 
                        style="margin-top: 1rem;"
                        data-article-id="' . intval($article->Id) . '"
                        data-deal-id="' . $deal_id . '">
                            <span class="dashicons dashicons-awards"></span>
                            ' . __('Welding certificat', 'creation-reservoir') . '
                    </button>' . self::getScript();

        }
    }
    private static function getScript(){
        return '<script>
                // Votre script JavaScript existant devrait maintenant fonctionner avec les data-attributs
                // Assurez-vous que ce script est chargé après que le bouton soit présent dans le DOM
                document.addEventListener(\'click\', function (event) {
                    if (event.target.matches(\'#welding-certificat-pdf\') || event.target.closest(\'#welding-certificat-pdf\')) {
                        const button = event.target.closest(\'#welding-certificat-pdf\');
                        const articleId = button.dataset.articleId;
                        const dealId = button.dataset.dealId;

                        if (articleId) {
                            const url = new URL(\'' . admin_url('admin-ajax.php') . '\');
                            url.searchParams.set(\'action\', \'ispag_generate_welding_certificat_pdf\');
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

    public function generate_weld_certificat($project, $svg_path, $article = null, $tank_datas = array(), $weld_control = array()) {
        $this->title = __('Welding certificat', 'creation-reservoir');
        $this->AddPage();
        // $title_iso = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $this->title);
        // $this->SetTitle($title_iso);
        // $this->SetAutoPageBreak(true, 20);
        // $this->addHeader();        
        // $this->addModernHeader($project, $article);
        
        // $this->addArticleTitle($article);

        // $y = $this->getY();
        // $this->design_vertical_line($y + 5);
        
        // $this->addLayoutBlocks($article, $svg_path, $tank_datas, $weld_control);
        
    }

    protected function design_vertical_line($y1 = 0){
        $x = $this->GetPageWidth() / 2;
        $this->SetXY($x, $y1);
        $y2 = $this->GetPageHeight() - $y1 - 5;
        $this->SetTextColor(65, 76, 82);
        $this->SetFont('Arial','',8);
        $this->SetDrawColor(222, 226, 230);
        $this->SetLineWidth('0.5');
        $this->Line($x, $y1, $x, $y1);
    }

    protected function addModernHeader($project, $article) {
        $this->SetTextColor(65, 76, 82);
        $this->SetFont('Arial','',8);
        $this->SetDrawColor(222, 226, 230);
        $this->SetLineWidth('0.5');

        $width = $this->GetPageWidth() / 2;
        $this->SetXY($width-10, 20);
        $this->Cell($width, 5, mb_convert_encoding($project->ObjetCommande, 'ISO-8859-1', 'UTF-8'), 0, 2, 'R');
        $this->Cell($width, 5, mb_convert_encoding($article->Groupe, 'ISO-8859-1', 'UTF-8'), 0, 2, 'R');
        $this->Cell($width, 5, mb_convert_encoding(__('Number of tanks', 'creation-reservoir') . ' : ' . $article->Qty, 'ISO-8859-1', 'UTF-8'), 0, 2, 'R');
        $this->Cell($width, 5, date('d.m.Y'), 0, 1, 'R');
        $this->Ln(5);
        
        $x1 = 10;
        $y1 = $this->GetY();
        $x2 = $this->GetPageWidth() - $x1;
        $this->Line($x1, $y1, $x2, $y1);

        $this->Ln(5);

    }

    protected function addArticleTitle($article) {
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(0);
        $this->Cell(0, 12, mb_convert_encoding($article->Article, 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Ln(5);
    }

    protected function addLayoutBlocks($article, $svg_path, $tank_datas = array(), $weld_control = array()) {
        $startY = $this->GetY();
        
        
        // Bloc gauche
        $this->SetXY(10, $startY);
        $this->addBlocDimensions($tank_datas);
        // $this->addBlocEchangeur($article);
        $this->addBlocSoudure($weld_control);
        // $this->addBlocIsolation($article);

        // Bloc droit (image SVG convertie en PNG et raccords)

        $y_bloc = $this->GetY();
        $this->SetXY(130, $startY);
        $this->addCuveImage($svg_path);
        $y_image = $this->GetY();
        
        if($y_image > $y_bloc){
            $this->SetY($y_image);
        }else{
            $this->SetY($y_bloc);
        }
        $this->SetX(10);
        $this->add_certificat_bottom($article);
    }

    protected function addBlocDimensions($tank_datas) {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255); // blanc
        $this->SetDrawColor(218, 124, 81);  // orange ISPAG
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, __('Dimensions', 'creation-reservoir'), 0, 1);

        // echo'<pre>';
        // var_dump($tank_datas);
        // echo'</pre>';

        $this->SetFont('Arial', '', 8);
        $dims = [
            __('Diameter', 'creation-reservoir') => $tank_datas['dimensions']->Diameter . ' mm' ?? '-',
            __('Volume', 'creation-reservoir') => $tank_datas['dimensions']->Volume . ' L' ?? '-',
            __('Height', 'creation-reservoir') => $tank_datas['dimensions']->Height . ' mm' ?? '-',
            __('Tipping height', 'creation-reservoir') => $tank_datas['dimensions']->TippingHeight . ' mm' ?? '-',
            __('Materials', 'creation-reservoir') => __($tank_datas['conception']->material_text, 'creation-reservoir') ?? '-',
            __('Temperature', 'creation-reservoir') => $tank_datas['dimensions']->usingTemperature . ' °C' ?? '-',
            __('Operating pressure', 'creation-reservoir') => $tank_datas['dimensions']->MaxPressure . ' bar' ?? '-',
            __('Test pressure', 'creation-reservoir') => $tank_datas['dimensions']->TestPressure . ' bar' ?? '-'
        ];

        foreach ($dims as $label => $value) {
            $this->Cell(60, 5, mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8'), 0, 0);
            $this->Cell(0, 5, mb_convert_encoding(': ' . $value, 'ISO-8859-1', 'UTF-8'), 0, 1);
        }

        $this->Ln(3);

         // Dessine le cadre après le contenu
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D'); // 120 = largeur bloc gauche

        $this->Ln(3);
    }

    protected function addCuveImage($svgUrl) {
        $svgPath = $this->get_local_path_from_url($svgUrl);
        $pngPath = str_replace('.svg', '.png', $svgPath);
        if (file_exists($pngPath)) {
            unlink($pngPath); // Supprime le PNG existant
        }
        if (!file_exists($pngPath)) {
            $this->convert_svg_to_png($svgPath, $pngPath);
        }

        if (file_exists($pngPath)) {
            
            // $this->Rect(130, $this->GetY(), 60, 60); // rectangle de test
            $this->Image($pngPath, 130, $this->GetY(), 0, 80);
            // $this->MultiCell(80, 10, $pngPath, 0, 1);
            $this->Ln(65);
        } else {
            $this->Cell(0, 10, __('Image not available', 'creation-reservoir'), 0, 1);
        }

        $this->y_image_bottom = $this->GetY();
    }

    protected function get_local_path_from_url($url) {
        $plugin_url = plugin_dir_url(__FILE__);
        $plugin_path = plugin_dir_path(__FILE__);
        return str_replace($plugin_url, $plugin_path, $url);
    }

    protected function convert_svg_to_png($svgPath, $pngPath) {
        

        $imagick = new Imagick();

        $imagick->setBackgroundColor(new ImagickPixel('white'));
        $imagick->readImage($svgPath);

        // Forcer la taille (ajuster selon besoin)
        $imagick->resizeImage(1200, 2400, Imagick::FILTER_LANCZOS, 1);

        // Convertir en 8 bits (256 couleurs)
        $imagick->quantizeImage(256, Imagick::COLORSPACE_RGB, 0, false, false);
        $imagick->setImageDepth(8);

        $imagick->setImageFormat('png');
        $imagick->writeImage($pngPath);

        $imagick->clear();
        $imagick->destroy();
    }



    protected function addBlocSoudure($weld_control) {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255); // blanc
        $this->SetDrawColor(218, 124, 81);  // orange ISPAG

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Inspection Results', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->SetFont('Arial', '', 8);


        foreach ($weld_control as $label => $value) {
            $this->Cell(60, 5, mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8'), 0, 0);
            $this->Cell(0, 5, mb_convert_encoding(': ' . $value, 'ISO-8859-1', 'UTF-8'), 0, 1);
        }

        $this->Ln(3);

        // Dessine le cadre après le contenu
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D');
        $this->Ln(3);
    }




    protected function RoundedRect($x, $y, $w, $h, $r = 2, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        $op = ($style == 'F') ? 'f' : (($style == 'FD' || $style == 'DF') ? 'B' : 'S');

        $MyArc = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));

        // coin haut droit
        $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);

        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));

        // coin bas droit
        $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);

        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));

        // coin bas gauche
        $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);

        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));

        // coin haut gauche
        $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);

        $this->_out($op);
    }

    protected function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ',
            $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k,
            $x3 * $this->k, ($h - $y3) * $this->k));
    }

    protected function add_certificat_bottom($article){
        // On se positionne en bas, sous l'image de la cuve (variable de position définie dans addCuveImage)
        $y = max($this->y_image_bottom, $this->GetY());
        $this->SetY($y + 10);
        $this->SetX(10); // Début à gauche

        $this->SetTextColor(65, 76, 82);
        $this->SetFont('Arial', '', 8);

        // Récupération et formatage de la date de contrôle/livraison
        $control_date = date('d.m.Y', strtotime($article->date_livraison));

        // --- Colonne de gauche (Date) ---
        $width_col = $this->GetPageWidth() / 3;

        // Traduction pour 'Control Date:'
        $control_date_label = __('Control Date', 'creation-reservoir');
        
        // Affichage de la date de livraison (utilisée comme date de contrôle)
        $this->Cell($width_col, 5, mb_convert_encoding($control_date_label . ': ' . $control_date, 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');

        $this->Ln(5);
        
        // --- Colonne de droite (Contrôleur et Signature) ---
        
        // Positionnement pour la signature
        $x_sig = $this->GetPageWidth() - 70; // Environ 70mm depuis la droite pour la colonne
        $y_start_sig = $this->GetY() - 5; 
        $this->SetXY($x_sig, $y_start_sig);

        // Traduction pour 'Qualified Inspector:'
        $qualified_inspector_label = __('Qualified Inspector', 'creation-reservoir');

        // Titre de la section
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(60, 5, mb_convert_encoding($qualified_inspector_label . ':', 'ISO-8859-1', 'UTF-8'), 0, 2, 'C'); // 60mm de large

        // Nom du contrôleur (ne change pas, car c'est un nom propre)
        $this->SetFont('Arial', '', 9);
        $this->Cell(60, 5, utf8_decode('Cyril Barthel'), 0, 2, 'C');
        
        $this->Ln(2); // Espace entre le nom et la signature
        
        // Ajout de l'image de la signature
        $signature_url = 'https://app.ispag-asp.ch/wp-content/uploads/2024/05/Signature_Cyril-Barthel.jpg';
        $this->Image($signature_url, $x_sig + 5, $this->GetY(), 50, 0); // 50mm de large, hauteur auto

        // Espace pour le texte après la signature
        $this->SetY($this->GetY() + 20); 

        // --- Ligne de fin ---
        $this->SetDrawColor(222, 226, 230);
        $this->SetLineWidth('0.5');
        $x1 = 10;
        $y1 = $this->GetY();
        $x2 = $this->GetPageWidth() - $x1;
        $this->Line($x1, $y1, $x2, $y1);

        // Note de bas de page multilingue
        // $footer_note = __('This document is for information purposes and does not replace the official conformity certificate.', 'creation-reservoir');
        
        // $this->Ln(5);
        // $this->SetFont('Arial', 'I', 7);
        // $this->Cell(0, 5, mb_convert_encoding($footer_note, 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
    }
}