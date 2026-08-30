// =============================================
// GESTION DES NOUVEAUX RÉSERVOIRS (Création)
// =============================================

/**
 * Initialise les champs dynamiques pour un NOUVEAU réservoir
 * (appelé quand on sélectionne un type depuis la modale de création).
 */
function initializeNewTankFields() {
    // Vérifier si on est en mode création (h2 avec data-id)
    const $h2 = $('#ispag-product-modal-content h2');
    console.log('🔍 [DEBUG] Sélecteur $h2:', $h2); // 👈 Affiche l'élément jQuery
    console.log('🔍 [DEBUG] $h2.length:', $h2.length); // 👈 Doit être 1
    console.log('🔍 [DEBUG] $h2.data("id"):', $h2.data('id')); // 👈 Doit être "4"

    if (!$h2.length || !$h2.data('id')) {
        console.warn('⚠️ [TANK] h2 non trouvé ou data-id manquant.');
        return; // On est en mode édition, pas de création
    }

    const typeId = $h2.data('id');
    console.log(`🆕 [TANK] Initialisation pour NOUVEAU réservoir (Type ID: ${typeId})`);

    // Attendre que les restrictions soient chargées
    if (typeof setIspagTankRestrictionsValue === 'function' && !isDataLoaded) {
        setIspagTankRestrictionsValue().then(() => {
            applyNewTankRestrictions(typeId);
        });
    } else if (typeof restrictions !== 'undefined') {
        applyNewTankRestrictions(typeId);
    } else {
        console.warn('⚠️ [TANK] restrictions non chargé. Réessayer plus tard...');
        // Réessayer après un délai
        setTimeout(initializeNewTankFields, 500);
    }
}

/**
 * Applique les restrictions pour un NOUVEAU réservoir.
 * @param {number} typeId - ID du type de réservoir.
 */
function applyNewTankRestrictions(typeId) {
    if (!restrictions?.typ?.[typeId]) {
        console.warn(`⚠️ [TANK] Aucune restriction trouvée pour le type ID: ${typeId}`);
        return;
    }

    const typeRestrictions = restrictions.typ[typeId];
    const defaults = typeRestrictions.default;
    const allowed = typeRestrictions.restrictions;

    // --- 1. Matériaux ---
    if (allowed.Material) {
        const $materialSelect = $('select[name="tank[materiau]"]');
        $materialSelect.find('option').each(function() {
            const $option = $(this);
            const materialId = parseInt($option.val());
            if (materialId === 0) return; // Garder "-- Choisir --"

            if (allowed.Material.includes(materialId)) {
                $option.show().prop('disabled', false);
            } else {
                $option.hide().prop('disabled', true);
            }
        });

        // Appliquer la valeur par défaut si elle existe
        if (defaults.Material) {
            $materialSelect.val(defaults.Material).trigger('change');
        }
    }

    // --- 2. Supports ---
    if (allowed.Support) {
        const $supportSelect = $('select[name="tank[support]"]');
        $supportSelect.find('option').each(function() {
            const $option = $(this);
            const supportId = parseInt($option.val());
            if (supportId === 0) return; // Garder "-- Choisir --"

            if (allowed.Support.includes(supportId)) {
                $option.show().prop('disabled', false);
            } else {
                $option.hide().prop('disabled', true);
            }
        });

        // Appliquer la valeur par défaut si elle existe
        if (defaults.Support) {
            $supportSelect.val(defaults.Support);
        }
    }

    // --- 3. Diamètres ---
    // Utiliser arrayBottomHeight[typeId] pour remplir les diamètres
    if (arrayBottomHeight?.[typeId]) {
        updateDiameterDatalistByType(typeId);
    }

    // --- 4. Isolation ---
    if (allowed.insulation) {
        const $insulationSelect = $('select[name="tank[insulation]"]');
        $insulationSelect.find('option').each(function() {
            const $option = $(this);
            const insulationId = parseInt($option.val());
            if (insulationId === 0) return; // Garder "-- Aucun --"

            if (allowed.insulation.includes(insulationId)) {
                $option.show().prop('disabled', false);
            } else {
                $option.hide().prop('disabled', true);
            }
        });

        // Appliquer la valeur par défaut si elle existe
        if (defaults.insulation) {
            $insulationSelect.val(defaults.insulation).trigger('change');
        }
    }

    // --- 5. Pression de service ---
    if (defaults.MaxPressure) {
        const $maxPressureInput = $('input[name="tank[max_pressure]"]');
        if (!$maxPressureInput.val() || $maxPressureInput.val() == 0) {
            $maxPressureInput.val(defaults.MaxPressure).trigger('change');
        }
    }

    // --- 6. Pression d'essai ---
    if (defaults.TestPressure) {
        const $testPressureInput = $('input[name="tank[test_pressure]"]');
        if (!$testPressureInput.val() || $testPressureInput.val() == 0) {
            $testPressureInput.val(defaults.TestPressure);
        }
    }

    // --- 7. Température ---
    if (defaults.temperature) {
        const $tempInput = $('input[name="tank[temperature]"]');
        if (!$tempInput.val() || $tempInput.val() == 0) {
            $tempInput.val(defaults.temperature);
        }
    }

    console.log(`✅ [TANK] Restrictions appliquées pour le NOUVEAU réservoir (Type ID: ${typeId})`);
}

// =============================================
// APPPEL INITIAL POUR LES NOUVEAUX RÉSERVOIRS
// =============================================

// 1. Appeler initializeNewTankFields() après le chargement de la modale de création
$(document).on('ispag_new_tank_modal_loaded', function() {
    // Petit délai pour laisser le temps au DOM de se mettre à jour
    setTimeout(initializeNewTankFields, 100);
});

// 2. Déclencher l'événement après le chargement AJAX de la modale
// (À ajouter dans ton code existant où tu charges la modale de création)
/*
Exemple :
.then(html => {
    $('#ispag-product-modal-content > .ispag-type-grid, #ispag-product-modal-content > .ispag-modal-subtitle').fadeOut(150, function() {
        const container = document.getElementById("new-article-form-container");
        container.innerHTML = html;
        container.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // 👇 Déclencher l'événement pour les nouveaux réservoirs
        $(document).trigger('ispag_tank_modal_loaded');

        attachEditModalEvents();
        bindStandardTitleListener();
    });
});
*/