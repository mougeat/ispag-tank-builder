<?php
/**
 * Class ISPAG_Tank_TechSheet_Generator
 *
 * Génère une fiche technique PDF pour les réservoirs ISPAG.
 * Étend la classe ISPAG_PDF_Generator pour ajouter des fonctionnalités spécifiques.
 */
class ISPAG_Tank_TechSheet_Generator extends ISPAG_PDF_Generator
{
    /**
     * Position Y après l'image de la cuve.
     * @var float
     */
    protected $y_image_bottom;

    /**
     * Constructeur : Initialise la classe et charge le domaine de traduction.
     */
    public function __construct()
    {
        parent::__construct();
        // Charge le domaine de traduction pour éviter les erreurs "too early"
        load_plugin_textdomain(
            'creation-reservoir',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Génère la fiche technique PDF.
     *
     * @param object $project    Données du projet.
     * @param object $article   Données de l'article.
     * @param array  $tank_datas Données techniques du réservoir.
     * @param string $svg_path   Chemin vers le fichier SVG de la cuve.
     * @param array  $raccords   Liste des raccords (optionnel).
     */
    public function generate_tech_sheet($project, $article, $tank_datas, $svg_path, $raccords = [])
    {
        $this->title = __('Technical specifications', 'creation-reservoir');
        $this->AddPage();
        $this->SetTitle(mb_convert_encoding($this->title, 'ISO-8859-1', 'UTF-8'));
        $this->SetAutoPageBreak(true, 20);
        $this->addHeader();

        $this->addModernHeader($project, $article);
        $this->addArticleTitle($article);
        $this->addLayoutBlocks($article, $tank_datas, $svg_path, $raccords);
    }

    // ========== MÉTHODES POUR L'EN-TÊTE ET LE TITRE ==========

    /**
     * Ajoute un en-tête moderne avec les informations du projet.
     *
     * @param object $project Données du projet.
     * @param object $article Données de l'article.
     */
    protected function addModernHeader($project, $article)
    {
        $this->SetTextColor(65, 76, 82);
        $this->SetFont('Arial', '', 8);
        $this->SetDrawColor(222, 226, 230);
        $this->SetLineWidth(0.5);

        $width = $this->GetPageWidth() / 2;
        $this->SetXY($width - 10, 20);
        $this->Cell($width, 5, mb_convert_encoding($project->ObjetCommande ?? '', 'ISO-8859-1', 'UTF-8'), 0, 2, 'R');
        $this->Cell($width, 5, mb_convert_encoding($article->Groupe ?? '', 'ISO-8859-1', 'UTF-8'), 0, 2, 'R');
        $this->Cell($width, 5, date('d.m.Y'), 0, 1, 'R');
        $this->Ln(5);

        $x1 = 10;
        $y1 = $this->GetY();
        $x2 = $this->GetPageWidth() - $x1;
        $this->Line($x1, $y1, $x2, $y1);
        $this->Ln(5);
    }

    /**
     * Ajoute le titre de l'article.
     *
     * @param object $article Données de l'article.
     */
    protected function addArticleTitle($article)
    {
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(0);
        $this->Cell(0, 12, mb_convert_encoding($article->Article ?? '', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Ln(5);
    }

    // ========== MÉTHODES POUR LA MISE EN PAGE ==========

    /**
     * Ajoute les blocs de contenu (dimensions, échangeur, soudure, isolation, image, raccords).
     *
     * @param object $article    Données de l'article.
     * @param array  $tank_datas Données techniques du réservoir.
     * @param string $svg_path   Chemin vers le fichier SVG.
     * @param array  $raccords   Liste des raccords.
     */
    protected function addLayoutBlocks($article, $tank_datas, $svg_path, $raccords)
    {
        $startY = $this->GetY();

        // Bloc gauche
        $this->SetXY(10, $startY);
        $this->addBlocDimensions($tank_datas);
        $this->addBlocEchangeur($article);
        $this->addBlocSoudure($article);
        $this->addBlocIsolation($article);

        // Bloc droit (image SVG convertie en PNG et raccords)
        $this->SetXY(130, $startY);
        $this->addCuveImage($svg_path);
        $this->addRaccordsList($article);
    }

    // ========== MÉTHODES POUR LES BLOCS DE DONNÉES ==========

    /**
     * Ajoute le bloc des dimensions.
     *
     * @param array $tank_datas Données techniques du réservoir.
     */
    protected function addBlocDimensions($tank_datas)
    {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(218, 124, 81);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Dimensions', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);

        $this->SetFont('Arial', '', 8);
        $dims = [
            __('Diameter', 'creation-reservoir') => isset($tank_datas['dimensions']->Diameter) ? $tank_datas['dimensions']->Diameter . ' mm' : '-',
            __('Volume', 'creation-reservoir') => isset($tank_datas['dimensions']->Volume) ? $tank_datas['dimensions']->Volume . ' L' : '-',
            __('Height', 'creation-reservoir') => isset($tank_datas['dimensions']->Height) ? $tank_datas['dimensions']->Height . ' mm' : '-',
            __('Tipping height', 'creation-reservoir') => isset($tank_datas['dimensions']->TippingHeight) ? $tank_datas['dimensions']->TippingHeight . ' mm' : '-',
            __('Materials', 'creation-reservoir') => isset($tank_datas['conception']->material_text) ? __($tank_datas['conception']->material_text, 'creation-reservoir') : '-',
            __('Temperature', 'creation-reservoir') => isset($tank_datas['dimensions']->usingTemperature) ? $tank_datas['dimensions']->usingTemperature . ' °C' : '-',
            __('Operating pressure', 'creation-reservoir') => isset($tank_datas['dimensions']->MaxPressure) ? $tank_datas['dimensions']->MaxPressure . ' bar' : '-',
            __('Test pressure', 'creation-reservoir') => isset($tank_datas['dimensions']->TestPressure) ? $tank_datas['dimensions']->TestPressure . ' bar' : '-'
        ];

        foreach ($dims as $label => $value) {
            $this->Cell(60, 5, mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8'), 0, 0);
            $this->Cell(0, 5, ': ' . $value, 0, 1);
        }

        $this->Ln(3);
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D');
        $this->Ln(3);
    }

    /**
     * Ajoute l'image de la cuve (conversion SVG vers PNG).
     *
     * @param string $svgUrl URL du fichier SVG.
     */
    protected function addCuveImage($svgUrl)
    {
        $svgPath = $this->get_local_path_from_url($svgUrl);
        if (!file_exists($svgPath)) {
            $this->Cell(0, 10, mb_convert_encoding(__('Error: SVG file not found.', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
            $this->y_image_bottom = $this->GetY();
            return;
        }

        $pngPath = str_replace('.svg', '.png', $svgPath);
        if (file_exists($pngPath)) {
            unlink($pngPath);
        }

        try {
            $this->convert_svg_to_png($svgPath, $pngPath);
            if (file_exists($pngPath)) {
                $this->Image($pngPath, 130, $this->GetY(), 0, 80);
                $this->Ln(65);
            } else {
                $this->Cell(0, 10, mb_convert_encoding(__('Failed to convert SVG to PNG.', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
            }
        } catch (ImagickException $e) {
            $this->Cell(0, 10, mb_convert_encoding(__('Error converting SVG: ', 'creation-reservoir') . $e->getMessage(), 'ISO-8859-1', 'UTF-8'), 0, 1);
        }

        $this->y_image_bottom = $this->GetY();
    }

    /**
     * Convertit un fichier SVG en PNG.
     *
     * @param string $svgPath Chemin vers le fichier SVG.
     * @param string $pngPath Chemin vers le fichier PNG de sortie.
     * @throws ImagickException Si la conversion échoue.
     */
    protected function convert_svg_to_png($svgPath, $pngPath)
    {
        if (!class_exists('Imagick')) {
            throw new Exception(__('Imagick extension is not installed.', 'creation-reservoir'));
        }

        $imagick = new Imagick();
        $imagick->setBackgroundColor(new ImagickPixel('white'));
        $imagick->readImage($svgPath);
        $imagick->resizeImage(1200, 2400, Imagick::FILTER_LANCZOS, 1);
        $imagick->quantizeImage(256, Imagick::COLORSPACE_RGB, 0, false, false);
        $imagick->setImageDepth(8);
        $imagick->setImageFormat('png');
        $imagick->writeImage($pngPath);
        $imagick->clear();
        $imagick->destroy();
    }

    /**
     * Convertit une URL en chemin local.
     *
     * @param string $url URL du fichier.
     * @return string Chemin local absolu.
     */
    protected function get_local_path_from_url($url)
    {
        $site_url = site_url();
        $server_path = ABSPATH;
        return str_replace($site_url, $server_path, $url);
    }

    /**
     * Ajoute le bloc échangeur thermique.
     *
     * @param object $article Données de l'article.
     */
    protected function addBlocEchangeur($article)
    {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(218, 124, 81);

        $heat_exchanger_datas = apply_filters('ispag_get_heat_exchanger_datas', null, $article->Id ?? 0);
        if (empty($heat_exchanger_datas)) {
            return;
        }

        $datas = [];
        foreach ($heat_exchanger_datas as $key => $coil) {
            $surface = !empty($coil['coilSurface']) && $coil['coilSurface'] > 0 ? $coil['coilSurface'] : null;
            $input = !empty($coil['loadInputTemperature']) && $coil['loadInputTemperature'] > 0 ? $coil['loadInputTemperature'] : null;
            $output = !empty($coil['loadOutputTemperature']) && $coil['loadOutputTemperature'] > 0 ? $coil['loadOutputTemperature'] : null;
            $cold = !empty($coil['coldWaterInputTemperature']) && $coil['coldWaterInputTemperature'] > 0 ? $coil['coldWaterInputTemperature'] : null;
            $hot = !empty($coil['hotWaterOutputTemperature']) && $coil['hotWaterOutputTemperature'] > 0 ? $coil['hotWaterOutputTemperature'] : null;
            $power = !empty($coil['exchangerPower']) && $coil['exchangerPower'] > 0 ? $coil['exchangerPower'] : null;

            $parts = [];
            if ($surface !== null) $parts[] = sprintf(__('%sm²', 'creation-reservoir'), $surface);
            if ($power !== null) $parts[] = sprintf(__('%s kW', 'creation-reservoir'), $power);
            if ($input !== null || $output !== null) $parts[] = sprintf(__('In %s°C / Out %s°C', 'creation-reservoir'), $input ?? '-', $output ?? '-');
            if ($cold !== null || $hot !== null) $parts[] = sprintf(__('Water %s°C / %s°C', 'creation-reservoir'), $cold ?? '-', $hot ?? '-');

            if (!empty($parts)) {
                $coil_nb = str_ireplace('coil', '', $key);
                $line_title = sprintf(__('Coil %s', 'creation-reservoir'), $coil_nb);
                $line_data = implode("\n", $parts);
                $datas[$line_title] = $line_data;
            }
        }

        if (empty($datas)) return;

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Heat exchanger', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->SetFont('Arial', '', 8);

        foreach ($datas as $label => $value) {
            $this->Cell(60, 5, mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8'), 0, 0);
            $this->MultiCell(0, 5, mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8'), 0, 1);
        }

        $this->Ln(3);
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D');
        $this->Ln(3);
    }

    /**
     * Ajoute le bloc soudure.
     *
     * @param object $article Données de l'article.
     */
    protected function addBlocSoudure($article)
    {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(218, 124, 81);

        if (empty($article->tank_on_site_welded)) return;

        $welding_description = apply_filters('ispag_get_welding_text', null, $article->Id ?? 0, false);
        $warranty_information = apply_filters('ispag_get_warranty_information', null, $article->Id ?? 0);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Welding', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->SetFont('Arial', '', 8);

        $this->MultiCell($this->GetPageWidth() / 2, 5, mb_convert_encoding($warranty_information ?? '', 'ISO-8859-1', 'UTF-8'));
        $this->Ln(3);

        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D');
        $this->Ln(3);
    }

    /**
     * Ajoute le bloc isolation.
     *
     * @param object $article Données de l'article.
     */
    protected function addBlocIsolation($article)
    {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(218, 124, 81);

        if (empty($article)) return;

        // 1. Tenter de récupérer l'isolation liée (accessoire/spécifique)
        $insulation = apply_filters('ispag_get_related_insulation_information', null, $article->Id ?? 0);
        
        // 2. Si aucune isolation spécifique, on récupère l'isolation standard du réservoir
        if (!$insulation) 
        {
            $tank_datas = apply_filters('ispag_get_tank_datas', null, $article->Id ?? 0);
        
            if (!empty($tank_datas['insulation']) && !empty($tank_datas['dimensions'])) 
            {
                $insulation_obj = $tank_datas['insulation'];
                $dimensions_obj = $tank_datas['dimensions'];

                // Récupération des variables pour le sprintf
                $thickness_text   = $insulation_obj->InsulationThickness ?? '0';
                $type_text        = $insulation_obj->insulation ?? '';
                $cover_text       = $insulation_obj->insulationCover ?? '';
                
                $volume           = $dimensions_obj->Volume ?? '0';
                $tank_height      = $dimensions_obj->Height ?? '0';
                $tank_height_text = 'H-Total'; // Ou la clé de traduction correspondante à votre hauteur

                // Génération du Titre (comme dans votre exemple)
                $title = sprintf(
                    __('%dmm %s for %dL tank', 'creation-reservoir'),
                    $thickness_text,
                    __($type_text, 'creation-reservoir'),
                    $volume,
                    
                );

                // Assemblage de la description complète avec des retours à la ligne (\n) pour le PDF
                $desc = $title;
                $desc .= "\n" . __('Assembly at the customer\'s expense', 'creation-reservoir');

                $insulation = $desc;
            }
        }

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Insulation', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->SetFont('Arial', '', 8);

        $this->MultiCell($this->GetPageWidth() / 2, 5, mb_convert_encoding($insulation ?? '', 'ISO-8859-1', 'UTF-8'));
        $this->Ln(3);

        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D');
        $this->Ln(3);
    }

    // ========== MÉTHODES POUR LES RACCORDS ==========

    /**
     * Ajoute la liste des raccords.
     *
     * @param object $article Données de l'article.
     */
    protected function addRaccordsList($article)
    {
        $this->SetXY(130, $this->y_image_bottom + 20);
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(218, 124, 81);

        if (empty($article)) return;

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Fittings', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);

        $this->SetX(130);
        $this->SetFont('Arial', '', 8);
        $this->MultiCell($this->GetPageWidth() / 3, 5, mb_convert_encoding($article->fittings_description ?? '', 'ISO-8859-1', 'UTF-8'));
        $this->Ln(3);

        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, $this->GetPageWidth() / 3, $height, 3, 'D');
        $this->Ln(3);
    }

    // ========== MÉTHODES UTILITAIRES POUR LES FORMES ==========

    /**
     * Dessine un rectangle arrondi.
     *
     * @param float $x      Position X.
     * @param float $y      Position Y.
     * @param float $w      Largeur.
     * @param float $h      Hauteur.
     * @param float $r      Rayon des coins.
     * @param string $style Style (D pour bordure, F pour remplissage, DF pour les deux).
     */
    protected function RoundedRect($x, $y, $w, $h, $r = 2, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        $op = ($style == 'F') ? 'f' : (($style == 'FD' || $style == 'DF') ? 'B' : 'S');

        $MyArc = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));

        // Coin haut droit
        $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);

        // Coin bas droit
        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);

        // Coin bas gauche
        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);

        // Coin haut gauche
        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);

        $this->_out($op);
    }

    /**
     * Dessine un arc de cercle pour les coins arrondis.
     *
     * @param float $x1 Coordonnée X du point de départ.
     * @param float $y1 Coordonnée Y du point de départ.
     * @param float $x2 Coordonnée X du point de contrôle 1.
     * @param float $y2 Coordonnée Y du point de contrôle 1.
     * @param float $x3 Coordonnée X du point final.
     * @param float $y3 Coordonnée Y du point final.
     */
    protected function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c ',
            $x1 * $this->k,
            ($h - $y1) * $this->k,
            $x2 * $this->k,
            ($h - $y2) * $this->k,
            $x3 * $this->k,
            ($h - $y3) * $this->k
        ));
    }
}