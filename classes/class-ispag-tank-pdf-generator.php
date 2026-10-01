<?php
/**
 * Class ISPAG_Tank_TechSheet_Generator
 *
 * Fiche technique PDF d'un réservoir ISPAG (A4 portrait).
 *
 * Mise en page :
 *   1. bandeau : logo, titre du document, référence projet
 *   2. titre du réservoir, sous-titre (projet · groupe)
 *   3. quatre indicateurs clés (volume, diamètre, hauteur, pression de service)
 *   4. deux colonnes : caractéristiques, échangeurs, isolation, soudure | dessin et piquages
 *   5. pied de page : coordonnées de la société, pagination
 *
 * Même point d'entrée qu'avant : generate_tech_sheet($project, $article, $tank_datas, $svg_path, $raccords).
 */
class ISPAG_Tank_TechSheet_Generator extends ISPAG_PDF_Generator
{
    // Palette
    const RED    = [210, 16, 52];
    const INK    = [30, 41, 59];
    const MUTED  = [100, 116, 139];
    const LINE   = [226, 232, 240];
    const PANEL  = [244, 246, 249];
    const WHITE  = [255, 255, 255];

    // Grille de la page (mm)
    const MARGIN   = 12;
    const COL_GAP  = 8;
    const LEFT_W   = 112;
    const TOP_NEXT = 22;   // début du contenu sur les pages suivantes

    /** @var float Largeur de la colonne de droite. */
    protected $right_w;
    /** @var float Position X de la colonne de droite. */
    protected $right_x;

    public function __construct()
    {
        parent::__construct();
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->right_x = self::MARGIN + self::LEFT_W + self::COL_GAP;
        $this->right_w = 210 - self::MARGIN - $this->right_x;
    }

    // ------------------------------------------------------------------ Point d'entrée

    public function generate_tech_sheet($project, $article, $tank_datas, $svg_path, $raccords = [])
    {
        $this->title = __('Technical specifications', 'creation-reservoir');
        $this->AliasNbPages('{nb}');
        $this->SetTitle($this->cleanStr($this->title . ' - ' . ($article->Article ?? '')), true);
        $this->SetAutoPageBreak(false);
        $this->AddPage();

        $this->drawBand($project, $article);
        $y = $this->drawTitle($project, $article);
        $y = $this->drawKpis($tank_datas, $y);

        // Colonne de droite d'abord : elle reste sur la première page ; celle de gauche peut continuer sur la suivante
        $right_end = $this->drawRightColumn($article, $svg_path, $y);
        $left_end  = $this->drawLeftColumn($project, $article, $tank_datas, $y);

        $this->drawNotes(max($left_end, $right_end));
    }

    // ------------------------------------------------------------------ Outils de dessin

    protected function color($type, array $rgb)
    {
        if ($type === 'fill') $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        elseif ($type === 'draw') $this->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
        else $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    /** Texte converti pour FPDF (Windows-1252). */
    protected function t($text)
    {
        return $this->cleanStr(html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8'));
    }

    /** Saute à la page suivante si $h ne tient plus ; renvoie le Y à utiliser. */
    protected function ensure($y, $h)
    {
        if ($y + $h <= 297 - 22) return $y;
        $this->AddPage();
        $this->drawMiniBand();
        return self::TOP_NEXT;
    }

    protected function roundedBox($x, $y, $w, $h, $fill, $border = null, $r = 2.5)
    {
        $this->color('fill', $fill);
        if ($border) {
            $this->color('draw', $border);
            $this->SetLineWidth(0.25);
        }
        $this->RoundedRect($x, $y, $w, $h, $r, $border ? 'DF' : 'F');
    }

    /** Titre de section : petites capitales rouges et filet. Renvoie le Y suivant. */
    protected function sectionTitle($x, $y, $w, $label)
    {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 8);
        $this->color('text', self::RED);
        $this->Cell($w, 5, $this->t(mb_strtoupper($label)), 0, 1, 'L');
        $this->color('draw', self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line($x, $y + 5.5, $x + $w, $y + 5.5);
        return $y + 8;
    }

    /** Lignes « libellé : valeur » sur fond alterné. Renvoie le Y suivant. */
    protected function keyValueRows($x, $y, $w, array $rows, $label_w = 44)
    {
        $i = 0;
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') continue;
            $y = $this->ensure($y, 6);
            if ($i % 2 === 0) {
                $this->color('fill', self::PANEL);
                $this->Rect($x, $y, $w, 5.8, 'F');
            }
            $this->SetXY($x + 2, $y + 0.4);
            $this->SetFont('Arial', '', 8.5);
            $this->color('text', self::MUTED);
            $this->Cell($label_w, 5, $this->t($label), 0, 0, 'L');
            $this->SetFont('Arial', 'B', 8.5);
            $this->color('text', self::INK);
            $this->Cell($w - $label_w - 4, 5, $this->t($value), 0, 0, 'L');
            $y += 5.8;
            $i++;
        }
        return $y + 2;
    }

    // ------------------------------------------------------------------ Bandeau et titre

    /** Titre du document affiché dans le bandeau. */
    protected function docTitle()
    {
        return __('Technical data sheet', 'creation-reservoir');
    }

    protected function drawBand($project, $article)
    {
        try {
            $this->Image($this->logo_url, self::MARGIN, 10, 34);
        } catch (Exception $e) {
            $this->SetXY(self::MARGIN, 11);
            $this->SetFont('Arial', 'B', 16);
            $this->color('text', self::RED);
            $this->Cell(60, 8, 'ISPAG', 0, 0, 'L');
        }

        $this->SetXY(100, 11);
        $this->SetFont('Arial', 'B', 15);
        $this->color('text', self::INK);
        $this->Cell(98, 7, $this->t($this->docTitle()), 0, 2, 'R');
        $this->SetFont('Arial', '', 8.5);
        $this->color('text', self::MUTED);
        $this->Cell(98, 5, $this->t(date('d.m.Y')), 0, 0, 'R');

        $this->color('fill', self::RED);
        $this->Rect(self::MARGIN, 24, 186, 0.9, 'F');
    }

    /** Bandeau réduit des pages suivantes. */
    protected function drawMiniBand()
    {
        $this->SetXY(self::MARGIN, 10);
        $this->SetFont('Arial', 'B', 9);
        $this->color('text', self::RED);
        $this->Cell(60, 5, 'ISPAG', 0, 0, 'L');
        $this->SetFont('Arial', '', 8.5);
        $this->color('text', self::MUTED);
        $this->Cell(126, 5, $this->t($this->docTitle()), 0, 0, 'R');
        $this->color('draw', self::LINE);
        $this->Line(self::MARGIN, 16, 198, 16);
    }

    protected function drawTitle($project, $article)
    {
        $y = 31;
        $this->SetXY(self::MARGIN, $y);
        $this->SetFont('Arial', 'B', 19);
        $this->color('text', self::INK);
        $this->MultiCell(186, 8, $this->t($article->Article ?? ''), 0, 'L');
        $y = $this->GetY() + 1;

        // Sous-titre : projet · groupe
        $sub = array_filter([
            $project->ObjetCommande ?? ($project->project_name ?? ''),
            $article->Groupe ?? '',
        ], function ($v) { return trim((string) $v) !== ''; });
        if ($sub) {
            $this->SetXY(self::MARGIN, $y);
            $this->SetFont('Arial', '', 10);
            $this->color('text', self::MUTED);
            $this->Cell(186, 6, $this->t(implode('   |   ', $sub)), 0, 1, 'L');
            $y = $this->GetY();
        }
        return $y + 5;
    }

    // ------------------------------------------------------------------ Indicateurs clés

    /** Les quatre indicateurs clés : [libellé, valeur, unité]. */
    protected function kpiItems($tank_datas)
    {
        $d = $tank_datas['dimensions'] ?? null;
        return [
            [__('Volume', 'creation-reservoir'),         $d->Volume ?? null,      'L'],
            [__('Diameter', 'creation-reservoir'),       $d->Diameter ?? null,    'mm'],
            [__('Height', 'creation-reservoir'),         $d->Height ?? null,      'mm'],
            [__('Design pressure', 'creation-reservoir'), $d->MaxPressure ?? null, 'bar'],
        ];
    }

    protected function drawKpis($tank_datas, $y)
    {
        $kpis = $this->kpiItems($tank_datas);

        $gap = 4;
        $w = (186 - 3 * $gap) / 4;
        $h = 20;
        foreach ($kpis as $i => [$label, $value, $unit]) {
            $x = self::MARGIN + $i * ($w + $gap);
            $this->roundedBox($x, $y, $w, $h, self::PANEL);

            $this->SetXY($x, $y + 3);
            $this->SetFont('Arial', '', 7.5);
            $this->color('text', self::MUTED);
            $this->Cell($w, 4, $this->t(mb_strtoupper($label)), 0, 2, 'C');

            $this->SetFont('Arial', 'B', 15);
            $this->color('text', self::INK);
            $text = ($value === null || $value === '') ? '-' : rtrim(rtrim(number_format((float) $value, 1, '.', "'"), '0'), '.');
            $this->Cell($w, 8, $this->t($text . ($value === null || $value === '' ? '' : ' ')), 0, 0, 'C');
            if ($value !== null && $value !== '') {
                // unité en petit, juste après la valeur
                $this->SetXY($x + $w / 2 + $this->GetStringWidth($text) / 2 + 0.6, $y + 9.2);
                $this->SetFont('Arial', '', 8);
                $this->color('text', self::MUTED);
                $this->Cell(10, 6, $this->t($unit), 0, 0, 'L');
            }
        }
        return $y + $h + 8;
    }

    // ------------------------------------------------------------------ Colonne de gauche

    protected function drawLeftColumn($project, $article, $tank_datas, $y)
    {
        $x = self::MARGIN;
        $w = self::LEFT_W;
        $d = $tank_datas['dimensions'] ?? null;
        $c = $tank_datas['conception'] ?? null;

        // Caractéristiques
        $y = $this->sectionTitle($x, $y, $w, __('Specifications', 'creation-reservoir'));
        $y = $this->keyValueRows($x, $y, $w, [
            __('Materials', 'creation-reservoir')        => !empty($c->material_text) ? __($c->material_text, 'creation-reservoir') : null,
            __('Temperature', 'creation-reservoir')      => isset($d->usingTemperature) && $d->usingTemperature !== '' ? $d->usingTemperature . ' °C' : null,
            __('Design pressure', 'creation-reservoir')  => isset($d->MaxPressure) && $d->MaxPressure !== '' ? $d->MaxPressure . ' bar' : null,
            __('Test pressure', 'creation-reservoir')    => isset($d->TestPressure) && $d->TestPressure !== '' ? $d->TestPressure . ' bar' : null,
            __('Tipping height', 'creation-reservoir')   => !empty($d->TippingHeight) ? $d->TippingHeight . ' mm' : null,
            __('Ground clearance', 'creation-reservoir') => !empty($d->GroundClearance) ? $d->GroundClearance . ' mm' : null,
        ]);

        // Échangeurs
        $y = $this->drawExchangers($article, $x, $y + 3, $w);

        // Isolation
        $y = $this->drawTextBlock($x, $y + 3, $w, __('Insulation', 'creation-reservoir'), $this->insulationText($article));

        // Soudure : réalisée par le client (réservoir livré en morceaux, sans garantie) ou par ISPAG sur site
        $welding = $this->weldingInfo($article);
        if ($welding['by_client'] && $welding['pieces'] > 1) {
            $y = $this->drawWarningBlock($x, $y + 3, $w, __('Welding', 'creation-reservoir'), [
                str_replace('%NB_PIECES%', $welding['pieces'], __('Tank delivered to site in %NB_PIECES% pieces', 'creation-reservoir')) . '.',
                __('Welding carried out by the customer.', 'creation-reservoir'),
                __('No warranty can be granted for this tank, as the welding is not carried out by us.', 'creation-reservoir'),
            ]);
        } elseif (!empty($article->tank_on_site_welded)) {
            $text = apply_filters('ispag_get_warranty_information', null, $article->Id ?? 0);
            $y = $this->drawTextBlock($x, $y + 3, $w, __('Welding', 'creation-reservoir'), $text);
        }
        return $y;
    }

    /** Nombre de morceaux (soudures + 1) et soudure faite par le client, lus en base. */
    protected function weldingInfo($article)
    {
        global $wpdb;
        $dim = $wpdb->get_row($wpdb->prepare(
            "SELECT Id, weldingByClient FROM {$wpdb->prefix}achats_tank_dimensions WHERE customerTankId = %d LIMIT 1",
            (int) ($article->Id ?? 0)
        ));
        if (!$dim) return ['by_client' => false, 'pieces' => 1];

        $nb = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}achats_tank_connection WHERE TankId = %d AND Type = 23", (int) $dim->Id
        ));
        return ['by_client' => (int) $dim->weldingByClient === 1, 'pieces' => $nb + 1];
    }

    /** Bloc d'avertissement : fond rosé, barre rouge à gauche, première ligne en gras. */
    protected function drawWarningBlock($x, $y, $w, $title, array $lines)
    {
        $this->SetFont('Arial', '', 8.5);
        $inner = $w - 12 - 2 * $this->cMargin - 0.5;
        $wrapped = [];
        foreach ($lines as $i => $line) {
            $this->SetFont('Arial', $i !== 1 ? 'B' : '', 8.5);
            foreach ($this->wrapLines($this->t($line), $inner) as $l) $wrapped[] = [$l, $i !== 1];
        }
        $h = count($wrapped) * 4.4 + 7;

        $y = $this->ensure($y, $h + 10);
        $y = $this->sectionTitle($x, $y, $w, $title);

        $this->roundedBox($x, $y, $w, $h, [253, 240, 242]);
        $this->color('fill', self::RED);
        $this->Rect($x, $y + 1, 1.4, $h - 2, 'F');

        $ly = $y + 3.5;
        foreach ($wrapped as [$line, $bold]) {
            $this->SetXY($x + 6, $ly);
            $this->SetFont('Arial', $bold ? 'B' : '', 8.5);
            $this->color('text', $bold ? self::INK : [120, 20, 40]);
            $this->Cell($w - 10, 4.4, $line, 0, 0, 'L');
            $ly += 4.4;
        }
        return $y + $h + 2;
    }

    protected function drawExchangers($article, $x, $y, $w)
    {
        $coils = apply_filters('ispag_get_heat_exchanger_datas', null, $article->Id ?? 0);
        if (empty($coils) || !is_array($coils)) return $y;

        $cards = [];
        foreach ($coils as $key => $coil) {
            $num = str_ireplace('coil', '', $key);
            $rows = [
                __('Surface', 'creation-reservoir')       => !empty($coil['coilSurface']) ? $coil['coilSurface'] . ' m²' : null,
                __('Power', 'creation-reservoir')         => !empty($coil['exchangerPower']) ? $coil['exchangerPower'] . ' kW' : null,
                __('Primary', 'creation-reservoir')       => (!empty($coil['loadInputTemperature']) || !empty($coil['loadOutputTemperature']))
                                                              ? ($coil['loadInputTemperature'] ?? '-') . ' / ' . ($coil['loadOutputTemperature'] ?? '-') . ' °C' : null,
                __('Water', 'creation-reservoir')         => (!empty($coil['coldWaterInputTemperature']) || !empty($coil['hotWaterOutputTemperature']))
                                                              ? ($coil['coldWaterInputTemperature'] ?? '-') . ' / ' . ($coil['hotWaterOutputTemperature'] ?? '-') . ' °C' : null,
                __('Pressure', 'creation-reservoir')      => !empty($coil['exchangerPression']) ? $coil['exchangerPression'] . ' bar' : null,
                __('Comment', 'creation-reservoir')       => !empty($coil['comment']) ? $coil['comment'] : null,
            ];
            if (!empty($coil['spiraflex']) && (string) $coil['spiraflex'] === '1') {
                $rows = [__('Type', 'creation-reservoir') => 'Spiraflex DN32'] + $rows;
            }
            if (array_filter($rows, function ($v) { return $v !== null; })) {
                $cards[] = [sprintf(__('Coil %s', 'creation-reservoir'), $num), $rows];
            }
        }
        if (!$cards) return $y;

        $y = $this->sectionTitle($x, $y, $w, _n('Heat exchanger', 'Heat exchangers', count($cards), 'creation-reservoir'));

        // Un échangeur : pleine largeur ; plusieurs : deux cartes côte à côte
        $per_row = count($cards) === 1 ? 1 : 2;
        $gap = 4;
        $card_w = ($w - ($per_row - 1) * $gap) / $per_row;
        $label_w = $per_row === 1 ? 36 : 22;

        foreach (array_chunk($cards, $per_row) as $group) {
            $tallest = 0;
            foreach ($group as $c) $tallest = max($tallest, count(array_filter($c[1], function ($v) { return $v !== null; })));
            $y = $this->ensure($y, 6 + $tallest * 5.8);

            $row_end = $y;
            foreach ($group as $i => [$title, $rows]) {
                $cx = $x + $i * ($card_w + $gap);
                $this->SetXY($cx, $y);
                $this->SetFont('Arial', 'B', 8.5);
                $this->color('text', self::INK);
                $this->Cell($card_w, 5, $this->t($title), 0, 1, 'L');
                $row_end = max($row_end, $this->keyValueRows($cx, $y + 5.5, $card_w, $rows, $label_w));
            }
            $y = $row_end;
        }
        return $y;
    }

    /** Bloc de texte libre (isolation, soudure) dans un cadre gris. */
    protected function drawTextBlock($x, $y, $w, $title, $text)
    {
        $text = trim(str_replace(["\r", '<br />', '<br>', '<br/>'], ["", "\n", "\n", "\n"], (string) $text));
        if ($text === '') return $y;

        $size = 8;
        $lead = 4;
        $this->SetFont('Arial', '', $size);
        // MultiCell retire 1 mm de marge de chaque côté : on mesure sur la largeur réellement disponible
        $inner = $w - 8 - 2 * $this->cMargin - 0.5;
        $lines = $this->wrapLines($this->t($this->reflow($text)), $inner);
        $h = max(9, count($lines) * $lead + 5);

        $y = $this->ensure($y, $h + 10);
        $y = $this->sectionTitle($x, $y, $w, $title);
        $this->SetFont('Arial', '', $size);
        $this->roundedBox($x, $y, $w, $h, self::PANEL);
        $this->SetXY($x + 4, $y + 2.5);
        $this->color('text', self::INK);
        $this->MultiCell($w - 8, $lead, implode("\n", $lines), 0, 'L');
        return $y + $h + 2;
    }

    /** Recolle les phrases coupées par un retour à la ligne (la ligne suivante commence par une minuscule). */
    protected function reflow($text)
    {
        $out = [];
        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($out && preg_match('/^\p{Ll}/u', $line)) {
                $out[count($out) - 1] .= ' ' . $line;
            } else {
                $out[] = $line;
            }
        }
        return implode("\n", $out);
    }

    /** Découpe un texte en lignes qui tiennent dans $width (mm) avec la police courante. */
    protected function wrapLines($text, $width)
    {
        $out = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/', trim($paragraph)) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->GetStringWidth($try) > $width) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    /** Texte d'isolation : ligne liée au réservoir, sinon isolation saisie dans le formulaire. */
    protected function insulationText($article)
    {
        $insulation = apply_filters('ispag_get_related_insulation_information', null, $article->Id ?? 0);
        if ($insulation) return $insulation;

        $tank_datas = apply_filters('ispag_get_tank_datas', null, $article->Id ?? 0);
        if (!empty($tank_datas['insulation']) && !empty($tank_datas['dimensions'])) {
            $i = $tank_datas['insulation'];
            if (!empty($i->insulation)) {
                return sprintf(
                    __('%dmm %s for %dL tank', 'creation-reservoir'),
                    $i->InsulationThickness ?? 0,
                    __($i->insulation, 'creation-reservoir'),
                    $tank_datas['dimensions']->Volume ?? 0
                ) . "\n" . __('Assembly at the customer\'s expense', 'creation-reservoir');
            }
        }
        return '';
    }

    // ------------------------------------------------------------------ Colonne de droite

    protected function drawRightColumn($article, $svg_path, $y)
    {
        $x = $this->right_x;
        $w = $this->right_w;

        // Dessin
        $y = $this->sectionTitle($x, $y, $w, __('Drawing', 'creation-reservoir'));
        $box_h = 112;
        $this->roundedBox($x, $y, $w, $box_h, self::WHITE, self::LINE);
        $this->placeDrawing($svg_path, $x + 3, $y + 3, $w - 6, $box_h - 6);
        $y += $box_h + 6;

        // Piquages
        $lines = $this->fittingLines($article->fittings_description ?? '');
        if ($lines) {
            $y = $this->sectionTitle($x, $y, $w, __('Fittings', 'creation-reservoir'));
            foreach ($lines as $i => $line) {
                $wrapped = $this->wrapLinesFor($line, $w - 12, 8);
                $h = count($wrapped) * 4.2 + 2.4;
                $y = $this->ensure($y, $h);
                if ($i % 2 === 0) {
                    $this->color('fill', self::PANEL);
                    $this->Rect($x, $y, $w, $h, 'F');
                }
                $this->color('fill', self::RED);
                $this->Rect($x + 1.8, $y + 1.9, 1.4, 1.4, 'F');
                $this->SetXY($x + 5.5, $y + 1.2);
                $this->SetFont('Arial', '', 8);
                $this->color('text', self::INK);
                $this->MultiCell($w - 7, 4.2, implode("\n", $wrapped), 0, 'L');
                $y += $h;
            }
        }
        return $y;
    }

    protected function wrapLinesFor($text, $width, $size)
    {
        $this->SetFont('Arial', '', $size);
        return $this->wrapLines($this->t($text), $width);
    }

    /** Une ligne par piquage à partir de la description texte/HTML. */
    protected function fittingLines($description)
    {
        $description = str_ireplace(['<br />', '<br>', '<br/>', '</li>', '</p>', '</tr>'], "\n", (string) $description);
        $lines = [];
        foreach (preg_split('/\n+/', wp_strip_all_tags($description)) as $line) {
            $line = trim(html_entity_decode($line, ENT_QUOTES, 'UTF-8'));
            if ($line !== '') $lines[] = $line;
        }
        return $lines;
    }

    /** Dessin du réservoir centré dans le cadre, proportions conservées. */
    protected function placeDrawing($svgUrl, $x, $y, $max_w, $max_h)
    {
        $png = $this->drawingPng($svgUrl);

        if (!$png) {
            $this->SetXY($x, $y + $max_h / 2 - 3);
            $this->SetFont('Arial', 'I', 8.5);
            $this->color('text', self::MUTED);
            $this->Cell($max_w, 6, $this->t(__('Drawing not available.', 'creation-reservoir')), 0, 0, 'C');
            return;
        }

        $size = @getimagesize($png);
        $ratio = ($size && $size[1] > 0) ? $size[0] / $size[1] : 0.5;
        $h = $max_h;
        $w = $h * $ratio;
        if ($w > $max_w) { $w = $max_w; $h = $w / $ratio; }
        $this->Image($png, $x + ($max_w - $w) / 2, $y + ($max_h - $h) / 2, $w, $h);
    }

    /** Chemin du PNG généré à partir du SVG du réservoir, ou null s'il n'est pas disponible. */
    protected function drawingPng($svgUrl)
    {
        $svgPath = $this->get_local_path_from_url($svgUrl);
        if (!$svgUrl || !file_exists($svgPath)) return null;

        $png = str_replace('.svg', '.png', $svgPath);
        if (file_exists($png)) @unlink($png);
        try {
            $this->convert_svg_to_png($svgPath, $png);
        } catch (Exception $e) {
            return null;
        }
        return file_exists($png) ? $png : null;
    }

    /** Convertit un fichier SVG en PNG (proportions conservées). */
    protected function convert_svg_to_png($svgPath, $pngPath)
    {
        if (!class_exists('Imagick')) {
            throw new Exception(__('Imagick extension is not installed.', 'creation-reservoir'));
        }

        $imagick = new Imagick();
        $imagick->setBackgroundColor(new ImagickPixel('white'));
        $imagick->readImage($svgPath);
        $imagick->resizeImage(1200, 2400, Imagick::FILTER_LANCZOS, 1, true);
        $imagick->setImageDepth(8);
        $imagick->setImageFormat('png');
        $imagick->writeImage($pngPath);
        $imagick->clear();
        $imagick->destroy();
    }

    /** Convertit une URL en chemin local. */
    protected function get_local_path_from_url($url)
    {
        return str_replace(site_url(), ABSPATH, (string) $url);
    }

    // ------------------------------------------------------------------ Remarque et pied de page

    protected function drawNotes($y)
    {
        // La remarque ne crée jamais de page : au pire elle se place juste au-dessus du pied de page
        $y = min($y + 2, 297 - 27);
        $this->SetXY(self::MARGIN, $y);
        $this->SetFont('Arial', 'I', 7.5);
        $this->color('text', self::MUTED);
        $this->MultiCell(186, 4, $this->t(__('Technical data is given for information and may be modified without notice. Dimensions in millimetres.', 'creation-reservoir')), 0, 'L');
    }

    public function Footer()
    {
        $this->SetY(-17);
        $this->color('draw', self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line(self::MARGIN, $this->GetY(), 198, $this->GetY());
        $this->Ln(2);

        $company = array_filter([
            get_option('wpcb_companyName'),
            get_option('wpcb_companyAdress'),
            trim(get_option('wpcb_companyNIP') . ' ' . get_option('wpcb_companyCity')),
            get_option('wpcb_companyMail'),
            get_option('wpcb_companyPhone'),
            get_option('wpcb_companyWebsite'),
        ]);

        $this->SetFont('Arial', '', 7.5);
        $this->color('text', self::MUTED);
        $this->Cell(160, 4, $this->t(implode('  -  ', $company)), 0, 0, 'L');
        $this->Cell(26, 4, $this->t(sprintf(__('Page %s / %s', 'creation-reservoir'), $this->PageNo(), '{nb}')), 0, 0, 'R');
    }

    // ------------------------------------------------------------------ Rectangle arrondi

    protected function RoundedRect($x, $y, $w, $h, $r = 2, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        $op = ($style == 'F') ? 'f' : (($style == 'FD' || $style == 'DF') ? 'B' : 'S');
        $arc = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_Arc($xc + $r * $arc, $yc - $r, $xc + $r, $yc - $r * $arc, $xc + $r, $yc);
        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc + $r, $yc + $r * $arc, $xc + $r * $arc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_Arc($xc - $r * $arc, $yc + $r, $xc - $r, $yc + $r * $arc, $xc - $r, $yc);
        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_Arc($xc - $r, $yc - $r * $arc, $xc - $r * $arc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    protected function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c ',
            $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k,
            $x3 * $this->k, ($h - $y3) * $this->k
        ));
    }
}
