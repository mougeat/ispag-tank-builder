<?php
require_once __DIR__ . '/class-ispag-tank-accessories-svg.php';

/**
 * Plan (sketch) PDF d'un réservoir : A3 paysage, vue de face cotée, vue de dessus avec angles,
 * nomenclature (BOM), bloc d'informations et cartouche.
 */
class ISPAG_Tank_Drawing_Generator extends ISPAG_PDF_Generator{

    // Géométrie des SVG produits par ISPAG_Tank_SVG_Generator / ISPAG_Tank_SVG_Top_View_Generator (en mm réels)
    const FRONT_MARGIN_LEFT = 200;   // marge gauche du SVG de face
    const FRONT_PAD_X       = 340;   // espace conservé de chaque côté de la cuve (piquages)
    const FRONT_PAD_TOP     = 300;   // espace conservé au-dessus de la cuve (piquages verticaux)
    const TOP_INSULATION    = 160;
    const TOP_RADIAL_PAD    = 450;   // espace conservé autour du cercle de la cuve

    protected $margin = 5;
    protected $creator_name;
    protected $bom = [];          // [id => ['desc', 'qty', 'material']]
    protected $fitting_item = []; // fitting_id => id nomenclature
    protected $scale_label = '-';
    protected $front_scale = null;

    public function __construct($creator_name = 'Cyril Barthel') {

        parent::__construct('L', 'mm', 'A3'); // paysage, mm, A3
        $this->creator_name = $creator_name;
        $this->SetAutoPageBreak(false);
    }

    public function Footer() {
        // Pas de pied de page sur un plan
    }

    // =====================================================================
    // Point d'entrée
    // =====================================================================
    public function generate_drawing($article, $tank_datas, $title = null, $project = null) {

        $this->title = $title;

        $this->AddPage();
        $this->SetTitle($this->tx($this->title));
        $this->SetCreator('ISPAG');
        $this->SetLineWidth(0.3);

        $fittings = $this->load_fittings($article->Id);
        $this->build_bom($article, $tank_datas, $fittings);

        $this->drawFrame();

        // Vue de face et vue de dessus à la même échelle : la place de chacune découle de l'échelle commune
        [$front_h, $top_y, $top_h] = $this->view_layout($tank_datas);
        $this->drawFrontView($article, $tank_datas, $fittings, 9, 12, 216, $front_h);
        $this->drawTopView($article, $tank_datas, $fittings, 10, $top_y, 120, $top_h);
        $bom_end = $this->drawBomTable(235, 10);
        $this->drawInfoBlock($article, $tank_datas, $project, 242, min($bom_end + 12, 150));
        $this->drawDisclaimer(135, 262);
        $this->drawCartouche($article, $tank_datas, $project);
    }

    // =====================================================================
    // Données
    // =====================================================================
    protected function tx($str) {
        return mb_convert_encoding((string) $str, 'ISO-8859-1', 'UTF-8');
    }

    protected function load_fittings($article_id) {
        if (!class_exists('ISPAG_Tank_Fittings')) {
            return [];
        }
        $tank_fittings = new ISPAG_Tank_Fittings();
        $fittings = $tank_fittings->get_all_fittings($article_id);
        return is_array($fittings) ? $fittings : [];
    }

    protected function fitting_label($f) {
        $type = (isset($f->Type) && !is_numeric($f->Type)) ? __($f->Type, 'creation-reservoir') : __('Fitting', 'creation-reservoir');
        $label = trim($type . ' ' . ($f->Pouces ?? ''));
        if (!empty($f->Accessories)) {
            $label .= ' ' . __('with', 'creation-reservoir') . ' ' . __($f->Accessories, 'creation-reservoir');
        }
        if (!empty($f->madeFor)) {
            $label .= ' (' . trim($f->madeFor) . ')';
        }
        return $label;
    }

    /** Isolation liée à la cuve (article isolation du projet, ou livraison fournisseur) ; '' si aucune. */
    protected function get_insulation_text($article) {
        $id = $article->Id ?? 0;
        $html = apply_filters('ispag_get_related_insulation_information', null, $id);
        if (empty($html)) {
            $html = apply_filters('ispag_get_insulation_for_tank_description', null, $id, false);
        }
        if (empty($html)) {
            return '';
        }
        // On garde les deux premières lignes (épaisseur/type et revêtement), sans les conditions commerciales
        $lines = preg_split('/<br\s*\/?>|\R/i', (string) $html);
        $lines = array_values(array_filter(array_map(function ($l) {
            return trim(html_entity_decode(strip_tags($l), ENT_QUOTES, 'UTF-8'));
        }, $lines)));
        return implode(' - ', array_slice($lines, 0, 2));
    }

    /** Construit la nomenclature : un numéro par type de raccord, puis pieds / virole / échangeurs. */
    protected function build_bom($article, $tank_datas, $fittings) {
        $material = isset($tank_datas['conception']->material_text) ? __($tank_datas['conception']->material_text, 'creation-reservoir') : '';
        $keys = [];
        $this->bom = [];
        $this->fitting_item = [];

        foreach ($fittings as $f) {
            $label = $this->fitting_label($f);
            if (!isset($keys[$label])) {
                $id = count($this->bom) + 1;
                $keys[$label] = $id;
                $this->bom[$id] = ['desc' => $label, 'qty' => 0, 'material' => $material];
            }
            $id = $keys[$label];
            $this->bom[$id]['qty']++;
            $this->fitting_item[$f->fitting_id ?? spl_object_id($f)] = $id;
        }

        $coils = (array) apply_filters('ispag_get_heat_exchanger_datas', null, $article->Id);
        foreach (array_values($coils) as $i => $coil) {
            $surface = $coil['coilSurface'] ?? '?';
            $label = (($coil['spiraflex'] ?? '') == '1') ? __('Spiraflex coil', 'creation-reservoir') : __('Heat exchanger', 'creation-reservoir');
            $this->bom[] = ['desc' => $label . ' ' . ($i + 1) . ' - ' . $surface . ' m²', 'qty' => 1, 'material' => $material];
        }

        // Réindexe à partir de 1
        // (nomenclature vide, sans piquage ni échangeur : range(1, 0) donnerait [1, 0] et ferait échouer array_combine)
        $this->bom = $this->bom ? array_combine(range(1, count($this->bom)), array_values($this->bom)) : [];
    }

    protected function item_id($f) {
        return $this->fitting_item[$f->fitting_id ?? spl_object_id($f)] ?? 0;
    }

    protected function bottom_height($tank_datas) {
        $file = __DIR__ . '/../assets/json/tank_data.json';
        $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $material = $tank_datas['conception']->Material ?? null;
        $diam = $tank_datas['dimensions']->Diameter ?? null;
        return intval($data['arrayBottomHeight'][$material][$diam] ?? 0);
    }

    // =====================================================================
    // Cadre, cartouche, blocs d'information
    // =====================================================================
    protected function drawFrame() {
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.5);
        $this->Rect($this->margin, $this->margin, $this->w - 2 * $this->margin, $this->h - 2 * $this->margin);
        $this->SetLineWidth(0.3);
    }

    protected function drawCartouche($article, $tank_datas, $project) {
        $w = 160;
        $h = 62;
        $x = $this->w - $this->margin - $w;
        $y = $this->h - $this->margin - $h;
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.4);
        $this->Rect($x, $y, $w, $h);
        $this->SetLineWidth(0.25);

        // Logo
        // logo du site (ou celui d'origine) ; indisponible : on continue sans
        $this->drawLogo($x + 3, $y + 3, 38, 14);

        // Bloc droit : références
        $bx = $x + 62;
        $bw = $w - 62;
        $this->Line($bx, $y, $bx, $y + $h);
        $this->Line($bx, $y + 26, $x + $w, $y + 26);

        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 7);
        $this->SetXY($bx + 1, $y + 1);
        $this->Cell(40, 4, $this->tx(__('Article', 'creation-reservoir') . ':'), 0, 0);
        $this->SetFont('Arial', 'B', 13);
        $this->SetXY($bx + 1, $y + 6);
        $this->MultiCell($bw - 2, 6, $this->tx($article->Article ?? '-'), 0, 'L');

        $project_name = $project->ObjetCommande ?? '';
        $this->SetFont('Arial', '', 8);
        $this->SetXY($bx + 1, $y + 20);
        $this->Cell($bw - 2, 5, $this->tx($project_name), 0, 0);

        $scale_txt = $this->scale_label;
        $rows = [
            [__('Group', 'creation-reservoir'), $article->Groupe ?? '-'],
            [__('Date', 'creation-reservoir'), date('d/m/Y')],
            [__('Created by', 'creation-reservoir'), $this->creator_name ?: '-'],
            [__('Scale', 'creation-reservoir'), $scale_txt],
            [__('Format', 'creation-reservoir'), 'A3'],
            [__('Material', 'creation-reservoir'), isset($tank_datas['conception']->material_text) ? __($tank_datas['conception']->material_text, 'creation-reservoir') : '-'],
        ];
        $ry = $y + 26;
        $rh = 6;
        foreach ($rows as $i => $row) {
            $this->SetFont('Arial', '', 7);
            $this->SetXY($bx + 1, $ry);
            $this->Cell(24, $rh, $this->tx($row[0] . ':'), 0, 0);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell($bw - 26, $rh, $this->tx($row[1]), 0, 0);
            $ry += $rh;
            if ($i < count($rows) - 1) {
                $this->Line($bx, $ry, $x + $w, $ry);
            }
        }

        // Bloc gauche sous le logo : mention légale
        $this->SetFont('Arial', 'I', 6.5);
        $this->SetTextColor(90);
        $this->SetXY($x + 3, $y + 36);
        $this->MultiCell(56, 3.2, $this->tx(__('Dimensions in mm. Tolerance +/- 5 mm.', 'creation-reservoir')), 0, 'L');
        $this->SetTextColor(0);

        // Mention "pour information"
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(185);
        $this->SetXY($x, $y - 9);
        $this->Cell($w - 2, 8, $this->tx(__('SKETCH - FOR INFORMATION', 'creation-reservoir')), 0, 0, 'R');
        $this->SetTextColor(0);
    }

    protected function drawDisclaimer($x, $y) {
        $text = __("This sketch is given for information purposes only. The manufacturing plan will be sent later for validation and will be the only official document.", "creation-reservoir");
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(0);
        $this->SetXY($x, $y - 5);
        $this->Cell(60, 4, 'NB', 0, 0);
        $this->SetFont('Arial', '', 7.5);
        $this->SetXY($x, $y - 1);
        $this->MultiCell(92, 3.8, $this->tx($text), 0, 'L');
    }

    protected function drawInfoBlock($article, $tank_datas, $project, $x, $y) {
        $dim = $tank_datas['dimensions'] ?? null;
        $conc = $tank_datas['conception'] ?? null;

        // Capacité utile dans un ovale
        $cap = ($dim->Volume ?? '-') . ' Lt';
        $this->SetFont('Arial', 'I', 11);
        $label = __('Effective capacity', 'creation-reservoir') . ': ';
        $lw = $this->GetStringWidth($this->tx($label));
        $this->SetFont('Arial', 'B', 11);
        $vw = $this->GetStringWidth($this->tx($cap));
        $bw = $lw + $vw + 8;
        $this->SetLineWidth(0.35);
        $this->pill($x, $y, $bw, 8);
        $this->SetXY($x + 4, $y);
        $this->SetFont('Arial', 'I', 11);
        $this->Cell($lw, 8, $this->tx($label), 0, 0);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell($vw, 8, $this->tx($cap), 0, 0);
        $this->SetLineWidth(0.3);

        $y += 14;

        // Références
        $refs = [
            [__('Customer', 'creation-reservoir'), $project->nom_entreprise ?? '-'],
            [__('Order', 'creation-reservoir'), $project->ObjetCommande ?? '-'],
            [__('Order No.', 'creation-reservoir'), $project->NumCommande ?? '-'],
        ];
        foreach ($refs as $r) {
            $this->SetXY($x, $y);
            $this->SetFont('Arial', 'I', 6.5);
            $this->Cell(16, 3.4, $this->tx($r[0] . ':'), 0, 0);
            $this->SetFont('Arial', 'B', 6.5);
            $this->Cell(60, 3.4, $this->tx($r[1]), 0, 0);
            $y += 3.6;
        }
        $y += 6;

        // Quantité
        $qty = intval($article->Qty ?? 1) ?: 1;
        $this->SetFont('Arial', 'B', 20);
        $this->SetXY($x, $y);
        $this->Cell(60, 10, $this->tx('N° ' . $qty . ' Pcs'), 0, 0);
        $y += 12;

        // Caractéristiques
        $finition = $conc->Finition ?? '';
        $insulation = $this->get_insulation_text($article);
        $lines = [
            [__('Tank', 'creation-reservoir'), $article->Groupe ?? ($article->Article ?? '-')],
            [__('Material', 'creation-reservoir'), isset($conc->material_text) ? __($conc->material_text, 'creation-reservoir') : '-'],
            [__('Finish', 'creation-reservoir'), $finition ? __($finition, 'creation-reservoir') : '-'],
            [__('Pressure (exerc./test)', 'creation-reservoir'), ($dim->MaxPressure ?? '-') . ' bar / ' . ($dim->TestPressure ?? '-') . ' bar'],
            [__('Temperature', 'creation-reservoir'), ($dim->usingTemperature ?? '-') . ' °C'],
            [__('Insulation', 'creation-reservoir'), $insulation !== '' ? $insulation : __('NOT INSULATED', 'creation-reservoir')],
        ];
        foreach ($lines as $l) {
            $this->SetXY($x, $y);
            $this->SetFont('Arial', 'I', 9);
            $lw = $this->GetStringWidth($this->tx($l[0] . ': ')) + 0.5;
            $this->Cell($lw, 5, $this->tx($l[0] . ': '), 0, 0);
            $this->SetFont('Arial', 'B', 9);
            $this->MultiCell(150 - $lw, 5, $this->tx($l[1]), 0, 'L');
            $y = max($y + 5, $this->GetY());
        }
    }

    // =====================================================================
    // Nomenclature
    // =====================================================================
    protected function drawBomTable($x, $y) {
        $widths = [9, 88, 58, 11, 13];
        $heads = ['ID', __('Description', 'creation-reservoir'), __('Material', 'creation-reservoir'), 'UM', __('Qty', 'creation-reservoir')];
        $rh = count($this->bom) > 22 ? 4.2 : 5;

        $this->SetDrawColor(0);
        $this->SetLineWidth(0.25);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetXY($x, $y);
        $this->Cell(array_sum($widths), 5.5, $this->tx(__('Bill of Materials', 'creation-reservoir') . ': ' . ($this->title ?? '')), 1, 1, 'C');
        $this->SetX($x);
        foreach ($heads as $i => $hd) {
            $this->Cell($widths[$i], $rh, $this->tx($hd), 1, 0, 'C');
        }
        $this->Ln();

        $this->SetFont('Arial', '', 7.5);
        foreach ($this->bom as $id => $row) {
            $this->SetX($x);
            $this->Cell($widths[0], $rh, $id, 1, 0, 'C');
            $this->Cell($widths[1], $rh, $this->tx($this->fit_text($row['desc'], $widths[1] - 2)), 1, 0, 'C');
            $this->Cell($widths[2], $rh, $this->tx($this->fit_text($row['material'], $widths[2] - 2)), 1, 0, 'C');
            $this->Cell($widths[3], $rh, 'PZ', 1, 0, 'C');
            $this->Cell($widths[4], $rh, $row['qty'], 1, 1, 'C');
        }
        return $this->GetY();
    }

    protected function fit_text($text, $max_width) {
        $t = $this->tx($text);
        if ($this->GetStringWidth($t) <= $max_width) {
            return $text;
        }
        while (mb_strlen($text) > 3 && $this->GetStringWidth($this->tx($text . '...')) > $max_width) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '...';
    }

    // =====================================================================
    // Vue de face
    // =====================================================================
    /**
     * Répartition verticale des deux vues pour qu'elles aient la MÊME échelle.
     * Échelle commune = la plus petite de : largeur disponible pour la vue de face, largeur de la vue de dessus,
     * et hauteur totale partagée entre les deux vues. Retourne [hauteur de la vue de face, Y de la vue de dessus,
     * hauteur de la vue de dessus].
     */
    protected function view_layout($tank_datas) {
        $dim = $tank_datas['dimensions'] ?? null;
        $top_limit = 290;   // bas de la zone de dessin
        $gap = 5;
        if (!$dim || empty($dim->Diameter) || empty($dim->Height)) {
            return [205, 222, 68];
        }

        $diam = floatval($dim->Diameter);
        $height = floatval($dim->Height);
        $vw = $diam + 2 * self::FRONT_PAD_X;
        $vh = $height + self::FRONT_PAD_TOP + 60;
        $half = $diam / 2 + self::TOP_RADIAL_PAD;

        $s_front_w = (216 - 34 - 52) / $vw;
        $s_top_w   = 120 / (2 * $half);
        $s_height  = ($top_limit - 12 - $gap) / ($vh + 2 * $half);
        $s = min($s_front_w, $s_top_w, $s_height);

        $front_h = $vh * $s;
        $top_h = 2 * $half * $s;
        return [$front_h, 12 + $front_h + $gap, $top_h];
    }

    protected function drawFrontView($article, $tank_datas, $fittings, $bx, $by, $bw, $bh) {
        $dim = $tank_datas['dimensions'] ?? null;
        if (!$dim || empty($dim->Diameter) || empty($dim->Height)) {
            $this->SetXY($bx, $by);
            $this->SetFont('Arial', 'I', 10);
            $this->Cell($bw, 8, $this->tx(__('Image not available', 'creation-reservoir')), 0, 1);
            return;
        }

        $diam = floatval($dim->Diameter);
        $height = floatval($dim->Height);
        $gc = floatval($dim->GroundClearance ?? 0);
        $bh_dome = $this->bottom_height($tank_datas);
        $dome = round($diam * 0.2);
        $svg_h = $dome * 2 + $height + $gc + 50;

        // Zone du SVG conservée
        $vx0 = self::FRONT_MARGIN_LEFT - self::FRONT_PAD_X;
        $vw = $diam + 2 * self::FRONT_PAD_X;
        $vy0 = $dome - self::FRONT_PAD_TOP;
        $vh = $height + self::FRONT_PAD_TOP + 60;

        $left_col = 34;  // cotes de gauche
        $right_col = 52; // cotes et bulles de droite
        $s = min(($bw - $left_col - $right_col) / $vw, $bh / $vh);
        $this->front_scale = $s;
        $this->scale_label = '1:' . max(1, round(1 / $s));

        $img_w = $vw * $s;
        $img_h = $vh * $s;
        $ix = $bx + $left_col;
        $iy = $by + ($bh - $img_h);

        $svg = $this->load_svg('ispag_get_tank_svg', $article->Id);
        if ($svg) {
            $png = $this->svg_to_png($svg, [$vx0, $vy0, $vw, $vh], 'sketch_front_' . intval($article->Id), 2600);
            if ($png) {
                $this->Image($png, $ix, $iy, $img_w, $img_h);
            }
        }

        // Conversions mm réels -> PDF
        $X = function ($real_x) use ($ix, $vx0, $s) { return $ix + ($real_x + self::FRONT_MARGIN_LEFT - $vx0) * $s; };           // x dans la cuve (0 = bord gauche)
        $Y = function ($h) use ($iy, $vy0, $dome, $height, $s) { return $iy + ($dome + $height - $h - $vy0) * $s; };           // hauteur depuis le sol
        $tank_l = $X(0);
        $tank_r = $X($diam);

        $this->SetDrawColor(0);
        $this->SetTextColor(0);
        $this->SetLineWidth(0.15);

        $this->drawFrontAccessories($fittings, $diam, $height, $X, $Y, $s);
        $this->drawFrontCoils($article, $tank_datas, $diam, $height, $gc, $bh_dome, $X, $Y, $s);

        // Cote du diamètre
        $yd = $iy + 4;
        $this->dimH($tank_l, $tank_r, $yd, 'Ø' . round($diam));
        $this->SetDrawColor(120);
        $this->Line($tank_l, $yd - 1, $tank_l, $Y($height - $bh_dome) );
        $this->Line($tank_r, $yd - 1, $tank_r, $Y($height - $bh_dome));
        $this->SetDrawColor(0);

        // Cotes verticales de gauche : hauteur totale + découpage
        $x_out = $bx + 6;
        $x_in = $bx + 22;
        $levels = [0, $gc, $gc + $bh_dome, $height - $bh_dome, $height];
        $levels = array_values(array_unique(array_map('round', array_filter($levels, function ($v) use ($height) { return $v >= 0 && $v <= $height; }))));
        sort($levels);
        $this->SetDrawColor(120);
        foreach ($levels as $lv) {
            $this->Line($x_out - 1, $Y($lv), $tank_l - 1.5, $Y($lv));
        }
        $this->SetDrawColor(0);
        $this->dimV($x_out, $Y(0), $Y($height), (string) round($height), true);
        for ($i = 0; $i < count($levels) - 1; $i++) {
            if (($levels[$i + 1] - $levels[$i]) * $s < 3) { continue; }
            $this->dimV($x_in, $Y($levels[$i]), $Y($levels[$i + 1]), (string) round($levels[$i + 1] - $levels[$i]), false);
        }

        // Cotes de droite : hauteur des piquages + bulles
        $groups = [];
        foreach ($fittings as $f) {
            $key = (string) round(floatval($f->Height ?? 0));
            $groups[$key][] = $f;
        }
        krsort($groups, SORT_NUMERIC);
        $x_start = $tank_r;
        $x_mid = $X($diam) + 28;
        $x_text = $x_mid + 6;
        $min_gap = 5.4;
        $last_y = null;
        $this->SetFont('Arial', '', 8.5);
        foreach ($groups as $h_key => $list) {
            $y_real = $Y(floatval($h_key));
            $y_text = $y_real;
            if ($last_y !== null && $y_text - $last_y < $min_gap) {
                $y_text = $last_y + $min_gap;
            }
            $last_y = $y_text;

            $this->SetDrawColor(90);
            $this->Line($x_start, $y_real, $x_mid, $y_real);
            if (abs($y_text - $y_real) > 0.01) {
                $this->Line($x_mid, $y_real, $x_mid, $y_text);
            }
            $this->Line($x_mid, $y_text, $x_text, $y_text);
            $this->SetDrawColor(0);

            $this->SetFont('Arial', '', 8.5);
            $this->SetXY($x_text + 0.5, $y_text - 2);
            $this->Cell(12, 4, $h_key, 0, 0, 'L');

            // Bulles triées par angle
            usort($list, function ($a, $b) { return intval($a->Angle ?? 0) <=> intval($b->Angle ?? 0); });
            $bx_ = $x_text + 14;
            foreach ($list as $f) {
                $this->balloon($bx_ + 2.4, $y_text, $this->item_id($f));
                $bx_ += 5.4;
            }
        }
    }

    // =====================================================================
    // Vue de dessus
    // =====================================================================
    protected function drawTopView($article, $tank_datas, $fittings, $bx, $by, $bw, $bh) {
        $dim = $tank_datas['dimensions'] ?? null;
        if (!$dim || empty($dim->Diameter)) {
            return;
        }
        $diam = floatval($dim->Diameter);
        $half = $diam / 2 + self::TOP_RADIAL_PAD;
        $side = min($bw, $bh);
        $s = min($side / (2 * $half), $this->front_scale ?: PHP_FLOAT_MAX);
        $img = 2 * $half * $s;
        $ix = $bx + ($bw - $img) / 2;
        $iy = $by + ($bh - $img) / 2;
        $cx = $ix + $img / 2;
        $cy = $iy + $img / 2;

        // Dessin vectoriel direct (plus de dépendance au SVG/PNG : positions toujours cohérentes)
        $tank_h = floatval($dim->Height ?? 0);
        $r = $diam / 2 * $s;
        $ins = self::TOP_INSULATION * $s;

        $this->SetDrawColor(150);
        $this->SetLineWidth(0.15);
        $this->SetDash(1.2, 1.2);
        $this->circle($cx, $cy, $r + $ins, 'D');
        $this->SetDash();

        $this->SetDrawColor(0);
        $this->SetFillColor(255);
        $this->SetLineWidth(0.25);
        foreach ($fittings as $f) {
            $this->drawTopFitting($f, $cx, $cy, $r, $ins, $s, $tank_h);
        }
        $this->SetFillColor(255);
        $this->circle($cx, $cy, $r, 'FD');
        $this->drawTopAccessories($fittings, $cx, $cy, $r, $diam, $tank_h, $s);
        $this->drawTopCoils($article, $tank_datas, $cx, $cy, $diam, $s);
        // piquages verticaux (au-dessus de la cuve) par-dessus la calotte
        $this->SetFillColor(255);
        foreach ($fittings as $f) {
            if (floatval($f->Height ?? 0) > $tank_h) {
                $this->circle($cx, $cy, max(0.8, floatval($f->InternalDiamter ?? 50) / 2 * $s), 'FD');
            }
        }

        // Angles + bulles
        $groups = [];
        foreach ($fittings as $f) {
            if (floatval($f->Height ?? 0) > $tank_h) { continue; } // piquage vertical : au centre
            $groups[intval($f->Angle ?? 0) % 360][] = $f;
        }
        $r_lab = ($diam / 2 + 370) * $s;
        $this->SetTextColor(0);
        foreach ($groups as $angle => $list) {
            $a = deg2rad($angle);
            $px = $cx + $r_lab * sin($a);
            $py = $cy + $r_lab * cos($a);
            $this->SetFont('Arial', '', 8);
            $this->SetXY($px - 8, $py - 4.5);
            $this->Cell(16, 4, $this->tx($angle . '°'), 0, 0, 'C');
            $n = count($list);
            $start = $px - ($n * 5.2) / 2 + 2.6;
            foreach ($list as $i => $f) {
                $this->balloon($start + $i * 5.2, $py + 2.2, $this->item_id($f));
            }
        }
        $this->SetFont('Arial', 'I', 7.5);
        $this->SetXY($bx, $by - 2);
        $this->Cell(40, 4, $this->tx(__('Top view', 'creation-reservoir')), 0, 0);
    }

    // =====================================================================
    // Primitives graphiques
    // =====================================================================
    protected function arrow($x, $y, $dx, $dy) {
        $len = 1.8;
        $ang = atan2($dy, $dx);
        foreach ([0.4, -0.4] as $off) {
            $this->Line($x, $y, $x - $len * cos($ang + $off), $y - $len * sin($ang + $off));
        }
    }

    protected function dimH($x1, $x2, $y, $label) {
        $this->SetLineWidth(0.15);
        $this->Line($x1, $y, $x2, $y);
        $this->arrow($x1, $y, -1, 0);
        $this->arrow($x2, $y, 1, 0);
        $this->SetFont('Arial', '', 9);
        $w = $this->GetStringWidth($this->tx($label));
        $this->SetFillColor(255);
        $this->Rect(($x1 + $x2) / 2 - $w / 2 - 1, $y - 5.5, $w + 2, 4.5, 'F');
        $this->SetXY(($x1 + $x2) / 2 - $w / 2 - 1, $y - 5.5);
        $this->Cell($w + 2, 4.5, $this->tx($label), 0, 0, 'C');
    }

    protected function dimV($x, $y1, $y2, $label, $big = false) {
        $this->SetLineWidth(0.15);
        $this->Line($x, $y1, $x, $y2);
        $this->arrow($x, $y1, 0, 1);
        $this->arrow($x, $y2, 0, -1);
        $this->SetFont('Arial', '', $big ? 9 : 8);
        $w = $this->GetStringWidth($this->tx($label)) + 1.5;
        $ym = ($y1 + $y2) / 2;
        $this->SetFillColor(255);
        $this->Rect($x - $w / 2, $ym - 2.2, $w, 4.4, 'F');
        $this->SetXY($x - $w / 2, $ym - 2.2);
        $this->Cell($w, 4.4, $this->tx($label), 0, 0, 'C');
    }

    protected function circle($cx, $cy, $r, $style = 'D') {
        $k = $this->k;
        $hp = $this->h;
        $m = 0.5522847498 * $r;
        $pts = [
            [$cx + $r, $cy],
            [$cx + $r, $cy + $m, $cx + $m, $cy + $r, $cx, $cy + $r],
            [$cx - $m, $cy + $r, $cx - $r, $cy + $m, $cx - $r, $cy],
            [$cx - $r, $cy - $m, $cx - $m, $cy - $r, $cx, $cy - $r],
            [$cx + $m, $cy - $r, $cx + $r, $cy - $m, $cx + $r, $cy],
        ];
        $this->_out(sprintf('%.2F %.2F m', $pts[0][0] * $k, ($hp - $pts[0][1]) * $k));
        for ($i = 1; $i < 5; $i++) {
            $p = $pts[$i];
            $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $p[0] * $k, ($hp - $p[1]) * $k, $p[2] * $k, ($hp - $p[3]) * $k, $p[4] * $k, ($hp - $p[5]) * $k));
        }
        $this->_out($style === 'F' ? 'f' : ($style === 'FD' || $style === 'DF' ? 'B' : 'S'));
    }

    /** Type d'accessoire interne : 'bend' (tube plongeant), 'spray' (tube diffuseur), 'baffle' (tôle de déflexion) ou ''. */
    protected function accessory_kind($f) {
        $txt = mb_strtolower((string) ($f->Accessories ?? ''));
        $id = intval($f->id_accessories ?? 0);
        if ($id === 15 || preg_match('/spray|sparge|diffus/', $txt) && !preg_match('/bend pipe/', $txt)) {
            return 'spray';
        }
        if ($id === 16 || preg_match('/baffle|d[ée]fle/', $txt)) {
            return 'baffle';
        }
        if ($id === 14 || preg_match('/bend pipe|plongeant|plongeur/', $txt)) {
            return 'bend';
        }
        return '';
    }

    /** Tube vu de côté : deux traits parallèles le long de la ligne brisée $pts (centre), rayon $r, bouchon final. */
    protected function drawTube(array $pts, $r) {
        $n = count($pts);
        for ($i = 0; $i < $n - 1; $i++) {
            $dx = $pts[$i + 1][0] - $pts[$i][0];
            $dy = $pts[$i + 1][1] - $pts[$i][1];
            $l = sqrt($dx * $dx + $dy * $dy) ?: 1;
            $nx = -$dy / $l * $r;
            $ny = $dx / $l * $r;
            foreach ([1, -1] as $sg) {
                $this->Line($pts[$i][0] + $sg * $nx, $pts[$i][1] + $sg * $ny, $pts[$i + 1][0] + $sg * $nx, $pts[$i + 1][1] + $sg * $ny);
            }
            if ($i > 0) { // pli (onglet) entre deux tronçons
                $this->Line($pts[$i][0] + $nx, $pts[$i][1] + $ny, $pts[$i][0] - $nx, $pts[$i][1] - $ny);
            }
        }
        $last = $pts[$n - 1];
        $dx = $last[0] - $pts[$n - 2][0];
        $dy = $last[1] - $pts[$n - 2][1];
        $l = sqrt($dx * $dx + $dy * $dy) ?: 1;
        $nx = -$dy / $l * $r;
        $ny = $dx / $l * $r;
        $this->Line($last[0] + $nx, $last[1] + $ny, $last[0] - $nx, $last[1] - $ny);
    }

    protected function coil_geo($article, $tank_datas, $diam, $height, $gc) {
        $coils = (array) apply_filters('ispag_get_heat_exchanger_datas', null, $article->Id);
        return ISPAG_Tank_Accessories_SVG::coil_geometry($coils, $diam, $height, $gc, ISPAG_Tank_Accessories_SVG::bottom_height($tank_datas));
    }

    /** Serpentins en vue de face : spires inclinées (traits fins). */
    protected function drawFrontCoils($article, $tank_datas, $diam, $height, $gc, $bh, $X, $Y, $s) {
        $this->SetDrawColor(90);
        $this->SetLineWidth(0.12);
        foreach ($this->coil_geo($article, $tank_datas, $diam, $height, $gc) as $g) {
            foreach ($g['layers'] as $l) {
                $r = $l['dc'] / 2;
                $count = max(2, min(80, (int) round($l['turns'])));
                $pitch = $g['h'] / $count;
                $xl = $X($g['cx'] - $r);
                $xr = $X($g['cx'] + $r);
                for ($t = 0; $t < $count; $t++) {
                    $z = $g['z0'] + $t * $pitch;
                    $this->Line($xl, $Y($z), $xr, $Y($z + $pitch / 2));
                }
                $this->Line($xl, $Y($g['z0']), $xl, $Y($g['z0'] + $g['h']));
                $this->Line($xr, $Y($g['z0']), $xr, $Y($g['z0'] + $g['h']));
            }
        }
    }

    /** Serpentins en vue de dessus : un cercle par couche. */
    protected function drawTopCoils($article, $tank_datas, $cx, $cy, $diam, $s) {
        $this->SetDrawColor(90);
        $this->SetLineWidth(0.12);
        $dim = $tank_datas['dimensions'];
        foreach ($this->coil_geo($article, $tank_datas, $diam, floatval($dim->Height), floatval($dim->GroundClearance ?? 0)) as $g) {
            foreach ($g['layers'] as $l) {
                $this->circle($cx + ($g['cx'] - $diam / 2) * $s, $cy, $l['dc'] / 2 * $s, 'D');
            }
        }
    }

    /** Accessoires internes en vue de face (traits interrompus) : tube plongeant, tube diffuseur, tôle de déflexion. */
    protected function drawFrontAccessories($fittings, $diam, $tank_h, $X, $Y, $s) {
        $this->SetDrawColor(70);
        $this->SetLineWidth(0.2);
        $this->SetDash(1.4, 0.9);
        foreach ($fittings as $f) {
            $kind = $this->accessory_kind($f);
            $h = floatval($f->Height ?? 0);
            if ($kind === '' || $h > $tank_h) { continue; }
            $a = deg2rad(intval($f->Angle ?? 0));
            $sin = sin($a);
            $dn = max(20, floatval($f->InternalDiamter ?? 50));
            $r = max(0.8, $dn / 2 * $s);
            $x0 = $X($diam / 2 * (1 + $sin));
            $y0 = $Y($h);
            $p = abs($sin);                 // projection de la profondeur sur la vue
            $dir = $sin > 0 ? -1 : 1;       // vers l'intérieur de la cuve
            if ($p < 0.25) {                // piquage de face ou de dos : tube vu par son extrémité
                $this->SetDash();
                $this->circle($x0, $y0, $r, 'D');
                $this->SetDash(1.4, 0.9);
                continue;
            }
            if ($kind === 'bend') {
                $up = $h > $tank_h / 2 ? -1 : 1;
                $x1 = $x0 + $dir * 120 * $p * $s;
                $x2 = $x1 + $dir * 200 * cos(M_PI_4) * $p * $s;
                $y2 = $y0 + $up * 200 * sin(M_PI_4) * $s;
                $this->drawTube([[$x0, $y0], [$x1, $y0], [$x2, $y2]], $r);
            } elseif ($kind === 'spray') {
                $x1 = $x0 + $dir * 0.7 * $diam * $p * $s;
                $this->drawTube([[$x0, $y0], [$x1, $y0]], $r);
                $this->SetDash();
                for ($i = 1; $i <= 8; $i++) { // perçages horizontaux, vus de face
                    $this->circle($x0 + ($x1 - $x0) * ($i / 9), $y0, 0.35, 'D');
                }
                $this->SetDash(1.4, 0.9);
            } else { // baffle
                $xp = $x0 + $dir * 70 * $p * $s;
                $half = 1.1 * $dn * $s;
                $t = max(0.6, 10 * $s);
                $this->Rect(min($xp, $xp + $dir * $t), $y0 - $half, $t, 2 * $half, 'D');
            }
        }
        $this->SetDash();
    }

    /** Accessoires internes en vue de dessus. */
    protected function drawTopAccessories($fittings, $cx, $cy, $r, $diam, $tank_h, $s) {
        $this->SetDrawColor(70);
        $this->SetLineWidth(0.2);
        $this->SetDash(1.4, 0.9);
        foreach ($fittings as $f) {
            $kind = $this->accessory_kind($f);
            if ($kind === '' || floatval($f->Height ?? 0) > $tank_h) { continue; }
            $a = deg2rad(intval($f->Angle ?? 0));
            $ux = sin($a);
            $uy = cos($a);
            $dn = max(20, floatval($f->InternalDiamter ?? 50));
            $w = max(1.2, $dn * $s);
            if ($kind === 'bend') {
                $this->radialRect($cx, $cy, $ux, $uy, $r - (120 + 200 * cos(M_PI_4)) * $s, $r, $w, 'S');
            } elseif ($kind === 'spray') {
                $this->radialRect($cx, $cy, $ux, $uy, $r - 0.7 * $diam * $s, $r, $w, 'S');
                $this->SetDash();
                for ($i = 1; $i <= 8; $i++) { // jets horizontaux de chaque côté du tube
                    $d = $r - 0.7 * $diam * $s * ($i / 9) - 0.0;
                    $px = $cx + $ux * $d;
                    $py = $cy + $uy * $d;
                    $nx = -$uy;
                    $ny = $ux;
                    foreach ([1, -1] as $sg) {
                        $this->Line($px + $sg * $nx * $w / 2, $py + $sg * $ny * $w / 2, $px + $sg * $nx * ($w / 2 + 1.6), $py + $sg * $ny * ($w / 2 + 1.6));
                    }
                }
                $this->SetDash(1.4, 0.9);
            } else {
                $this->radialRect($cx, $cy, $ux, $uy, $r - 80 * $s, $r - 70 * $s, 2.2 * $dn * $s, 'S');
            }
        }
        $this->SetDash();
    }

    /** Piquage latéral vu de dessus : tube radial (+ bride si type 24), de la paroi vers l'extérieur. */
    protected function drawTopFitting($f, $cx, $cy, $r, $ins, $s, $tank_h) {
        if (floatval($f->Height ?? 0) > $tank_h) {
            return; // vertical : dessiné au centre
        }
        $a = deg2rad(intval($f->Angle ?? 0));
        $ux = sin($a);   // 0° = bas de la vue, 90° = droite (comme le plan d'atelier)
        $uy = cos($a);
        $is_flange = intval($f->Type ?? 0) === 24;
        $len = $ins + ($is_flange ? 30 * $s : 0);
        $wd = max(1.2, floatval($f->InternalDiamter ?? 20) * $s);
        $this->radialRect($cx, $cy, $ux, $uy, $r - 0.3, $r + $len, $wd);
        if ($is_flange) {
            $fw = max($wd + 1, floatval($f->ExternalDiameter ?? 0) * $s);
            $ft = max(0.8, floatval($f->Thickness ?? 10) * $s);
            $this->radialRect($cx, $cy, $ux, $uy, $r + $len - $ft, $r + $len, $fw);
        }
    }

    /** Rectangle orienté selon (ux,uy), entre les rayons d1 et d2, de largeur $w. */
    protected function radialRect($cx, $cy, $ux, $uy, $d1, $d2, $w, $op = 'b') {
        $px = -$uy * $w / 2;
        $py = $ux * $w / 2;
        $pts = [
            [$cx + $ux * $d1 + $px, $cy + $uy * $d1 + $py],
            [$cx + $ux * $d2 + $px, $cy + $uy * $d2 + $py],
            [$cx + $ux * $d2 - $px, $cy + $uy * $d2 - $py],
            [$cx + $ux * $d1 - $px, $cy + $uy * $d1 - $py],
        ];
        $k = $this->k;
        $hp = $this->h;
        $this->_out(sprintf('%.2F %.2F m', $pts[0][0] * $k, ($hp - $pts[0][1]) * $k));
        for ($i = 1; $i < 4; $i++) {
            $this->_out(sprintf('%.2F %.2F l', $pts[$i][0] * $k, ($hp - $pts[$i][1]) * $k));
        }
        $this->_out($op);
    }

    protected function SetDash($black = null, $white = null) {
        $this->_out($black !== null ? sprintf('[%.3F %.3F] 0 d', $black * $this->k, $white * $this->k) : '[] 0 d');
    }

    protected function balloon($cx, $cy, $n) {
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(0);
        $this->SetFillColor(255);
        $this->circle($cx, $cy, 2.3, 'FD');
        $this->SetFont('Arial', '', 6.5);
        $this->SetTextColor(0);
        $this->SetXY($cx - 2.3, $cy - 1.6);
        $this->Cell(4.6, 3.2, (string) $n, 0, 0, 'C');
    }

    protected function pill($x, $y, $w, $h) {
        $r = $h / 2;
        $k = $this->k;
        $hp = $this->h;
        $m = 0.5522847498 * $r;
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $this->_out(sprintf('%.2F %.2F l', ($x + $w - $r) * $k, ($hp - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x + $w - $r + $m) * $k, ($hp - $y) * $k, ($x + $w) * $k, ($hp - ($y + $r - $m)) * $k, ($x + $w) * $k, ($hp - ($y + $r)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x + $w) * $k, ($hp - ($y + $r + $m)) * $k, ($x + $w - $r + $m) * $k, ($hp - ($y + $h)) * $k, ($x + $w - $r) * $k, ($hp - ($y + $h)) * $k));
        $this->_out(sprintf('%.2F %.2F l', ($x + $r) * $k, ($hp - ($y + $h)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', ($x + $r - $m) * $k, ($hp - ($y + $h)) * $k, $x * $k, ($hp - ($y + $r + $m)) * $k, $x * $k, ($hp - ($y + $r)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x * $k, ($hp - ($y + $r - $m)) * $k, ($x + $r - $m) * $k, ($hp - $y) * $k, ($x + $r) * $k, ($hp - $y) * $k));
        $this->_out('S');
    }

    // =====================================================================
    // SVG -> PNG
    // =====================================================================
    protected function get_local_path_from_url($url) {
        $relativePath = str_replace(site_url('/'), ABSPATH, $url);
        return realpath($relativePath);
    }

    /** Demande le SVG (sans cotation) via le filtre et retourne son contenu. */
    protected function load_svg($filter, $article_id) {
        $url = $filter === 'ispag_get_tank_svg'
            ? apply_filters($filter, null, $article_id, false)
            : apply_filters($filter, null, $article_id);
        if (!$url) {
            return '';
        }
        $path = $this->get_local_path_from_url($url);
        return ($path && file_exists($path)) ? file_get_contents($path) : '';
    }

    /**
     * Recadre le SVG sur $viewbox (mm réels), le rastérise en haute définition (fond blanc) et retourne le chemin du PNG.
     */
    protected function svg_to_png($svg, array $viewbox, $name, $px_width) {
        $px_height = (int) round($px_width * $viewbox[3] / $viewbox[2]);
        $tag = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s" width="%d" height="%d">',
            $viewbox[0], $viewbox[1], $viewbox[2], $viewbox[3], $px_width, $px_height
        );
        $svg = preg_replace('/<svg\b[^>]*>/', $tag, $svg, 1);
        $svg = preg_replace('/<\?xml[^>]*\?>/', '', $svg);
        // Plan : cuve sans remplissage (dégradés -> blanc) et sans accessoires SVG (redessinés en vectoriel)
        $svg = preg_replace('#<g id=[\'"]internal-(?:accessories|coils)[\'"].*?</g>#s', '', $svg);
        $svg = preg_replace('/url\(#[A-Za-z0-9_-]+\)/', '#fff', $svg);

        $upload_dir = wp_upload_dir();
        $dir = trailingslashit($upload_dir['basedir']) . 'ispag-svg/';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $svg_path = $dir . $name . '.svg';
        $png_path = $dir . $name . '.png';
        file_put_contents($svg_path, $svg);
        if (file_exists($png_path)) {
            unlink($png_path);
        }

        return $this->rasterize($svg_path, $png_path, $px_width, $px_height) ? $png_path : '';
    }

    protected function rasterize($svg_path, $png_path, $px_width, $px_height) {
        if (extension_loaded('imagick')) {
            try {
                $im = new Imagick();
                $im->setBackgroundColor(new ImagickPixel('white'));
                $im->readImage($svg_path);
                $im->setImageBackgroundColor('white');
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $im->setImageFormat('png');
                $im->setImageDepth(8);
                $im->writeImage($png_path);
                $im->clear();
                $im->destroy();
                if (file_exists($png_path)) {
                    return true;
                }
            } catch (Exception $e) {
                // repli sur rsvg-convert
            }
        }

        $cmd = 'rsvg-convert -w ' . intval($px_width) . ' -b white -f png -o ' . escapeshellarg($png_path) . ' ' . escapeshellarg($svg_path);
        exec($cmd, $output, $code);
        return $code === 0 && file_exists($png_path);
    }
}
