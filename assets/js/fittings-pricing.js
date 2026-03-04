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
        const articleId = jQuery('#current-editing-article-id').val();
        const supplierEl = jQuery('#tank-supplier-display');
        const supplierName = supplierEl.attr('data-value') || supplierEl.data('value') || "Fournisseur inconnu";
        
        const tankPression = parseFloat(jQuery('#current-tank-pression').val()) || 0;
        const pressureKey = (tankPression <= 6) ? "prix_pn6" : "prix_pn16";

        if (supplierName !== lastSupplier) {
            isAlertSuppressed = false;
            lastSupplier = supplierName;
        }

        const tankDiameter = jQuery('#current-tank-diam').val(); 
        const tankType = jQuery('select[name="tank[type]"]').val() || 'energy';
        const accPriceDisplay = jQuery('#tank-acc-price-' + articleId);

        if (!supplierName || supplierName === "Fournisseur inconnu") return;

        let missingDataLog = [];
        const accessoryMapping = { "14": "bogenrohr", "15": "spruehrohr", "16": "prallteller" };
        const ids_manchons_taraudes = ["11", "12", "13", "14", "15", "16", "18"];
        
        const fileName = supplierName.replace(/\s+/g, '_') + '_accessories.json';
        const jsonUrl = `${ispag_vars.plugin_url}/price/${fileName}`;

        try {
            const response = await fetch(jsonUrl);
            if (!response.ok) throw new Error('Fichier JSON non trouvé');
            
            const data = await response.json();
            const logic = data.logic;

            // --- 1. COMPTAGE DES TÔLES PERFORÉES (WELDING CONTAINER) ---
            let lochblechCount = 0;
            let totalLochblechPrice = 0;
            let lochTrace = "";

            jQuery('#welding-container .fitting-row').each(function() {
                const typeVal = jQuery(this).find('select[name="fitting[type][]"]').val();
                if (typeVal === "22") { // drilled plate (35%)
                    lochblechCount++;
                    const lochTable = data.tarifs_accessoires_complexes.lochblech_fix;
                    const priceLoch = lochTable ? parseFloat(lochTable[tankDiameter]) : NaN;

                    if (!isNaN(priceLoch)) {
                        totalLochblechPrice += priceLoch;
                        lochTrace += `- Tôle perforée n°${lochblechCount} (Ø${tankDiameter}) : +${priceLoch.toFixed(2)}€\n`;
                    } else {
                        missingDataLog.push(`Prix Lochblech manquant (Ø${tankDiameter})`);
                    }
                }
            });

            // Quota standard (10 ou 14)
            const limit = (tankType === 'combi') ? logic.included_fittings_combi : logic.included_fittings_energy;

            // --- 2. CALCUL DES RACCORDS ---
            let totalFittings = 0;
            let standardFittingCount = 0;
            let fittingsTrace = "";

            jQuery('.fitting-row').not('#welding-container .fitting-row').each(function(index) {
                const $row = jQuery(this);
                const diaId = $row.find('select[name^="fitting[diameter]"]').val();
                const rawAccId = $row.find('select[name^="fitting[accessories]"]').val();
                const fHeight = parseInt($row.find('input[name^="fitting[height]"]').val()) || 0;
                
                if (!diaId) return;

                const dnInfo = (data.tarifs_raccords_standards && data.tarifs_raccords_standards[diaId]) 
                                ? data.tarifs_raccords_standards[diaId] : null;
                const dnName = dnInfo ? dnInfo.dn : `ID:${diaId}`;
                
                let rowPrice = 0;
                let rowLabel = `- Ligne ${index + 1} (${dnName}) : `;

                // A. Prix du raccord (soumis au quota)
                if (dnInfo) {
                    const priceUnit = parseFloat(dnInfo[pressureKey]);
                    if (!isNaN(priceUnit)) {
                        if (ids_manchons_taraudes.includes(diaId.toString())) {
                            standardFittingCount++;
                            if (standardFittingCount > limit) {
                                rowPrice += priceUnit;
                                rowLabel += `${priceUnit}€ (Hors quota)`;
                            } else {
                                rowLabel += `0€ (Inclus)`;
                            }
                        } else {
                            rowPrice += priceUnit;
                            rowLabel += `${priceUnit}€`;
                        }
                    }
                }

                // B. Accessoires + Plus-value cumulative Verrohrung
                if (rawAccId && rawAccId !== "0") {
                    const jsonKey = accessoryMapping[rawAccId] || rawAccId; 
                    if (data.tarifs_accessoires_complexes && data.tarifs_accessoires_complexes[jsonKey]) {
                        const priceAcc = parseFloat(data.tarifs_accessoires_complexes[jsonKey][diaId]);
                        if (!isNaN(priceAcc)) {
                            rowPrice += priceAcc;
                            rowLabel += ` + ${priceAcc}€ (${jsonKey})`;
                            
                            // SI COUDE (Bogenrohr) : on ajoute 60€ par tôle présente
                            if (jsonKey === "bogenrohr" && lochblechCount > 0) {
                                const unitPV = parseFloat(data.supplements.Verrohrung_durch_Lochblech) || 60;
                                // const totalPVRow = unitPV * lochblechCount;
                                const totalPVRow = unitPV;
                                rowPrice += totalPVRow;
                                rowLabel += ` + ${totalPVRow}€ (Verrohrung durch Lochblech)`;
                            }
                        }
                    }
                }

                // C. Longueurs
                if (fHeight > logic.max_standard_length_mm) {
                    let key = fHeight <= 250 ? "extra_length_250" : (fHeight <= 350 ? "extra_length_350" : "extra_length_550");
                    const lenPrice = data.supplements ? parseFloat(data.supplements[key]) : 0;
                    if (lenPrice > 0) {
                        rowPrice += lenPrice;
                        rowLabel += ` + ${lenPrice}€ (L=${fHeight})`;
                    }
                }
                
                fittingsTrace += rowLabel + ` = ${rowPrice.toFixed(2)}€\n`;
                totalFittings += rowPrice;
            });

            // --- 3. SYNTHÈSE ---
            const totalFinalBrut = totalFittings + totalLochblechPrice;

            let finalTrace = `--- CALCUL FITTING ISPAG ---\n`;
            // finalTrace += `Nombre de tôles détectées : ${lochblechCount}\n`;
            finalTrace += `---------------------------------------\n`;
            if (lochTrace) finalTrace += lochTrace + `\n`;
            finalTrace += fittingsTrace;
            finalTrace += `TOTAL FINAL : ${totalFinalBrut.toFixed(2)} €\n`;

            window.lastFittingTrace = finalTrace;

            if (missingDataLog.length > 0) {
                accPriceDisplay.css('background-color', '#ffe6e6');
                if (!isAlertSuppressed) {
                    alert(`⚠️ Données manquantes :\n- ` + [...new Set(missingDataLog)].join("\n- "));
                    isAlertSuppressed = true;
                }
            } else {
                accPriceDisplay.css('background-color', '');
            }

            accPriceDisplay.val(totalFinalBrut.toFixed(2)).trigger('change');

        } catch (error) {
            console.error("Erreur technique pricing :", error.message);
        }
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