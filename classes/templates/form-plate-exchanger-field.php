<?php
/**
 * ISPAG Plate Heat Exchanger Sub-template
 * @version 1.1.0
 */
?>
<div id="plate-exchanger-form" class="detail-block" style="margin-top: 25px;">
    
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 12px;">
        <h3 style="margin:0; color: var(--ispag-red); font-size: 1.1em;">
            <span class="dashicons dashicons-randomize" style="vertical-align: middle; margin-right: 5px;"></span> 
            <?php echo __('Plate Heat Exchanger Data', 'creation-reservoir'); ?>
        </h3>
    </div>

    <div class="ispag-modal-grid" style="margin-bottom: 20px; background: #f9f9f9; padding: 15px; border-radius: var(--ispag-btn-border-radius); display: flex; gap: 20px;">
        <div class="ispag-field" style="flex: 1;">
            <div class="field-group">
                <label><strong><?php echo __('Exchanger Type', 'creation-reservoir'); ?></strong></label>
                <select name="exchanger[type]" style="width: 100%; font-size: 1.1em; height: 40px;">
                    <option value="brazed" <?php selected($data['exchanger']->type ?? 'brazed', 'brazed'); ?>>
                        <?php echo __('Brazed', 'creation-reservoir'); ?>
                    </option>
                    <option value="gasketed_3104" <?php selected($data['exchanger']->type ?? 'brazed', 'gasketed'); ?>>
                        <?php echo __('Gasketed / Screwed (Inox 304)', 'creation-reservoir'); ?>
                    </option>
                    <option value="gasketed_316" <?php selected($data['exchanger']->type ?? 'brazed', 'gasketed'); ?>>
                        <?php echo __('Gasketed / Screwed (Inox 316 L)', 'creation-reservoir'); ?>
                    </option>
                </select>
            </div>
        </div>

        <div class="ispag-field" style="flex: 1;">
            <div class="field-group">
                <label><strong><?php echo __('Total Power', 'creation-reservoir'); ?> (kW)</strong></label>
                <input type="number" step="0.1" name="exchanger[power]" value="<?= esc_attr($data['exchanger']->power ?? '') ?>" style="width: 100%; font-size: 1.2em; font-weight: bold; height: 40px;">
            </div>
        </div>
    </div>

    <div class="ispag-modal-grid" style="display: flex; gap: 30px;">
        
        <div class="ispag-field" style="flex: 1; min-width: 250px; border-right: 1px solid #eee; padding-right: 20px;">
            <h4 style="margin-top: 0; color: #555; border-bottom: 2px solid #0073aa; display: inline-block; padding-bottom: 3px;">
                <?php echo __('Primary Circuit', 'creation-reservoir'); ?>
            </h4>

            <div class="field-group" style="margin-bottom: 15px; display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label><?php echo __('Inlet Temp.', 'creation-reservoir'); ?> (°C)</label>
                    <input type="number" step="0.1" name="exchanger[primary_temp_in]" value="<?= esc_attr($data['exchanger']->primary_temp_in ?? '') ?>" style="width: 100%;">
                </div>
                <div style="flex: 1;">
                    <label><?php echo __('Outlet Temp.', 'creation-reservoir'); ?> (°C)</label>
                    <input type="number" step="0.1" name="exchanger[primary_temp_out]" value="<?= esc_attr($data['exchanger']->primary_temp_out ?? '') ?>" style="width: 100%;">
                </div>
            </div>

            <div class="field-group" style="margin-bottom: 15px;">
                <label><?php echo __('Pressure Drop', 'creation-reservoir'); ?> (kPa)</label>
                <input type="number" step="0.01" name="exchanger[primary_pressure_drop]" value="<?= esc_attr($data['exchanger']->primary_pressure_drop ?? '') ?>" style="width: 100%;">
            </div>

            <div class="field-group">
                <label><?php echo __('Fluid Type', 'creation-reservoir'); ?></label>
                <select name="exchanger[primary_fluid]" style="width: 100%;">
                    <?php foreach ($fluids as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($data['exchanger']->primary_fluid ?? 'water', $key); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="ispag-field" style="flex: 1; min-width: 250px;">
            <h4 style="margin-top: 0; color: #555; border-bottom: 2px solid #23282d; display: inline-block; padding-bottom: 3px;">
                <?php echo __('Secondary Circuit', 'creation-reservoir'); ?>
            </h4>

            <div class="field-group" style="margin-bottom: 15px; display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label><?php echo __('Inlet Temp.', 'creation-reservoir'); ?> (°C)</label>
                    <input type="number" step="0.1" name="exchanger[secondary_temp_in]" value="<?= esc_attr($data['exchanger']->secondary_temp_in ?? '') ?>" style="width: 100%;">
                </div>
                <div style="flex: 1;">
                    <label><?php echo __('Outlet Temp.', 'creation-reservoir'); ?> (°C)</label>
                    <input type="number" step="0.1" name="exchanger[secondary_temp_out]" value="<?= esc_attr($data['exchanger']->secondary_temp_out ?? '') ?>" style="width: 100%;">
                </div>
            </div>

            <div class="field-group" style="margin-bottom: 15px;">
                <label><?php echo __('Pressure Drop', 'creation-reservoir'); ?> (kPa)</label>
                <input type="number" step="0.01" name="exchanger[secondary_pressure_drop]" value="<?= esc_attr($data['exchanger']->secondary_pressure_drop ?? '') ?>" style="width: 100%;">
            </div>

            <div class="field-group">
                <label><?php echo __('Fluid Type', 'creation-reservoir'); ?></label>
                <select name="exchanger[secondary_fluid]" style="width: 100%;">
                    <?php foreach ($fluids as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($data['exchanger']->secondary_fluid ?? 'water', $key); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>