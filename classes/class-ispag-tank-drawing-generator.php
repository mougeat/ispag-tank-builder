<?php

class ISPAG_Tank_Drawing_Generator extends ISPAG_PDF_Generator{

    
    protected $margin;
    protected $w;
    protected $h;
    protected $cw;
    protected $ch;
    protected $creator_name;

    public function __construct($creator_name = 'Cyril Barthel') {
        
        parent::__construct('L', 'mm', 'A4'); // paysage, mm, A4
        $this->creator_name = $creator_name;
        $this->SetAutoPageBreak(true, 5); 

        $this->margin = 5;
        $this->w = $this->GetPageWidth();
        $this->h = $this->GetPageHeight();
        $this->cw = 120; // largeur cartouche
        $this->ch = 60; // hauteur cartouche
    }

    public function Footer() {
        // Ne rien mettre pour désactiver le footer
    }

    public function generate_drawing($article, $tank_datas, $title = null) {

        $this->title = $title;


        $this->AddPage();
        $this->SetTitle(utf8_decode($this->title));
        $this->drawFrame();
        $this->drawCartouche($article, $tank_datas);

        $this->SetY($this->margin*2);
        $this->addTitle($article);
        $start_drawing_y = $this->GetY();
        $svg_path = apply_filters('ispag_get_tank_png',null, $article->Id, true);
        $this->addCuveImage_png($svg_path, null, $start_drawing_y, 150);
        
        $svg_top_view_path = apply_filters('ispag_get_tank_top_view_png',null , $article->Id);
        $this->addCuveImage_png($svg_top_view_path, 150, $start_drawing_y, 100);
        
        // Texte en bas à gauche, juste au-dessus du cadre
        $text = __("This sketch is given for information purposes only. The manufacturing plan will be sent later for validation and will be the only official document.", "creation-reservoir");
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100);
        $x = $this->margin + 2; // un petit décalage à gauche
        $y = $this->h - $this->margin - 10; // 10 mm au-dessus du bas
        $this->SetXY($x, $y);
        $this->MultiCell($this->w / 2, 4, utf8_decode($text), 0, 'L');
    }

    protected function drawFrame() {
        // $margin = 5;
        // $w = $this->GetPageWidth();
        // $h = $this->GetPageHeight();
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.5);
        $this->Rect($this->margin, $this->margin, $this->w - 2 * $this->margin, $this->h - 2 * $this->margin);
    }

    protected function drawCartouche($article, $tank_datas) {
        
        // $margin = 5;
        // $w = $this->GetPageWidth();
        // $h = $this->GetPageHeight();
        // $cw = 100; // largeur cartouche
        // $ch = 60; // hauteur cartouche
        $x = $this->w - $this->cw - $this->margin;
        $y = $this->h - $this->ch - $this->margin;

        // Cadre cartouche
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.5);
        $this->Rect($x, $y, $this->cw, $this->ch);

        // Logo
        if ($this->logo_url) {
            $this->Image($this->logo_url, $x + 2, $y + 2, 20); // exemple position + taille
        }


        $this->SetFont('Arial', 'B', 9);
        $this->SetXY($x + 25, $y + 3);
        

        $this->SetFont('Arial', '', 8);
        $lineHeight = 5;
        $startX = $x + 2;
        $this->SetXY($startX, $y + 15);

        $infos = [
            __('Group', 'creation-reservoir') . ':'      => $article->Groupe ?? '-',
            __('Product', 'creation-reservoir') . ':'    => $article->Article ?? '-',
            __('Material', 'creation-reservoir') . ':'   => $tank_datas['conception']->material_text ?? '-',
            __('Volume', 'creation-reservoir') . ':'     => ($tank_datas['dimensions']->Volume ?? '-') . ' L',
            __('Diameter', 'creation-reservoir') . ':'   => ($tank_datas['dimensions']->Diameter ?? '-') . ' mm',
            __('Insulation', 'creation-reservoir') . ':' => $article->insulationType ?? '-',
            __('Date', 'creation-reservoir') . ':'       => date('d/m/Y'),
            __('Created by', 'creation-reservoir') . ':' => $this->creator_name ?: '-',
        ];

        foreach ($infos as $label => $value) {
            $this->setX($startX);
            $this->Cell(30, $lineHeight, mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8'), 0, 0);

            // Sauvegarde la position Y et X après le label
            $x = $this->GetX();
            $y = $this->GetY();

            // Largeur restante pour la valeur
            $width = $this->GetPageWidth() - $this->GetX() - $this->rMargin;


            // MultiCell pour gérer les retours à la ligne
            $this->MultiCell($width, $lineHeight, mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8'), 0);

            // Si MultiCell a bougé sur plusieurs lignes, on remet X à gauche pour la suite
            $this->SetXY($startX, max($this->GetY(), $y + $lineHeight));
        }

    }

    protected function addTitle($article) {
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(0);
        // $this->Ln(10);
        $this->Cell(0, 12, mb_convert_encoding($article->Article ?? 'Tank', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->Ln(5);
    }

    protected function addDimensionsBlock($tank_datas) {
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, 'Dimensions', 0, 1);

        $this->SetFont('Arial', '', 8);

        $dims = [
            'Diameter' => $tank_datas['dimensions']->Diameter ?? '-',
            'Volume'   => $tank_datas['dimensions']->Volume ?? '-',
            'Height'   => $tank_datas['dimensions']->Height ?? '-',
        ];

        foreach ($dims as $label => $value) {
            $this->Cell(30, 6, $label, 0, 0);
            $this->Cell(0, 6, $value, 0, 1);
        }
    }

    protected function addCuveImage_png($pngUrl, $startX = null, $startY = null, $height = 150) {
        $startX = $startX ?? $this->margin * 2;
        $startX = $startX ?? $this->GetY();

        $pngPath = $this->get_local_path_from_url($pngUrl);

        if (file_exists($pngPath)) {
            
            // $this->Rect(130, $this->GetY(), 60, 60); // rectangle de test
            $this->Image($pngPath, $startX, $startY, 0, $height);
            // $this->MultiCell(80, 10, $pngPath, 0, 1);
            $this->Ln(65);
        } else {
            $this->SetXY($startX, $startY);
            $this->Cell(0, 10, __('Image not available', 'creation-reservoir'), 0, 1);
        }

        // $this->y_image_bottom = $this->GetY();
    }

    protected function addCuveImage($svgUrl, $startX = null, $startY = null, $height = 150) {
        $startX = $startX ?? $this->margin * 2;
        $startX = $startX ?? $this->GetY();

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
            $this->Image($pngPath, $startX, $startY, 0, $height);
            // $this->MultiCell(80, 10, $pngPath, 0, 1);
            $this->Ln(65);
        } else {
            $this->SetXY($startX, $startY);
            $this->Cell(0, 10, __('Image not available', 'creation-reservoir'), 0, 1);
        }

        // $this->y_image_bottom = $this->GetY();
    }

    protected function get_local_path_from_url($url) {
        $relativePath = str_replace(site_url('/'), ABSPATH, $url);
        return realpath($relativePath);
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
}