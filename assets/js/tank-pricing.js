/**
 * Fichier : tank-pricing.js
 * Logique de calcul du prix basée sur les JSON fournisseurs
 */

async function updateTankPrice() {
    
    const articleId = jQuery('#current-editing-article-id').val();
    const supplierEl = jQuery(document).find('#tank-supplier-display');
    const supplier = supplierEl.attr('data-value') || supplierEl.data('value');
    // Récupération des dimensions (noms conformes au template PHP)
    const diameter = parseInt(jQuery('select[name="tank[diameter]"], input[name="tank[diameter]"]').val());
    const height = parseInt(jQuery('input[name="tank[height]"]').val());
    const pressure = parseFloat(jQuery('input[name="tank[max_pressure]"]').val()) || 3;
    
    const priceDisplay = jQuery('#tank-price-display');
    const priceBarDisplay = jQuery('#tank-bare-price-' + articleId);
    const priceValue = jQuery('#tank-price-value');

    console.log("--- Calcul du prix ISPAG ---");
    console.log('ID Article', articleId, priceBarDisplay);
    console.log("Fournisseur source :", supplier);
    console.log("Paramètres : Ø" + diameter + " H" + height + " P" + pressure);

    // Sécurité : arrêt si données incomplètes
    if (!supplier || supplier === "" || isNaN(diameter) || isNaN(height)) {
        console.warn("Calcul annulé : infos manquantes (Fournisseur ou Dimensions).");
        priceDisplay.val("---");
        priceBarDisplay.val("---");
        priceValue.val("");
        return;
    }

    // Construction du nom de fichier (Espaces -> Underscores)
    const fileName = supplier.replace(/\s+/g, '_') + '.json';
    const jsonUrl = `${ispag_vars.plugin_url}/price/${fileName}`;
    
    console.log("Tentative de chargement :", jsonUrl);

    try {
        const response = await fetch(jsonUrl);
        if (!response.ok) throw new Error('Fichier JSON non trouvé ou inaccessible');
        
        const data = await response.json();
        const grille = data.grille_tarifaire;

        // 1. Recherche Diamètre (égal ou supérieur)
        const sortedDiameters = Object.keys(grille).map(Number).sort((a, b) => a - b);
        const targetDia = sortedDiameters.find(d => d >= diameter);

        if (!targetDia) {
            priceDisplay.val("Hors grille (Ø)");
            priceBarDisplay.val("Hors grille (Ø)");
            return;
        }

        // 2. Recherche Hauteur (égale ou supérieure)
        const heightsForDia = grille[targetDia];
        const sortedHeights = Object.keys(heightsForDia).map(Number).sort((a, b) => a - b);
        const targetHeight = sortedHeights.find(h => h >= height);

        if (!targetHeight) {
            priceDisplay.val("Hors grille (H)");
            priceBarDisplay.val("Hors grille (H)");
            return;
        }

        // 3. Choix Pression (Seuil 3bar)
        const pressKey = (pressure <= 3) ? '3bar' : '6bar';
        const finalPrice = heightsForDia[targetHeight][pressKey];

        if (finalPrice) {
            console.log("Prix trouvé :", finalPrice, "€");
            priceDisplay.val(finalPrice.toLocaleString('fr-FR', { minimumFractionDigits: 2 }));
            priceBarDisplay.val(finalPrice);
            priceValue.val(finalPrice);
        } else {
            priceDisplay.val("N/A");
            priceBarDisplay.val("N/A");
        }

    } catch (error) {
        console.error("Erreur pricing :", error.message);
        priceDisplay.val("Tarif non dispo.");
        priceBarDisplay.val("Tarif non dispo.");
    }
}

// 2. ÉCOUTEURS D'ÉVÉNEMENTS
jQuery(document).on('change input', 
    'select[name="tank[diameter]"], input[name="tank[diameter]"], input[name="tank[volume]"], input[name="tank[height]"], input[name="tank[max_pressure]"]', 
    function() {
        console.log("Modification détectée sur :", jQuery(this).attr('name'));
        updateTankPrice();
    }
);

// Ecouteur spécial pour le changement de fournisseur via inline-edit
// Il faut que ton script d'édition déclenche un événement quand il a fini
jQuery(document).on('ispag-inline-edit-success', function(e, fieldName) {
    if (fieldName === 'Fournisseur') {
        console.log("Le fournisseur a changé, recalcul du prix...");
        updateTankPrice();
    }
});