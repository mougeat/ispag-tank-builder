<?php
/**
 * Class ISPAG_Tank_Welding_Site_Sheet
 *
 * Génère un PDF "fiche chantier" à destination des soudeurs pour les réservoirs
 * livrés à souder sur place.
 * Langue : celle du back-office au moment du clic (get_locale() / Polylang).
 */

defined('ABSPATH') || exit;

// 1. Chargement de FPDF
if (!class_exists('FPDF')) {
    $fpdf_path = ispag_project_manager_dir() . 'libs/fpdf/fpdf.php';
    if (file_exists($fpdf_path)) {
        require_once $fpdf_path;
    }
}

// 2. Chargement de l'autoloader de FPDI
$fpdi_autoload = ispag_project_manager_dir() . 'libs/fpdi/autoload.php';
if (file_exists($fpdi_autoload)) {
    require_once $fpdi_autoload;
}

class ISPAG_Tank_Welding_Site_Sheet extends \setasign\Fpdi\Fpdi
{
    protected static $instance = null;

    protected $logo_url  = 'https://app.ispag-asp.ch/wp-content/uploads/2024/06/Logo_ISPAG_CMYK_F_web.png';
    protected $schema_url  = 'https://app.ispag-asp.ch/wp-content/uploads/2026/09/access_schema.jpg';
    protected $logo_path;
    protected $schema_path;

    // Palette de la fiche technique
    const RED   = [210, 16, 52];
    const INK   = [30, 41, 59];
    const MUTED = [100, 116, 139];
    const LINE  = [226, 232, 240];
    const PANEL = [244, 246, 249];
    const WHITE = [255, 255, 255];

    protected $margin = 12;

    /** @var bool Pied de page actif sur la page courante (inactif sur les plans importés). */
    protected $footer_on = true;

    protected $tank_index = 0;
    protected $tank_total = 0;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');

        $this->logo_path = ISPAG_PLUGIN_PATH . 'assets/logo_ispag.png';
        // Logo du site s'il existe (sinon fichier d'origine, téléchargé au besoin)
        if (class_exists('ISPAG_Site_Logo') && ($site_logo = ISPAG_Site_Logo::path())) {
            $this->logo_path = $site_logo;
        }
        if (!file_exists($this->logo_path)) {
            $this->download_logo();
        }
        $this->schema_path = ISPAG_PLUGIN_PATH . 'assets/img/access_schema.png';
        // if (!file_exists($this->schema_path)) {
        //     $this->download_logo();
        // }

        $this->SetAutoPageBreak(true, 15);
        $this->SetMargins($this->margin, $this->margin, $this->margin);
    }

    public static function init()
    {
        require_once ISPAG_PLUGIN_PATH . 'classes/class-ispag-tank-repository.php';

        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_filter('ispag_get_welding_site_sheet_btn', [self::$instance, 'get_welding_site_sheet_btn'], 10, 2);
        add_action('wp_ajax_ispag_generate_welding_site_sheet', [self::$instance, 'ajax_generate_welding_site_sheet']);
        add_action('wp_ajax_ispag_check_welding_sheet_data', [self::$instance, 'ajax_check_welding_sheet_data']);
        add_action('wp_ajax_ispag_save_welding_sheet_data', [self::$instance, 'ajax_save_welding_sheet_data']);
    }

    /* ==================================================================== */
    /* BOUTON                                                               */
    /* ==================================================================== */

    public function get_welding_site_sheet_btn($html, $deal_id)
    {
        if (!current_user_can('manage_site_welding_datas')) {
            return $html;
        }

        ob_start();
        ?>
        <button type="button" id="generate-welding-site-sheet" class="ispag-btn ispag-btn-secondary-outlined">
            <span class="dashicons dashicons-admin-tools"></span>
            <?php echo esc_html__('Welding site checklist', 'creation-reservoir'); ?>
        </button>

        <!-- Modale complète de saisie des informations de chantier -->
        <div id="ispag-welding-modal" style="display:none; position:fixed; z-index:99999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; overflow-y:auto; padding:20px 0;">
            <div style="background:#fff; padding:25px; border-radius:6px; width:650px; max-width:95%; max-height:90vh; overflow-y:auto; box-shadow:0 4px 15px rgba(0,0,0,0.2);">
                <h3 style="margin-top:0; color:#b00;"><?php echo esc_html__('Site & Delivery Information', 'creation-reservoir'); ?></h3>
                <p style="font-size:13px; color:#555;"><?php echo esc_html__('Please fill in or verify the missing site details below. They will be saved for future generations:', 'creation-reservoir'); ?></p>
                
                <form id="ispag-welding-info-form">
                    <input type="hidden" name="deal_id" value="<?php echo intval($deal_id); ?>">
                    
                    <!-- Adresse & Contact -->
                    <fieldset style="border:1px solid #ddd; padding:10px; margin-bottom:15px; border-radius:4px;">
                        <legend style="font-weight:bold; font-size:12px; color:#b00; padding:0 5px;"><?php echo esc_html__('Address & Contact', 'creation-reservoir'); ?></legend>
                        <div style="margin-bottom:8px;">
                            <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Address', 'creation-reservoir'); ?></label>
                            <input type="text" name="AdresseDeLivraison" id="modal_AdresseDeLivraison" style="width:100%; padding:5px; box-sizing:border-box;">
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Address line 2', 'creation-reservoir'); ?></label>
                            <input type="text" name="DeliveryAdresse2" id="modal_DeliveryAdresse2" style="width:100%; padding:5px; box-sizing:border-box;">
                        </div>
                        <div style="display:flex; gap:10px; margin-bottom:8px;">
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('ZIP Code', 'creation-reservoir'); ?></label>
                                <input type="text" name="NIP" id="modal_NIP" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                            <div style="flex:2;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('City', 'creation-reservoir'); ?></label>
                                <input type="text" name="City" id="modal_City" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Contact Person', 'creation-reservoir'); ?></label>
                                <input type="text" name="PersonneContact" id="modal_PersonneContact" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Contact Phone', 'creation-reservoir'); ?></label>
                                <input type="text" name="num_tel_contact" id="modal_num_tel_contact" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Accessibilité -->
                    <fieldset style="border:1px solid #ddd; padding:10px; margin-bottom:15px; border-radius:4px;">
                        <legend style="font-weight:bold; font-size:12px; color:#b00; padding:0 5px;"><?php echo esc_html__('Accessibility & internal path', 'creation-reservoir'); ?></legend>
                        <div style="display:flex; gap:10px; margin-bottom:8px;">
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Corridor width (cm)', 'creation-reservoir'); ?></label>
                                <input type="text" name="corridor_width" id="modal_corridor_width" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Smallest door width (cm)', 'creation-reservoir'); ?></label>
                                <input type="text" name="door_width" id="modal_door_width" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Number of doors', 'creation-reservoir'); ?></label>
                                <input type="text" name="number_doors" id="modal_number_doors" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Other obstacles', 'creation-reservoir'); ?></label>
                                <input type="text" name="other_obstacles" id="modal_other_obstacles" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Local technique -->
                    <fieldset style="border:1px solid #ddd; padding:10px; margin-bottom:15px; border-radius:4px;">
                        <legend style="font-weight:bold; font-size:12px; color:#b00; padding:0 5px;"><?php echo esc_html__('Heater room / Technical room', 'creation-reservoir'); ?></legend>
                        <div style="display:flex; gap:10px; margin-bottom:8px;">
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Room size (LxW)', 'creation-reservoir'); ?></label>
                                <input type="text" name="room_size" id="modal_room_size" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Room height', 'creation-reservoir'); ?></label>
                                <input type="text" name="room_height" id="modal_room_height" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; margin-bottom:8px;">
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Ceiling type', 'creation-reservoir'); ?></label>
                                <input type="text" name="ceiling_type" id="modal_ceiling_type" placeholder="E.g. concrete / beam" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Hoist allowed', 'creation-reservoir'); ?></label>
                                <select name="hoist_allowed" id="modal_hoist_allowed" style="width:100%; padding:5px; box-sizing:border-box;">
                                    <option value=""><?php echo esc_html__('-- Select --', 'creation-reservoir'); ?></option>
                                    <option value="Yes"><?php echo esc_html__('Yes', 'creation-reservoir'); ?></option>
                                    <option value="No"><?php echo esc_html__('No', 'creation-reservoir'); ?></option>
                                </select>
                            </div>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Floor covering', 'creation-reservoir'); ?></label>
                                <input type="text" name="floor_covering" id="modal_floor_covering" style="width:100%; padding:5px; box-sizing:border-box;">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Ventilation', 'creation-reservoir'); ?></label>
                                <select name="ventilation" id="modal_ventilation" style="width:100%; padding:5px; box-sizing:border-box;">
                                    <option value=""><?php echo esc_html__('-- Select --', 'creation-reservoir'); ?></option>
                                    <option value="No"><?php echo esc_html__('No', 'creation-reservoir'); ?></option>
                                    <option value="Natural"><?php echo esc_html__('Natural', 'creation-reservoir'); ?></option>
                                    <option value="Mechanical"><?php echo esc_html__('Mechanical', 'creation-reservoir'); ?></option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Infrastructure & Parking -->
                    <fieldset style="border:1px solid #ddd; padding:10px; margin-bottom:15px; border-radius:4px;">
                        <legend style="font-weight:bold; font-size:12px; color:#b00; padding:0 5px;"><?php echo esc_html__('Infrastructure & Parking', 'creation-reservoir'); ?></legend>
                        <div style="margin-bottom:8px;">
                            <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Electricity available', 'creation-reservoir'); ?></label>
                            <select name="electricity_available" id="modal_electricity_available" style="width:100%; padding:5px; box-sizing:border-box;">
                                <option value=""><?php echo esc_html__('-- Select --', 'creation-reservoir'); ?></option>
                                <option value="Yes"><?php echo esc_html__('Yes', 'creation-reservoir'); ?></option>
                                <option value="No"><?php echo esc_html__('No', 'creation-reservoir'); ?></option>
                            </select>
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Parking address / Unloading location', 'creation-reservoir'); ?></label>
                            <input type="text" name="parking_address" id="modal_parking_address" style="width:100%; padding:5px; box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block; font-size:11px; font-weight:bold;"><?php echo esc_html__('Observations', 'creation-reservoir'); ?></label>
                            <textarea name="observations" id="modal_observations" rows="2" style="width:100%; padding:5px; box-sizing:border-box;"></textarea>
                        </div>
                    </fieldset>

                    <div style="text-align:right; display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" id="ispag-modal-cancel" class="button"><?php echo esc_html__('Cancel', 'creation-reservoir'); ?></button>
                        <button type="submit" class="button button-primary" style="background:#b00; border-color:#b00;"><?php echo esc_html__('Save and Generate', 'creation-reservoir'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        // La modale est déplacée dans <body> : dans un bloc article (transform, overflow, z-index) elle passerait derrière les autres blocs
        (function () {
            const modal = document.getElementById('ispag-welding-modal');
            if (modal && modal.parentNode !== document.body) document.body.appendChild(modal);
        })();
        // Articles cochés ; si aucun ne l'est, tous les articles du projet (la case « tout sélectionner » n'a pas d'identifiant d'article)
        function selectedArticleIds() {
            const ids = selector => [...document.querySelectorAll(selector)].map(cb => cb.dataset.articleId).filter(Boolean);
            const checked = ids('.ispag-article-checkbox:checked');
            return checked.length ? checked : ids('.ispag-article-checkbox');
        }
        document.getElementById('generate-welding-site-sheet').addEventListener('click', function () {
            const ids = selectedArticleIds();

            // if (ids.length === 0) {
            //     alert('<?php echo esc_js(__('No items selected', 'creation-reservoir')); ?>');
            //     return;
            // }

            const dealId = '<?php echo intval($deal_id); ?>';

            jQuery.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'ispag_check_welding_sheet_data',
                    deal_id: dealId
                },
                success: function(response) {
                    if (response.success) {
                        // Toujours alimenter la modale avec les valeurs existantes (si présentes en DB)
                        const info = response.data.info || {};
                        document.getElementById('modal_AdresseDeLivraison').value = info.AdresseDeLivraison || '';
                        document.getElementById('modal_DeliveryAdresse2').value = info.DeliveryAdresse2 || '';
                        document.getElementById('modal_NIP').value = info.NIP || '';
                        document.getElementById('modal_City').value = info.City || '';
                        document.getElementById('modal_PersonneContact').value = info.PersonneContact || '';
                        document.getElementById('modal_num_tel_contact').value = info.num_tel_contact || '';
                        document.getElementById('modal_corridor_width').value = info.corridor_width || '';
                        document.getElementById('modal_door_width').value = info.door_width || '';
                        document.getElementById('modal_number_doors').value = info.number_doors || '';
                        document.getElementById('modal_other_obstacles').value = info.other_obstacles || '';
                        document.getElementById('modal_room_size').value = info.room_size || '';
                        document.getElementById('modal_room_height').value = info.room_height || '';
                        document.getElementById('modal_ceiling_type').value = info.ceiling_type || '';
                        document.getElementById('modal_hoist_allowed').value = info.hoist_allowed || '';
                        document.getElementById('modal_floor_covering').value = info.floor_covering || '';
                        document.getElementById('modal_ventilation').value = info.ventilation || '';
                        document.getElementById('modal_electricity_available').value = info.electricity_available || '';
                        document.getElementById('modal_parking_address').value = info.parking_address || '';
                        document.getElementById('modal_observations').value = info.observations || '';

                        if (response.data.missing) {
                            // S'il manque des infos vitales, on force l'affichage de la modale
                            document.getElementById('ispag-welding-modal').style.display = 'flex';
                        } else {
                            // Sinon, on peut soit ouvrir directement, soit laisser l'utilisateur ouvrir ou modifier. 
                            // Ici, s'il n'y a rien de manquant, on ouvre directement le PDF ou on peut afficher la modale s'il veut modifier. 
                            // Mettons l'ouverture directe si complet, ouvrez la modale si vous préférez toujours la relire.
                            openPdfUrl(dealId, ids);
                        }
                    }
                }
            });
        });

        document.getElementById('ispag-modal-cancel').addEventListener('click', function() {
            document.getElementById('ispag-welding-modal').style.display = 'none';
        });

        document.getElementById('ispag-welding-info-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'ispag_save_welding_sheet_data');

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: new URLSearchParams(formData).toString(),
                contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
                success: function(response) {
                    if (response.success) {
                        document.getElementById('ispag-welding-modal').style.display = 'none';
                        openPdfUrl(formData.get('deal_id'), selectedArticleIds());
                    } else {
                        alert('Error saving data.');
                    }
                }
            });
        });

        function openPdfUrl(dealId, ids) {
            const url = new URL('<?php echo esc_url(admin_url('admin-ajax.php')); ?>');
            url.searchParams.set('action', 'ispag_generate_welding_site_sheet');
            url.searchParams.set('deal_id', dealId);
            url.searchParams.set('ids', ids.join(','));
            window.open(url.toString(), '_blank');
        }
        </script>
        <?php
        return $html . ob_get_clean();
    }


    /* ==================================================================== */
    /* AJAX                                                                 */
    /* ==================================================================== */

    public function ajax_generate_welding_site_sheet()
    {
        if (!current_user_can('manage_site_welding_datas')) {
            wp_die(__('Unauthorized', 'creation-reservoir'));
        }

        $deal_id = isset($_GET['deal_id']) ? intval($_GET['deal_id']) : 0;
        $ids_raw = isset($_GET['ids']) ? sanitize_text_field($_GET['ids']) : '';

        if (!$deal_id || empty($ids_raw)) {
            wp_die(__('Missing project or article IDs.', 'creation-reservoir'));
        }

        $article_ids = array_filter(array_map('intval', explode(',', $ids_raw)));

        $valid_ids = [];
        foreach ($article_ids as $article_id) {
            $article = apply_filters('ispag_get_article_by_id', null, $article_id);
            if (!$article || intval($article->Type) !== 1) {
                continue;
            }
            if (!apply_filters('ispag_get_tank_on_site_welded', null, $article_id)) {
                continue;
            }
            $valid_ids[] = $article_id;
        }

        if (empty($valid_ids)) {
            wp_die(__('None of the selected items require on-site welding.', 'creation-reservoir'));
        }

        $project = apply_filters('ispag_get_project_by_deal_id', null, $deal_id);
        if (!$project) {
            wp_die(__('Project not found.', 'creation-reservoir'));
        }

        $this->generate($deal_id, $project, $valid_ids);

        $file_name = sanitize_file_name(
            'checklist_soudure_' . ($project->ObjetCommande ?? $deal_id)
        ) . '.pdf';

        if (ob_get_length()) {
            ob_end_clean();
        }

        $this->Output('I', $file_name);
        exit;
    }

    public function ajax_check_welding_sheet_data()
    {
        if (!current_user_can('manage_site_welding_datas')) {
            wp_send_json_error('Unauthorized');
        }

        $deal_id = isset($_GET['deal_id']) ? intval($_GET['deal_id']) : 0;
        if (!$deal_id) {
            wp_send_json_error('Missing deal ID');
        }

        $result = $this->check_welding_sheet_data($deal_id);

        if ($result === false) {
            wp_send_json_error('Invalid deal ID');
        }

        wp_send_json_success($result);
    }

    public function check_welding_sheet_data($deal_id)
    {
        if (!$deal_id) {
            return false;
        }

        $delivery_info = (new ISPAG_Project_Details_Repository())->get_infos_livraison($deal_id);

        // Liste de tous les champs obligatoires (adresse, contact, accès, local, infrastructure)
        $fields_to_check = [
            'AdresseDeLivraison',
            'City',
            'PersonneContact',
            'num_tel_contact',
            'corridor_width',
            'door_width',
            'room_height',
            'ceiling_type',
            'hoist_allowed',
            'electricity_available',
            'parking_address'
        ];

        $is_missing = false;
        foreach ($fields_to_check as $field) {
            if (empty($delivery_info->$field)) {
                $is_missing = true;
                break; // Dès qu'un seul champ est vide, on stoppe
            }
        }

        return [
            'missing' => $is_missing,
            'info'    => $delivery_info
        ];
    }

    public function ajax_save_welding_sheet_data()
    {
        if (!current_user_can('manage_site_welding_datas')) {
            wp_send_json_error('Unauthorized');
        }

        $deal_id = isset($_POST['deal_id']) ? intval($_POST['deal_id']) : 0;
        if (!$deal_id) {
            wp_send_json_error('Missing deal ID');
        }

        $data = [
            'AdresseDeLivraison'    => sanitize_text_field($_POST['AdresseDeLivraison'] ?? ''),
            'DeliveryAdresse2'      => sanitize_text_field($_POST['DeliveryAdresse2'] ?? ''),
            'NIP'                   => sanitize_text_field($_POST['NIP'] ?? ''),
            'City'                  => sanitize_text_field($_POST['City'] ?? ''),
            'PersonneContact'       => sanitize_text_field($_POST['PersonneContact'] ?? ''),
            'num_tel_contact'       => sanitize_text_field($_POST['num_tel_contact'] ?? ''),
            'corridor_width'        => sanitize_text_field($_POST['corridor_width'] ?? ''),
            'door_width'            => sanitize_text_field($_POST['door_width'] ?? ''),
            'number_doors'          => sanitize_text_field($_POST['number_doors'] ?? ''),
            'other_obstacles'       => sanitize_textarea_field($_POST['other_obstacles'] ?? ''),
            'room_size'             => sanitize_text_field($_POST['room_size'] ?? ''),
            'room_height'           => sanitize_text_field($_POST['room_height'] ?? ''),
            'ceiling_type'          => sanitize_text_field($_POST['ceiling_type'] ?? ''),
            'hoist_allowed'         => sanitize_text_field($_POST['hoist_allowed'] ?? ''),
            'floor_covering'        => sanitize_text_field($_POST['floor_covering'] ?? ''),
            'ventilation'           => sanitize_text_field($_POST['ventilation'] ?? ''),
            'electricity_available' => sanitize_text_field($_POST['electricity_available'] ?? ''),
            'parking_address'       => sanitize_textarea_field($_POST['parking_address'] ?? ''),
            'observations'          => sanitize_textarea_field($_POST['observations'] ?? ''),
        ];

        $result = $this->save_welding_sheet_data($deal_id, $data);

        if ($result !== false) {
            wp_send_json_success('Saved successfully');
        } else {
            wp_send_json_error('Error saving data');
        }
    }

    public function save_welding_sheet_data($deal_id, array $data)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'achats_info_commande';

        if (!$deal_id) {
            return false;
        }

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT Id FROM {$table_name} WHERE hubspot_deal_id = %d LIMIT 1",
            $deal_id
        ));

        if ($existing_id) {
            return $wpdb->update($table_name, $data, ['Id' => $existing_id]);
        } else {
            $data['hubspot_deal_id'] = $deal_id;
            return $wpdb->insert($table_name, $data);
        }
    }

    /* ==================================================================== */
    /* GÉNÉRATION                                                           */
    /* ==================================================================== */

    public function generate($deal_id, $project, array $tank_article_ids)
    {
        $this->tank_total = count($tank_article_ids);
        $this->tank_index = 0;

        $this->AliasNbPages('{nb}');
        $this->SetTitle($this->tx(__('Welding site sheet', 'creation-reservoir') . ' - ' . ($project->ObjetCommande ?? '')), false);
        $this->SetAutoPageBreak(true, 22);

        $delivery_info = (new ISPAG_Project_Details_Repository())->get_infos_livraison($deal_id);

        // 1. Page générale et questionnaire technique de chantier
        $this->add_site_header_page($project, $delivery_info);

        // 2. Les cuves à la suite les unes des autres, puis le plan validé de chacune
        $tanks = [];
        foreach ($tank_article_ids as $article_id) {
            $tanks[$article_id] = [
                apply_filters('ispag_get_article_by_id', null, $article_id),
                ISPAG_Tank_Repository::get_tank_details($article_id),
            ];
        }
        $this->add_tanks_pages($tanks);
        foreach (array_keys($tanks) as $article_id) {
            $this->append_last_validated_drawing($article_id);
        }

        // 3. Schéma d'accès et introduction de l'accumulateur
        $this->add_access_diagram_page();

        // 4. Page finale : Conditions générales et signature client
        $this->add_general_conditions_page();
    }

    /* ==================================================================== */
    /* OUTILS DE DESSIN (même charte que la fiche technique)                */
    /* ==================================================================== */

    protected function color($type, array $rgb)
    {
        if ($type === 'fill') $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        elseif ($type === 'draw') $this->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
        else $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
    }

    protected function roundedBox($x, $y, $w, $h, array $fill, ?array $border = null, $r = 2.5)
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
        $this->Cell($w, 5, $this->tx(mb_strtoupper($label)), 0, 1, 'L');
        $this->color('draw', self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line($x, $y + 5.5, $x + $w, $y + 5.5);
        return $y + 8;
    }

    /** Lignes « libellé : valeur » sur fond alterné. Renvoie le Y suivant. */
    protected function keyValueRows($x, $y, $w, array $rows, $label_w = 56)
    {
        $i = 0;
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') continue;
            if ($i % 2 === 0) {
                $this->color('fill', self::PANEL);
                $this->Rect($x, $y, $w, 5.8, 'F');
            }
            $this->SetXY($x + 2, $y + 0.4);
            $this->SetFont('Arial', '', 8.5);
            $this->color('text', self::MUTED);
            $this->Cell($label_w, 5, $this->tx($label), 0, 0, 'L');
            $this->SetFont('Arial', 'B', 8.5);
            $this->color('text', self::INK);
            $this->Cell($w - $label_w - 4, 5, $this->tx($value), 0, 0, 'L');
            $y += 5.8;
            $i++;
        }
        return $y + 2;
    }

    /** Nouvelle page avec bandeau ; renvoie le Y où commence le contenu. */
    protected function start_page($doc_title)
    {
        $this->AddPage();
        $this->footer_on = true;
        $this->draw_header($doc_title);
        return 31;
    }

    /** Titre du document et sous-titre (une ligne grise) ; renvoie le Y suivant. */
    protected function page_title($title, $subtitle = '', $y = 31)
    {
        $this->SetXY($this->margin, $y);
        $this->SetFont('Arial', 'B', 19);
        $this->color('text', self::INK);
        $this->MultiCell(186, 8, $this->tx($title), 0, 'L');
        $y = $this->GetY() + 1;
        if ($subtitle !== '') {
            $this->SetXY($this->margin, $y);
            $this->SetFont('Arial', '', 10);
            $this->color('text', self::MUTED);
            $this->Cell(186, 6, $this->tx($subtitle), 0, 1, 'L');
            $y = $this->GetY();
        }
        return $y + 5;
    }

    /** Indicateurs clés : [libellé, valeur, unité]. Renvoie le Y suivant. */
    protected function kpi_row(array $items, $y)
    {
        $gap = 4;
        $w = (186 - ($gap * (count($items) - 1))) / count($items);
        foreach ($items as $i => [$label, $value, $unit]) {
            $x = $this->margin + $i * ($w + $gap);
            $this->roundedBox($x, $y, $w, 20, self::PANEL);

            $this->SetXY($x, $y + 3);
            $this->SetFont('Arial', '', 7.5);
            $this->color('text', self::MUTED);
            $this->Cell($w, 4, $this->tx(mb_strtoupper($label)), 0, 2, 'C');

            $has = ($value !== null && $value !== '');
            $text = $has ? (string) $value : '-';
            $this->SetFont('Arial', 'B', 15);
            $this->color('text', self::INK);
            $this->Cell($w, 8, $this->tx($text), 0, 0, 'C');
            if ($has && $unit !== '') {
                $this->SetXY($x + $w / 2 + $this->GetStringWidth($this->tx($text)) / 2 + 0.6, $y + 9.2);
                $this->SetFont('Arial', '', 8);
                $this->color('text', self::MUTED);
                $this->Cell(10, 6, $this->tx($unit), 0, 0, 'L');
            }
        }
        return $y + 28;
    }

    /**
     * Champ de formulaire : libellé au-dessus, cadre dessous.
     * Valeur connue : cadre grisé, texte en gras. Sinon : cadre blanc à remplir à la main,
     * avec des cases à cocher si $options est renseigné. Renvoie le Y suivant.
     */
    protected function form_field($x, $y, $w, $label, $value = null, array $options = [], $h = 8, $prefix = '')
    {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', '', 7.5);
        $this->color('text', self::MUTED);
        $this->Cell($w, 4, $this->tx(rtrim($label, ' :')), 0, 0, 'L');

        $by = $y + 4.2;
        $known = ($value !== null && $value !== '');
        $this->roundedBox($x, $by, $w, $h, $known ? self::PANEL : self::WHITE, self::LINE, 1.5);

        if ($known) {
            $this->SetXY($x + 2, $by + ($h <= 8 ? 1.7 : 1.8));
            $this->SetFont('Arial', 'B', 8.5);
            $this->color('text', self::INK);
            $this->MultiCell($w - 4, 4.4, $this->tx($prefix . $value), 0, 'L');
        } elseif ($options) {
            $cx = $x + 2.5;
            $cy = $by + $h / 2;
            if ($prefix !== '') {
                $this->SetXY($cx, $cy - 2.4);
                $this->SetFont('Arial', '', 8);
                $this->color('text', self::MUTED);
                $this->Cell($this->GetStringWidth($this->tx($prefix)) + 2, 4.8, $this->tx($prefix), 0, 0, 'L');
                $cx += $this->GetStringWidth($this->tx($prefix)) + 4;
            }
            $this->SetFont('Arial', '', 8);
            foreach ($options as $opt) {
                $this->color('draw', self::MUTED);
                $this->SetLineWidth(0.25);
                $this->Rect($cx, $cy - 1.6, 3.2, 3.2, 'D');
                $this->color('text', self::INK);
                $this->SetXY($cx + 4.4, $cy - 2.4);
                $tw = $this->GetStringWidth($this->tx($opt));
                $this->Cell($tw + 1, 4.8, $this->tx($opt), 0, 0, 'L');
                $cx += $tw + 11;
            }
        }
        return $by + $h + 3;
    }

    /** Liste à puces (carrés rouges) ou numérotée ; le texte est coupé automatiquement. Renvoie le Y suivant. */
    protected function bullet_list($x, $y, $w, array $items, $numbered = false)
    {
        foreach ($items as $i => $text) {
            $this->SetXY($x + 7, $y);
            $this->SetFont('Arial', '', 8.5);
            $this->color('text', self::INK);
            $this->MultiCell($w - 8, 4.6, $this->tx($text), 0, 'L');
            $end = $this->GetY();

            if ($numbered) {
                $this->SetXY($x + 1, $y);
                $this->SetFont('Arial', 'B', 8.5);
                $this->color('text', self::RED);
                $this->Cell(5, 4.6, ($i + 1) . '.', 0, 0, 'L');
            } else {
                $this->color('fill', self::RED);
                $this->Rect($x + 2, $y + 1.6, 1.4, 1.4, 'F');
            }
            $y = $end + 1.6;
        }
        return $y + 2;
    }

    /* ==================================================================== */
    /* PAGE 1 : QUESTIONNAIRE DE CHANTIER                                   */
    /* ==================================================================== */

    protected function add_site_header_page($project, $delivery_info)
    {
        $y = $this->start_page(__('Welding site sheet', 'creation-reservoir'));

        // Titre : projet, avec le numéro de commande ; date de retour souhaitée (J+7) à droite
        $y = $this->page_title($project->ObjetCommande ?? '', __('Order No.', 'creation-reservoir') . ' : ' . ($project->NumCommande ?? ''));

        $return_text = __('Please return by:', 'creation-reservoir') . ' ' . date('d.m.Y', strtotime('+7 days'));
        $this->SetFont('Arial', 'B', 8.5);
        $pill_w = $this->GetStringWidth($this->tx($return_text)) + 10;
        $px = $this->margin + 186 - $pill_w;
        $this->roundedBox($px, 32.5, $pill_w, 7, self::RED, null, 1.8);
        $this->SetXY($px, 32.5);
        $this->color('text', self::WHITE);
        $this->Cell($pill_w, 7, $this->tx($return_text), 0, 0, 'C');

        $x1 = $this->margin;
        $x2 = $this->margin + 95;
        $half = 91;
        $d = $delivery_info;

        // Adresse et contact
        $y = $this->sectionTitle($x1, $y, 186, __('Site address & contact', 'creation-reservoir'));
        $city_line = trim(($d->NIP ?? '') . ' ' . ($d->City ?? ''));
        $address = implode("\n", array_filter([$d->AdresseDeLivraison ?? '', $d->DeliveryAdresse2 ?? '', $city_line]));
        $lines = max(1, substr_count($address, "\n") + 1);
        $y = $this->form_field($x1, $y, 186, __('Address', 'creation-reservoir'), $address, [], 8 + ($lines - 1) * 4.4);
        $this->form_field($x1, $y, $half, __('Contact', 'creation-reservoir'), $d->PersonneContact ?? null);
        $y = $this->form_field($x2, $y, $half, __('Phone', 'creation-reservoir'), $d->num_tel_contact ?? null);

        // Accessibilité
        $y = $this->sectionTitle($x1, $y + 1, 186, __('Accessibility & internal path', 'creation-reservoir'));
        $this->form_field($x1, $y, $half, __('Corridor width:', 'creation-reservoir') . ' (cm)', $d->corridor_width ?? null);
        $y = $this->form_field($x2, $y, $half, __('Smallest door width:', 'creation-reservoir') . ' (cm)', $d->door_width ?? null);
        $this->form_field($x1, $y, $half, __('Number of doors:', 'creation-reservoir'), $d->number_doors ?? null);
        $y = $this->form_field($x2, $y, $half, __('Other obstacles:', 'creation-reservoir'), $d->other_obstacles ?? null);

        // Local technique / chaufferie
        $y = $this->sectionTitle($x1, $y + 1, 186, __('Heater room / Technical room', 'creation-reservoir'));
        $this->form_field($x1, $y, $half, __('Room size (LxW):', 'creation-reservoir') . ' (cm)', $d->room_size ?? null);
        $y = $this->form_field($x2, $y, $half, __('Room height:', 'creation-reservoir') . ' (cm)', $d->room_height ?? null);
        $this->form_field($x1, $y, $half, __('Ceiling type:', 'creation-reservoir'), $d->ceiling_type ?? null,
            [__('concrete', 'creation-reservoir'), __('wood beam', 'creation-reservoir')]);
        $y = $this->form_field($x2, $y, $half, __('Hoist allowed on ceiling:', 'creation-reservoir'), $d->hoist_allowed ?? null,
            [__('Yes', 'creation-reservoir'), __('No', 'creation-reservoir')]);
        $this->form_field($x1, $y, $half, __('Floor covering:', 'creation-reservoir'), $d->floor_covering ?? null,
            [__('poured cement', 'creation-reservoir'), __('tiles', 'creation-reservoir')]);
        $y = $this->form_field($x2, $y, $half, __('Ventilation available:', 'creation-reservoir'), $d->ventilation ?? null,
            [__('No', 'creation-reservoir'), __('Natural', 'creation-reservoir'), __('Mechanical', 'creation-reservoir')]);

        // Infrastructure
        $y = $this->sectionTitle($x1, $y + 1, 186, __('Infrastructure', 'creation-reservoir'));
        $y = $this->form_field($x1, $y, 186, __('Electricity available:', 'creation-reservoir'), $d->electricity_available ?? null,
            [__('Yes', 'creation-reservoir'), __('No', 'creation-reservoir')], 8, '3x400V min. 10A   ');

        // Parking et observations
        $y = $this->sectionTitle($x1, $y + 1, 186, __('Parking / Unloading address', 'creation-reservoir'));
        $y = $this->form_field($x1, $y, 186, __('Parking address / Unloading location:', 'creation-reservoir'), $d->parking_address ?? null);
        $this->form_field($x1, $y, 186, __('Observations:', 'creation-reservoir'), $d->observations ?? null, [], 22);
    }

    /* ==================================================================== */
    /* UNE PAGE PAR CUVE + PLAN VALIDÉ                                      */
    /* ==================================================================== */

    /** Fiches des cuves, l'une sous l'autre ; une nouvelle page est ouverte quand la suivante ne tient plus. */
    protected function add_tanks_pages(array $tanks)
    {
        $y = $this->start_page(__('Welding site sheet', 'creation-reservoir'));
        $y = $this->page_title(_n('Tank', 'Tanks', max(1, count($tanks)), 'creation-reservoir'));

        foreach ($tanks as $article_id => [$article, $tank_datas]) {
            $this->tank_index++;
            if ($y + 56 > 297 - 22) {
                $y = $this->start_page(__('Welding site sheet', 'creation-reservoir'));
            }
            $y = $this->tank_card($article, $tank_datas, $y);
        }
    }

    /** Fiche d'une cuve : titre, quatre indicateurs, caractéristiques. Renvoie le Y suivant. */
    protected function tank_card($article, $tank_datas, $y)
    {
        $dim = $tank_datas['dimensions_principales'] ?? [];
        $article_id = $article->IdCommandeClient ?? ($article->Id ?? 0);

        $nb_welding = 0;
        if (class_exists('ISPAG_Tank_Welding')) {
            $welding = new ISPAG_Tank_Welding();
            $nb_welding = intval($welding->count_nb_welding_in_tank($article_id));
        }
        $nb_pieces = $nb_welding + 1;

        // Titre du réservoir, numéro à droite
        $y = $this->sectionTitle($this->margin, $y, 186, $article->Article ?? '');
        $this->SetXY($this->margin, $y - 8);
        $this->SetFont('Arial', 'B', 8);
        $this->color('text', self::MUTED);
        $this->Cell(186, 5, $this->tx(sprintf(__('Tank %d / %d', 'creation-reservoir'), $this->tank_index, $this->tank_total)), 0, 0, 'R');

        // Indicateurs clés, en petit
        $items = [
            [__('Diameter', 'creation-reservoir'),        $dim['Diametre_mm'] ?? null,      'mm'],
            [__('Total height', 'creation-reservoir'),    $dim['Hauteur_mm'] ?? null,       'mm'],
            [__('Design pressure', 'creation-reservoir'), $dim['Pression_Max_bar'] ?? null, 'bar'],
            [__('Number of welds', 'creation-reservoir'), (string) $nb_welding,             ''],
        ];
        $gap = 4;
        $w = (186 - 3 * $gap) / 4;
        foreach ($items as $i => [$label, $value, $unit]) {
            $x = $this->margin + $i * ($w + $gap);
            $this->roundedBox($x, $y, $w, 14, self::PANEL, null, 2);
            $this->SetXY($x, $y + 2);
            $this->SetFont('Arial', '', 7);
            $this->color('text', self::MUTED);
            $this->Cell($w, 3.5, $this->tx(mb_strtoupper($label)), 0, 2, 'C');
            $this->SetFont('Arial', 'B', 11);
            $this->color('text', self::INK);
            $has = ($value !== null && $value !== '');
            $this->Cell($w, 6, $this->tx($has ? $value . ($unit !== '' ? ' ' . $unit : '') : '-'), 0, 0, 'C');
        }
        $y += 18;

        // Caractéristiques sur deux colonnes
        $half = 91;
        $left = $this->keyValueRows($this->margin, $y, $half, [
            __('Material', 'creation-reservoir')      => $dim['Matiere'] ?? null,
            __('Test pressure', 'creation-reservoir') => isset($dim['Pression_Test_bar']) && $dim['Pression_Test_bar'] !== '' ? $dim['Pression_Test_bar'] . ' bar' : null,
        ], 34);
        $right = $this->keyValueRows($this->margin + 95, $y, $half, [
            __('Delivered in pieces', 'creation-reservoir')    => (string) $nb_pieces,
            __('Expected delivery date', 'creation-reservoir') => !empty($article->TimestampDateLivraisonConfirme) ? date('d.m.Y', $article->TimestampDateLivraisonConfirme) : null,
        ], 40);

        return max($left, $right) + 5;
    }

    protected function append_last_validated_drawing($article_id)
    {
        $source_path = $this->get_last_validated_drawing_path($article_id);

        if (!$source_path || !file_exists($source_path)) {
            $this->plan_message(__('No validated plan available for this tank yet.', 'creation-reservoir'));
            return;
        }

        try {
            $working_path = $this->maybe_decompress_pdf($source_path);
            $page_count   = $this->setSourceFile($working_path);

            for ($i = 1; $i <= $page_count; $i++) {
                $tpl  = $this->importPage($i);
                $size = $this->getTemplateSize($tpl);
                $this->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $this->footer_on = false; // la page du plan reste telle qu'elle a été validée
                $this->useTemplate($tpl);
            }
        } catch (\Exception $e) {
            $this->plan_message(__('The plan could not be merged automatically. Please attach it manually.', 'creation-reservoir'));
        }
    }

    /** Page de plan absent : message centré dans un cadre. */
    protected function plan_message($message)
    {
        $y = $this->start_page(__('Manufacturing plan', 'creation-reservoir'));
        $this->roundedBox($this->margin, $y + 20, 186, 40, self::PANEL);
        $this->SetXY($this->margin, $y + 36);
        $this->SetFont('Arial', 'I', 10);
        $this->color('text', self::MUTED);
        $this->Cell(186, 8, $this->tx($message), 0, 1, 'C');
    }

    /* ==================================================================== */
    /* SCHEMA D'ACCÈS & INTRODUCTION DE L'ACCUMULATEUR                      */
    /* ==================================================================== */

    protected function add_access_diagram_page()
    {
        $y = $this->start_page(__('Tank introduction & critical turning points', 'creation-reservoir'));
        $y = $this->page_title(__('Path analysis for tank parts introduction', 'creation-reservoir'));

        $this->SetXY($this->margin, $y - 2);
        $this->SetFont('Arial', '', 8.5);
        $this->color('text', self::INK);
        $this->MultiCell(186, 4.6, $this->tx(
            __('Please note: Since the introduction and positioning of the tank parts are your responsibility, please pay special attention to critical passages (doors, corridors, staircases, tight turns) and sketch them below.', 'creation-reservoir')
        ), 0, 'L');
        $y = $this->GetY() + 5;

        // Cadre de croquis, rempli par le schéma s'il existe
        $box_h = 150;
        $this->roundedBox($this->margin, $y, 186, $box_h, self::WHITE, self::LINE);
        // Schéma centré dans le cadre, proportions conservées
        $size = @getimagesize((string) $this->schema_path);
        $ratio = ($size && $size[1] > 0) ? $size[0] / $size[1] : 1;
        $img_w = min(176, ($box_h - 10) * $ratio);
        $rendered = $this->render_schema_image($this->schema_path, $this->margin + (186 - $img_w) / 2, $y + 5, $img_w);

        if (!$rendered) {
            $this->SetXY($this->margin, $y + $box_h / 2 - 4);
            $this->SetFont('Arial', 'I', 9);
            $this->color('text', self::MUTED);
            $this->Cell(186, 8, $this->tx(__('[Schema of critical turning points & staircase introduction]', 'creation-reservoir')), 0, 1, 'C');
        }
    }

    /**
     * Insère une image de façon défensive : valide le fichier, le "nettoie" via GD
     * si possible (évite les PNG entrelacés / avec canal alpha exotique qui font
     * planter le parseur PNG natif de FPDF), et ne laisse jamais une erreur
     * d'image interrompre la génération du PDF.
     *
     * @return bool true si l'image a été dessinée, false sinon (placeholder à afficher).
     */
    protected function render_schema_image($path, $x, $y, $width)
    {
        if (empty($path) || !file_exists($path)) {
            return false;
        }

        $info = @getimagesize($path);
        if (!$info) {
            return false;
        }

        $safe_path = $path;

        // Si GD est dispo, on ré-encode toujours l'image dans un format simple
        // (JPEG fond blanc) pour éliminer toute variante PNG problématique.
        if (function_exists('imagecreatefromstring')) {
            try {
                $data = file_get_contents($path);
                $img  = @imagecreatefromstring($data);

                if ($img !== false) {
                    $w = imagesx($img);
                    $h = imagesy($img);

                    $flat = imagecreatetruecolor($w, $h);
                    $white = imagecolorallocate($flat, 255, 255, 255);
                    imagefill($flat, 0, 0, $white);
                    imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);

                    $tmp_path = sys_get_temp_dir() . '/' . uniqid('ispag_schema_') . '.jpg';
                    if (imagejpeg($flat, $tmp_path, 90)) {
                        $safe_path = $tmp_path;
                    }

                    imagedestroy($img);
                    imagedestroy($flat);
                }
            } catch (\Throwable $e) {
                // On retombe sur le fichier d'origine ci-dessous.
            }
        }

        try {
            $this->Image($safe_path, $x, $y, $width);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ==================================================================== */
    /* PAGE FINALE : CONDITIONS GÉNÉRALES & SIGNATURE                       */
    /* ==================================================================== */

    protected function add_general_conditions_page()
    {
        $y = $this->start_page(__('General assembly conditions', 'creation-reservoir'));
        $y = $this->page_title(__('General assembly conditions', 'creation-reservoir'));

        $y = $this->sectionTitle($this->margin, $y, 186, __('A. Services provided by ISPAG', 'creation-reservoir'));
        $y = $this->bullet_list($this->margin, $y, 186, [
            __('The intervention of the lead welder and assistant', 'creation-reservoir'),
            __('Travel expenses and accommodation', 'creation-reservoir'),
            __('Welding and assembly tools', 'creation-reservoir'),
            __('Weld inspection by penetrant testing (dye penetrant test)', 'creation-reservoir'),
        ]);

        $y = $this->sectionTitle($this->margin, $y + 3, 186, __('B. Services provided by the client', 'creation-reservoir'));
        $y = $this->bullet_list($this->margin, $y, 186, [
            __('Material introduction: Tank parts must be brought in before work begins.', 'creation-reservoir'),
            __('Site preparation: Clean, well-lit, ventilated, and cleared of any foreign debris.', 'creation-reservoir'),
            __('Equipment: Anchor point above the location and high-flow water supply for pressure testing.', 'creation-reservoir'),
            __('Welding power supply: An electrical socket (3 x 400V / min. 10A) must be installed by a certified electrician at client expense.', 'creation-reservoir'),
        ], true);

        // Cadre de signature client
        $y += 6;
        $this->roundedBox($this->margin, $y, 186, 38, self::PANEL);
        $this->SetXY($this->margin + 6, $y + 6);
        $this->SetFont('Arial', 'B', 9);
        $this->color('text', self::INK);
        $this->Cell(0, 5, $this->tx(__('Date and signature of client (or representative):', 'creation-reservoir')), 0, 1);

        $this->color('draw', self::MUTED);
        $this->SetLineWidth(0.25);
        $this->SetFont('Arial', '', 8);
        $this->color('text', self::MUTED);
        $this->SetXY($this->margin + 6, $y + 28);
        $this->Cell(14, 5, $this->tx(__('Date:', 'creation-reservoir')), 0, 0);
        $this->Line($this->margin + 20, $y + 32, $this->margin + 80, $y + 32);
        $this->SetXY($this->margin + 96, $y + 28);
        $this->Cell(20, 5, $this->tx(__('Signature:', 'creation-reservoir')), 0, 0);
        $this->Line($this->margin + 116, $y + 32, $this->margin + 180, $y + 32);
    }

    protected function get_last_validated_drawing_path($article_id)
    {
        global $wpdb;

        $media_id = $wpdb->get_var($wpdb->prepare(
            "SELECT IdMedia
            FROM {$wpdb->prefix}achats_historique
            WHERE Historique = %d
            AND ClassCss = 'drawingApproval'
            ORDER BY dateReadable DESC
            LIMIT 1",
            $article_id
        ));

        if (!$media_id || !is_numeric($media_id)) {
            return null;
        }

        return get_attached_file($media_id) ?: null;
    }

    protected function maybe_decompress_pdf($source_pdf)
    {
        try {
            $output_pdf = sys_get_temp_dir() . '/' . uniqid('ispag_welding_sheet_') . '.pdf';
            $command = "gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dNOPAUSE -dQUIET -dBATCH "
                . "-sOutputFile=" . escapeshellarg($output_pdf) . " "
                . escapeshellarg($source_pdf);

            exec($command, $output, $result_code);

            if ($result_code === 0 && file_exists($output_pdf)) {
                return $output_pdf;
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $source_pdf;
    }

    /** Bandeau : logo, titre du document, date et filet rouge. */
    protected function draw_header($title)
    {
        $drawn = false;
        if (file_exists($this->logo_path)) {
            [$logo_w, $logo_h] = class_exists('ISPAG_Site_Logo') ? ISPAG_Site_Logo::fit($this->logo_path, 38, 12) : [38, 0];
            try {
                $this->Image($this->logo_path, $this->margin, 9, $logo_w, $logo_h);
                $drawn = true;
            } catch (\Throwable $e) {
                // logo illisible : le nom ISPAG le remplace
            }
        }
        if (!$drawn) {
            $this->SetXY($this->margin, 11);
            $this->SetFont('Arial', 'B', 16);
            $this->color('text', self::RED);
            $this->Cell(60, 8, 'ISPAG', 0, 0, 'L');
        }

        $this->SetXY(70, 11);
        $this->SetFont('Arial', 'B', 13);
        $this->color('text', self::INK);
        $this->Cell(128, 7, $this->tx($title), 0, 2, 'R');
        $this->SetFont('Arial', '', 8.5);
        $this->color('text', self::MUTED);
        $this->Cell(128, 5, $this->tx(date('d.m.Y')), 0, 0, 'R');

        $this->color('fill', self::RED);
        $this->Rect($this->margin, 24, 186, 0.9, 'F');
        $this->SetXY($this->margin, 31);
    }

    protected function download_logo()
    {
        $dir = dirname($this->logo_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $response = wp_remote_get($this->logo_url);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            file_put_contents($this->logo_path, wp_remote_retrieve_body($response));
        }
    }

    protected function tx($text)
    {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string) $text);
    }

    /** Pied de page : coordonnées de la société et pagination (absent des pages de plan importées). */
    public function Footer()
    {
        if (!$this->footer_on) return;

        $this->SetY(-17);
        $this->color('draw', self::LINE);
        $this->SetLineWidth(0.3);
        $this->Line($this->margin, $this->GetY(), 210 - $this->margin, $this->GetY());
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
        $this->SetX($this->margin);
        $this->Cell(160, 4, $this->tx(implode('  -  ', $company)), 0, 0, 'L');
        $this->Cell(26, 4, $this->tx(sprintf(__('Page %s / %s', 'creation-reservoir'), $this->PageNo(), '{nb}')), 0, 0, 'R');
    }

    /** Rectangle à coins arrondis. */
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
        $this->arc_curve($xc + $r * $arc, $yc - $r, $xc + $r, $yc - $r * $arc, $xc + $r, $yc);
        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->arc_curve($xc + $r, $yc + $r * $arc, $xc + $r * $arc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->arc_curve($xc - $r * $arc, $yc + $r, $xc - $r, $yc + $r * $arc, $xc - $r, $yc);
        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->arc_curve($xc - $r, $yc - $r * $arc, $xc - $r * $arc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    protected function arc_curve($x1, $y1, $x2, $y2, $x3, $y3)
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
