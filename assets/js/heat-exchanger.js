jQuery(function($) {
    // Objets pour suivre l'état des échangeurs et des erreurs
    let hasErrors = {};

    // --- OUVRIR LA MODALE ---
    $(document).on('click', '.openExchangerModal', function() {
        const tankId = $(this).data('tank-id');
        const $modal = $('#exchangerModal_' + tankId);
        $modal.fadeIn();

        // Réinitialiser les erreurs pour ce tank
        hasErrors[tankId] = false;

        // Recalculer la surface pour chaque échangeur existant
        $modal.find('.exchanger-form').each(function() {
            const coilNb = $(this).data('coilnb');
            calculateExchangerSurface(coilNb, $(this), tankId);
        });
    });

    // --- FERMER LA MODALE ---
    $(document).on('click', '.closeExchangerModal', function() {
        $(this).closest('.ispag-product-modal').fadeOut();
    });

    // Fermeture en cliquant en dehors de la modale
    $(document).on('click', '.ispag-product-modal', function(e) {
        if ($(e.target).hasClass('ispag-product-modal')) {
            $(this).fadeOut();
        }
    });

    // Fermeture avec la touche Echap (Esc)
    $(document).on('keydown', function(e) {
        if (e.key === "Escape") {
            $('.ispag-product-modal:visible').fadeOut();
        }
    });

    // --- AJOUTER UN ÉCHANGEUR ---
    $(document).on('click', '.addExchangerForm', function() {
        const $modal = $(this).closest('.ispag-product-modal');
        const $container = $modal.find('.exchangerFormsContainer');
        const tankId = $modal.data('tank-id');
        const nextCoilNb = $container.find('.exchanger-form').length + 1;

        $(this).prop('disabled', true);

        $.ajax({
            url: ispag_ajax.url,
            method: 'POST',
            data: {
                action: 'ispag_add_heat_exchanger_form',
                coil_nb: nextCoilNb,
                tank_id: tankId,
                nonce: ispag_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Insérer le HTML sans exécuter de scripts
                    $container.append(response.data);

                    // Recalculer la surface pour le nouvel échangeur
                    calculateExchangerSurface(nextCoilNb, $container.find(`.exchanger-form[data-coilnb="${nextCoilNb}"]`), tankId);

                    // Réinitialiser les erreurs
                    hasErrors[`${tankId}_${nextCoilNb}`] = false;
                    updateSaveButtonState(tankId);
                } else {
                    console.error("Erreur : ", response.message || "Réponse vide");
                    alert("Erreur : Impossible de charger le formulaire.");
                }
            },
            error: function(xhr) {
                console.error("Erreur AJAX :", xhr.responseText);
                alert("Erreur lors du chargement du formulaire.");
            },
            complete: function() {
                $(this).prop('disabled', false);
            }
        });
    });

    // --- SAUVEGARDER LES ÉCHANGEURS ---
    $(document).on('click', '.saveExchangers', function() {
        const $btn = $(this);
        const tankId = $btn.data('tank-id');
        const $modal = $btn.closest('.ispag-product-modal');
        const $modalContent = $modal.find('.ispag-modal-content');
        const exchangers = {};

        // Vérifier s'il y a des erreurs dans ce tank
        if (hasErrors[tankId]) {
            alert("Corrigez les erreurs de température avant d'enregistrer.");
            return;
        }

        const $forms = $modalContent.find('.exchanger-form');

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
            alert("Erreur critique : L'ID du réservoir est manquant.");
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
                    $modal.fadeOut(300, function() {
                        // Optionnel : rafraîchir une partie de la page
                    });
                } else {
                    alert("Erreur PHP : " + (response.data || "Erreur inconnue"));
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

    // --- CALCUL DE LA SURFACE EN TEMPS RÉEL ---
    $(document).on('input', '.exchanger-form input', function() {
        const $container = $(this).closest('.exchanger-form');
        const coilNb = $container.data('coilnb');
        const tankId = $container.closest('.ispag-product-modal').data('tank-id');

        calculateExchangerSurface(coilNb, $container, tankId);
    });

    // --- FONCTION DE CALCUL DE LA SURFACE ---
    function calculateExchangerSurface(coilNb, $container, tankId) {
        if (!$container) {
            $container = $(`.exchanger-form[data-coilnb="${coilNb}"]`);
        }

        // Récupération des valeurs
        const hotWaterOutput = parseFloat($container.find(`[name="hotWaterOutputTemperature_${coilNb}"]`).val());
        const coldWaterInput = parseFloat($container.find(`[name="coldWaterInputTemperature_${coilNb}"]`).val());
        const loadOutput = parseFloat($container.find(`[name="loadOutputTemperature_${coilNb}"]`).val());
        const loadInput = parseFloat($container.find(`[name="loadInputTemperature_${coilNb}"]`).val());
        const power = parseFloat($container.find(`[name="exchangerPower_${coilNb}"]`).val());
        const surfaceField = $container.find(`[name="coilSurface_${coilNb}"]`);

        // Réinitialiser les messages d'erreur
        $container.find('.error-message').text('');

        // Vérification que toutes les valeurs sont numériques
        const allNumeric = (
            !isNaN(hotWaterOutput) &&
            !isNaN(coldWaterInput) &&
            !isNaN(loadOutput) &&
            !isNaN(loadInput) &&
            !isNaN(power)
        );

        // Si toutes les données sont présentes, on calcule la surface
        if (allNumeric) {
            // --- GARDE-FOUS SUR LES TEMPÉRATURES ---
            let hasError = false;

            // 1. hotWaterOutput doit être <= loadOutput - 2°C
            if (hotWaterOutput > (loadOutput - 2)) {
                $container.find(`[name="hotWaterOutputTemperature_${coilNb}"]`).next('.error-message')
                          .text("Doit être ≤ T° charge sortie - 2°C");
                hasError = true;
            }

            // 2. coldWaterInput doit être < loadOutput
            if (coldWaterInput >= loadOutput) {
                $container.find(`[name="coldWaterInputTemperature_${coilNb}"]`).next('.error-message')
                          .text("Doit être < T° charge sortie");
                hasError = true;
            }

            // 3. loadInput doit être > hotWaterOutput
            if (loadInput <= hotWaterOutput) {
                $container.find(`[name="loadInputTemperature_${coilNb}"]`).next('.error-message')
                          .text("Doit être > T° eau chaude sortie");
                hasError = true;
            }

            // 4. Vérification que deltaA et deltaB sont positifs
            const deltaA = loadOutput - coldWaterInput;
            const deltaB = loadInput - hotWaterOutput;

            if (deltaA <= 0 || deltaB <= 0) {
                surfaceField.val("Erreur : Écart de température invalide")
                           .prop('readonly', false)
                           .css('background', '#ffcccc');
                hasError = true;
            }

            if (hasError) {
                hasErrors[`${tankId}_${coilNb}`] = true;
                updateSaveButtonState(tankId);
                return;
            } else {
                hasErrors[`${tankId}_${coilNb}`] = false;
                updateSaveButtonState(tankId);
            }

            // --- CALCUL DE LA SURFACE ---
            const difTemp = deltaA - deltaB;
            const logTemp = Math.log(deltaA / deltaB);
            const deltaTM = difTemp / logTemp;
            const coefTransmission = 600; // Coefficient standard

            // Formule : S = P / (K * DTLM) * 1000
            const exchangerSurface = Math.round(((power / (deltaTM * coefTransmission)) * 1000) * 100) / 100;

            // Affichage du résultat
            surfaceField.val(exchangerSurface)
                       .prop('readonly', true)
                       .css('background', '#f0f0f0');
        }
        // Si une ou plusieurs données manquent, on laisse le champ modifiable
        else {
            surfaceField.prop('readonly', false)
                       .css('background', '#ffffff');
            hasErrors[`${tankId}_${coilNb}`] = false;
            updateSaveButtonState(tankId);
        }
    }

    // --- METTRE À JOUR L'ÉTAT DU BOUTON "ENREGISTRER" ---
    function updateSaveButtonState(tankId) {
        const $modal = $(`#exchangerModal_${tankId}`);
        const $saveBtn = $modal.find('.saveExchangers');
        const $forms = $modal.find('.exchanger-form');

        let tankHasErrors = false;
        $forms.each(function() {
            const coilNb = $(this).data('coilnb');
            if (hasErrors[`${tankId}_${coilNb}`]) {
                tankHasErrors = true;
                return false; // Sortir de la boucle
            }
        });

        hasErrors[tankId] = tankHasErrors;
        $saveBtn.prop('disabled', tankHasErrors);
    }
});