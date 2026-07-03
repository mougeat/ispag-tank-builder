/**
 * Fichier : tank-pricing.js
 * Version : 4.5.0 - Gestion silencieuse et complète + Soudure sur place
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
    lastPricingTrace = '';

    const articleId = jQuery('#current-editing-article-id').val();

    // Sélecteurs
    const priceBarDisplay = jQuery(document).find('#tank-bare-price-' + articleId);
    const priceDisplay = jQuery('#tank-price-display');
    const supplierEl = jQuery('#tank-supplier-display');

    const supplier = supplierEl.attr('data-value') || supplierEl.data('value');
    const diameter = parseInt(jQuery('select[name="tank[diameter]"], input[name="tank[diameter]"]').val());
    const heightTotal = parseInt(jQuery('input[name="tank[height]"]').val());
    const material = jQuery('#tank-material').val();

    if (!supplier || isNaN(diameter) || isNaN(heightTotal)) {
        return;
    }

    try {
        const fileName = supplier.replace(/\s+/g, '_') + '.json';
        const jsonUrl = `${ispag_vars.plugin_url}/price/${fileName}`;
        const response = await fetch(jsonUrl);
        const data = await response.json();

        let basePrice = 0;
        const grille = data.grille_tarifaire;
        const targetDia = Object.keys(grille).map(Number).sort((a, b) => a - b).find(d => d >= diameter);
        const targetHeight = targetDia ? Object.keys(grille[targetDia]).map(Number).sort((a, b) => a - b).find(h => h >= heightTotal) : null;

        if (targetHeight) {
            const pressKey = (parseFloat(jQuery('input[name="tank[max_pressure]"]').val()) || 3) <= 3 ? '3bar' : '6bar';
            basePrice = grille[targetDia][targetHeight][pressKey];

            // --- AJOUT DU PRIX DE BASE DANS LA TRACE ---
            lastPricingTrace += `Prix de base cuve (${targetDia}x${targetHeight} - ${pressKey}) : ${basePrice.toFixed(2)} €\n`;

            // --- VÉRIFICATION SOUDURE SUR PLACE (20% si welding-nb > 0) ---
            const weldingNb = parseInt(jQuery('#welding-nb').val()) || 0;
            if (weldingNb > 0) {
                const weldingSurcharge = basePrice * 0.20;
                basePrice += weldingSurcharge;
                lastPricingTrace += `- Majorations soudure sur place (20% de ${(basePrice - weldingSurcharge).toFixed(2)}€) : +${weldingSurcharge.toFixed(2)}€\n`;
            }
        }

        let optionsPrice = 0;
        // Calcul Zinc
        const techResponse = await fetch(`${ispag_vars.plugin_url}/assets/js/tank_data.json`);
        const techData = await techResponse.json();
        const bottomHeight = techData.arrayBottomHeight[material]?.[diameter] || 0;
        const mantleHeight = heightTotal - (2 * bottomHeight);
        const tankType = jQuery('select[name="tank[type]"]').val();

        if ((tankType === "6" || tankType === "7") && data.accessoires?.traitement_surface) {
            const surfaceM2 = Math.ceil(((diameter/1000)**2 * 2) + ((diameter/1000) * 4 * (mantleHeight/1000)));
            const valZinc = surfaceM2 * data.accessoires.traitement_surface.options.exterieur_zinc_1K.prix_m2;
            optionsPrice += valZinc;
            lastPricingTrace += `- Option Zinc : (+${surfaceM2.toFixed(2)} m²) +${valZinc.toFixed(2)} €\n`;
        }

        // Calcul Ground Clearance (Pieds)
        const clearance = parseInt(jQuery('input[name="tank[clearance]"]').val()) || 0;
        const baseClearance = data.accessoires?.ground_clearance?.base_mm || 50;

        if (clearance > baseClearance && data.accessoires?.ground_clearance?.plus_values) {
            const rule = data.accessoires.ground_clearance.plus_values.find(r => diameter <= r.diametre_max_mm);
            if (rule) {
                const clearancePrice = parseFloat(rule.prix) || 0;
                optionsPrice += clearancePrice;
                lastPricingTrace += `- Plus-value Garde au sol (${clearance}mm) : +${clearancePrice.toFixed(2)} €\n`;
            }
        }

        // Calcul du prix des PIEDS
        const supportType = jQuery('select[name="tank[support]"], input[name="tank[support]"]').val();
        const volumeLiters = parseInt(jQuery('input[name="tank[volume]"]').val()) || 0;
        const nbPieds = parseInt(jQuery('select[name="tank[legs_nb]"]').val()) || 3;

        // On n'applique la plus-value QUE si le type de support est "Pieds" (valeur 10)
        if (supportType == "10" && data.accessoires?.pieds) {
            let prixBasePieds = 0;
            let typePiedsUtilise = "";

            // Sélection du type de profilé (Rohr vs UNP) selon les limites du JSON
            if (volumeLiters >= data.accessoires.pieds.regles.volume_max_rohr || mantleHeight >= data.accessoires.pieds.regles.hauteur_manteau_max_rohr) {
                const rule = data.accessoires.pieds.unp_füße.find(r => volumeLiters <= r.volume_max_litres);
                prixBasePieds = rule ? rule.prix : data.accessoires.pieds.unp_füße[data.accessoires.pieds.unp_füße.length - 1].prix;
                typePiedsUtilise = "UNP";
            } else {
                const rule = data.accessoires.pieds.rohrfüße.find(r => volumeLiters <= r.volume_max_litres);
                prixBasePieds = rule ? rule.prix : data.accessoires.pieds.rohrfüße[data.accessoires.pieds.rohrfüße.length - 1].prix;
                typePiedsUtilise = "Rohr";
            }

            // Calcul du prix final (Forfait pour 3 pieds de base, prorata si 4)
            let prixFinalPieds = prixBasePieds;
            if (nbPieds === 4) {
                prixFinalPieds = (prixBasePieds / 3) * 4;
            }

            optionsPrice += prixFinalPieds;
            lastPricingTrace += `- Pieds (${nbPieds}x ${typePiedsUtilise}) : +${prixFinalPieds.toFixed(2)} €\n`;
        }

        // Calcul final
        const totalPurchaseBrut = basePrice + optionsPrice;
        const discountDefaut = parseFloat(data.discount_defaut) || 0;
        if (priceBarDisplay.length) {
            priceBarDisplay.attr('data-discount', discountDefaut);
        }
        const sales = calculateSalesPriceFromPurchase(totalPurchaseBrut, (parseInt(jQuery('input[name="tank[volume]"]').val()) || 0), (parseFloat(data.discount_defaut) || 0));
        lastPricingTrace += sales.trace;

        // --- ARRONDI ---
        const finalRoundedPrice = Math.ceil(sales.finalPrice);
        const priceFormatted = finalRoundedPrice.toFixed(2);

        // --- ÉCRITURE ---
        // 1. On écrit dans la modal (champ visuel)
        if (priceDisplay.length) {
            priceDisplay.val(finalRoundedPrice.toLocaleString('fr-FR', { minimumFractionDigits: 2 }));
        }

        // 2. On écrit dans l'article (champ caché)
        if (priceBarDisplay.length) {
            priceBarDisplay.val(priceFormatted);
            calculateTotalCombinedPrice(articleId);
            priceBarDisplay.trigger('change');
        } else {
            console.error("[8] Élément #tank-bare-price-" + articleId + " introuvable");
        }

    } catch (error) {
        console.error("Erreur:", error);
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