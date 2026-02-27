/**
 * Fichier : fittings-pricing.js
 * Intègre le calcul des raccords, accessoires complexes et tôles perforées (Lochblech)
 */

async function updateFittingsPrice() {
    const articleId = jQuery('#current-editing-article-id').val();
    const supplierEl = jQuery('#tank-supplier-display');
    const supplier = supplierEl.attr('data-value') || supplierEl.data('value');
    
    // Récupération des paramètres de la cuve
    const tankDiameter = jQuery('#current-tank-diam').val(); 
    const tankType = jQuery('select[name="tank[type]"]').val() || 'energy';
    
    const accPriceDisplay = jQuery('#tank-acc-price-' + articleId);

    if (!supplier) return;

    const accessoryMapping = {
        "14": "bogenrohr",
        "15": "spruehrohr",
        "16": "prallteller"
    };

    const ids_gratuits_max_2_pouces = ["11", "12", "13", "14", "15", "16", "18"];
    const fileName = supplier.replace(/\s+/g, '_') + '_accessories.json';
    const jsonUrl = `${ispag_vars.plugin_url}/price/${fileName}`;

    try {
        const response = await fetch(jsonUrl);
        if (!response.ok) throw new Error('Fichier JSON non trouvé');
        
        const data = await response.json();
        const logic = data.logic;
        const limit = (tankType === 'combi') ? logic.included_fittings_combi : logic.included_fittings_energy;

        let totalExtra = 0;
        let standardFittingCount = 0;

        console.group(`🔍 CALCUL PRICING - ${supplier} (Cuve Ø${tankDiameter})`);

        // --- 1. CALCUL DES RACCORDS (FITTINGS) ---
        jQuery('.fitting-row').each(function(index) {
            const $row = jQuery(this);
            const diaId = $row.find('select[name^="fitting[diameter]"]').val();
            const rawAccId = $row.find('select[name^="fitting[accessories]"]').val();
            const fHeight = parseInt($row.find('input[name^="fitting[height]"]').val()) || 0;
            
            if (!diaId) return;

            let rowExtra = 0;
            const raccordInfo = data.tarifs_raccords_standards[diaId];

            // Raccord & Quota
            if (raccordInfo) {
                const priceUnit = parseFloat(raccordInfo.prix_unitaire) || 0;
                if (ids_gratuits_max_2_pouces.includes(diaId.toString())) {
                    standardFittingCount++;
                    if (standardFittingCount > limit) {
                        rowExtra += priceUnit;
                        console.log(` > Raccord #${index+1} : Hors Quota (+${priceUnit}€)`);
                    }
                } else {
                    rowExtra += priceUnit;
                    console.log(` > Raccord #${index+1} : Bride/Spécial (+${priceUnit}€)`);
                }
            }

            // Accessoires Complexes (Bogenrohr, etc.)
            const jsonKey = accessoryMapping[rawAccId] || rawAccId; 
            if (data.tarifs_accessoires_complexes[jsonKey]) {
                const priceAcc = parseFloat(data.tarifs_accessoires_complexes[jsonKey][diaId]) || 0;
                rowExtra += priceAcc;
                if(priceAcc > 0) console.log(` > Accessoire ${jsonKey} : +${priceAcc}€`);
            }

            // Longueur Hors Standard
            if (fHeight > logic.max_standard_length_mm) {
                let lenPrice = 0;
                if (fHeight <= 250) lenPrice = data.supplements.extra_length_250;
                else if (fHeight <= 350) lenPrice = data.supplements.extra_length_350;
                else lenPrice = data.supplements.extra_length_550;
                rowExtra += lenPrice;
                console.log(` > Supplément Longueur : +${lenPrice}€`);
            }

            totalExtra += rowExtra;
        });

        // --- 2. CALCUL WELDING (TÔLES PERFORÉES) ---
        jQuery('.welding-row').each(function() {
            const $row = jQuery(this);
            const weldingType = $row.find('select[name^="welding[type]"]').val(); 

            if (weldingType === 'lochblech' || weldingType === '22') { // "22" est l'ID SQL de la tôle
                const priceLoch = data.lochblech_fix[tankDiameter] || 0;
                totalExtra += parseFloat(priceLoch);
                console.log(` > Tôle Perforée Ø${tankDiameter} : +${priceLoch}€`);
            } 
            // Autres soudures simples si définies
            else if (data.tarifs_welding && data.tarifs_welding[weldingType]) {
                totalExtra += parseFloat(data.tarifs_welding[weldingType]);
            }
        });

        console.log(`%cTOTAL ACCESSOIRES : ${totalExtra.toFixed(2)}€`, "color: green; font-weight: bold;");
        console.groupEnd();

        // Mise à jour de l'input de prix dans l'interface WordPress
        accPriceDisplay.val(totalExtra.toFixed(2)).trigger('change');

    } catch (error) {
        console.error("Erreur pricing :", error.message);
    }
}


// --- ÉCOUTEURS D'ÉVÉNEMENTS ---

// Changement sur n'importe quel champ de raccord
jQuery(document).on('change input', 'select[name^="fitting"], input[name^="fitting"]', function() {
    updateFittingsPrice();
});

// Changement sur le diamètre ou type de cuve (influence le quota et le Lochblech)
jQuery(document).on('change input', 'select[name="tank[diameter]"], input[name="tank[diameter]"], select[name="tank[type]"]', function() {
    updateFittingsPrice();
});

// Déclencher le calcul lors de la duplication d'une ligne
jQuery(document).on('click', '.btn-duplicate', function() {
    // On attend un court instant que le script de duplication 
    // ait terminé d'ajouter la ligne au DOM
    setTimeout(function() {
        console.log("🔄 Ligne dupliquée détectée, recalcul du prix...");
        updateFittingsPrice();
    }, 100); 
});
// Déclencher le calcul lors de la suppression d'une ligne
jQuery(document).on('click', '.btn-delete-fitting', function() {
    // On utilise un délai un peu plus long (200ms) car les scripts 
    // de suppression ont souvent une petite animation (fadeOut)
    setTimeout(function() {
        console.log("🗑️ Ligne supprimée, recalcul du prix...");
        updateFittingsPrice();
    }, 200); 
});