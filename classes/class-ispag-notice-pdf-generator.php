<?php
/**
 * Class ISPAG_Notice_PDF_Generator
 *
 * Notice d'installation et d'utilisation d'un réservoir ISPAG (A4 portrait, FR + DE + EN dans un seul PDF).
 * Même charte que la fiche technique (ISPAG_Tank_TechSheet_Generator) ; les textes viennent de templates/notice_{lang}.json.
 *
 * Mise en page de chaque langue :
 *   1. bandeau : logo, titre de la notice, date, langue
 *   2. titre du réservoir, sous-titre (projet · groupe), quatre indicateurs clés
 *   3. première page, deux colonnes : généralités, description, caractéristiques | dessin du réservoir
 *   4. suite pleine largeur : bride, piquages, sécurité, installation, mise en service, maintenance, garantie
 *   5. pied de page : mention légale, langue et pagination
 */
defined('ABSPATH') || exit;

require_once ispag_project_manager_dir() . 'libs/fpdf/fpdf.php';
require_once ispag_project_manager_dir() . 'classes/class-ispag-pdf-generator.php';
require_once __DIR__ . '/class-ispag-tank-pdf-generator.php';

class ISPAG_Notice_PDF_Generator extends ISPAG_Tank_TechSheet_Generator
{
    const LANGUAGES = ['fr', 'de', 'en'];
    const WARN_BG   = [253, 240, 242];

    /** @var array Gabarit de la langue en cours. */
    protected $tpl = [];
    /** @var array Libellés de la langue en cours. */
    protected $labels = [];
    /** @var string Langue en cours (pour le pied de page). */
    protected $lang = 'fr';
    /** @var array Valeurs des champs dynamiques ({{...}} => valeur). */
    protected $values = [];
    /** @var int Première page de la langue en cours. */
    protected $lang_start_page = 1;
    /** @var string|null PNG du réservoir, converti une seule fois pour les trois langues. */
    protected $png;

    // ------------------------------------------------------------------ WordPress : scripts et AJAX

    public static function init()
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('wp_ajax_generate_notice_pdf', [__CLASS__, 'handle_ajax_request']);
    }

    public static function enqueue_scripts()
    {
        $js_path = ISPAG_PLUGIN_PATH . 'assets/js/ispag-notice-pdf.js';
        if (!file_exists($js_path)) {
            return;
        }

        wp_register_script(
            'ispag-notice-pdf',
            ISPAG_PLUGIN_URL . 'assets/js/ispag-notice-pdf.js',
            ['jquery'],
            filemtime($js_path),
            true
        );
        wp_add_inline_script(
            'ispag-notice-pdf',
            'var ispagNoticePdf = ' . json_encode([
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('ispag_nonce'),
            ]) . ';'
        );
        wp_enqueue_script('ispag-notice-pdf');
    }

    public static function handle_ajax_request()
    {
        try {
            check_ajax_referer('ispag_nonce', 'nonce');

            $article_id = isset($_POST['article_id']) ? intval($_POST['article_id']) : 0;
            if (!$article_id) {
                wp_send_json_error('ID de l\'article manquant.', 400);
            }

            wp_send_json_success(self::generate_multilingual_notice_pdf($article_id));
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage(), 500);
        }
    }

    /** Génère le PDF (FR, DE, EN), l'enregistre dans les téléversements et renvoie son URL. */
    public static function generate_multilingual_notice_pdf($article_id)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        $pdf = new self();
        $article = $pdf->generate_notice($article_id);

        $upload_dir = wp_upload_dir();
        $filename = 'Notice_Reservoir_' . self::sanitize_filename($article->Article) . '_Multilingue_' . time() . '.pdf';
        $pdf->Output('F', $upload_dir['path'] . '/' . $filename);

        return [
            'pdf_url'  => $upload_dir['url'] . '/' . $filename,
            'filename' => $filename,
        ];
    }

    protected static function sanitize_filename($filename)
    {
        $filename = iconv('UTF-8', 'ASCII//TRANSLIT', (string) $filename);
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename);
    }

    // ------------------------------------------------------------------ Données

    protected static function load_template($lang)
    {
        $dir = ISPAG_PLUGIN_PATH . 'templates/';
        $file = $dir . "notice_{$lang}.json";
        if (!file_exists($file)) {
            $file = $dir . 'notice_fr.json';
        }
        if (!file_exists($file)) {
            throw new Exception('Default template not found.');
        }

        $template = json_decode(file_get_contents($file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error JSON dans le template : ' . json_last_error_msg());
        }
        return $template;
    }

    protected function load_tank_datas($article_id)
    {
        $designer = new ISPAG_Tank_Designer();
        $tank_datas = $designer->get_tank_data(null, $article_id);

        $fittings = new ISPAG_Tank_Fittings();
        $tank_datas['piquages'] = $fittings->get_all_fittings($article_id, false);

        return $tank_datas;
    }

    /** Valeurs des champs {{...}}, vides si la donnée n'existe pas. */
    protected function build_values($article, $project, $tank_datas)
    {
        $designer = new ISPAG_Tank_Designer();
        $insulation = new ISPAG_Tank_Insulation();
        $c = $tank_datas['conception'] ?? null;
        $d = $tank_datas['dimensions'] ?? null;

        return [
            '{{article.Article}}'                       => (string) ($article->Article ?? ''),
            '{{project.ObjetCommande}}'                 => (string) ($project->ObjetCommande ?? ''),
            '{{tank_datas.conception.TankType}}'        => (string) $designer->get_tank_text_data($c->TankType ?? ''),
            '{{tank_datas.conception.material_text}}'   => (string) ($c->material_text ?? ''),
            '{{tank_datas.conception.Finition}}'        => (string) ($c->Finition ?? ''),
            '{{tank_datas.dimensions.Volume}}'          => (string) ($d->Volume ?? ''),
            '{{tank_datas.dimensions.Diameter}}'        => (string) ($d->Diameter ?? ''),
            '{{tank_datas.dimensions.Height}}'          => (string) ($d->Height ?? ''),
            '{{tank_datas.dimensions.MaxPressure}}'     => (string) ($d->MaxPressure ?? ''),
            '{{tank_datas.dimensions.TestPressure}}'    => (string) ($d->TestPressure ?? ''),
            '{{tank_datas.dimensions.usingTemperature}}' => (string) ($d->usingTemperature ?? ''),
            '{{tank_datas.insulation.insulationCover}}' => (string) $insulation->get_conception_value($tank_datas['insulation']->insulationCover ?? ''),
            '{{year}}'                                  => date('Y'),
        ];
    }

    /** Remplace les champs {{...}} ; une donnée absente devient « - ». */
    protected function fill($text)
    {
        $map = array_map(function ($v) { return $v === '' ? '-' : $v; }, $this->values);
        return str_replace(array_keys($map), array_values($map), (string) $text);
    }

    /** Vrai si le texte contient un champ {{...}} dont la donnée est vide. */
    protected function hasEmptyField($text)
    {
        foreach ($this->values as $key => $value) {
            if ($value === '' && strpos((string) $text, $key) !== false) return true;
        }
        return false;
    }

    // ------------------------------------------------------------------ Point d'entrée

    /** Dessine la notice dans les trois langues et renvoie l'article. */
    public function generate_notice($article_id)
    {
        $tank_datas = $this->load_tank_datas($article_id);
        $article = apply_filters('ispag_get_article_by_id', null, $article_id);
        if (!$article) {
            throw new Exception('Article not found.');
        }
        $project = apply_filters('ispag_get_project_by_deal_id', null, apply_filters('ispag_get_article_deal_id', null, $article_id));
        if (empty($article->Article)) {
            $article->Article = $article->ID ?? $article_id;
        }
        $svg_url = apply_filters('ispag_get_tank_svg', null, $article_id, false);
        $this->values = $this->build_values($article, $project, $tank_datas);

        $this->title = 'Notice';
        $this->AliasNbPages('{nb}');
        $this->SetTitle($this->cleanStr('Notice - ' . $article->Article), true);
        $this->SetAutoPageBreak(false);

        foreach (self::LANGUAGES as $lang) {
            $template = self::load_template($lang);

            // Gabarit et langue changent après AddPage : FPDF dessine d'abord le pied de la page précédente
            $this->AddPage();
            $this->lang = $lang;
            $this->tpl = $template;
            $this->labels = $template['labels'] ?? [];

            $this->drawBand($project, $article);
            $y = $this->drawTitle($project, $article);
            $y = $this->drawKpis($tank_datas, $y);

            $right_end = $this->drawDrawingColumn($svg_url, $y);
            $left_end = $this->drawIntroColumn($y);
            $y = $this->PageNo() === $this->lang_start_page ? max($left_end, $right_end) : $left_end;

            $this->drawFlowSections($y + 4, $tank_datas);
        }
        return $article;
    }

    // ------------------------------------------------------------------ Bandeau, indicateurs, pied de page

    protected function docTitle()
    {
        return $this->labels['doc_title'] ?? 'Notice';
    }

    protected function drawBand($project, $article)
    {
        $this->lang_start_page = $this->PageNo();
        parent::drawBand($project, $article);

        // Pastille de langue, à droite du titre du réservoir
        $code = $this->labels['code'] ?? strtoupper($this->lang);
        $this->roundedBox(self::MARGIN + 186 - 14, 32.5, 14, 6, self::RED, null, 1.5);
        $this->SetXY(self::MARGIN + 186 - 14, 33.4);
        $this->SetFont('Arial', 'B', 8.5);
        $this->color('text', self::WHITE);
        $this->Cell(14, 4, $this->t($code), 0, 0, 'C');
    }

    protected function kpiItems($tank_datas)
    {
        $d = $tank_datas['dimensions'] ?? null;
        $l = $this->labels;
        return [
            [$l['volume'] ?? 'Volume',     $d->Volume ?? null,      'L'],
            [$l['diameter'] ?? 'Diameter', $d->Diameter ?? null,    'mm'],
            [$l['height'] ?? 'Height',     $d->Height ?? null,      'mm'],
            [$l['pressure'] ?? 'Pressure', $d->MaxPressure ?? null, 'bar'],
        ];
    }

    public function Footer()
    {
        $this->SetY(-17);
        $this->color('draw', self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line(self::MARGIN, $this->GetY(), 198, $this->GetY());
        $this->Ln(2);

        $footer = $this->tpl['footer'] ?? [];
        $text = trim($this->fill($footer['text'] ?? '') . '   -   ' . ($footer['disclaimer'] ?? ''), ' -');

        $this->SetFont('Arial', '', 7.5);
        $this->color('text', self::MUTED);
        $this->Cell(150, 4, $this->t($text), 0, 0, 'L');
        $this->Cell(36, 4, $this->t(strtoupper($this->lang) . '  -  ' . ($this->labels['page'] ?? 'Page') . ' ' . $this->PageNo() . ' / {nb}'), 0, 0, 'R');
    }

    // ------------------------------------------------------------------ Première page : deux colonnes

    /** Colonne de droite : dessin du réservoir dans un cadre. Renvoie le Y suivant. */
    protected function drawDrawingColumn($svg_url, $y)
    {
        $x = $this->right_x;
        $w = $this->right_w;

        $y = $this->sectionTitle($x, $y, $w, $this->labels['drawing'] ?? 'Drawing');
        $box_h = 150;
        $this->roundedBox($x, $y, $w, $box_h, self::WHITE, self::LINE);
        $this->placeDrawing($svg_url, $x + 3, $y + 3, $w - 6, $box_h - 6);
        return $y + $box_h + 4;
    }

    protected function drawingPng($svgUrl)
    {
        // Conversion SVG -> PNG une seule fois pour les trois langues
        if ($this->png === null) {
            $this->png = parent::drawingPng($svgUrl) ?: '';
        }
        return $this->png ?: null;
    }

    /** Colonne de gauche : généralités, description et caractéristiques. Renvoie le Y suivant. */
    protected function drawIntroColumn($y)
    {
        $x = self::MARGIN;
        $w = self::LEFT_W;
        $sections = $this->tpl['sections'] ?? [];

        foreach (['generalites', 'description_appareil'] as $key) {
            if (empty($sections[$key]['content']) || !is_string($sections[$key]['content'])) continue;
            $y = $this->drawTextBlock($x, $y, $w, $sections[$key]['title'], $this->fill($sections[$key]['content']));
            $y += 3;
        }

        $model = $sections['model'] ?? null;
        if ($model && !empty($model['content']['table_rows'])) {
            $rows = [];
            foreach ($model['content']['table_rows'] as $row) {
                if (count($row) < 2 || $this->hasEmptyField($row[1])) continue;
                $rows[$row[0]] = $this->fill($row[1]);
            }
            if ($rows) {
                $y = $this->sectionTitle($x, $y, $w, $model['title']);
                $y = $this->keyValueRows($x, $y, $w, $rows, 50);
            }
        }
        return $y;
    }

    // ------------------------------------------------------------------ Suite : sections pleine largeur

    protected function drawFlowSections($y, $tank_datas)
    {
        $skip = ['generalites', 'description_appareil', 'model'];
        $x = self::MARGIN;
        $w = 186;

        foreach ($this->tpl['sections'] ?? [] as $key => $section) {
            if (!is_array($section) || in_array($key, $skip, true)) continue;

            $content = $section['content'] ?? null;
            $text = is_string($content) ? $content : ($content['text'] ?? '');

            // Piquages : tableau issu de la base
            $rows = null;
            if (is_array($content) && isset($content['table_headers'], $content['table_rows'])) {
                $rows = $content['table_rows'];
                if ($rows === '{{piquages}}') {
                    $rows = $this->fittingRows($tank_datas['piquages'] ?? []);
                    if (!$rows) continue;
                }
            }

            // Titre de groupe sans contenu : il s'affiche avec la première sous-section, jamais seul
            if ($text === '' && $rows === null) {
                $pending = $section['title'];
                continue;
            }

            // Un titre ne reste jamais seul en bas de page : on réserve la place de ce qui le suit
            $need = $rows !== null ? 38 : 28;
            if (isset($pending)) {
                $y = $this->flowHeading($x, $y, $w, $pending, $need + 8);
                unset($pending);
            }
            $y = $this->flowHeading($x, $y, $w, $section['title'], $need);

            if ($text !== '') {
                $y = $key === 'secu'
                    ? $this->flowCallout($x, $y, $w, $this->fill($text))
                    : $this->flowParagraphs($x, $y, $w, $this->fill($text));
            }
            if ($rows !== null) {
                $y = $this->flowTable($x, $y, $w, $content['table_headers'], $rows, $content['column_widths'] ?? []);
            }
            $y += 3;
        }
    }

    protected function fittingRows($piquages)
    {
        $rows = [];
        foreach ((array) $piquages as $p) {
            if (!is_object($p)) continue;
            $rows[] = [
                __($p->Type ?? '', 'creation-reservoir'),
                __($p->Accessories ?? '', 'creation-reservoir'),
                (string) ($p->Pouces ?? ''),
                (string) ($p->Height ?? ''),
            ];
        }
        return $rows;
    }

    /** Titre : « 3. Sécurité » (pastille rouge numérotée, filet) ou « 2.1. Description » (sous-titre). */
    protected function flowHeading($x, $y, $w, $title, $need = 28)
    {
        preg_match('/^(\d+(?:\.\d+)*)\.?\s*(.*)$/u', trim($title), $m);
        $number = $m[1] ?? '';
        $label = $m[2] ?? $title;
        $is_sub = strpos($number, '.') !== false;

        if ($is_sub) {
            $y = $this->ensure($y, $need);
            $this->color('fill', self::RED);
            $this->Rect($x, $y + 0.8, 1.4, 4, 'F');
            $this->SetXY($x + 4, $y);
            $this->SetFont('Arial', 'B', 9.5);
            $this->color('text', self::INK);
            $this->Cell($w - 4, 5.6, $this->t($number . '  ' . $label), 0, 0, 'L');
            return $y + 8;
        }

        $y = $this->ensure($y + 3, $need);
        $this->roundedBox($x, $y, 7, 7, self::RED, null, 1.6);
        $this->SetXY($x, $y + 0.9);
        $this->SetFont('Arial', 'B', 9);
        $this->color('text', self::WHITE);
        $this->Cell(7, 5.2, $this->t($number), 0, 0, 'C');
        $this->SetXY($x + 10, $y + 0.4);
        $this->SetFont('Arial', 'B', 12);
        $this->color('text', self::INK);
        $this->Cell($w - 10, 6.4, $this->t($label), 0, 0, 'L');
        $this->color('draw', self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line($x, $y + 9, $x + $w, $y + 9);
        return $y + 12;
    }

    /** Paragraphes ; une ligne commençant par « * » devient une puce. */
    protected function flowParagraphs($x, $y, $w, $text)
    {
        $lead = 4.6;
        foreach (preg_split('/\n/', str_replace("\r", '', $text)) as $line) {
            $line = trim($line);
            if ($line === '') {
                $y += 1.5;
                continue;
            }
            $bullet = $line[0] === '*';
            if ($bullet) $line = ltrim($line, "* \t");
            $indent = $bullet ? 6 : 0;

            $this->SetFont('Arial', '', 9);
            $lines = $this->wrapLines($this->t($line), $w - $indent - 1);
            foreach ($lines as $i => $l) {
                $y = $this->ensure($y, $lead);
                if ($bullet && $i === 0) {
                    $this->color('fill', self::RED);
                    $this->Rect($x + 1.5, $y + 1.7, 1.4, 1.4, 'F');
                }
                $this->SetXY($x + $indent, $y);
                $this->SetFont('Arial', '', 9);
                $this->color('text', self::INK);
                $this->Cell($w - $indent, $lead, $l, 0, 0, 'L');
                $y += $lead;
            }
            $y += 1.2;
        }
        return $y;
    }

    /** Consignes importantes : fond rosé et barre rouge (le bloc n'est jamais coupé entre deux pages). */
    protected function flowCallout($x, $y, $w, $text)
    {
        $lead = 4.6;
        $this->SetFont('Arial', '', 9);
        $wrapped = [];
        foreach (preg_split('/\n/', str_replace("\r", '', $text)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            foreach ($this->wrapLines($this->t($line), $w - 14) as $l) $wrapped[] = $l;
            $wrapped[] = null; // espace entre paragraphes
        }
        array_pop($wrapped);
        $h = 0;
        foreach ($wrapped as $l) $h += $l === null ? 1.5 : $lead;
        $h += 7;

        $y = $this->ensure($y, $h);
        $this->roundedBox($x, $y, $w, $h, self::WARN_BG);
        $this->color('fill', self::RED);
        $this->Rect($x, $y + 1, 1.4, $h - 2, 'F');

        $ly = $y + 3.5;
        foreach ($wrapped as $l) {
            if ($l === null) {
                $ly += 1.5;
                continue;
            }
            $this->SetXY($x + 6, $ly);
            $this->SetFont('Arial', '', 9);
            $this->color('text', self::INK);
            $this->Cell($w - 10, $lead, $l, 0, 0, 'L');
            $ly += $lead;
        }
        return $y + $h + 2;
    }

    /** Tableau : en-tête grisé, lignes alternées ; largeurs proportionnelles à $widths. */
    protected function flowTable($x, $y, $w, array $headers, array $rows, array $widths)
    {
        $n = count($headers);
        $sum = array_sum($widths) ?: 0;
        $cols = [];
        for ($i = 0; $i < $n; $i++) {
            $cols[] = $sum > 0 ? $w * ($widths[$i] ?? 0) / $sum : $w / $n;
        }
        // Un tableau court ne s'étire pas sur toute la largeur
        if ($n <= 3) {
            $max = 62 * $n;
            if (array_sum($cols) > $max) {
                $f = $max / array_sum($cols);
                $cols = array_map(function ($c) use ($f) { return $c * $f; }, $cols);
            }
        }

        // Chaque colonne garde la place de son en-tête ; le manque est repris sur la colonne la plus large
        $this->SetFont('Arial', 'B', 8);
        foreach ($headers as $i => $head) {
            $min = $this->GetStringWidth($this->t(mb_strtoupper($head))) + 5;
            if ($cols[$i] < $min) {
                $widest = array_search(max($cols), $cols);
                $cols[$widest] -= $min - $cols[$i];
                $cols[$i] = $min;
            }
        }

        $y = $this->ensure($y, 7 + 6 * min(count($rows), 3));
        $this->color('fill', self::PANEL);
        $this->Rect($x, $y, array_sum($cols), 6.2, 'F');
        $cx = $x;
        $this->SetFont('Arial', 'B', 8);
        $this->color('text', self::MUTED);
        foreach ($headers as $i => $head) {
            $this->SetXY($cx + 2, $y + 0.6);
            $this->Cell($cols[$i] - 3, 5, $this->t(mb_strtoupper($head)), 0, 0, 'L');
            $cx += $cols[$i];
        }
        $y += 6.2;

        foreach ($rows as $r => $row) {
            $y = $this->ensure($y, 6);
            $this->color('draw', self::LINE);
            $this->SetLineWidth(0.2);
            $this->Line($x, $y + 6, $x + array_sum($cols), $y + 6);
            $cx = $x;
            $this->SetFont('Arial', '', 8.5);
            $this->color('text', self::INK);
            foreach ($row as $i => $cell) {
                $this->SetXY($cx + 2, $y + 0.6);
                $this->Cell($cols[$i] - 3, 5, $this->t($this->fill($cell)), 0, 0, 'L');
                $cx += $cols[$i];
            }
            $y += 6;
        }
        return $y + 2;
    }
}

ISPAG_Notice_PDF_Generator::init();
