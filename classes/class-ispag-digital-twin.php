<?php
defined('ABSPATH') || exit;

/**
 * Digital Product Twin : page publique ouverte par le QR code de la plaque signalétique (/ispag-digital-product-twin/<serial>/).
 *
 * Page autonome (hors thème) : ni menus ni pied de page du site, pensée pour un téléphone sur le chantier.
 * Le numéro de série est « <type>-<n° de projet>-<matière>-<Id de l'article sur 5 chiffres> » ; l'article doit appartenir
 * au projet indiqué (un Id d'article seul ne suffit pas). Aucun prix n'est affiché.
 */
class ISPAG_Digital_Twin {

    const SLUG       = 'ispag-digital-product-twin';
    const QUERY_VAR  = 'ispag_twin';
    const RULES_OPT  = 'ispag_twin_rewrite_version';
    const RULES_VER  = 1;

    public static function init() {
        add_filter('query_vars', function ($vars) { $vars[] = self::QUERY_VAR; return $vars; });
        add_action('init', [self::class, 'add_rewrite'], 1);
        add_action('template_redirect', [self::class, 'render'], 0);
    }

    public static function add_rewrite() {
        add_rewrite_rule('^' . self::SLUG . '/([a-zA-Z0-9\-_]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=_', 'top');
        if ((int) get_option(self::RULES_OPT, 0) < self::RULES_VER) {
            flush_rewrite_rules(false);
            update_option(self::RULES_OPT, self::RULES_VER, false);
        }
    }

    /** Numéro de série demandé ('' = pas cette page, '_' = page de recherche). */
    private static function requested_serial() {
        $serial = (string) get_query_var(self::QUERY_VAR);
        if ($serial === '' && is_page(self::SLUG)) {
            $serial = (string) (get_query_var('serial') ?: ($_GET['serial'] ?? ''));
            $serial = $serial !== '' ? $serial : '_';
        }
        return sanitize_text_field($serial);
    }

    public static function render() {
        $serial = self::requested_serial();
        if ($serial === '') {
            return;
        }
        $data = $serial === '_' ? null : self::load($serial);

        nocache_headers();
        if ($serial !== '_' && !$data) {
            status_header(404);
        }
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        header('X-Robots-Tag: noindex, nofollow');
        self::output($serial, $data);
        exit;
    }

    // ------------------------------------------------------------------ données

    private static function load($serial) {
        global $wpdb;
        $parts      = explode('-', $serial);
        $project_no = $parts[1] ?? '';
        $article_id = (int) ($parts[3] ?? 0);
        if ($project_no === '' || !$article_id) {
            return null;
        }

        $project = $wpdb->get_row($wpdb->prepare(
            "SELECT hubspot_deal_id, ObjetCommande, NumCommande FROM {$wpdb->prefix}achats_liste_commande WHERE NumCommande = %s LIMIT 1",
            $project_no
        ));
        $article = $project ? apply_filters('ispag_get_article_by_id', null, $article_id) : null;
        // L'article doit appartenir au projet du numéro de série
        if (!$project || !$article || (int) ($article->hubspot_deal_id ?? 0) !== (int) $project->hubspot_deal_id) {
            return null;
        }

        $tank = ['conception' => null, 'dimensions' => null, 'insulation' => null];
        if (class_exists('ISPAG_Tank_Designer') && (int) ($article->Type ?? 0) === 1) {
            $tank = array_merge($tank, (array) (new ISPAG_Tank_Designer())->get_tank_data(null, $article_id));
        }

        // Garantie : N ans à partir de la livraison (réglable)
        $years   = max(1, (int) apply_filters('ispag_twin_warranty_years', 5));
        $ts      = (int) ($article->TimestampDateDeLivraisonFin ?? 0);
        $warranty = null;
        if ($ts > 0) {
            $end   = strtotime('+' . $years . ' years', $ts);
            $total = $end - $ts;
            $warranty = [
                'delivered' => $ts,
                'end'       => $end,
                'active'    => time() < $end,
                'days_left' => (int) floor(($end - time()) / DAY_IN_SECONDS),
                'percent'   => $total > 0 ? max(0, min(100, (int) round((time() - $ts) / $total * 100))) : 0,
            ];
        }

        // Documents publics : plan (dernier), certificats / documentation de l'article
        $docs = [];
        if (!empty($article->last_drawing_url)) {
            $approved = ($article->last_doc_type['slug'] ?? '') === 'drawingApproval';
            $docs[] = ['label' => $approved ? __('Approved drawing', 'creation-reservoir') : __('Technical drawing', 'creation-reservoir'), 'url' => $article->last_drawing_url, 'icon' => '📐'];
        }
        if (class_exists('ISPAG_Article_Repository')) {
            $repo = new ISPAG_Article_Repository();
            foreach ($repo->get_all_article_documents((int) $project->hubspot_deal_id, $article_id) as $d) {
                if (in_array($d['class'], ['certificat_conformity', 'documentation'], true)) {
                    $docs[] = ['label' => __($d['label'], 'creation-reservoir'), 'url' => $d['url'], 'icon' => '📄'];
                }
            }
        }

        return [
            'serial'   => $serial,
            'article'  => $article,
            'article_id' => $article_id,
            'project'  => $project,
            'tank'     => $tank,
            'warranty' => $warranty,
            'years'    => $years,
            'docs'     => $docs,
            'manual'   => (string) apply_filters('ispag_digital_twin_manual_url', '', $article, $serial),
        ];
    }

    // ------------------------------------------------------------------ rendu

    private static function specs($d) {
        $a = $d['article'];
        $c = $d['tank']['conception'] ?? null;
        $m = $d['tank']['dimensions'] ?? null;
        $i = $d['tank']['insulation'] ?? null;
        $rows = [];
        $add = function ($label, $value, $unit = '') use (&$rows) {
            if ($value !== null && $value !== '' && $value !== '0' && $value !== 0) {
                $rows[] = [$label, trim($value . ' ' . $unit)];
            }
        };
        if (is_object($c)) {
            $add(__('Type', 'creation-reservoir'), $c->TankType ?? '');
            $add(__('Material', 'creation-reservoir'), $c->material_text ?? '');
            $add(__('Support', 'creation-reservoir'), $c->Support ?? '');
        }
        if (is_object($m)) {
            $add(__('Nominal volume', 'creation-reservoir'), isset($m->Volume) ? number_format((float) $m->Volume, 0, '.', "'") : '', 'L');
            $add(__('Diameter', 'creation-reservoir'), $m->Diameter ?? '', 'mm');
            $add(__('Height', 'creation-reservoir'), $m->Height ?? '', 'mm');
            $add(__('Design pressure', 'creation-reservoir'), $m->MaxPressure ?? '', 'bar');
            $add(__('Test pressure', 'creation-reservoir'), $m->TestPressure ?? '', 'bar');
            $add(__('Max. temperature', 'creation-reservoir'), $m->usingTemperature ?? '', '°C');
        }
        if (is_object($i) && !empty($i->insulation)) {
            $add(__('Insulation', 'creation-reservoir'), trim(($i->insulation ?? '') . ' ' . ($i->InsulationThickness ?? '') . (!empty($i->InsulationThickness) ? ' mm' : '')));
        }
        return $rows;
    }

    private static function output($serial, $d) {
        $company = (string) get_option('wpcb_companyName', get_bloginfo('name'));
        $phone   = (string) get_option('wpcb_companyPhone', '');
        $mail    = (string) get_option('wpcb_companyMail', '');
        $lookup  = home_url('/' . self::SLUG . '/');
        $title   = $d ? (string) $d['article']->Article : __('Digital Product Twin', 'creation-reservoir');
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($title . ' · ' . $company); ?></title>
<style>
:root{--bg:#f3f4f6;--card:#fff;--ink:#111827;--muted:#6b7280;--line:#e5e7eb;--brand:#d32f2f;--ok:#15803d;--okbg:#dcfce7;--bad:#b91c1c;--badbg:#fee2e2}
@media (prefers-color-scheme:dark){:root{--bg:#0f172a;--card:#1e293b;--ink:#f1f5f9;--muted:#94a3b8;--line:#334155;--okbg:#14532d;--badbg:#7f1d1d}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:720px;margin:0 auto;padding:0 16px 40px}
.top{background:var(--brand);color:#fff;padding:14px 16px;font-weight:700;letter-spacing:.02em}
.top .in{max-width:720px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:12px}
.hero{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;margin-top:-1px;margin-top:16px}
.cat{color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:.06em}
h1{margin:4px 0 10px;font-size:1.5rem;line-height:1.25}
.sn{display:inline-flex;gap:8px;align-items:center;background:var(--bg);border:1px solid var(--line);border-radius:999px;padding:4px 6px 4px 12px;font-family:ui-monospace,Menlo,monospace;font-size:13px}
.sn button{border:0;background:var(--brand);color:#fff;border-radius:999px;padding:3px 10px;cursor:pointer;font-size:12px}
.badge{display:inline-block;margin-top:14px;border-radius:999px;padding:5px 12px;font-size:13px;font-weight:600}
.badge.ok{background:var(--okbg);color:var(--ok)}.badge.bad{background:var(--badbg);color:var(--bad)}
.bar{height:8px;background:var(--line);border-radius:999px;overflow:hidden;margin-top:10px}.bar>span{display:block;height:100%;background:var(--ok)}
.bar.bad>span{background:var(--bad)}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}
.fact{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px}
.fact small{display:block;color:var(--muted);font-size:12px}.fact b{font-size:15px}
h2{font-size:.95rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin:24px 4px 8px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
.row{display:flex;justify-content:space-between;gap:12px;padding:11px 16px;border-bottom:1px solid var(--line)}.row:last-child{border-bottom:0}
.row span{color:var(--muted)}.row b{text-align:right}
.docs a{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--line);color:var(--ink);text-decoration:none}.docs a:last-child{border-bottom:0}
.docs a:hover{background:var(--bg)}.docs .ic{font-size:22px}.docs small{display:block;color:var(--muted)}
.help{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-top:24px}
.btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.btn{display:inline-block;padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:600;border:1px solid var(--brand);color:var(--brand)}
.btn.p{background:var(--brand);color:#fff}
form.find{display:flex;gap:8px;margin-top:12px}form.find input{flex:1;padding:12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:16px}
form.find button{padding:12px 16px;border:0;border-radius:10px;background:var(--brand);color:#fff;font-weight:600;cursor:pointer}
.err{text-align:center;padding:40px 16px}.err .x{width:56px;height:56px;margin:0 auto 10px;border-radius:50%;background:var(--badbg);color:var(--bad);font-size:30px;font-weight:700;display:flex;align-items:center;justify-content:center}
.foot{text-align:center;color:var(--muted);font-size:12px;margin-top:28px}
</style>
</head>
<body class="ispag-digital-twin">
<div class="top"><div class="in"><span><?php echo esc_html($company); ?></span><span style="opacity:.85;font-weight:500;font-size:13px"><?php esc_html_e('Digital Product Twin', 'creation-reservoir'); ?></span></div></div>
<div class="wrap">
<?php if ($d): $a = $d['article']; $w = $d['warranty']; $specs = self::specs($d); ?>

    <section class="hero">
        <div class="cat"><?php echo esc_html(($a->Type_produit ?? '') ?: __('ISPAG equipment', 'creation-reservoir')); ?></div>
        <h1><?php echo esc_html(stripslashes((string) $a->Article)); ?></h1>
        <span class="sn"><?php esc_html_e('S/N', 'creation-reservoir'); ?> <b id="twin-serial"><?php echo esc_html($d['serial']); ?></b>
            <button type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('twin-serial').textContent);this.textContent='✓'"><?php esc_html_e('Copy', 'creation-reservoir'); ?></button></span>
        <?php if ($w): ?>
            <div><span class="badge <?php echo $w['active'] ? 'ok' : 'bad'; ?>">
                <?php echo $w['active']
                    ? esc_html(sprintf(__('Under warranty – %d days left', 'creation-reservoir'), $w['days_left']))
                    : esc_html__('Warranty expired', 'creation-reservoir'); ?></span></div>
            <div class="bar <?php echo $w['active'] ? '' : 'bad'; ?>" role="progressbar" aria-valuenow="<?php echo (int) $w['percent']; ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?php echo (int) $w['percent']; ?>%"></span></div>
        <?php endif; ?>
    </section>

    <div class="facts">
        <div class="fact"><small><?php esc_html_e('Project', 'creation-reservoir'); ?></small><b><?php echo esc_html($d['project']->ObjetCommande); ?></b></div>
        <?php if ($w): ?>
        <div class="fact"><small><?php esc_html_e('Delivered on', 'creation-reservoir'); ?></small><b><?php echo esc_html(wp_date('d.m.Y', $w['delivered'])); ?></b></div>
        <div class="fact"><small><?php echo esc_html(sprintf(__('Warranty until (%d years)', 'creation-reservoir'), $d['years'])); ?></small><b><?php echo esc_html(wp_date('d.m.Y', $w['end'])); ?></b></div>
        <?php endif; ?>
    </div>

    <?php if ($specs): ?>
    <h2><?php esc_html_e('Technical specifications', 'creation-reservoir'); ?></h2>
    <div class="card"><?php foreach ($specs as $r): ?><div class="row"><span><?php echo esc_html($r[0]); ?></span><b><?php echo esc_html($r[1]); ?></b></div><?php endforeach; ?></div>
    <?php endif; ?>

    <h2><?php esc_html_e('Documentation', 'creation-reservoir'); ?></h2>
    <div class="card docs">
        <?php foreach ($d['docs'] as $doc): ?>
            <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" rel="noopener"><span class="ic"><?php echo esc_html($doc['icon']); ?></span><span><strong><?php echo esc_html($doc['label']); ?></strong><small>PDF</small></span></a>
        <?php endforeach; ?>
        <?php if ($d['manual'] !== ''): ?>
            <a href="<?php echo esc_url($d['manual']); ?>" target="_blank" rel="noopener"><span class="ic">📘</span><span><strong><?php esc_html_e('Maintenance manual', 'creation-reservoir'); ?></strong><small><?php esc_html_e('View online', 'creation-reservoir'); ?></small></span></a>
        <?php endif; ?>
        <?php if (!$d['docs'] && $d['manual'] === ''): ?><div class="row"><span><?php esc_html_e('No document available online yet.', 'creation-reservoir'); ?></span></div><?php endif; ?>
    </div>

    <div class="help">
        <strong><?php esc_html_e('Need assistance?', 'creation-reservoir'); ?></strong>
        <p style="margin:6px 0 0;color:var(--muted)"><?php echo esc_html(sprintf(__('The %s team is here to help you.', 'creation-reservoir'), $company)); ?></p>
        <div class="btns">
            <?php if ($phone !== ''): ?><a class="btn p" href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $phone)); ?>">📞 <?php esc_html_e('Call', 'creation-reservoir'); ?></a><?php endif; ?>
            <?php if ($mail !== ''): ?><a class="btn" href="mailto:<?php echo esc_attr($mail); ?>?subject=<?php echo rawurlencode('Support ' . $d['serial']); ?>">✉️ <?php esc_html_e('Email support', 'creation-reservoir'); ?></a><?php endif; ?>
        </div>
    </div>

<?php else: ?>

    <section class="hero err">
        <?php if ($serial === '_'): ?>
            <h1><?php esc_html_e('Find your equipment', 'creation-reservoir'); ?></h1>
            <p style="color:var(--muted)"><?php esc_html_e('Enter the serial number printed on the nameplate, or scan its QR code.', 'creation-reservoir'); ?></p>
        <?php else: ?>
            <div class="x">!</div>
            <h1><?php esc_html_e('Product not found', 'creation-reservoir'); ?></h1>
            <p style="color:var(--muted)"><?php esc_html_e('The serial number is invalid or not found in our database.', 'creation-reservoir'); ?></p>
        <?php endif; ?>
        <form class="find" onsubmit="var s=this.s.value.trim();if(s){location.href='<?php echo esc_url($lookup); ?>'+encodeURIComponent(s)+'/';}return false;">
            <input name="s" type="text" placeholder="XX-0000-XX-00000" autocomplete="off" autocapitalize="characters" value="<?php echo $serial !== '_' ? esc_attr($serial) : ''; ?>">
            <button type="submit"><?php esc_html_e('Search', 'creation-reservoir'); ?></button>
        </form>
    </section>

<?php endif; ?>
    <p class="foot"><?php echo esc_html($company); ?><?php echo $phone !== '' ? ' · ' . esc_html($phone) : ''; ?></p>
</div>
</body>
</html>
        <?php
    }
}
