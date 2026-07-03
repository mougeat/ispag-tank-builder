<?php
$coil_nb = $args['coil_nb'];
$data = $args['data'] ?? [];
?>

<div class="ispag-modal-left exchanger-form vertical-form" data-coilnb="<?= esc_attr($coil_nb) ?>" data-tank-id="<?= esc_attr($tank_id) ?>">
    <h3><?php printf(__('Heat exchanger #%d', 'creation-reservoir'), $coil_nb); ?></h3>

    <!-- Load input temperature -->
    <label><?php _e('Load input temperature', 'creation-reservoir'); ?></label>
    <input type="number" name="loadInputTemperature_<?= $coil_nb ?>" value="<?= esc_attr($data['loadInputTemperature'] ?? '') ?>" class="form-field">
    <span class="error-message" style="color: red; font-size: 12px;"></span>

    <!-- Load output temperature -->
    <label><?php _e('Load output temperature', 'creation-reservoir'); ?></label>
    <input type="number" name="loadOutputTemperature_<?= $coil_nb ?>" value="<?= esc_attr($data['loadOutputTemperature'] ?? '') ?>" class="form-field">
    <span class="error-message" style="color: red; font-size: 12px;"></span>

    <!-- Cold water temperature -->
    <label><?php _e('Cold water temperature', 'creation-reservoir'); ?></label>
    <input type="number" name="coldWaterInputTemperature_<?= $coil_nb ?>" value="<?= esc_attr($data['coldWaterInputTemperature'] ?? '') ?>" class="form-field">
    <span class="error-message" style="color: red; font-size: 12px;"></span>

    <!-- Warm water temperature -->
    <label><?php _e('Warm water temperature', 'creation-reservoir'); ?></label>
    <input type="number" name="hotWaterOutputTemperature_<?= $coil_nb ?>" value="<?= esc_attr($data['hotWaterOutputTemperature'] ?? '') ?>" class="form-field">
    <span class="error-message" style="color: red; font-size: 12px;"></span>

    <!-- Power (kW) -->
    <label><?php _e('Power (kW)', 'creation-reservoir'); ?></label>
    <input type="number" name="exchangerPower_<?= $coil_nb ?>" value="<?= esc_attr($data['exchangerPower'] ?? '') ?>" class="form-field">
    <span class="error-message" style="color: red; font-size: 12px;"></span>

    <!-- Heat exchanger surface (m²) -->
    <label><?php _e('Heat exchanger surface (m²)', 'creation-reservoir'); ?></label>
    <input type="number"
           name="coilSurface_<?= $coil_nb ?>"
           value="<?= esc_attr($data['coilSurface'] ?? '') ?>"
           class="form-field surface-field">
</div>