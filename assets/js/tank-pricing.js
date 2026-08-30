jQuery(document).ready(function($) {
    // console.log('[Tank Pricing] Script initialisé.');

    // URL de base pour les fichiers JSON (déjà définie dans wp_localize_script)
    const jsonBaseUrl = ispag_tank_pricing_vars.plugin_url;

    // Sélecteurs pour les champs de la cuve
    const tankFields = [
        'select[name="tank[type]"]',
        'select[name="tank[materiau]"]',
        'select[name="tank[support]"]',
        'select[name="tank[diameter]"]',
        'input[name="tank[height]"]',
        'input[name="tank[clearance]"]',
        'input[name="tank[max_pressure]"]',
        'input[name="tank[volume]"]',
        'input[name="tank[nbWelding]"]'
    ].join(', ');

    // Sélecteur pour le conteneur des piquages
    const fittingsContainer = '#fittings-container';

    // Sélecteur pour le champ caché de l'article ID
    const articleIdField = '#current-editing-article-id';

    // Fonction pour masquer/afficher le bouton de rapport
    function toggleReportButtonVisibility() {
        const tankParams = getTankParams();
        const $reportButton = $('#generate-report-button');

        if (tankParams.is_project_or_purchase !== 'purchase') {
            $reportButton.hide(); // Masquer le bouton
            // console.log('[Tank Pricing] Bouton de rapport masqué (mode project).');
        } else {
            $reportButton.show(); // Afficher le bouton
            // console.log('[Tank Pricing] Bouton de rapport affiché (mode purchase).');
        }
    }

    // Fonction pour masquer/afficher le conteneur de calcul de prix
    function togglePricingCalculationVisibility(jsonFilesExist) {
        const $pricingCalculation = $('#tank-pricing-calculation');

        if (!jsonFilesExist) {
            $pricingCalculation.hide(); // Masquer le conteneur
            // console.log('[Tank Pricing] Conteneur de calcul masqué (fichiers JSON manquants).');
        } else {
            $pricingCalculation.show(); // Afficher le conteneur
            // console.log('[Tank Pricing] Conteneur de calcul affiché (fichiers JSON présents).');
        }
    }

    // Fonction pour collecter les données de la cuve
    function getTankParams() {
        const params = {
            type: parseInt($(tankFields).filter('select[name="tank[type]"]').val()),
            material: parseInt($(tankFields).filter('select[name="tank[materiau]"]').val()),
            support: parseInt($(tankFields).filter('select[name="tank[support]"]').val()),
            diameter: parseInt($(tankFields).filter('select[name="tank[diameter]"]').val()),
            volume: parseInt($(tankFields).filter('input[name="tank[volume]"]').val()),
            height: parseInt($(tankFields).filter('input[name="tank[height]"]').val()),
            ground_clearance: parseInt($(tankFields).filter('input[name="tank[clearance]"]').val()),
            pressure: parseFloat($(tankFields).filter('input[name="tank[max_pressure]"]').val()),
            welding: parseInt($(tankFields).filter('input[name="tank[nbWelding]"]').val()),
            supplier: $('#tank-supplier-display').data('value') || 'Diem-Werke GmbH',
            article_id: $(articleIdField).val(),
            is_project_or_purchase: $('input[name="isProjectOrPurchase"]').val()
        };
        // console.log('[Tank Pricing] Paramètres de la cuve collectés :', params);
        return params;
    }

    // Fonction pour collecter les données des piquages
    function getFittingsParams() {
        const fittings = [];
        $(fittingsContainer).find('.fitting-row').each(function() {
            const $row = $(this);
            fittings.push({
                Pouces: $row.find('select[name="fitting_type"]').val(),
                Accessories: $row.find('select[name="fitting_accessories"]').val(),
                MaxPressure: parseFloat($row.find('input[name="fitting_pressure"]').val()) || 6
            });
        });
        // console.log('[Tank Pricing] Paramètres des piquages collectés :', fittings);
        return fittings;
    }

    // Fonction pour calculer le prix via AJAX
    function calculatePrice() {
        // console.log('[Tank Pricing] Début du calcul du prix...');

        const tankParams = getTankParams();
        const fittingsParams = getFittingsParams();

        // Vérifier que les données nécessaires sont présentes
        if (!tankParams.diameter || !tankParams.height || !tankParams.article_id) {
            console.warn('[Tank Pricing] Données manquantes (diamètre, hauteur ou ID article). Calcul annulé.');
            $('#tank-price-display').val('---');
            $('#tank-price-errors').empty();
            return;
        }

        // Afficher un indicateur de chargement
        $('#tank-price-display').val(ispag_texts.calculation_progress + '...');
        $('#tank-price-errors').empty();
        // console.log('[Tank Pricing] Envoi de la requête AJAX au serveur...', {
        //     url: ispag_tank_pricing_vars.ajax_url,
        //     tank_params: tankParams,
        //     fittings: fittingsParams
        // });

        // Envoyer une requête AJAX au backend
        $.ajax({
            url: ispag_tank_pricing_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'calculate_tank_price',
                nonce: ispag_tank_pricing_vars.nonce,
                tank_params: tankParams,
                fittings: fittingsParams
            },
            success: function(response) {
                // console.log('[Tank Pricing] Réponse AJAX complète reçue :', response);

                // Vérifier que la réponse contient bien `data`
                if (!response || !response.success || !response.data) {
                    console.error('[Tank Pricing] Réponse AJAX invalide ou manquante :', response);
                    $('#tank-price-display').val('---');
                    $('#tank-price-errors').html('<div class="ispag-errors" style="color: red; margin-top: 10px;"><strong>⚠️ ' + ispag_texts.error + ' :</strong> ' + ispag_texts.invalid_server_response + '</div>');
                    return;
                }

                const data = response.data;
                // console.log('[Tank Pricing] Données de prix reçues :', data);

                // Vérifier si les fichiers JSON existent
                if (typeof data.json_files_exist !== 'undefined' && !data.json_files_exist) {
                    togglePricingCalculationVisibility(false);
                    $('#tank-price-display').val('---');
                    $('#tank-price-errors').html('<div class="ispag-errors" style="color: orange; margin-top: 10px;"><strong>ℹ️ ' + ispag_texts.request_quote + '</strong></div>');
                    return;
                } else {
                    togglePricingCalculationVisibility(true);
                }

                // Vérifier que `net_price` ou `sales_price` existe dans les données
                if (typeof data.net_price === 'undefined' && typeof data.sales_price === 'undefined') {
                    console.error('[Tank Pricing] net_price et sales_price sont undefined dans la réponse :', data);
                    $('#tank-price-display').val('---');
                    $('#tank-price-errors').html('<div class="ispag-errors" style="color: red; margin-top: 10px;"><strong>⚠️ ' + ispag_texts.error + ' :</strong> ' + ispag_texts.net_price_missing + '</div>');
                    return;
                }

                // Afficher le prix approprié dans le champ
                if (tankParams.is_project_or_purchase !== 'purchase' && typeof data.sales_price !== 'undefined') {
                    $('#tank-price-display').val(data.sales_price.toFixed(2));
                } else {
                    $('#tank-price-display').val(data.gross_price.toFixed(2));
                }

                // Afficher les erreurs si elles existent
                if (data.errors && data.errors.length > 0) {
                    let errorsHtml = '<div class="ispag-errors" style="color: red; margin-top: 10px;"><strong>⚠️ ' + ispag_texts.calculation_error + ' :</strong><ul>';
                    data.errors.forEach(error => {
                        errorsHtml += `<li>${error}</li>`;
                    });
                    errorsHtml += '</ul></div>';
                    $('#tank-price-errors').html(errorsHtml);
                } else {
                    $('#tank-price-errors').empty();
                }

                // Mettre à jour le champ caché si nécessaire
                $('#tank-price-value').val(data.net_price);
            },
            error: function(xhr, status, error) {
                console.error('[Tank Pricing] Erreur AJAX critique :', error, { xhr, status });
                $('#tank-price-display').val('Erreur');
                $('#tank-price-errors').html('<div class="ispag-errors" style="color: red; margin-top: 10px;"><strong>⚠️ ' + ispag_texts.critical_error + ' :</strong> ' + error + '</div>');
            }
        });
    }

    // Générer le rapport sur demande
    async function generateReport() {
        // console.log('[Tank Pricing] Génération du rapport demandée...');

        const $salesPriceInput = $('input[name="sales_price"]');
        const currentSalesPrice = $salesPriceInput.val();

        // Vérifier si le champ sales_price contient une valeur
        if (currentSalesPrice && currentSalesPrice.trim() !== '' && currentSalesPrice !== '0.00' && currentSalesPrice !== '0' && currentSalesPrice !== '---') {
            const confirmed = await ispagConfirm(
                ispag_texts?.confirm_overwrite_price || "Un prix existe déjà. Voulez-vous le recalculer et l'écraser ?",
                { danger: true }
            );
            if (!confirmed) {
                // console.log('[Tank Pricing] Génération du rapport annulée par l\'utilisateur.');
                return;
            }
        }

        const tankParams = getTankParams();
        const fittingsParams = getFittingsParams();

        if (!tankParams.diameter || !tankParams.height || !tankParams.article_id) {
            $('#report-status').html('<div class="ispag-errors" style="color: red; margin-top: 10px;">⚠️ ' + ispag_texts.unable_generate_report + '</div>');
            return;
        }

        // Afficher le spinner et désactiver le bouton
        const $button = $('#generate-report-button');
        const originalButtonHtml = $button.html();
        $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> ' + (ispag_texts.loading || 'Chargement...'));
        $('#report-status').html('<span style="color: orange;">' + ispag_texts.report_generation_progress + '...</span>');

        $.ajax({
            url: ispag_tank_pricing_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_tank_report',
                nonce: ispag_tank_pricing_vars.nonce,
                tank_params: tankParams,
                fittings: fittingsParams
            },
            success: function(response) {
                $button.prop('disabled', false).html(originalButtonHtml);

                if (!response || !response.success || !response.data) {
                    const errorMsg = response && response.data && response.data.message ? response.data.message : ispag_texts.invalid_server_response;
                    $('#report-status').html('<div class="ispag-errors" style="color: red; margin-top: 10px;">⚠️ ' + ispag_texts.error + ' : ' + errorMsg + '</div>');
                    console.error('[Tank Pricing] Erreur lors de la génération du rapport :', errorMsg);
                    return;
                }

                const data = response.data;
                // console.log('[Tank Pricing] Rapport généré avec succès :', data.report_path);

                // Mise à jour des champs
                document.querySelector('input[name="sales_price"]').value = data.gross_price.toFixed(2);
                document.querySelector('input[name="discount"]').value = data.discount.toFixed(2);
                $('#ispag_article_net_price_' + tankParams.article_id).val(data.net_price.toFixed(2));
                $('#ispag_article_discount_' + tankParams.article_id).val(data.discount.toFixed(2));

                if (tankParams.is_project_or_purchase !== 'purchase' && data.sales_price) {
                    document.querySelector('input[name="sales_price"]').value = data.sales_price.toFixed(2);
                }

                $('#report-status').html('');
            },
            error: function(xhr, status, error) {
                $button.prop('disabled', false).html(originalButtonHtml);
                $('#report-status').html('<div class="ispag-errors" style="color: red; margin-top: 10px;">⚠️ ' + ispag_texts.critical_error + ' : ' + error + '</div>');
                console.error('[Tank Pricing] Erreur AJAX :', error);
            }
        });
    }

    // Écouteurs d'événements
    $(document).on('change', tankFields, calculatePrice);
    $(document).on('change', `${fittingsContainer} .fitting-row select, ${fittingsContainer} .fitting-row input`, calculatePrice);
    $(document).on('change', '#tank-supplier-display', calculatePrice);
    $(document).on('change', articleIdField, calculatePrice);
    $(document).on('click', '#generate-report-button', generateReport);
    $(document).on('change', 'input[name="isProjectOrPurchase"]', function() {
        toggleReportButtonVisibility();
        calculatePrice();
    });

    // Initialisation
    toggleReportButtonVisibility();
    togglePricingCalculationVisibility(true);
    // console.log('[Tank Pricing] Initialisation terminée.');
});