/**
 * Fichier : tank-pricing.js
 * Version : 3.7.0 - PV Soudure dynamique + Arrondi Peinture
 */

// Variable globale pour stocker le détail du calcul en cours
let lastPricingTrace = "";

async function updateTankPrice() {
    console.log("--- Début updateTankPrice ---");
    const articleId = jQuery('#current-editing-article-id').val();
    const supplierEl = jQuery(document).find('#tank-supplier-display');
    const supplier = supplierEl.attr('data-value') || supplierEl.data('value');
    
    const priceDisplay = jQuery('#tank-price-display');
    const priceBarDisplay = jQuery('#tank-bare-price-' + articleId);
    const priceValue = jQuery('#tank-price-value');

    // Récupération des valeurs du formulaire
    const diameter = parseInt(jQuery('select[name="tank[diameter]"], input[name="tank[diameter]"]').val());
    const height = parseInt(jQuery('input[name="tank[height]"]').val());
    const volume = parseInt(jQuery('input[name="tank[volume]"]').val()) || 0;
    const pressure = parseFloat(jQuery('input[name="tank[max_pressure]"]').val()) || 3;
    const tankTypeId = jQuery('#tank-typ').val(); 
    const supportValue = jQuery('select[name="tank[support]"]').val(); 
    const groundClearance = parseInt(jQuery('input[name="tank[clearance]"]').val()) || 50; 
    const nbWelding = parseInt(jQuery('#welding-nb').val()) || 0;

    if (!supplier || isNaN(diameter) || isNaN(height)) {
        if (priceDisplay.length) priceDisplay.val("---");
        return;
    }

    const fileName = supplier.replace(/\s+/g, '_') + '.json';
    const jsonUrl = `${ispag_vars.plugin_url}/price/${fileName}`;

    try {
        const response = await fetch(jsonUrl);
        if (!response.ok) throw new Error('Fichier JSON non trouvé');
        const data = await response.json();
        
        // --- INITIALISATION DE LA TRACE ---
        let trace = `--- DÉTAILS DU CALCUL ISPAG (${new Date().toLocaleString()}) ---\n`;
        trace += `FOURNISSEUR : ${data.fournisseur}\n`;
        trace += `CONFIG : Ø${diameter}mm, Ht:${height}mm, Vol:${volume}L, Pression:${pressure}bar\n`;
        trace += `NOMBRE DE SOUDURE : ${nbWelding}\n`;
        trace += `---------------------------------------\n`;

        // 1. RÉCUPÉRATION DU DISCOUNT
        const supplierDiscount = parseFloat(data.discount_defaut) || 0;
        priceValue.attr('data-discount', supplierDiscount);

        // 2. RECHERCHE PRIX DE BASE DANS LA GRILLE
        const grille = data.grille_tarifaire;
        const sortedDiameters = Object.keys(grille).map(Number).sort((a, b) => a - b);
        const targetDia = sortedDiameters.find(d => d >= diameter);
        const heightsForDia = grille[targetDia];
        const sortedHeights = Object.keys(heightsForDia).map(Number).sort((a, b) => a - b);
        const targetHeight = sortedHeights.find(h => h >= height);

        const pressKey = (pressure <= 3) ? '3bar' : '6bar';
        let basePrice = heightsForDia[targetHeight][pressKey];
        trace += `PRIX DE BASE (Grille) [${targetDia}x${targetHeight} @ ${pressKey}] : ${basePrice.toFixed(2)} €\n`;

        // 3. PLUS-VALUE SOUDURE SUR PLACE (Si nbWelding > 0)
        if (nbWelding > 0) {
            const tauxSoudure = (data.logic && data.logic.surcharge_soudure_sur_place) ? parseFloat(data.logic.surcharge_soudure_sur_place) : 0;
            if (tauxSoudure > 0) {
                const pvSoudure = basePrice * (tauxSoudure / 100);
                basePrice += pvSoudure;
                trace += `PV SOUDURE SUR PLACE (${nbWelding} détectée(s), +${tauxSoudure}%) : +${pvSoudure.toFixed(2)} €\n`;
            }
        }

        // 4. CALCUL OPTIONS : PIEDS
        let feetPrice = 0;
        if (supportValue === "10" && data.accessoires.pieds) {
            const config = data.accessoires.pieds;
            let pied = config.rohrfüße.find(p => volume <= p.volume_max_litres) || config.unp_füße.find(p => volume <= p.volume_max_litres);
            if (pied) {
                feetPrice = pied.prix;
                trace += `OPTION Pieds : +${feetPrice.toFixed(2)} €\n`;
            }
        }

        // 5. CALCUL OPTIONS : GARDE AU SOL
        let gcPrice = 0;
        if (groundClearance > 50 && data.accessoires.ground_clearance) {
            const gcConfig = data.accessoires.ground_clearance.plus_values.find(p => diameter <= p.diametre_max_mm);
            if (gcConfig) {
                gcPrice = gcConfig.prix;
                trace += `OPTION Garde au sol (${groundClearance}mm) : +${gcPrice.toFixed(2)} €\n`;
            }
        }

        // 6. CALCUL OPTIONS : PEINTURE (ARRONDI M2 SUPÉRIEUR)
        let paintPrice = 0;
        if ((tankTypeId === "6" || tankTypeId === "7") && data.accessoires.traitement_surface) {
            const dM = diameter / 1000;
            const hM = height / 1000;
            const rawSurface = (dM * dM * 2) + (dM * 4 * hM);
            const surfaceM2 = Math.ceil(rawSurface); // Arrondi m2 supérieur
            
            const prixUnitM2 = data.accessoires.traitement_surface.options.exterieur_zinc_1K.prix_m2;
            paintPrice = surfaceM2 * prixUnitM2;
            trace += `OPTION Peinture (Surf réelle: ${rawSurface.toFixed(2)}m² -> Arrondie: ${surfaceM2}m² * ${prixUnitM2.toFixed(2)}) : +${paintPrice.toFixed(2)} €\n`;
        }

        const finalTankPrice = basePrice + feetPrice + gcPrice + paintPrice;
        trace += `TOTAL CUVE (Brut) : ${finalTankPrice.toFixed(2)} €\n`;

        // 7. AJOUT PRIX RACCORDS (FITTINGS)
        const accPrice = parseFloat(jQuery('#tank-acc-price-' + articleId).val()) || 0;
        if (accPrice > 0) {
            trace += `TOTAL RACCORDS (Accessoires) : +${accPrice.toFixed(2)} €\n`;
        }

        // On stocke la trace pour l'envoi AJAX
        lastPricingTrace = trace;

        // Mise à jour de l'affichage
        priceDisplay.val(finalTankPrice.toLocaleString('fr-FR', { minimumFractionDigits: 2 }));
        priceValue.val(finalTankPrice.toFixed(2));
          
        if(priceBarDisplay.length) {
            priceBarDisplay.val(finalTankPrice.toFixed(2))
                           .attr('data-discount', supplierDiscount)
                           .trigger('change');
            calculateTotalCombinedPrice(articleId);
        }

    } catch (error) {
        console.error("Erreur pricing:", error.message);
    }
}

function calculateTotalCombinedPrice(articleId) {
    const bareInput = jQuery('#tank-bare-price-' + articleId);
    const accInput = jQuery('#tank-acc-price-' + articleId);
    
    const bareValue = parseFloat(bareInput.val()) || 0;
    const accValue = parseFloat(accInput.val()) || 0;
    const total = bareValue + accValue;

    const btnId = `btn-save-total-${articleId}`;
    const container = bareInput.closest('.ispag-article-prices');

    if (bareValue > 0) {
        if (!jQuery(`#${btnId}`).length) {
            const buttonHtml = `
                <div class="ispag-row btn-container-db" style="margin-top:5px;">
                    <button type="button" id="${btnId}" data-id="${articleId}"
                            class="ispag-btn btn-insert-tank-price" 
                            style="background-color:#2c3e50; color:white; width:100%; border:none; padding:4px 8px; cursor:pointer; font-size:11px; font-weight:bold; border-radius:4px;">
                            <i class="fas fa-save"></i> Reporter
                    </button>
                </div>`;
            container.append(buttonHtml);
        }
    } else {
        jQuery(`#${btnId}`).closest('.btn-container-db').remove();
    }
    return total;
}

function saveTotalToDatabase(articleId) {
    const totalPrice = calculateTotalCombinedPrice(articleId);
    const discountValue = parseFloat(jQuery('#tank-bare-price-' + articleId).attr('data-discount')) || 0;
    
    // FUSION DES LOGS (Cuve + Raccords de la fenêtre globale)
    const fullLog = lastPricingTrace + (window.lastFittingTrace || "");

    const btn = jQuery(`#btn-save-total-${articleId}`);
    btn.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);

    jQuery.ajax({
        url: ISPAG_TANK.ajax_url,
        type: 'POST',
        data: {
            action: 'ispag_save_tank_unit_price',
            article_id: articleId,
            price: totalPrice,
            discount: discountValue,
            log_details: fullLog 
        },
        success: function(response) {
            if (response.success) {
                btn.html('✅ Mis à jour').css('background-color', '#27ae60');
                const netPrice = totalPrice * (1 - (discountValue / 100));
                const netPriceCeil = Math.ceil(netPrice);
                btn.closest('.ispag-article').find('.ispag-article-prix-net').text(netPriceCeil.toLocaleString('fr-FR') + ' €');

                setTimeout(() => { 
                    btn.html('<i class="fas fa-save"></i> Reporter').css('background-color', '#2c3e50');
                    btn.prop('disabled', false);
                }, 2000);
            } else {
                alert("Erreur : " + (response.data ? response.data.message : 'Inconnue'));
                btn.prop('disabled', false).html('Réessayer');
            }
        },
        error: function(xhr) {
            console.error("Erreur critique AJAX :", xhr.responseText);
            btn.prop('disabled', false).html('Erreur');
        }
    });
}

// --- ÉCOUTEURS ---

// Écoute les changements sur les paramètres de la cuve ET les soudures
jQuery(document).on('change input', 'select[name^="tank["], input[name^="tank["], #welding-container select', function() {
    updateTankPrice();
});

// Écoute les changements de prix manuels ou calculés pour mettre à jour le bouton
jQuery(document).on('change input', '[id^="tank-bare-price-"], [id^="tank-acc-price-"]', function() {
    const id = jQuery(this).attr('id').split('-').pop();
    calculateTotalCombinedPrice(id);
});

// Action du bouton "Reporter"
jQuery(document).on('click', '.btn-insert-tank-price', function() {
    const id = jQuery(this).data('id');
    saveTotalToDatabase(id);
});