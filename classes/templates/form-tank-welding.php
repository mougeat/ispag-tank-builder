<?php
/**
 * ISPAG Welding Row Template
 * @version 2.1.9 - Fixed Alignment
 */
?>
<div class="fitting-row ispag-action-row" data-id="<?= esc_attr($welding->fitting_id ?? 0) ?>" 
     style="display: flex; gap: 10px; align-items: center; background: #fff; padding: 12px; border-radius: 6px; margin-bottom: 8px; border: 1px solid #ddd; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
    
    <input type="hidden" name="fitting[id][]" value="<?= esc_attr($welding->fitting_id ?? 0) ?>" />

    <div class="ispag-input-wrapper" style="flex: 3; min-width: 180px;">
        <label style="display:block; font-size:10px; color:#888; text-transform:uppercase; font-weight: 600;"><?= __('Welding / Plate Type', 'creation-reservoir') ?></label>
        <select name="fitting[type][]" style="width: 100%; border-radius: 4px; border: 1px solid #ccc; font-weight: 500;">
            <option value=""><?= __('-- Type --', 'creation-reservoir') ?></option>
            <?php
            global $wpdb;
            $welding_options = $wpdb->get_results("
                SELECT c.Id, c.Value 
                FROM {$wpdb->prefix}achats_tank_conception c
                WHERE c.SelectType IN ('welding', 'DrilledPlate')
                ORDER BY c.sort ASC
            ");
            foreach ($welding_options as $option) {
                $selected = (!empty($welding->type_id) && $welding->type_id == $option->Id) ? 'selected' : '';
                echo '<option value="' . esc_attr($option->Id) . '" ' . $selected . '>' . esc_html__($option->Value, 'creation-reservoir' ) . '</option>';
            }
            ?>
        </select>
    </div>

    <div class="ispag-input-wrapper" style="flex: 1; min-width: 100px;">
        <label style="display:block; font-size:10px; color:#888; text-transform:uppercase; font-weight: 600;"><?= __('Height', 'creation-reservoir') ?></label>
        <div style="position: relative;">
            <input type="number" name="fitting[height][]" value="<?= esc_attr($welding->Height ?? '') ?>" min="0" placeholder="0" style="width: 100%;  padding-right: 25px; border-radius: 4px; border: 1px solid #ccc;" />
            <span style="position: absolute; right: 8px; top: 10px; font-size: 10px; color: #aaa;">mm</span>
        </div>
    </div>

    <div class="ispag-row-actions" style="display: flex; gap: 5px; padding-top: 15px;">
        <button type="button" class="ispag-btn ispag-btn-secondary-outlined btn-duplicate" 
                data-fitting-id="<?= esc_attr($welding->fitting_id ?? 0); ?>" 
                title="<?= __('Duplicate', 'creation-reservoir') ?>" 
                style="width: 38px; padding: 0; display: flex; align-items: center; justify-content: center;">
            <span class="dashicons dashicons-admin-page"></span>
        </button>
        <button type="button" class="ispag-btn ispag-btn-red-outlined btn-delete-fitting" 
                data-fitting-id="<?= esc_attr($welding->fitting_id ?? 0); ?>" 
                title="<?= __('Delete', 'creation-reservoir') ?>" 
                style="width: 38px; padding: 0; display: flex; align-items: center; justify-content: center;">
            <span class="dashicons dashicons-trash"></span>
        </button>
    </div>
</div>