<?php
/**
 * ISPAG Tank Dimensions Sub-template
 * @version 2.1.8 - Modernized Grid
 */
$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
$allow_display_sensible_info = isset($_COOKIE['ispag_allow_prices']) && $_COOKIE['ispag_allow_prices'] === 'true';
$display = $display ?? null;

// error_log('Formulaire Tank Dimensions : ' . print_r($data, true));
?>
<form id="tank-conception-fields">
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

        <input type="text" name="tank[type]" id="tank-typ" value="">
        <input type="hidden" name="supplier" id="tank-supplier-display" value="" list="supplier-list" style="width:100%;" data-value="">
        <input type="hidden" name="isProjectOrPurchase" value="project">
            
            <div class="ispag-field" style="flex: 1; min-width: 200px;">
                <div class="ispag-field" style="flex: 1; min-width: 200px;">
                    <label><strong><?php echo __('Materials', 'creation-reservoir'); ?></strong></label>
                    <select name="tank[materiau]" id="tank-material" onchange="updateSupplierByMaterial(this)" style="width: 100%;">
                        <option value=""><?php echo __('-- Choose --', 'creation-reservoir'); ?></option>
                        <?php
                        $materials = $this->get_tank_materials();
                        $selected_material = $data['conception']->Material ?? '';
                        foreach ($materials as $mat) {
                            $is_selected = ($mat->Id === $selected_material) ? 'selected' : '';
                            echo "<option value='" . esc_attr($mat->Id) . "' data-id='" . esc_attr($mat->Id) . "'$is_selected>" . esc_html__( $mat->Value, 'creation-reservoir' ) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="ispag-field" style="flex: 1; min-width: 200px;">
                    <label><strong><?php echo __('Support', 'creation-reservoir'); ?></strong></label>
                    <select name="tank[support]" style="width: 100%;">
                        <option value=""><?php echo __('-- Choose --', 'creation-reservoir'); ?></option>
                        <?php
                        $supports = $this->get_tank_support();
                        $selected_support = $data['conception']->Support ?? '';
                        foreach ($supports as $support) {
                            $is_selected = ($support->Id === $selected_support) ? 'selected' : '';
                            echo "<option value='" . esc_attr($support->Id) . "' $is_selected>" . esc_html__( $support->Value, 'creation-reservoir' ) . "</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>


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
            
                
            <?php if ($can_view_prices && $allow_display_sensible_info): ?>
            <div class="ispag-field" style="flex: 1; min-width: 200px;">
                
                <div class="field-group" style="margin-bottom: 15px;">
                    <label><strong><?php echo __('Indicative purchase price (excluding VAT)', 'creation-reservoir'); ?></strong></label>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <input type="text" id="tank-price-display" readonly 
                            style="width: 100%; background: #fdfdfd; font-weight: bold; color: var(--ispag-red); border: 1px solid #ddd;" 
                            placeholder="---">
                        
                        <input type="hidden" name="tank[prix_achat_base]" id="tank-price-value">
                        
                        <span style="font-weight: bold;">€</span>
                    </div>
                    <small style="color: #666; font-size: 0.85em;">
                        <?php echo __('Raw tank price based on dimensions', 'creation-reservoir'); ?>
                    </small>
                </div>
                
            </div>
            <?php endif; ?>
            

        </div>

        
    </div>
</form>