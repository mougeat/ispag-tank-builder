<?php
defined('ABSPATH') || exit;

/**
 * Règles de conception des réservoirs stockées en base (remplace le bloc "restrictions" de assets/json/tank_data.json).
 *
 * Table achats_tank_rules : une ligne = une valeur autorisée ou par défaut pour un champ, dans une portée.
 *   scope    : 'typ' (type de réservoir), 'material' (matériau) ou 'insulation' (type d'isolation)
 *   scope_id : Id dans achats_tank_conception
 *   kind     : 'allowed' (valeur autorisée) ou 'default' (valeur par défaut)
 *   field    : Support, Material, insulation, InsulationThickness, insulationCover, MaxPressure, TestPressure, supplier
 *   value    : Id de conception (ou valeur numérique pour les pressions, Id de ispag_companies pour supplier)
 *   sort     : ordre (pour supplier, la valeur 0 = fournisseur proposé par défaut)
 *
 * Les formulaires JS reçoivent la même structure qu'avant (voir build_restrictions()).
 */
class ISPAG_Tank_Rules {

    const PAGE = 'ispag-tank-rules';

    /** Champs gérés par type de réservoir : [champ => [libellé, SelectType des options]] */
    const TYP_FIELDS = [
        'Support'             => ['Support', 'support'],
        'Material'            => ['Material', 'material'],
        'insulation'          => ['Insulation type', 'insulationType'],
        'InsulationThickness' => ['Insulation thickness', 'insulationThickness'],
    ];

    /** Champs à valeur par défaut unique saisie librement (pas une liste d'Id) */
    const TYP_DEFAULT_NUMBERS = ['MaxPressure' => 'Max pressure (bar)', 'TestPressure' => 'Test pressure (bar)'];

    public static function init() {
        add_action('wp_ajax_ispag_get_tank_rules', [self::class, 'ajax_get_rules']);
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_post_ispag_save_tank_rules', [self::class, 'handle_save']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'achats_tank_rules';
    }

    // ------------------------------------------------------------------ Noms de fournisseurs

    /** "Diem-Werke GmbH" et "Diemwerke" donnent la même clé : sans forme juridique, tirets, espaces ni casse. */
    public static function normalize_name($name) {
        $name = preg_replace('/\b(gmbh|s\.?a\.?|sarl|ag|ltd|inc|group|groupe)\b\.?/i', '', (string) $name);
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    }

    public static function suppliers() {
        global $wpdb;
        return (array) $wpdb->get_results("SELECT Id, company_name FROM {$wpdb->prefix}ispag_companies WHERE isSupplier = 1 AND company_name <> '' ORDER BY company_name ASC");
    }

    private static function supplier_id_by_name($name, array $suppliers) {
        $exact = null;
        $key = self::normalize_name($name);
        foreach ($suppliers as $s) {
            if ($s->company_name === $name) return (int) $s->Id;
            if ($exact === null && $key !== '' && self::normalize_name($s->company_name) === $key) $exact = (int) $s->Id;
        }
        return $exact;
    }

    // ------------------------------------------------------------------ Lecture

    private static function all_rows() {
        global $wpdb;
        return (array) $wpdb->get_results('SELECT scope, scope_id, kind, field, value, sort FROM ' . self::table() . ' ORDER BY scope, scope_id, kind, field, sort, Id');
    }

    /** Structure identique au bloc "restrictions" de tank_data.json (les fournisseurs sont donnés par leur nom). */
    public static function build_restrictions() {
        global $wpdb;
        $names = [];
        foreach (self::suppliers() as $s) $names[(int) $s->Id] = $s->company_name;

        $out = ['material' => [], 'typ' => [], 'insulation' => []];
        foreach (self::all_rows() as $r) {
            $id  = (string) $r->scope_id;
            $val = is_numeric($r->value) ? $r->value + 0 : $r->value;

            if ($r->field === 'supplier') {
                if (!isset($names[(int) $r->value])) continue;
                if ($r->scope === 'material')  $out['material'][$id]['default']['supplier_name'][] = $names[(int) $r->value];
                elseif ($r->scope === 'typ')   $out['typ'][$id]['default']['supplier_name'][] = $names[(int) $r->value];
                continue;
            }
            if ($r->scope === 'insulation') {
                $out['insulation'][$id][$r->field][] = $val;
            } elseif ($r->scope === 'typ' && $r->kind === 'default') {
                $out['typ'][$id]['default'][$r->field] = $val;
            } elseif ($r->scope === 'typ' && $r->kind === 'allowed') {
                $out['typ'][$id]['restrictions'][$r->field][] = $val;
            }
        }
        return $out;
    }

    public static function ajax_get_rules() {
        wp_send_json_success(['restrictions' => self::build_restrictions()]);
    }

    // ------------------------------------------------------------------ Installation / reprise de tank_data.json

    /** Remplit la table depuis tank_data.json, uniquement si elle est vide. */
    public static function maybe_seed() {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return true;
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") > 0) return true;

        $file = dirname(__DIR__) . '/assets/json/tank_data.json';
        $data = is_readable($file) ? json_decode(file_get_contents($file), true) : null;
        if (empty($data['restrictions'])) return true;
        $res = $data['restrictions'];

        $suppliers = self::suppliers();
        $valid_ins = array_map('intval', (array) $wpdb->get_col("SELECT Id FROM {$wpdb->prefix}achats_tank_conception WHERE SelectType = 'insulationType'"));
        $ok = true;
        $add = function ($scope, $scope_id, $kind, $field, $value, $sort = 0) use ($wpdb, $table, &$ok) {
            $done = $wpdb->insert($table, compact('scope', 'scope_id', 'kind', 'field', 'value', 'sort'));
            if ($done === false) $ok = false;
        };

        foreach ((array) ($res['material'] ?? []) as $id => $cfg) {
            foreach ((array) ($cfg['default']['supplier_name'] ?? []) as $i => $name) {
                $sid = self::supplier_id_by_name($name, $suppliers);
                if ($sid) $add('material', (int) $id, 'default', 'supplier', $sid, $i);
            }
        }
        foreach ((array) ($res['typ'] ?? []) as $id => $cfg) {
            foreach ((array) ($cfg['default'] ?? []) as $field => $value) {
                if ($field === 'supplier_name') {
                    foreach ((array) $value as $i => $name) {
                        $sid = self::supplier_id_by_name($name, $suppliers);
                        if ($sid) $add('typ', (int) $id, 'default', 'supplier', $sid, $i);
                    }
                } elseif ($field === 'insulation' && !in_array((int) $value, $valid_ins, true)) {
                    continue; // valeur par défaut invalide dans l'ancien JSON (n'est pas un type d'isolation)
                } else {
                    $add('typ', (int) $id, 'default', $field, $value);
                }
            }
            foreach ((array) ($cfg['restrictions'] ?? []) as $field => $values) {
                foreach ((array) $values as $i => $value) $add('typ', (int) $id, 'allowed', $field, $value, $i);
            }
        }
        foreach ((array) ($res['insulation'] ?? []) as $id => $cfg) {
            foreach ((array) $cfg as $field => $values) {
                foreach ((array) $values as $i => $value) $add('insulation', (int) $id, 'allowed', $field, $value, $i);
            }
        }
        return $ok;
    }

    // ------------------------------------------------------------------ Page d'administration

    public static function menu() {
        if (class_exists('ISPAG_Settings')) {
            add_submenu_page(ISPAG_Settings::PAGE, 'Tank rules', 'Tank rules', 'manage_options', self::PAGE, [self::class, 'render']);
        } else {
            add_options_page('ISPAG Tank rules', 'ISPAG Tank rules', 'manage_options', self::PAGE, [self::class, 'render']);
        }
    }

    private static function options($select_type) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT Id, Value FROM {$wpdb->prefix}achats_tank_conception WHERE SelectType = %s ORDER BY sort ASC, Id ASC", $select_type
        ));
    }

    /** Valeurs actuelles indexées [scope][scope_id][kind][field] => [valeurs triées] */
    private static function current() {
        $cur = [];
        foreach (self::all_rows() as $r) {
            $cur[$r->scope][(int) $r->scope_id][$r->kind][$r->field][] = $r->value;
        }
        return $cur;
    }

    private static function checkboxes($name, $options, array $checked, $label_key = 'Value') {
        echo '<div class="ispag-rule-checks">';
        foreach ($options as $o) {
            $id = (int) (isset($o->Id) ? $o->Id : 0);
            $label = isset($o->$label_key) ? $o->$label_key : ($o->company_name ?? '');
            printf(
                '<label><input type="checkbox" name="%s[]" value="%d" %s> %s</label>',
                esc_attr($name), $id, checked(in_array((string) $id, array_map('strval', $checked), true), true, false), esc_html($label)
            );
        }
        echo '</div>';
    }

    private static function supplier_block($base, array $suppliers, array $checked) {
        // $checked[0] = fournisseur par défaut
        $default = $checked[0] ?? '';
        self::checkboxes($base . '[supplier]', $suppliers, $checked, 'company_name');
        echo '<label class="ispag-rule-default">Default supplier : <select name="' . esc_attr($base) . '[supplier_default]"><option value="">–</option>';
        foreach ($suppliers as $s) {
            printf('<option value="%d" %s>%s</option>', (int) $s->Id, selected((string) $default, (string) $s->Id, false), esc_html($s->company_name));
        }
        echo '</select></label>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $cur       = self::current();
        $suppliers = self::suppliers();
        $types     = self::options('typ');
        $materials = self::options('material');
        $insul     = self::options('insulationType');
        $covers    = self::options('insulationCover');
        $thick     = self::options('insulationThickness');
        ?>
        <div class="wrap">
            <h1>Tank rules</h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Rules saved.</p></div><?php endif; ?>
            <p>Allowed values and defaults used by the tank form. Leave every box of a field unchecked to allow all values. The suppliers proposed for a tank are those of its type and of its material.</p>
            <style>
                .ispag-rule-card{background:#fff;border:1px solid #ccd0d4;padding:10px 16px;margin:0 0 14px;max-width:1100px}
                .ispag-rule-card h3{margin:.2em 0 .6em}
                .ispag-rule-row{margin:.6em 0}.ispag-rule-row>strong{display:block;margin-bottom:2px}
                .ispag-rule-checks label{display:inline-block;margin:0 14px 4px 0}
                .ispag-rule-default{display:block;margin-top:4px}
            </style>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ispag_save_tank_rules">
                <?php wp_nonce_field('ispag_save_tank_rules'); ?>

                <h2>By tank type</h2>
                <?php foreach ($types as $t): $tid = (int) $t->Id; $r = $cur['typ'][$tid] ?? []; ?>
                    <div class="ispag-rule-card">
                        <h3><?php echo esc_html($t->Value); ?></h3>
                        <?php foreach (self::TYP_FIELDS as $field => [$label, $select]): ?>
                            <div class="ispag-rule-row">
                                <strong><?php echo esc_html($label); ?> — allowed</strong>
                                <?php self::checkboxes("rules[typ][$tid][allowed][$field]", self::options($select), $r['allowed'][$field] ?? []); ?>
                                <?php if ($field !== 'InsulationThickness'): ?>
                                    <label class="ispag-rule-default">Default :
                                        <select name="rules[typ][<?php echo $tid; ?>][default][<?php echo esc_attr($field); ?>]">
                                            <option value="">–</option>
                                            <?php foreach (self::options($select) as $o): ?>
                                                <option value="<?php echo (int) $o->Id; ?>" <?php selected((string) (($r['default'][$field][0] ?? '')), (string) $o->Id); ?>><?php echo esc_html($o->Value); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="ispag-rule-row">
                            <?php foreach (self::TYP_DEFAULT_NUMBERS as $field => $label): ?>
                                <label style="margin-right:18px"><?php echo esc_html($label); ?> :
                                    <input type="number" step="0.1" style="width:80px" name="rules[typ][<?php echo $tid; ?>][default][<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($r['default'][$field][0] ?? ''); ?>">
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="ispag-rule-row">
                            <strong>Suppliers for this type</strong>
                            <?php self::supplier_block("rules[typ][$tid][default]", $suppliers, $r['default']['supplier'] ?? []); ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <h2>By material</h2>
                <?php foreach ($materials as $m): $mid = (int) $m->Id; $r = $cur['material'][$mid] ?? []; ?>
                    <div class="ispag-rule-card">
                        <h3><?php echo esc_html($m->Value); ?></h3>
                        <div class="ispag-rule-row">
                            <strong>Suppliers for this material</strong>
                            <?php self::supplier_block("rules[material][$mid][default]", $suppliers, $r['default']['supplier'] ?? []); ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <h2>By insulation type</h2>
                <?php foreach ($insul as $i): $iid = (int) $i->Id; $r = $cur['insulation'][$iid] ?? []; ?>
                    <div class="ispag-rule-card">
                        <h3><?php echo esc_html($i->Value); ?></h3>
                        <div class="ispag-rule-row"><strong>Allowed coverings</strong>
                            <?php self::checkboxes("rules[insulation][$iid][allowed][insulationCover]", $covers, $r['allowed']['insulationCover'] ?? []); ?></div>
                        <div class="ispag-rule-row"><strong>Allowed thicknesses</strong>
                            <?php self::checkboxes("rules[insulation][$iid][allowed][InsulationThickness]", $thick, $r['allowed']['InsulationThickness'] ?? []); ?></div>
                    </div>
                <?php endforeach; ?>

                <?php submit_button('Save rules'); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('ispag_save_tank_rules');
        global $wpdb;
        $table = self::table();
        $rules = isset($_POST['rules']) && is_array($_POST['rules']) ? wp_unslash($_POST['rules']) : [];
        $rows  = [];

        foreach (['typ', 'material', 'insulation'] as $scope) {
            foreach ((array) ($rules[$scope] ?? []) as $scope_id => $kinds) {
                $scope_id = (int) $scope_id;
                foreach ((array) $kinds as $kind => $fields) {
                    if (!in_array($kind, ['allowed', 'default'], true)) continue;
                    foreach ((array) $fields as $field => $values) {
                        $field = preg_replace('/[^A-Za-z_]/', '', $field);
                        if ($field === 'supplier_default') continue;
                        $list = is_array($values) ? $values : [$values];
                        $list = array_values(array_filter($list, function ($v) { return $v !== '' && $v !== null; }));
                        if ($field === 'supplier') {
                            // le fournisseur par défaut passe en tête (sort 0)
                            $def = isset($fields['supplier_default']) ? (string) $fields['supplier_default'] : '';
                            $list = array_map('strval', $list);
                            if ($def !== '' && in_array($def, $list, true)) {
                                $list = array_merge([$def], array_values(array_diff($list, [$def])));
                            }
                        }
                        foreach ($list as $i => $v) {
                            $rows[] = ['scope' => $scope, 'scope_id' => $scope_id, 'kind' => $kind, 'field' => $field, 'value' => sanitize_text_field($v), 'sort' => $i];
                        }
                    }
                }
            }
        }

        $wpdb->query("DELETE FROM `{$table}`");
        foreach ($rows as $row) $wpdb->insert($table, $row);

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'saved' => 1], class_exists('ISPAG_Settings') ? admin_url('admin.php') : admin_url('options-general.php')));
        exit;
    }
}
