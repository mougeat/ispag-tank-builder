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

    protected $margin = 12;

    protected $tank_index = 0;
    protected $tank_total = 0;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4');

        $this->logo_path = ISPAG_PLUGIN_PATH . 'assets/logo_ispag.png';
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
                                <input type="text" name="ceiling_type" id="modal_ceiling_type" placeholder="Ex: béton / poutre" style="width:100%; padding:5px; box-sizing:border-box;">
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
                                    <option value="Yes"><?php echo esc_html__('Yes', 'creation-reservoir'); ?></option>
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
        document.getElementById('generate-welding-site-sheet').addEventListener('click', function () {
            const ids = [...document.querySelectorAll('.ispag-article-checkbox:checked')]
                .map(cb => cb.dataset.articleId);

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
                        const ids = [...document.querySelectorAll('.ispag-article-checkbox:checked')]
                            .map(cb => cb.dataset.articleId);
                        openPdfUrl(document.querySelector('[name="deal_id"]').value, ids);
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

        $delivery_info = (new ISPAG_Project_Details_Repository())->get_infos_livraison($deal_id);

        // 1. Page générale et questionnaire technique de chantier
        $this->add_site_header_page($project, $delivery_info);

        // 2. Pages de détails par cuve + plan validé
        foreach ($tank_article_ids as $article_id) {
            $this->tank_index++;

            $article    = apply_filters('ispag_get_article_by_id', null, $article_id);
            $tank_datas = ISPAG_Tank_Repository::get_tank_details($article_id);

            $this->add_tank_detail_page($article, $tank_datas);
            $this->append_last_validated_drawing($article_id);
        }

        // 3. Schéma d'accès et introduction de l'accumulateur
        $this->add_access_diagram_page();

        // 4. Page finale : Conditions générales et signature client
        $this->add_general_conditions_page();
    }

    protected function add_site_header_page($project, $delivery_info)
    {
        $this->AddPage();
        
        // Titre du document plus grand
        $sheet_title = __('Welding site sheet', 'creation-reservoir') . ' : ' . ($project->ObjetCommande ?? '');
        $this->draw_header($sheet_title);

        // Date de retour souhaitée (J+7)
        $return_date = date('d.m.Y', strtotime('+7 days'));
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(180, 0, 0);
        $this->Cell(0, 5, $this->tx(__('Please return by:', 'creation-reservoir') . ' ' . $return_date), 0, 1, 'R');

        // Nom du projet / Objet de commande plus grand
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(0);
        $this->Cell(0, 8, $this->tx($project->ObjetCommande ?? ''), 0, 1);
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100);
        $this->Cell(0, 5, $this->tx(__('Order No.', 'creation-reservoir') . ' : ' . ($project->NumCommande ?? '')), 0, 1);
        $this->Ln(6);

        // Bloc Adresse & Contact plus grand
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(180, 0, 0);
        $this->Cell(0, 6, $this->tx(__('Site address & contact', 'creation-reservoir')), 0, 1);
        $this->Ln(2);

        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0);

        // Affichage multiligne de l'adresse
        $this->Cell(0, 5, $this->tx(__('Address', 'creation-reservoir') . ' :'), 0, 1);
        
        if (!empty($delivery_info->AdresseDeLivraison)) {
            $this->Cell(0, 5, $this->tx($delivery_info->AdresseDeLivraison), 0, 1);
        }
        if (!empty($delivery_info->DeliveryAdresse2)) {
            $this->Cell(0, 5, $this->tx($delivery_info->DeliveryAdresse2), 0, 1);
        }
        $city_line = trim(($delivery_info->NIP ?? '') . ' ' . ($delivery_info->City ?? ''));
        if (!empty($city_line)) {
            $this->Cell(0, 5, $this->tx($city_line), 0, 1);
        }

        if (empty($delivery_info->AdresseDeLivraison) && empty($city_line)) {
            $this->Cell(0, 5, $this->tx($this->dots()), 0, 1);
        }

        $this->Ln(2);
        $contact_name = $this->val($delivery_info->PersonneContact ?? '');
        $contact_tel = $this->val($delivery_info->num_tel_contact ?? '');
        $this->Cell(0, 5, $this->tx(__('Contact', 'creation-reservoir') . ' : ' . $contact_name . ' (' . $contact_tel . ')'), 0, 1);
        
        // Espacement important avant le tableau
        $this->Ln(8);

        // Résolution des valeurs : DB connue → affichée, sinon pointillés / cases à cocher.
        $corridor_width  = $this->val($delivery_info->corridor_width ?? null, ' cm');
        $door_width      = $this->val($delivery_info->door_width ?? null, ' cm');
        $number_doors    = $this->val($delivery_info->number_doors ?? null);
        $other_obstacles = $this->val($delivery_info->other_obstacles ?? null);
        $room_size       = $this->val($delivery_info->room_size ?? null, ' cm');
        $room_height     = $this->val($delivery_info->room_height ?? null, ' cm');

        $ceiling_type   = $this->checkbox_or_value(
            $delivery_info->ceiling_type ?? null,
            [__('concrete', 'creation-reservoir'), __('wood beam', 'creation-reservoir')]
        );
        $hoist_allowed  = $this->checkbox_or_value(
            $delivery_info->hoist_allowed ?? null,
            [__('Yes', 'creation-reservoir'), __('No', 'creation-reservoir')]
        );
        $floor_covering = $this->checkbox_or_value(
            $delivery_info->floor_covering ?? null,
            [__('poured cement', 'creation-reservoir'), __('tiles', 'creation-reservoir')]
        );
        $ventilation    = $this->checkbox_or_value(
            $delivery_info->ventilation ?? null,
            [__('Yes', 'creation-reservoir'), __('No', 'creation-reservoir'), __('Natural', 'creation-reservoir'), __('Mechanical', 'creation-reservoir')]
        );
        $electricity    = $this->checkbox_or_value(
            $delivery_info->electricity_available ?? null,
            [__('Yes', 'creation-reservoir'), __('No', 'creation-reservoir')],
            '3x400V min. 10A  '
        );
        $parking_address = $this->val($delivery_info->parking_address ?? null);
        $observations    = $this->val($delivery_info->observations ?? null);


        // Titre encadré : Accessibilité & Intérieur du bâtiment
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0);
        $this->Cell(0, 6, $this->tx(__('Accessibility & internal path', 'creation-reservoir')), 1, 1, 'L', true);

        $this->SetFont('Arial', '', 8);
        $this->row_field(__('Corridor width:', 'creation-reservoir'), $corridor_width, __('Smallest door width:', 'creation-reservoir'), $door_width, 42, 51, 42, 51);
        $this->row_field(__('Number of doors:', 'creation-reservoir'), $number_doors, __('Other obstacles:', 'creation-reservoir'), $other_obstacles, 42, 51, 42, 51);
        $this->Ln(2);

        // Local technique / Chaufferie
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 6, $this->tx(__('Heater room / Technical room', 'creation-reservoir')), 1, 1, 'L', true);

        $this->SetFont('Arial', '', 8);
        $this->row_field(__('Room size (LxW):', 'creation-reservoir'), $room_size, __('Room height:', 'creation-reservoir'), $room_height, 42, 51, 42, 51);
        $this->row_field(__('Ceiling type:', 'creation-reservoir'), $ceiling_type, __('Hoist allowed on ceiling:', 'creation-reservoir'), $hoist_allowed, 42, 51, 42, 51);
        $this->row_field(__('Floor covering:', 'creation-reservoir'), $floor_covering, __('Ventilation available:', 'creation-reservoir'), $ventilation, 42, 51, 42, 51);
        $this->Ln(2);

        // Infrastructure (Électricité uniquement)
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 6, $this->tx(__('Infrastructure', 'creation-reservoir')), 1, 1, 'L', true);

        $this->SetFont('Arial', '', 8);
        $this->Cell(35, 5, $this->tx(__('Electricity available:', 'creation-reservoir')), 1, 0);
        $this->Cell(0, 5, $this->tx($electricity), 1, 1);

        // Espacement important avant le parking
        $this->Ln(8);

        // Adresse du parking / déchargement
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 6, $this->tx(__('Parking / Unloading address', 'creation-reservoir')), 1, 1, 'L', true);

        $this->SetFont('Arial', '', 8);
        $this->Ln(2);
        $this->Cell(0, 5, $this->tx(__('Parking address / Unloading location:', 'creation-reservoir') . ' ' . $parking_address), 0, 1);

        $this->Ln(2);
        $this->Cell(0, 5, $this->tx(__('Observations:', 'creation-reservoir')), 0, 1);
        $this->Cell(0, 6, $this->tx($observations), 0, 1);
    }

    protected function row_field($label1, $val1, $label2, $val2, $w1 = 38, $w2 = 57, $w3 = 38, $w4 = 57)
    {
        $this->Cell($w1, 5, $this->tx($label1), 1, 0);
        $this->Cell($w2, 5, $this->tx($val1), 1, 0);
        $this->Cell($w3, 5, $this->tx($label2), 1, 0);
        $this->Cell($w4, 5, $this->tx($val2), 1, 1);
    }

    

    protected function add_tank_detail_page($article, $tank_datas)
    {
        $dim = $tank_datas['dimensions_principales'] ?? [];
        $article_id = $article->IdCommandeClient ?? ($article->Id ?? 0);

        $nb_welding = 0;
        if (class_exists('ISPAG_Tank_Welding')) {
            $welding = new ISPAG_Tank_Welding();
            $nb_welding = intval($welding->count_nb_welding_in_tank($article_id));
        }
        $nb_pieces = $nb_welding + 1;

        $this->AddPage();
        $this->draw_header(sprintf(__('Tank %d / %d', 'creation-reservoir'), $this->tank_index, $this->tank_total));

        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0);
        $this->Cell(0, 8, $this->tx($article->Article ?? ''), 0, 1);
        $this->Ln(2);

        $rows = [
            __('Material', 'creation-reservoir')                 => $this->val($dim['Matiere'] ?? null),
            __('Diameter', 'creation-reservoir')                  => $this->val($dim['Diametre_mm'] ?? null, ' mm'),
            __('Total height', 'creation-reservoir')              => $this->val($dim['Hauteur_mm'] ?? null, ' mm'),
            __('Design pressure', 'creation-reservoir')           => $this->val($dim['Pression_Max_bar'] ?? null, ' bar'),
            __('Test pressure', 'creation-reservoir')             => $this->val($dim['Pression_Test_bar'] ?? null, ' bar'),
            __('Number of welds', 'creation-reservoir')           => (string) $nb_welding,
            __('Delivered in pieces', 'creation-reservoir')       => (string) $nb_pieces,
            __('Expected delivery date', 'creation-reservoir')    => !empty($article->TimestampDateLivraisonConfirme) ? date('d.m.Y', $article->TimestampDateLivraisonConfirme) : $this->dots(),
        ];

        $this->SetFont('Arial', '', 9);
        foreach ($rows as $label => $value) {
            $this->SetTextColor(90);
            $this->Cell(60, 6, $this->tx($label . ' :'), 0, 0);
            $this->SetTextColor(0);
            $this->Cell(0, 6, $this->tx($value), 0, 1);
        }

        $this->Ln(4);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100);
        $this->Cell(0, 5, $this->tx(__('The manufacturing plan for this tank follows on the next page, if available.', 'creation-reservoir')), 0, 1);
    }

    protected function append_last_validated_drawing($article_id)
    {
        $source_path = $this->get_last_validated_drawing_path($article_id);

        if (!$source_path || !file_exists($source_path)) {
            $this->AddPage();
            $this->draw_header(__('Manufacturing plan', 'creation-reservoir'));
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(150);
            $this->Cell(0, 20, $this->tx(__('No validated plan available for this tank yet.', 'creation-reservoir')), 0, 1, 'C');
            return;
        }

        try {
            $working_path = $this->maybe_decompress_pdf($source_path);
            $page_count   = $this->setSourceFile($working_path);

            for ($i = 1; $i <= $page_count; $i++) {
                $tpl  = $this->importPage($i);
                $size = $this->getTemplateSize($tpl);
                $this->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $this->useTemplate($tpl);
            }
        } catch (\Exception $e) {
            $this->AddPage();
            $this->draw_header(__('Manufacturing plan', 'creation-reservoir'));
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(150);
            $this->Cell(0, 20, $this->tx(__('The plan could not be merged automatically. Please attach it manually.', 'creation-reservoir')), 0, 1, 'C');
        }
    }

    /* ==================================================================== */
    /* SCHEMA D'ACCÈS & INTRODUCTION DE L'ACCUMULATEUR                      */
    /* ==================================================================== */

    protected function add_access_diagram_page()
    {
        $this->AddPage();
        $this->draw_header(__('Tank introduction & critical turning points', 'creation-reservoir'));

        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(180, 0, 0);
        $this->Cell(0, 6, $this->tx(__('Path analysis for tank parts introduction', 'creation-reservoir')), 0, 1);

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(50);
        $this->MultiCell(0, 4, $this->tx(
            __('Please note: Since the introduction and positioning of the tank parts are your responsibility, please pay special attention to critical passages (doors, corridors, staircases, tight turns) and sketch them below.', 'creation-reservoir')
        ), 0, 1);
        $this->Ln(4);

        $rendered = $this->render_schema_image($this->schema_path, $this->margin, $this->GetY(), 140);

        if (!$rendered) {
            $this->SetDrawColor(200, 200, 200);
            $this->Rect($this->margin, $this->GetY(), 186, 140);
            $this->SetXY($this->margin + 10, $this->GetY() + 60);
            $this->SetFont('Arial', 'I', 9);
            $this->SetTextColor(150);
            $this->Cell(166, 10, $this->tx(__('[Schema of critical turning points & staircase introduction]', 'creation-reservoir')), 0, 1, 'C');
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
        $this->AddPage();
        $this->draw_header(__('General assembly conditions', 'creation-reservoir'));

        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(180, 0, 0);
        $this->Cell(0, 5, $this->tx(__('A. Services provided by ISPAG', 'creation-reservoir')), 0, 1);
        
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(50);
        $this->MultiCell(0, 4, $this->tx(
            "- " . __('The intervention of the lead welder and assistant', 'creation-reservoir') . "\n" .
            "- " . __('Travel expenses and accommodation', 'creation-reservoir') . "\n" .
            "- " . __('Welding and assembly tools', 'creation-reservoir') . "\n" .
            "- " . __('Weld inspection by penetrant testing (dye penetrant test)', 'creation-reservoir')
        ), 0, 1);
        $this->Ln(2);

        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(180, 0, 0);
        $this->Cell(0, 5, $this->tx(__('B. Services provided by the client', 'creation-reservoir')), 0, 1);

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(50);
        $this->MultiCell(0, 4, $this->tx(
            "1. " . __('Material introduction: Tank parts must be brought in before work begins.', 'creation-reservoir') . "\n" .
            "2. " . __('Site preparation: Clean, well-lit, ventilated, and cleared of any foreign debris.', 'creation-reservoir') . "\n" .
            "3. " . __('Equipment: Anchor point above the location and high-flow water supply for pressure testing.', 'creation-reservoir') . "\n" .
            "4. " . __('Welding power supply: An electrical socket (3 x 400V / min. 10A) must be installed by a certified electrician at client expense.', 'creation-reservoir')
        ), 0, 1);
        $this->Ln(6);

        // Cadre de signature client
        $this->SetDrawColor(180, 180, 180);
        $this->Rect($this->margin, $this->GetY(), $this->GetPageWidth() - (2 * $this->margin), 32);
        
        $this->SetXY($this->margin + 4, $this->GetY() + 4);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0);
        $this->Cell(0, 5, $this->tx(__('Date and signature of client (or representative):', 'creation-reservoir')), 0, 1);
        
        $this->SetXY($this->margin + 4, $this->GetY() + 18);
        $this->SetFont('Arial', '', 8);
        $this->Cell(90, 5, $this->tx(__('Date:', 'creation-reservoir') . ' ............................................'), 0, 0);
        $this->Cell(0, 5, $this->tx(__('Signature:', 'creation-reservoir')), 0, 1);
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

    protected function draw_header($title)
    {
        if (file_exists($this->logo_path)) {
            $this->Image($this->logo_path, $this->margin, $this->margin, 25);
        }

        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(180, 0, 0);
        $this->SetXY(0, $this->margin);
        $this->Cell($this->GetPageWidth() - $this->margin, 5, $this->tx($title), 0, 1, 'R');

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(120);
        $this->Cell($this->GetPageWidth() - 2 * $this->margin, 4, $this->tx(date('d.m.Y')), 0, 1, 'R');

        $this->SetY($this->margin + 18);
        $this->SetDrawColor(180, 0, 0);
        $this->Line($this->margin, $this->GetY(), $this->GetPageWidth() - $this->margin, $this->GetY());
        $this->Ln(4);
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

    protected function val($value, $suffix = '')
    {
        if ($value === null || $value === '') {
            return $this->dots();
        }
        return $value . $suffix;
    }

    /**
     * Pour les champs "choix" (oui/non, béton/bois, etc.) : si la donnée est
     * connue en DB, on l'affiche telle quelle ; sinon on affiche les cases à
     * cocher pour que le client les remplisse à la main.
     */
    protected function checkbox_or_value($value, array $options, $prefix = '')
    {
        if ($value !== null && $value !== '') {
            return $prefix . $value;
        }

        $boxes = array_map(function ($opt) {
            return '[  ] ' . $opt;
        }, $options);

        return $prefix . implode('    ', $boxes);
    }

    protected function dots()
    {
        return str_repeat('.', 22);
    }

    public function Footer() {}
}