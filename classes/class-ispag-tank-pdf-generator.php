<?php

class ISPAG_Tank_TechSheet_Generator extends ISPAG_PDF_Generator{

    protected $y_image_bottom;

    public function generate_tech_sheet($project, $article, $tank_datas, $svg_path, $raccords = []) {
        $this->title = __('Technical specifications', 'creation-reservoir');
        $this->AddPage();
        $this->SetTitle(utf8_decode($this->title));
        $this->SetAutoPageBreak(true, 20);
        $this->addHeader();
        
        $this->addModernHeader($project, $article);
        $this->addArticleTitle($article);
        $this->addLayoutBlocks($article, $tank_datas, $svg_path, $raccords);
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

    protected function addLayoutBlocks($article, $tank_datas, $svg_path, $raccords) {
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
            $this->Cell(0, 5, ': ' . $value, 0, 1);
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


    protected function addBlocEchangeur($article) {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255); // blanc
        $this->SetDrawColor(218, 124, 81);  // orange ISPAG

        $heat_exchanger_datas = apply_filters('ispag_get_heat_exchanger_datas', null, $article->Id);
        if (empty($heat_exchanger_datas)) return;

        $datas = [];

        foreach ($heat_exchanger_datas as $key => $coil) {
            // récupère les valeurs
            $surface = !empty($coil['coilSurface']) && $coil['coilSurface'] > 0 ? $coil['coilSurface'] : null;
            $input   = !empty($coil['loadInputTemperature']) && $coil['loadInputTemperature'] > 0 ? $coil['loadInputTemperature'] : null;
            $output  = !empty($coil['loadOutputTemperature']) && $coil['loadOutputTemperature'] > 0 ? $coil['loadOutputTemperature'] : null;
            $cold    = !empty($coil['coldWaterInputTemperature']) && $coil['coldWaterInputTemperature'] > 0 ? $coil['coldWaterInputTemperature'] : null;
            $hot     = !empty($coil['hotWaterOutputTemperature']) && $coil['hotWaterOutputTemperature'] > 0 ? $coil['hotWaterOutputTemperature'] : null;
            $power   = !empty($coil['exchangerPower']) && $coil['exchangerPower'] > 0 ? $coil['exchangerPower'] : null;

            // construit la ligne uniquement avec les champs valides
            $parts = [];
            if ($surface !== null) $parts[] = sprintf(__('%sm²', 'creation-reservoir'), $surface);
            if ($power   !== null) $parts[] = sprintf(__('%s kW', 'creation-reservoir'), $power);
            if ($input !== null || $output !== null)
                $parts[] = sprintf(__('In %s°C / Out %s°C', 'creation-reservoir'), $input ?? '-', $output ?? '-');
            if ($cold !== null || $hot !== null)
                $parts[] = sprintf(__('Water %s°C / %s°C', 'creation-reservoir'), $cold ?? '-', $hot ?? '-');

            if (!empty($parts)) {
                $coil_nb = str_ireplace('coil', '', $key); // supprime "coil" peu importe la casse
                $line_title = sprintf(__('Coil %s', 'creation-reservoir'), $coil_nb);
                $line_data  = implode("\n", $parts);
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

        // Dessine le cadre après le contenu
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D');
        $this->Ln(3);
    }


    protected function addBlocSoudure($article) {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255); // blanc
        $this->SetDrawColor(218, 124, 81);  // orange ISPAG

        $welding_description = apply_filters('ispag_get_welding_text', null, $article->Id, false);
        $warranty_information = apply_filters('ispag_get_warranty_information', null, $article->Id);
        // error_log("PDF : " . var_export($article, true));
        if (empty($article->tank_on_site_welded)) return;

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Welding', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
        $this->SetFont('Arial', '', 8);

        
        // $this->MultiCell($this->GetPageWidth()/2, 5, mb_convert_encoding($welding_description, 'ISO-8859-1', 'UTF-8') ?? '');
        $this->MultiCell($this->GetPageWidth()/2, 5, mb_convert_encoding($warranty_information, 'ISO-8859-1', 'UTF-8') ?? '');
        $this->Ln(3);


         // Dessine le cadre après le contenu
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D'); // 120 = largeur bloc gauche

        $this->Ln(3);
    }

    protected function addBlocIsolation($article) {
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255); // blanc
        $this->SetDrawColor(218, 124, 81);  // orange ISPAG

        if (empty($article)) return;
        $insulation = apply_filters('ispag_get_related_insulation_information', null, $article->Id);

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, __('Insulation', 'creation-reservoir'), 0, 1);
        $this->SetFont('Arial', '', 8);

        $this->MultiCell($this->GetPageWidth()/2, 5, mb_convert_encoding($insulation, 'ISO-8859-1', 'UTF-8'));
        $this->Ln(3);


        // Dessine le cadre après le contenu
        $height = $this->GetY() - $y;
        $this->RoundedRect($x, $y, 110, $height, 3, 'D'); // 120 = largeur bloc gauche

        $this->Ln(3);
    }
        
    protected function addRaccordsList($article){
        $this->SetXY(130, $this->y_image_bottom+20);
        $x = $this->GetX();
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255); // blanc
        $this->SetDrawColor(218, 124, 81);  // orange ISPAG

        if (empty($article)) return;

        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 10, mb_convert_encoding(__('Fittings', 'creation-reservoir'), 'ISO-8859-1', 'UTF-8'), 0, 1);
        
        $this->SetX(130);
        $this->SetFont('Arial', '', 8);
        $this->MultiCell($this->GetPageWidth()/3, 5, mb_convert_encoding($article->fittings_description, 'ISO-8859-1', 'UTF-8'));
        $this->Ln(3);
        // Dessine le cadre après le contenu
        $height = $this->GetY() - $y;
        
        $this->RoundedRect($x, $y, $this->GetPageWidth()/3, $height, 3, 'D'); // 120 = largeur bloc gauche

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

}