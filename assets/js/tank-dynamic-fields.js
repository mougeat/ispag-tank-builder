// console.log('tank-dynamic-fields.js loaded');
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
    // console.log('🔍 [DEBUG] Sélecteur $h2:', $h2); // 👈 Affiche l'élément jQuery
    // console.log('🔍 [DEBUG] $h2.length:', $h2.length); // 👈 Doit être 1
    // console.log('🔍 [DEBUG] $h2.data("id"):', $h2.data('id')); // 👈 Doit être "4"

    if (!$h2.length || !$h2.data('id')) {
        console.warn('⚠️ [TANK] h2 non trouvé ou data-id manquant.');
        return; // On est en mode édition, pas de création
    }

    const typeId = $h2.data('id');
    // console.log(`🆕 [TANK] Initialisation pour NOUVEAU réservoir (Type ID: ${typeId})`);

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

    // console.log(`✅ [TANK] Restrictions appliquées pour le NOUVEAU réservoir (Type ID: ${typeId})`);
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

// =============================================
// CONTRÔLE SOUDURE (nb sections) & GARDE AU SOL (tipping vs hauteur pièce)
// =============================================

(function ($) {

    const RED_BORDER_CSS = '2px solid #d63638';
    const MARGIN_TIPPING_MM = 20; // tolérance "trop juste" avant le rouge

    // Fonction modifiée pour accepter un message d'erreur
    function setFieldAlert($field, message) {
        if (!$field || !$field.length) return;
        
        // console.log('🚨 [ALERT] Application de l’alerte rouge sur le champ :', $field.attr('name'));$field.css('border', RED_BORDER_CSS).addClass('ispag-field-alert');

        // Gérer le message d'erreur associé
        let $errorMsg =$field.next('.ispag-inline-error');
        if (!$errorMsg.length) {
            // Si le message n'existe pas encore, on le crée juste après l'input
            $errorMsg =$('<div class="ispag-inline-error" style="color: #d63638; font-size: 12px; margin-top: 4px;"></div>');
            $field.after($errorMsg);
        }
        $errorMsg.text(message);
    }

    function clearFieldAlert($field) {
        if (!$field || !$field.length) return;
        $field.css('border', '').removeClass('ispag-field-alert');
        
        // Supprimer le message d'erreur s'il existe
        $field.next('.ispag-inline-error').remove();
    }

    // ---------------------------------------------------------------
    // 1. Vérification du nombre de soudures (tronçons - 1)
    // ---------------------------------------------------------------
    function checkWeldingCount() {
        // // console.log('--- [WELDING] Exécution de checkWeldingCount() ---');
        const $height     =$('input[name="tank[height]"]');
        const $doorWidth   =$('#tank_door_width');
        const $nbWelding   =$('input[name="tank[nbWelding]"]');

        // // console.log('🔍 [WELDING] Champs trouvés ? height:', $height.length, '| doorWidth:', $doorWidth.length, '| nbWelding:',$nbWelding.length);

        if (!$height.length || !$doorWidth.length || !$nbWelding.length) {
            console.warn('⚠️ [WELDING] Un ou plusieurs champs sont absents de cette modale.');
            return; 
        }

        const height     = parseFloat($height.val());
        const doorWidth  = parseFloat($doorWidth.val());

        // console.log(`📊 [WELDING] Valeurs brutes lues -> height: ${$height.val()} (${height}), doorWidth: ${$doorWidth.val()} (${doorWidth}), nbWelding.val(): ${JSON.stringify($nbWelding.val())}`);

        // Rien à calculer si les valeurs de base manquent
        if (!height || !doorWidth) {
            // console.log('ℹ️ [WELDING] Hauteur ou largeur de porte manquante/invalide, annulation du calcul.');
            clearFieldAlert($nbWelding);
            return;
        }

        // Calcul : Hauteur / largeur de porte = nombre de tronçons (arrondi au sup.)
        const computedSections = Math.ceil(height / doorWidth);
        // Nombre de soudures = tronçons - 1 (minimum 0 si un seul tronçon)
        const computedWelding = Math.max(0, computedSections - 1);
        
        const currentValue = $nbWelding.val();

        // console.log(`🧮 [WELDING] Calcul -> Tronçons: Math.ceil(${height} / ${doorWidth}) = ${computedSections} | Soudures attendues (tronçons - 1) = ${computedWelding}`);
        // console.log(`🧮 [WELDING] Valeur actuelle du champ nbWelding = "${currentValue}"`);

        if (currentValue === '' || currentValue === null) {
            // console.log('✍️ [WELDING] Champ nbWelding vide -> insertion automatique de la valeur:', computedWelding);
            $nbWelding.val(computedWelding).trigger('change');
            clearFieldAlert($nbWelding);
            return;
        }

        const currentWelding = parseInt(currentValue, 10);
        // console.log(`⚖️ [WELDING] Comparaison -> currentWelding (${currentWelding}) vs computedWelding (${computedWelding})`);

        if (currentWelding !== computedWelding) {
            // console.log('❌ [WELDING] Différent ! Déclenchement de l’alerte.');
            // setFieldAlert($nbWelding, `⚠️ Nombre de soudures incorrect pour ${computedSections} tronçon(s) (Recommandé : ${computedWelding})`);

            let weldingMsg = ispag_texts.warning_nb_welding_number 
            ? ispag_texts.warning_nb_welding_number.replace('%d', computedSections).replace('%d', computedWelding)
            : `Nombre de soudures incorrect pour ${computedSections} tronçon(s) (Recommandé : ${computedWelding})`;

        setFieldAlert($nbWelding, '⚠️ ' + weldingMsg);
        } else {
            // console.log('✅ [WELDING] Identique. Suppression de l’alerte.');
            clearFieldAlert($nbWelding);
        }
    }

    // ---------------------------------------------------------------
    // 2. Vérification cote de basculement vs hauteur de la pièce
    // ---------------------------------------------------------------
    function checkTippingVsRoomHeight() {
        // console.log('--- [TIPPING] Exécution de checkTippingVsRoomHeight() ---');
        const $tipping    =$('input[name="tank[tipping]"]');
        const $roomHeight =$('#tank_room_height');

        // console.log('🔍 [TIPPING] Champs trouvés ? tipping:', $tipping.length, '| roomHeight:',$roomHeight.length);

        if (!$tipping.length || !$roomHeight.length) {
            console.warn('⚠️ [TIPPING] Un ou plusieurs champs sont absents de cette modale.');
            return;
        }

        const tipping    = parseFloat($tipping.val());
        const roomHeight = parseFloat($roomHeight.val());

        // console.log(`📊 [TIPPING] Valeurs brutes lues -> tipping: ${$tipping.val()} (${tipping}), roomHeight: ${$roomHeight.val()} (${roomHeight})`);

        if (!tipping || !roomHeight) {
            // console.log('ℹ️ [TIPPING] Tipping ou room_height manquant/invalide, annulation du calcul.');
            clearFieldAlert($roomHeight);
            return;
        }

        const limitHeight = roomHeight - MARGIN_TIPPING_MM;
        // console.log(`🧮 [TIPPING] Seuil d'alerte (roomHeight - ${MARGIN_TIPPING_MM}mm) = ${limitHeight}`);
        // console.log(`⚖️ [TIPPING] Test -> tipping (${tipping}) >= limitHeight (${limitHeight}) ?`);

        // Rouge si le basculement dépasse la hauteur de pièce,
        // ou si on est "trop juste" (marge < MARGIN_TIPPING_MM)
        if (tipping >= limitHeight) {
            // console.log('❌ [TIPPING] Trop haut ou trop juste ! Déclenchement de l’alerte.');
            // Si tu utilises un format avec %d ou %s dans PHP :
            let message = ispag_texts.warning_tipping_height
                ? ispag_texts.warning_tipping_height.replace('%d', tipping)
                : `Attention : La cote de basculement (${tipping} mm) dépasse ou est trop proche de la hauteur de pièce !`;

            setFieldAlert($roomHeight, '⚠️ ' + message);

            // setFieldAlert($roomHeight, `⚠️ Attention : La cote de basculement (${tipping} mm) dépasse ou est trop proche de la hauteur de pièce !`);
        } else {
            // console.log('✅ [TIPPING] Marge OK. Suppression de l’alerte.');
            clearFieldAlert($roomHeight);
        }
    }

    function runAllChecks() {
        // console.log('🚀 [RUN ALL CHECKS] Lancement global des vérifications...');
        checkWeldingCount();
        checkTippingVsRoomHeight();
    }

    // ---------------------------------------------------------------
    // Écouteurs (délégués, car la modale est chargée en AJAX)
    // ---------------------------------------------------------------
    $(document).on('change input',
        'input[name="tank[height]"], ' +
        'input[name="door_width"], select[name="door_width"], ' +
        'input[name="tank[nbWelding]"]',
        function () {
            // console.log('⚡ [EVENT] Changement détecté sur un champ de soudure (input/change)');
            checkWeldingCount();
        }
    );

    $(document).on('change input',
        'input[name="tank[tipping]"], input[name="room_height"]',
        function () {
            // console.log('⚡ [EVENT] Changement détecté sur un champ de basculement/hauteur de pièce (input/change)');
            checkTippingVsRoomHeight();
        }
    );

    $(document).on('ispag_tank_modal_loaded ispag_new_tank_modal_loaded', function (e) {
        // console.log('🎯 [EVENT] Événement de chargement de modale capté :', e.type);
        setTimeout(runAllChecks, 150);
    });

})(jQuery);