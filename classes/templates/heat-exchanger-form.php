<?php
$coil_nb = $args['coil_nb'];
$data = $args['data'] ?? []; // données JSON du coilN
?>
<div class="ispag-modal-left exchanger-form vertical-form" data-coilnb="<?= esc_attr($coil_nb) ?>" data-tank-id="<?= esc_attr($tank_id) ?>">
    <h3><?php printf(__('Heat exchanger #%d', 'creation-reservoir'), $coil_nb); ?></h3>

    <label><?php _e('Load input temperature', 'creation-reservoir'); ?></label>
    <input type="number" name="loadInputTemperature_<?= $coil_nb ?>" value="<?= esc_attr($data['loadInputTemperature'] ?? '') ?>" class="form-field">

    <label><?php _e('Load output temperature', 'creation-reservoir'); ?></label>
    <input type="number" name="loadOutputTemperature_<?= $coil_nb ?>" value="<?= esc_attr($data['loadOutputTemperature'] ?? '') ?>" class="form-field">

    <label><?php _e('Cold water temperature', 'creation-reservoir'); ?></label>
    <input type="number" name="coldWaterInputTemperature_<?= $coil_nb ?>" value="<?= esc_attr($data['coldWaterInputTemperature'] ?? '') ?>" class="form-field">

    <label><?php _e('Warm water temperature', 'creation-reservoir'); ?></label>
    <input type="number" name="hotWaterOutputTemperature_<?= $coil_nb ?>" value="<?= esc_attr($data['hotWaterOutputTemperature'] ?? '') ?>" class="form-field">

    <label><?php _e('Power (kW)', 'creation-reservoir'); ?></label>
    <input type="number" name="exchangerPower_<?= $coil_nb ?>" value="<?= esc_attr($data['exchangerPower'] ?? '') ?>" class="form-field">

    <label><?php _e('Heat exchanger surface (m²)', 'creation-reservoir'); ?></label>
    <input type="number" name="coilSurface_<?= $coil_nb ?>" value="<?= esc_attr($data['coilSurface'] ?? '') ?>" class="form-field" readonly>

    
</div>
