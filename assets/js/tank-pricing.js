/**
 * Fichier : tank-pricing.js
 * Version : 4.4.0 - Gestion silencieuse et complète
 */

let lastPricingTrace = "";

/**
 * Logique de vente centralisée
 */
function calculateSalesPriceFromPurchase(purchasePrice, volumeLiters, supplierDiscount = 0) {
    const isProjectOrPurchase = jQuery('input[name="isProjectOrPurchase"]').val();
    const salesCoefType = jQuery('#ispag-coef-select').val();
    
    let price = parseFloat(purchasePrice) || 0;
    let details = "";

    if (isProjectOrPurchase !== 'project') {
        return { 
            finalPrice: price, 
            trace: `MODE PURCHASE : Prix brut conservé.\n` 
        };
    }

    details += `--- APPLICATION LOGIQUE PROJET (CLIENT) ---\n`;

    if (supplierDiscount > 0) {
        const discountAmount = price * (supplierDiscount / 100);
        price = price - discountAmount;
        details += `- Application Remise Fournisseur (${supplierDiscount}%) : ${price.toFixed(2)} € (Net)\n`;
    }

    const customsFee = parseFloat(ispag_vars.custom_fee) || 0;
    if (customsFee > 0) {
        const feeRate = customsFee / 100;
        if (1 - feeRate > 0) {
            price = price / (1 - feeRate);
            details += `- Dédouanement (${customsFee}%) : ${price.toFixed(2)} €\n`;
        }
    }

    let coef = parseFloat(ispag_vars.default_coef) || 1; 
    let labelCoef = "Standard";
    if (salesCoefType === 'wpcb_sales_coef_offre_revendeur') {
        coef = parseFloat(ispag_vars.coef_revendeur);
        labelCoef = "Revendeur";
    } else if (salesCoefType === 'wpcb_sales_coef_low') {
        coef = parseFloat(ispag_vars.coef_low);
        labelCoef = "Bas (Low)";
    }

    price = price * coef;
    details += `- Coefficient ${labelCoef} (${coef}) : ${price.toFixed(2)} €\n`;

    if (price < 6000) {
        const volumeM3 = (parseFloat(volumeLiters) || 0) / 1000;
        const transportFee = volumeM3 * 400;
        price += transportFee;
        details += `- Frais prix < 6000 (${volumeM3.toFixed(3)} m³ * 400) : +${transportFee.toFixed(2)} €\n`;
    }

    return { finalPrice: price, trace: details };
}

async function updateTankPrice() {
    const articleId = jQuery('#current-editing-article-id').val();
    const supplierEl = jQuery(document).find('#tank-supplier-display');
    const supplier = supplierEl.attr('data-value') || supplierEl.data('value');
    
    const priceDisplay = jQuery('#tank-price-display');
    const priceBarDisplay = jQuery(document).find('#tank-bare-price-' + articleId);
    const priceValue = jQuery('#tank-price-value');

    // Reset affichage (silencieux)
    const resetDisplay = () => {
        if (priceDisplay.length) priceDisplay.val("---");
        if (priceValue.length) priceValue.val("0");
        const btnId = `btn-save-total-${articleId}`;
        jQuery(`#${btnId}`).closest('.btn-container-db').remove();
    };

    const diameter = parseInt(jQuery('select[name="tank[diameter]"], input[name="tank[diameter]"]').val());
    const height = parseInt(jQuery('input[name="tank[height]"]').val());
    const volume = parseInt(jQuery('input[name="tank[volume]"]').val()) || 0;
    const pressure = parseFloat(jQuery('input[name="tank[max_pressure]"]').val()) || 3;
    const supportValue = jQuery('select[name="tank[support]"]').val(); 
    const nbWelding = parseInt(jQuery('#welding-nb').val()) || 0;

    if (!supplier || isNaN(diameter) || isNaN(height)) {
        resetDisplay();
        return;
    }

    const fileName = supplier.replace(/\s+/g, '_') + '.json';
    const jsonUrl = `${ispag_vars.plugin_url}/price/${fileName}`;

    try {
        const response = await fetch(jsonUrl);
        if (!response.ok) {
            resetDisplay();
            return; // Sortie silencieuse si fichier manquant
        }
        
        const data = await response.json();
        if (!data.grille_tarifaire) {
            resetDisplay();
            return;
        }

        let trace = `--- DÉTAILS DU CALCUL ISPAG (${new Date().toLocaleString()}) ---\n`;
        trace += `FOURNISSEUR : ${data.fournisseur}\n`;
        trace += `CONFIG : Ø${diameter}mm, Ht:${height}mm, Vol:${volume}L, Pression:${pressure}bar\n`;
        trace += `---------------------------------------\n`;

        const supplierDiscount = parseFloat(data.discount_defaut) || 0;
        priceValue.attr('data-discount', supplierDiscount);

        const grille = data.grille_tarifaire;
        const sortedDiameters = Object.keys(grille).map(Number).sort((a, b) => a - b);
        const targetDia = sortedDiameters.find(d => d >= diameter);

        if (!targetDia) { resetDisplay(); return; }

        const heightsForDia = grille[targetDia];
        const sortedHeights = Object.keys(heightsForDia).map(Number).sort((a, b) => a - b);
        const targetHeight = sortedHeights.find(h => h >= height);

        if (!targetHeight) { resetDisplay(); return; }

        const pressKey = (pressure <= 3) ? '3bar' : '6bar';
        let basePrice = heightsForDia[targetHeight][pressKey];

        if (basePrice === undefined || basePrice === null) { resetDisplay(); return; }

        trace += `PRIX ACHAT BRUT BASE : ${basePrice.toFixed(2)} €\n`;

        // Surcharge soudure sur place
        if (nbWelding > 0 && data.logic?.surcharge_soudure_sur_place) {
            const tauxSoudure = parseFloat(data.logic.surcharge_soudure_sur_place);
            const pvSoudure = basePrice * (tauxSoudure / 100);
            basePrice += pvSoudure;
            trace += `PV SOUDURE (+${tauxSoudure}%) : +${pvSoudure.toFixed(2)} €\n`;
        }

        // Calcul des options (Pieds)
        let optionsPrice = 0;
        if (supportValue === "10" && data.accessoires?.pieds) {
            let pied = data.accessoires.pieds.rohrfüße?.find(p => volume <= p.volume_max_litres) || 
                       data.accessoires.pieds.unp_füße?.find(p => volume <= p.volume_max_litres);
            if (pied) {
                optionsPrice += pied.prix;
                trace += `OPTION PIEDS : +${pied.prix.toFixed(2)} €\n`;
            }
        }

        const totalPurchaseBrut = basePrice + optionsPrice;
        const salesCalculation = calculateSalesPriceFromPurchase(totalPurchaseBrut, volume, supplierDiscount);
        const finalDisplayPrice = salesCalculation.finalPrice;
        
        lastPricingTrace = trace + salesCalculation.trace;

        // Affichage final
        priceDisplay.val(finalDisplayPrice.toLocaleString('fr-FR', { minimumFractionDigits: 2 }));
        priceValue.val(finalDisplayPrice.toFixed(2));
          
        if(priceBarDisplay.length) {
            priceBarDisplay.val(finalDisplayPrice.toFixed(2))
                           .attr('data-discount', supplierDiscount)
                           .trigger('change');
            calculateTotalCombinedPrice(articleId);
        }

    } catch (error) {
        resetDisplay();
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
            container.append(`<div class="ispag-row btn-container-db" style="margin-top:5px;">
                <button type="button" id="${btnId}" data-id="${articleId}" class="ispag-btn btn-insert-tank-price" style="background-color:#2c3e50; color:white; width:100%; border:none; padding:4px 8px; cursor:pointer; font-size:11px; font-weight:bold; border-radius:4px;">
                <i class="fas fa-save"></i> Reporter</button></div>`);
        }
    } else {
        jQuery(`#${btnId}`).closest('.btn-container-db').remove();
    }
    return total;
}

function saveTotalToDatabase(articleId) {
    const totalPrice = calculateTotalCombinedPrice(articleId);
    const discountValue = parseFloat(jQuery('#tank-bare-price-' + articleId).attr('data-discount')) || 0;
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
                const netPrice = Math.ceil(totalPrice * (1 - (discountValue / 100)));
                btn.closest('.ispag-article').find('.ispag-article-prix-net').text(netPrice.toLocaleString('fr-FR') + ' €');
                setTimeout(() => { btn.html('<i class="fas fa-save"></i> Reporter').css('background-color', '#2c3e50').prop('disabled', false); }, 2000);
            } else {
                btn.prop('disabled', false).html('Réessayer');
            }
        },
        error: function() { btn.prop('disabled', false).html('Erreur'); }
    });
}

// --- ÉCOUTEURS ---
jQuery(document).on('change input', 'select[name^="tank["], input[name^="tank["], #welding-nb, input[name="isProjectOrPurchase"], #ispag-coef-select', function() {
    updateTankPrice();
});

jQuery(document).on('change input', '[id^="tank-bare-price-"], [id^="tank-acc-price-"]', function() {
    const id = jQuery(this).attr('id').split('-').pop();
    calculateTotalCombinedPrice(id);
});

jQuery(document).on('click', '.btn-insert-tank-price', function() {
    saveTotalToDatabase(jQuery(this).data('id'));
});