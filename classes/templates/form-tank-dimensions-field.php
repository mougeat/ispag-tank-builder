<?php
/**
 * ISPAG Tank Dimensions Sub-template
 * @version 2.1.8 - Modernized Grid
 */
$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
$allow_display_sensible_info = isset($_COOKIE['ispag_allow_prices']) && $_COOKIE['ispag_allow_prices'] === 'true';

// error_log('Formulaire Tank Dimensions : ' . print_r($data, true));
?>
<div id="tank-dimensions-form" class="detail-block" <?php echo $display; ?> style="margin-top: 25px;">
    
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 12px;">
        <h3 style="margin:0; color: var(--ispag-red); font-size: 1.1em;">
            <span class="dashicons dashicons-editor-expand" style="vertical-align: middle; margin-right: 5px;"></span> 
            <?php echo __('Dimensions & Technical data', 'creation-reservoir'); ?>
        </h3>
        <div class="ispag-field-checkbox" style="background: #f0f0f1; padding: 5px 12px; border-radius: var(--ispag-btn-border-radius);">
            <input type="checkbox" name="tank[auto_calculate]" id="tank-auto-calculate" value="1" checked style="vertical-align: middle;">
            <label for="tank-auto-calculate" style="display: inline; margin-left: 5px; font-weight: 600; font-size: 12px; cursor: pointer;">
                <?php echo __('Automatically calculate', 'creation-reservoir'); ?>
            </label>
        </div>
    </div>

    <div class="ispag-modal-grid">
        
        <div class="ispag-field" style="flex: 1; min-width: 200px;">
            <div class="field-group" style="margin-bottom: 15px;">
                <label><strong><?php echo __('Diameter', 'creation-reservoir'); ?> (mm)</strong></label>
                <select name="tank[diameter]" id="tank-diameter" data-current-diameter="<?= esc_attr($data['dimensions']->Diameter ?? '') ?>" style="width: 100%;">
                    <option value="">-- <?php echo __('Select', 'creation-reservoir'); ?> --</option>
                </select>
            </div>

            <div class="field-group" style="margin-bottom: 15px;">
                <label><strong><?php echo __('Volume', 'creation-reservoir'); ?> (L)</strong></label>
                <input type="number" name="tank[volume]" value="<?= esc_attr($data['dimensions']->Volume ?? '') ?>" min="0" style="width: 100%;">
            </div>

            <div class="field-group">
                <label><strong><?php echo __('Total height', 'creation-reservoir'); ?> (mm)</strong></label>
                <input type="number" name="tank[height]" value="<?= esc_attr($data['dimensions']->Height ?? '') ?>" min="0" style="width: 100%;">
            </div>
        </div>

        <div class="ispag-field" style="flex: 1; min-width: 200px;">
            <div class="field-group" style="margin-bottom: 15px;">
                <label><strong><?php echo __('Ground clearance', 'creation-reservoir'); ?> (mm)</strong></label>
                <input type="number" name="tank[clearance]" value="<?= esc_attr($data['dimensions']->GroundClearance ?? '100') ?>" min="0" max="500" style="width: 100%;">
            </div>

            <div class="field-group">
                <label><strong><?php echo __('Tipping height', 'creation-reservoir'); ?> (mm)</strong></label>
                <input type="number" name="tank[tipping]" value="<?= esc_attr($data['dimensions']->TippingHeight ?? '') ?>" min="0" readonly style="width: 100%; background: #f0f0f1; cursor: not-allowed; border-color: #ddd;">
                <small style="color: #888; font-style: italic;"><?= __('Calculated automatically', 'creation-reservoir') ?></small>
            </div>
        </div>

        <div class="ispag-field" style="flex: 1; min-width: 200px;">
            <div class="field-group" style="margin-bottom: 15px;">
                <label><strong><?php echo __('Design pressure', 'creation-reservoir'); ?> (bar)</strong></label>
                <input type="number" step="0.1" name="tank[max_pressure]" value="<?= esc_attr($data['dimensions']->MaxPressure ?? '') ?>" style="width: 100%;">
            </div>

            <div class="field-group" style="margin-bottom: 15px;">
                <label><strong><?php echo __('Test pressure', 'creation-reservoir'); ?> (bar)</strong></label>
                <input type="number" step="0.1" name="tank[test_pressure]" value="<?= esc_attr($data['dimensions']->TestPressure ?? '') ?>" style="width: 100%; background-color: #f8f9fa; cursor: not-allowed;" readonly >
            </div>

            <div class="field-group">
                <label><strong><?php echo __('Temperature', 'creation-reservoir'); ?> (°C)</strong></label>
                <input type="number" name="tank[temperature]" value="<?= esc_attr($data['dimensions']->usingTemperature ?? '109') ?>" style="width: 100%;">
            </div>
        </div>
        
            
        <div class="ispag-field" style="flex: 1; min-width: 200px;" id="tank-pricing-calculation">
            <?php if ($can_view_prices && $allow_display_sensible_info): ?>
            <div class="field-group" style="margin-bottom: 15px;">
                <label><strong><?php echo __('Indicative price (excluding VAT)', 'creation-reservoir'); ?></strong></label>
                <div style="display: flex; align-items: center; gap: 5px;">
                    <!-- Champ pour afficher le prix -->
                    <input type="text" id="tank-price-display" readonly style="width: 100%; background: #fdfdfd; font-weight: bold; color: var(--ispag-red); border: 1px solid #ddd;" placeholder="---">

                    <!-- Champ caché pour stocker le prix -->
                    <input type="hidden" name="tank[prix_achat_base]" id="tank-price-value">

                    <!-- Conteneur pour les erreurs -->
                    <div id="tank-price-errors" style="margin-top: 10px;"></div>

                    <!-- Conteneur pour le statut du rapport -->
                    <div id="report-status" style="margin-top: 10px;"></div>
                </div>
                <!-- Bouton pour générer le rapport -->
                <button type="button" id="generate-report-button" class="button" style="margin-top: 10px;">
                    <?php echo __('Report Price', 'creation-reservoir'); ?>
                </button>
                <small style="color: #666; font-size: 0.85em;">
                    <?php echo __('Raw tank price based on dimensions', 'creation-reservoir'); ?>
                </small>
            </div>
            <?php else: ?>
                <label><strong><?php echo __('You are not allowed to view this field', 'creation-reservoir'); ?></strong></label>
            <?php endif; ?>
        </div>
        

    </div>

    <div class="ispag-modal-grid" style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
        <div class="ispag-field" style="flex: 1; min-width: 250px;">
            <div class="selector-box" style="background: #fff; padding: 10px; border-radius: var(--ispag-btn-border-radius); border: 1px solid #ddd;">
                <?php echo apply_filters('ispag_render_insulation_selector', null, $article_id); ?>
            </div>
        </div>
        <div class="ispag-field" style="flex: 1; min-width: 250px;">
            <div class="selector-box" style="background: #fff; padding: 10px; border-radius: var(--ispag-btn-border-radius); border: 1px solid #ddd;">
                <?php echo apply_filters('ispag_render_welding_selector', '', $article_id); ?>
            </div>
        </div>
    </div>

    <div class="ispag-modal-grid" style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
        <div class="ispag-field" style="flex: 1; min-width: 250px;">
             <div class="field-group" style="margin-bottom: 15px;">
                <div id="ispag-article-template-wrapper" style="margin-bottom: 15px;">
                    <label for="ispag-article-template-select"><strong><?php esc_html_e('Article comment template', 'creation-reservoir'); ?></strong></label>
                    <div style="display: flex; gap: 10px;">
                        <select id="ispag-article-template-select" style="flex: 1;">
                            <option value=""><?php esc_html_e('-- Select a template --', 'ispag-crm'); ?></option>
                            <?php
                            $repo = new ISPAG_Template_Repository();
                            $current_user_id = get_current_user_id();
                            $folders = $repo->get_folders($current_user_id);
                            $templates = $repo->get_templates_for_user($current_user_id, '', 'article_comment');

                            // foreach ($folders as $folder) :
                            //     echo '<optgroup label="' . esc_attr($folder->name) . '">';
                            //     foreach ($templates as $tpl) {
                            //         if ($tpl->folder_id == $folder->id) {
                            //             echo '<option value="' . esc_attr($tpl->id) . '">' . esc_html($tpl->name) . '</option>';
                            //         }
                            //     }
                            //     echo '</optgroup>';
                            // endforeach;
                            foreach ($folders as $folder) {
                                // 1. Filtrer ou vérifier s'il y a des templates pour ce dossier
                                $folder_templates = array_filter($templates, function($tpl) use ($folder) {
                                    return $tpl->folder_id == $folder->id;
                                });

                                // 2. Si le dossier ne contient aucun template, on passe au suivant
                                if (empty($folder_templates)) {
                                    continue;
                                }

                                // 3. Sinon, on affiche le dossier (ex: optgroup) et ses templates
                                echo '<optgroup label="' . esc_attr($folder->name) . '">';
                                foreach ($folder_templates as $tpl) {
                                    echo '<option value="' . esc_attr($tpl->id) . '">' . esc_html($tpl->name) . '</option>';
                                }
                                echo '</optgroup>';
                            }

                            echo '<optgroup label="' . esc_attr__('Other', 'ispag-crm') . '">';
                            foreach ($templates as $tpl) {
                                if (empty($tpl->folder_id)) {
                                    echo '<option value="' . esc_attr($tpl->id) . '">' . esc_html($tpl->name) . '</option>';
                                }
                            }
                            echo '</optgroup>';
                            ?>
                        </select>
                        <button type="button" id="ispag-apply-article-template" class="button button-secondary">
                            <?php esc_html_e('Apply', 'ispag-crm'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="ispag-field" style="flex: 1; min-width: 250px;">
                
            <label><strong><?php echo __('Open comment', 'creation-reservoir'); ?></strong></label>
            <p class="description" style="font-size: 0.85em; color: #666; margin-top: 2px; margin-bottom: 5px;">
                <?php echo __('will be inserted into the item description', 'creation-reservoir'); ?>
            </p>
            <textarea id="tank-open-comment" name="tank[openComment]" style="width: 100%;"><?= esc_attr($data['conception']->openComment ?? '') ?></textarea>
            
        </div>
    </div>
</div>