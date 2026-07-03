<?php
/**
 * ISPAG Tank Design Sub-template
 * @version 2.1.8
 */
$user_can = current_user_can('manage_order'); 
$can_view_prices = current_user_can('display_sales_prices');
$allow_display_sensible_info = isset($_COOKIE['ispag_allow_prices']) && $_COOKIE['ispag_allow_prices'] === 'true';
?>
<div class="ispag-tank-fields detail-block">
    <div class="card-header" style="margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
        <h3 style="margin:0; color: var(--ispag-red);">
            <span class="dashicons dashicons-admin-tools" style="vertical-align: middle;"></span> 
            <?php echo __('Tank design', 'creation-reservoir'); ?>
        </h3>
    </div>
    
    <input type="hidden" name="tank[article_id]" value="<?= esc_attr($article_id) ?>">

    <div class="ispag-modal-grid" style="gap: 15px;">
        
        <div class="ispag-field" style="flex: 1; min-width: 200px;">
            <label><strong><?php echo __('Tank type', 'creation-reservoir'); ?></strong></label>
            <select name="tank[type]" id="tank-typ" onchange="updateTankDefaultsFromSelect(this)" style="width: 100%;">
                <option value=""><?php echo __('-- Choose --', 'creation-reservoir'); ?></option>
                <?php
                $types = $this->get_tank_types();
                $selected = $data['conception']->TankType ?? '';
                foreach ($types as $type) {
                    $is_selected = ($type->Id === $selected) ? 'selected' : '';
                    echo "<option value='" . esc_attr($type->Id) . "' data-id='" . esc_attr($type->Id) . "' $is_selected>" . esc_html__( $type->Value, 'creation-reservoir' ) . "</option>";
                }
                ?>
            </select>
        </div>

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
                    echo "<option value='" . esc_attr($support->Id) . "' data-id='" . esc_attr($type->Id) . "'$is_selected>" . esc_html__( $support->Value, 'creation-reservoir' ) . "</option>";
                }
                ?>
            </select>
        </div>

    </div>

    <script>
        jQuery(function () {
            const $typeSelect = jQuery('#tank-typ');
            const selected = $typeSelect.find(':selected').data('id');
            if (selected) {
                // On s'assure que la fonction existe avant l'appel
                if(typeof updateTankDefaultsFromSelect === "function") {
                    updateTankDefaultsFromSelect($typeSelect[0]);
                }
            }
        });
    </script>
</div>