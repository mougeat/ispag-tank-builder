<?php
/**
 * ISPAG Fitting Row Template
 * @version 2.1.9 - Fixed Alignment
 */
?>
<div class="fitting-row ispag-action-row" data-id="<?= esc_attr($fitting->fitting_id ?? 0) ?>" 
     style="display: flex; gap: 10px; align-items: center; background: #fff; padding: 12px; border-radius: 6px; margin-bottom: 8px; border: 1px solid #ddd; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
    
    <input type="hidden" name="fitting[id][]" value="<?= esc_attr($fitting->fitting_id ?? 0) ?>" />
    <input type="hidden" name="fitting[type][]" />
    
    <div class="ispag-input-wrapper" style="flex: 0 0 110px;">
        <label style="display:block; font-size:10px; color:#888; text-transform:uppercase;"><?= __('Diam.', 'creation-reservoir') ?></label>
        <select name="fitting[diameter][]" style="width: 100%; border-radius: 4px; border: 1px solid #ccc; font-weight: 600;">
            <option value=""><?= __('-- Ø --', 'creation-reservoir') ?></option>
            <?php
            global $wpdb;
            $diameter_options = $wpdb->get_results("
                SELECT fd.Id, c.Value, fd.DN
                FROM {$wpdb->prefix}achats_tank_conception c
                LEFT JOIN {$wpdb->prefix}achats_flange_dimensions fd ON fd.Typ = c.Id
                WHERE c.SelectType = 'connection'
                ORDER BY c.sort ASC, fd.InternalDiamter ASC
            "); 
            foreach ($diameter_options as $option) {
                $selected = (!empty($fitting->Pouces) && $fitting->Pouces == $option->DN) ? 'selected' : '';
                echo '<option value="' . esc_attr($option->Id) . '" ' . $selected . '>' . esc_html__($option->DN, 'creation-reservoir' ) . '</option>';
            }
            ?>
        </select>
    </div>

    <div class="ispag-input-wrapper" style="flex: 2; min-width: 160px;">
        <label style="display:block; font-size:10px; color:#888; text-transform:uppercase;"><?= __('Accessory', 'creation-reservoir') ?></label>
        <select name="fitting[accessories][]" style="width: 100%; border-radius: 4px; border: 1px solid #ccc;">
            <option value=""><?= __('-- Accessories --', 'creation-reservoir') ?></option>
            <?php
            $accessory_options = $wpdb->get_results("
                SELECT c.Id, c.Value 
                FROM {$wpdb->prefix}achats_tank_conception c
                WHERE c.SelectType = 'accessories'
                ORDER BY c.sort ASC
            ");
            foreach ($accessory_options as $option) {
                $selected = (!empty($fitting->id_accessories) && $fitting->id_accessories == $option->Id) ? 'selected' : '';
                echo '<option value="' . esc_attr($option->Id) . '" ' . $selected . '>' . esc_html__($option->Value, 'creation-reservoir' ) . '</option>';
            }
            ?>
        </select>
    </div>

    <div class="ispag-input-wrapper" style="flex: 2; min-width: 140px;">
        <label style="display:block; font-size:10px; color:#888; text-transform:uppercase;"><?= __('Usage', 'creation-reservoir') ?></label>
        <input type="text" name="fitting[madeFor][]" value="<?= esc_attr($fitting->madeFor ?? '') ?>" placeholder="<?= __('ex: Aller', 'creation-reservoir') ?>" style="width: 100%; border-radius: 4px; border: 1px solid #ccc;" />
    </div>

    <div class="ispag-input-wrapper" style="flex: 0 0 90px;">
        <label style="display:block; font-size:10px; color:#888; text-transform:uppercase;"><?= __('Height', 'creation-reservoir') ?></label>
        <div style="position: relative;">
            <input type="number" name="fitting[height][]" value="<?= esc_attr($fitting->Height ?? '') ?>" min="0" style="width: 100%; padding-right: 25px; border-radius: 4px; border: 1px solid #ccc;" />
            <span style="position: absolute; right: 5px; top: 10px; font-size: 10px; color: #aaa;">mm</span>
        </div>
    </div>

    <div class="ispag-input-wrapper" style="flex: 0 0 85px;">
        <label style="display:block; font-size:10px; color:#888; text-transform:uppercase;"><?= __('Angle', 'creation-reservoir') ?></label>
        <div style="position: relative;">
            <input type="number" name="fitting[angle][]" value="<?= esc_attr($fitting->Angle ?? '') ?>" min="0" max="360" style="width: 100%; padding-right: 20px; border-radius: 4px; border: 1px solid #ccc;" />
            <span style="position: absolute; right: 5px; top: 10px; font-size: 10px; color: #aaa;">°</span>
        </div>
    </div>

    <div class="ispag-row-actions" style="display: flex; gap: 5px; padding-top: 15px;">
        <button type="button" class="ispag-btn ispag-btn-secondary-outlined btn-duplicate" data-fitting-id="<?= esc_attr($fitting->fitting_id ?? 0); ?>" title="<?= __('Duplicate', 'creation-reservoir') ?>" style="width: 38px; padding: 0; display: flex; align-items: center; justify-content: center;">
            <span class="dashicons dashicons-admin-page"></span>
        </button>
        <button type="button" class="ispag-btn ispag-btn-red-outlined btn-delete-fitting" data-fitting-id="<?= esc_attr($fitting->fitting_id ?? 0); ?>" title="<?= __('Delete', 'creation-reservoir') ?>" style="width: 38px; padding: 0; display: flex; align-items: center; justify-content: center;">
            <span class="dashicons dashicons-trash"></span>
        </button>
    </div>

</div>