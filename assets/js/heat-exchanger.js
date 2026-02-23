jQuery(function($) {
    let coilNb = {};

    // Ouvrir le bon modal
    $(document).on('click', '.openExchangerModal', function () {
        const tankId = $(this).data('tank-id');
        const $modal_exchanger = $('#exchangerModal_' + tankId);
        $modal_exchanger.fadeIn();

        const lastCoil = $modal_exchanger.find('.exchanger-form').last().data('coilnb') || 0;
        coilNb[tankId] = lastCoil + 1;
    });

    // Fermer modal
    $(document).on('click', '.closeExchangerModal', function () {
        $(this).closest('.ispag-modal').fadeOut();
    });

    // Ajouter un échangeur
    $(document).on('click', '.addExchangerForm', function () {
        const $modal1 = $(this).closest('.ispag-modal');
        const tankId = $modal1.data('tank-id');

        $.ajax({
            url: ispag_ajax.url,
            method: 'POST',
            data: {
                action: 'ispag_add_heat_exchanger_form',
                coil_nb: coilNb[tankId],
                tank_id: tankId,
            },
            success: function(response) {
                $modal1.find('.exchangerFormsContainer').append(response);
                coilNb[tankId]++;
            },
            error: function() {
                alert("Erreur lors du chargement du formulaire.");
            }
        });
    });

    // Sauvegarder
    $(document).on('click', '.saveExchangers', function () {
        const $btn = $(this);
        // On récupère l'ID directement sur le bouton cliqué
        const tankId = $btn.data('tank-id'); 
        
        // On cherche le container global de la modale pour trouver les inputs
        const $modal = $btn.closest('.ispag-product-modal');
        const $modalContent = $btn.closest('.ispag-modal-content');
        const exchangers = {};

        // On cible tous les formulaires d'échangeurs
        const $forms = $modalContent.find('.exchanger-form');

        console.log("ID Réservoir:", tankId);
        console.log("Nombre de formulaires trouvés:", $forms.length);

        if ($forms.length === 0) {
            alert("Erreur : Aucun formulaire d'échangeur trouvé.");
            return;
        }

        $forms.each(function() {
            const $form = $(this);
            const coilNbForm = $form.data('coilnb');

            exchangers['coil' + coilNbForm] = {
                loadInputTemperature: $form.find(`[name="loadInputTemperature_${coilNbForm}"]`).val(),
                loadOutputTemperature: $form.find(`[name="loadOutputTemperature_${coilNbForm}"]`).val(),
                coldWaterInputTemperature: $form.find(`[name="coldWaterInputTemperature_${coilNbForm}"]`).val(),
                hotWaterOutputTemperature: $form.find(`[name="hotWaterOutputTemperature_${coilNbForm}"]`).val(),
                exchangerPower: $form.find(`[name="exchangerPower_${coilNbForm}"]`).val(),
                coilSurface: $form.find(`[name="coilSurface_${coilNbForm}"]`).val()
            };
        });

        if (!tankId) {
            alert("Erreur critique : L'ID du réservoir est manquant sur le bouton.");
            return;
        }


        $.ajax({
            url: ISPAG_TANK.ajax_url,
            method: 'POST',
            data: {
                action: 'ispag_save_heat_exchangers',
                tank_id: tankId,
                exchangers: JSON.stringify(exchangers)
            },
            beforeSend: function() {
                $btn.text('Enregistrement...').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    // On ferme la modale (on remonte au parent qui a la classe de fond de modale)
                    $modal.fadeOut(300, function() {
                        // alert("Données enregistrées avec succès !");
                        // Optionnel : tu pourrais ici rafraîchir une partie de la page ISPAG
                    });
                } else {
                    alert("Erreur PHP : " + response.data);
                }
            },
            error: function() {
                alert("Erreur réseau lors de l'enregistrement.");
            },
            complete: function() {
                $btn.html('<span class="dashicons dashicons-media-archive"></span> Enregistrer').prop('disabled', false);
            }
        });
    });

    // Calcul surface en live
    $(document).on('input', '.exchanger-form input', function() {
        // On récupère le container parent qui a l'ID du serpentin
        const $container = $(this).closest('.exchanger-form');
        const coilNum = $container.data('coilnb');
        
        // On passe le container à la fonction de calcul
        calculateExchangerSurface(coilNum, $container);
    });

    // 1. Fermeture via la croix (X)
        $(document).on('click', '.closeExchangerModal', function() {
            $(this).closest('.ispag-product-modal').fadeOut();
        });

        // 2. Fermeture en cliquant en dehors de la modale (sur le fond sombre)
        $(document).on('click', '.ispag-product-modal', function(e) {
            // Si l'élément cliqué est exactement la modale (le fond) et pas son contenu
            if ($(e.target).hasClass('ispag-product-modal')) {
                $(this).fadeOut();
            }
        });

        // 3. Fermeture avec la touche Echap (Esc)
        $(document).on('keydown', function(e) {
            if (e.key === "Escape") {
                $('.ispag-product-modal:visible').fadeOut();
            }
        });
});

// Fonction calcul
function calculateExchangerSurface(coilNb, $container) {
    // Si $container n'est pas passé (appel direct), on le cherche par défaut
    if (!$container) {
        $container = $(`.exchanger-form[data-coilnb="${coilNb}"]`);
    }

    // console.log(`--- Début calcul Serpentin n°${coilNb} ---`);

    // On utilise $container.find() pour être certain de cibler les bons inputs
    const hotWaterOutput = parseFloat($container.find(`[name="hotWaterOutputTemperature_${coilNb}"]`).val());
    const coldWaterInput = parseFloat($container.find(`[name="coldWaterInputTemperature_${coilNb}"]`).val());
    const loadOutput     = parseFloat($container.find(`[name="loadOutputTemperature_${coilNb}"]`).val());
    const loadInput      = parseFloat($container.find(`[name="loadInputTemperature_${coilNb}"]`).val());
    const power          = parseFloat($container.find(`[name="exchangerPower_${coilNb}"]`).val());
    const surfaceField   = $container.find(`[name="coilSurface_${coilNb}"]`);

    // console.log("Valeurs saisies:", {
    //     hotWaterOutput,
    //     coldWaterInput,
    //     loadOutput,
    //     loadInput,
    //     power
    // });

    const valid = (
        $.isNumeric(hotWaterOutput) &&
        $.isNumeric(coldWaterInput) &&
        $.isNumeric(loadOutput) &&
        $.isNumeric(loadInput) &&
        $.isNumeric(power)
    );

    if (valid) {
        // Calcul des écarts de température aux deux extrémités de l'échangeur
        const deltaA = loadOutput - coldWaterInput;
        const deltaB = loadInput - hotWaterOutput;

        // console.log(`Écart A (LoadOut - ColdIn): ${deltaA}`);
        // console.log(`Écart B (LoadIn - HotOut): ${deltaB}`);

        // Sécurité : Vérifier que les écarts sont positifs pour le Log
        if (deltaA <= 0 || deltaB <= 0) {
            console.error("ERREUR : Les écarts de température doivent être positifs. Vérifiez que la source (Load) est plus chaude que l'eau sanitaire.");
            surfaceField.val("Erreur Temp").css('background', '#ffcccc');
            return;
        }

        const difTemp = deltaA - deltaB;
        const logTemp = Math.log(deltaA / deltaB);
        
        // console.log(`Différence des écarts: ${difTemp}`);
        // console.log(`Logarithme du rapport: ${logTemp}`);

        const deltaTM = difTemp / logTemp;
        // console.log(`Delta TM (DTLM) calculé: ${deltaTM}`);

        const coefTransmission = 600; // Coef standard pour échangeur tubulaire

        // Formule : S = P / (K * DTLM)
        // Note : On multiplie par 1000 si la puissance est en kW
        const exchangerSurface = Math.round(((power / (deltaTM * coefTransmission)) * 1000) * 100) / 100;
        
        // console.log(`Surface finale calculée: ${exchangerSurface} m²`);

        surfaceField.val(exchangerSurface)
                    .prop('readonly', true)
                    .css('background', '#f0f0f0');
    } else {
        console.warn("Calcul ignoré : Certains champs ne sont pas numériques ou vides.");
        surfaceField.prop('readonly', false)
                    .css('background', '#ffffff');
    }
}