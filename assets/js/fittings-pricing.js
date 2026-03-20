/**
 * Fichier : fittings-pricing.js
 * Version : 3.4.0 - PV Verrohrung Cumulative (Nb Tôles x 60€)
 */

let pricingTimeout;
let isAlertSuppressed = false;
let lastSupplier = "";

// Trace globale pour le log PHP
window.lastFittingTrace = "";

async function updateFittingsPrice() {
    clearTimeout(pricingTimeout);
    
    pricingTimeout = setTimeout(async () => {
        console.group("%c--- DÉBOGAGE FITTINGS ISPAG ---", "background: #e67e22; color: white; padding: 2px 5px;");
        
        const articleId = jQuery('#current-editing-article-id').val();
        const supplierEl = jQuery('#tank-supplier-display');
        const supplierName = supplierEl.attr('data-value') || supplierEl.data('value') || "Fournisseur inconnu";
        const tankPression = parseFloat(jQuery('#current-tank-pression').val()) || 0;
        const pressureKey = (tankPression <= 6) ? "prix_pn6" : "prix_pn16";
        const tankDiameter = jQuery('#current-tank-diam').val(); 
        const tankType = jQuery('select[name="tank[type]"]').val() || 'energy';
        const accPriceDisplay = jQuery('#tank-acc-price-' + articleId);

        console.log(`[1] Context: Supplier=${supplierName}, Pression=${tankPression}bar, Ø=${tankDiameter}`);

        if (!supplierName || supplierName === "Fournisseur inconnu") {
            console.error("Fournisseur manquant, arrêt.");
            console.groupEnd();
            return;
        }

        try {
            const fileName = supplierName.replace(/\s+/g, '_') + '_accessories.json';
            const jsonUrl = `${ispag_vars.plugin_url}/price/${fileName}`;
            const response = await fetch(jsonUrl);
            const data = await response.json();
            const logic = data.logic;

            // --- 1. TÔLES PERFORÉES ---
            let lochblechCount = 0;
            let totalLochblechPrice = 0;
            let lochTrace = "";

            jQuery('#welding-container .fitting-row').each(function() {
                const typeVal = jQuery(this).find('select[name="fitting[type][]"]').val();
                if (typeVal === "22") { 
                    lochblechCount++;
                    const priceLoch = data.tarifs_accessoires_complexes.lochblech_fix?.[tankDiameter];
                    if (priceLoch) {
                        totalLochblechPrice += parseFloat(priceLoch);
                        lochTrace += `- Tôle perforée n°${lochblechCount} (Ø${tankDiameter}) : +${priceLoch}€\n`;
                    }
                }
            });
            console.log(`[2] Lochblech: ${lochblechCount} trouvée(s), Total=${totalLochblechPrice}€`);

            // --- 2. RACCORDS ---
            let totalFittingsPurchase = 0;
            let standardFittingCount = 0;
            let fittingsTrace = ""; // Initialisation déjà présente
            const limit = (tankType === 'combi') ? logic.included_fittings_combi : logic.included_fittings_energy;

            jQuery('.fitting-row').not('#welding-container .fitting-row').each(function(index) {
                const $row = jQuery(this);
                const diaId = $row.find('select[name^="fitting[diameter]"]').val();
                const diaText = $row.find('select[name^="fitting[diameter]"] option:selected').text(); // Pour le log
                const rawAccId = $row.find('select[name^="fitting[accessories]"]').val();
                const fHeight = parseInt($row.find('input[name^="fitting[height]"]').val()) || 0;
                
                if (!diaId) return;

                const dnInfo = data.tarifs_raccords_standards?.[diaId];
                let rowPrice = 0;
                let rowLog = `- Raccord ${diaText}`;

                // Calcul raccord + quota
                if (dnInfo) {
                    const priceUnit = parseFloat(dnInfo[pressureKey]);
                    if (["11", "12", "13", "14", "15", "16", "18"].includes(diaId.toString())) {
                        standardFittingCount++;
                        if (standardFittingCount > limit) {
                            rowPrice += priceUnit;
                            rowLog += ` : +${priceUnit}€ (Hors quota > ${limit})`;
                        } else {
                            rowLog += ` : Inclus (Quota ${standardFittingCount}/${limit})`;
                        }
                    } else {
                        rowPrice += priceUnit;
                        rowLog += ` : +${priceUnit}€ (Standard)`;
                    }
                }

                // Accessoires (Bogenrohr, etc.)
                if (rawAccId && rawAccId !== "0") {
                    const jsonKey = { "14": "bogenrohr", "15": "spruehrohr", "16": "prallteller" }[rawAccId] || rawAccId;
                    const priceAcc = parseFloat(data.tarifs_accessoires_complexes?.[jsonKey]?.[diaId] || 0);
                    rowPrice += priceAcc;
                    rowLog += ` + Acc. ${jsonKey} (+${priceAcc}€)`;
                    
                    if (jsonKey === "bogenrohr" && lochblechCount > 0) {
                        const extraLoch = parseFloat(data.supplements.Verrohrung_durch_Lochblech) || 60;
                        rowPrice += extraLoch;
                        rowLog += ` + PV Traversée Tôle (+${extraLoch}€)`;
                    }
                }

                // Longueur
                if (fHeight > logic.max_standard_length_mm) {
                    let key = fHeight <= 250 ? "extra_length_250" : (fHeight <= 350 ? "extra_length_350" : "extra_length_550");
                    const priceLen = parseFloat(data.supplements?.[key] || 0);
                    rowPrice += priceLen;
                    rowLog += ` + Longueur ${fHeight}mm (+${priceLen}€)`;
                }
                
                fittingsTrace += rowLog + ` | Sous-total: ${rowPrice}€\n`; // Ajout à la trace
                totalFittingsPurchase += rowPrice;
            });

            // --- 3. VENTE & LOGS FINAUX ---
            const totalFinalBrutAchat = totalFittingsPurchase + totalLochblechPrice;
            const sales = calculateSalesPriceFromPurchase(totalFinalBrutAchat, 0, 0); 
            const finalPriceRounded = Math.ceil(sales.finalPrice);

            // MISE À JOUR ICI :
            window.lastFittingTrace = `\n--- LOG FITTINGS & ACCESSOIRES ---\n` +
                                    `DÉTAIL ACHAT :\n` +
                                    (lochTrace || "- Aucune tôle perforée\n") +
                                    (fittingsTrace || "- Aucun raccord payant\n") +
                                    `TOTAL ACHAT BRUT : ${totalFinalBrutAchat.toFixed(2)}€\n` +
                                    `--------------------------\n` +
                                    sales.trace + // Contient la logique coef/douane/transport
                                    `TOTAL VENTE ACCESSOIRES : ${finalPriceRounded}€\n`;

            if (accPriceDisplay.length) {
                accPriceDisplay.val(finalPriceRounded.toFixed(2));
                calculateTotalCombinedPrice(articleId);
                accPriceDisplay.trigger('change');
            }

        } catch (error) {
            console.error("Erreur technique :", error);
        }
        console.groupEnd();
    }, 150);
}

// --- ÉCOUTEURS ---
jQuery(document).on('change input', 'select[name^="fitting"], input[name^="fitting"]', function() {
    updateFittingsPrice();
});

jQuery(document).on('change input', 'select[name="tank[diameter]"], input[name="tank[diameter]"], select[name="tank[type]"]', function() {
    updateFittingsPrice();
});

jQuery(document).on('click', '.btn-duplicate', function() {
    setTimeout(() => updateFittingsPrice(), 100); 
});

jQuery(document).on('click', '.btn-delete-fitting', function() {
    setTimeout(() => updateFittingsPrice(), 200); 
});

jQuery(document).on('change input', '#current-tank-pression', function() {
    isAlertSuppressed = false; 
    updateFittingsPrice();
});