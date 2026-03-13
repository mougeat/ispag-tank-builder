window.TankGeometry = {
    drawVesselBody: function(engine, entities, cx, cy, R, Htot, f, GC, s, dessusY) {
        const groundY = cy; 
        const vesselBottomY = cy + s(GC + f);
        const vesselTopY = cy + s(Htot - f);
        const vesselPeakY = vesselTopY + s(f); 

        // --- 1. DESSIN DU RÉSERVOIR ---
        entities.push(engine.createEllipse(cx, vesselBottomY, s(R), f/R, Math.PI, 2 * Math.PI, "FONDS"));
        entities.push(engine.createEllipse(cx, vesselTopY, s(R), f/R, 0, Math.PI, "FONDS"));
        entities.push(engine.createLine(cx - s(R), vesselBottomY, cx - s(R), vesselTopY, "VIROLE"));
        entities.push(engine.createLine(cx + s(R), vesselBottomY, cx + s(R), vesselTopY, "VIROLE"));

        // --- 2. APPEL DES SUPPORTS ---
        this.drawVesselSupports(engine, entities, cx, cy, R, f, GC, s);

        // --- 3. COTATION HAUTEUR TOTALE (À gauche) ---
        engine.createDimension(entities, cx - s(R) - 300, groundY, cx - s(R) - 300, groundY + s(Htot), Htot, "COTATIONS");

        // --- 4. NOUVEAU : COTATION DIAMÈTRE SOUS LES PIEDS (En bas) ---
        // On place la cote environ 300 unités (mm) sous le sol
        const dimensionYUnder = groundY - 300;

        engine.createDimension(entities, cx - s(R), dimensionYUnder, cx + s(R), dimensionYUnder, "Diam.  " + (R*2), "COTATIONS");

        // Lignes de rappel vers le bas
        entities.push(engine.createLine(cx - s(R), groundY - 50, cx - s(R), dimensionYUnder - 20, "COTATIONS"));
        entities.push(engine.createLine(cx + s(R), groundY - 50, cx + s(R), dimensionYUnder - 20, "COTATIONS"));
    },

    // ... Welding, TopView et Nozzle restent identiques ...
    drawWelding: function(engine, entities, cx, faceY, R, alt, s) {
        const weldY = faceY + s(alt);
        entities.push(engine.createLine(cx - s(R), weldY, cx + s(R), weldY, "SOUDURES"));
        const tx = cx + s(R) + s(100);
        engine.addText(entities, tx, weldY, "H=" + alt, 0, "SOUDURES", s(30), 1);
        entities.push(engine.createLine(cx + s(R), weldY, tx - s(20), weldY, "SOUDURES"));
    },

    drawTopView: function(engine, entities, cx, cy, R, s) {
        entities.push(engine.createEllipse(cx, cy, s(R), 1, 0, 2 * Math.PI, "VIROLE"));
        const axe = s(R * 1.3);
    },

    drawNozzle: function(engine, entities, cx, faceY, dessusY, R, p, index, s) {
        if (!p.Bride_Int_mm && !p.Diametre_Nominal) return;
        const ang = parseFloat(p.Angle_degres || 0);
        const alt = parseFloat(p.Elevation_mm || 0);
        const dI = parseFloat(p.Bride_Int_mm || 50);
        const rad = (ang - 90) * Math.PI / 180;
        const cosA = Math.cos(rad);
        const sinA = Math.sin(rad);
        const xB = cx + s(R * cosA);
        const side = cosA >= 0 ? 1 : -1;
        entities.push(engine.createLine(xB, faceY + s(alt + dI/2), xB + s(100 * side), faceY + s(alt + dI/2), "PIQUAGES"));
        entities.push(engine.createLine(xB, faceY + s(alt - dI/2), xB + s(100 * side), faceY + s(alt - dI/2), "PIQUAGES"));
        const px = cx + s(R * cosA);
        const py = dessusY + s(R * sinA);
        entities.push(engine.createLine(px, py, cx + s((R + 100) * cosA), dessusY + s((R + 100) * sinA), "PIQUAGES"));
        engine.addText(entities, cx + s((R + 150) * cosA), dessusY + s((R + 150) * sinA), (index + 1), 0, "PIQUAGES", s(30), 1);
    },
    /**
     * D/**
 * Dessine les pieds en cornière soudés sur la virole (Plan 99.03061228-26N)
 */
drawVesselSupports: function(engine, entities, cx, cy, R, f, GC, s) {
    const groundY = cy;
    // Le bas de la virole est à GC au-dessus du sol
    const shellBottomY = cy + s(GC); 
    
    // Paramètres selon le plan
    const footWidth = s(80);   // Largeur du profilé
    const plateWidth = s(120); // Platine de sol
    const plateHeight = s(10); // Épaisseur platine
    
    // Les pieds sont alignés sur l'extérieur de la virole (Rayon R)
    const externalEdge = s(R);

    /**
     * Dessine un pied spécifique
     * @param {number} side -1 pour gauche, 1 pour droit
     */
    const drawAngleFoot = (side) => {
        const xEdge = cx + (side * externalEdge);
        const xInner = xEdge - (side * footWidth);
        
        // Soudure sur la virole : le pied remonte le long de la virole
        // Sur le plan, le pied chevauche la virole sur environ 200-300mm
        const overlapY = shellBottomY + s(250); 

        // Ligne extérieure (alignée sur la paroi de la cuve)
        entities.push(engine.createLine(xEdge, groundY, xEdge, overlapY, "SUPPORTS"));
        
        // Ligne intérieure du profilé
        entities.push(engine.createLine(xInner, groundY, xInner, overlapY, "SUPPORTS"));
        
        // Sommet du pied (souvent coupé en biais ou droit)
        entities.push(engine.createLine(xInner, overlapY, xEdge, overlapY, "SUPPORTS"));

        // Platine de sol centrée sous le profilé
        const xPlateCenter = xEdge - (side * (footWidth / 2));
        entities.push(engine.createLine(xPlateCenter - plateWidth/2, groundY, xPlateCenter + plateWidth/2, groundY, "SUPPORTS"));
        entities.push(engine.createLine(xPlateCenter - plateWidth/2, groundY - plateHeight, xPlateCenter + plateWidth/2, groundY - plateHeight, "SUPPORTS"));
        entities.push(engine.createLine(xPlateCenter - plateWidth/2, groundY, xPlateCenter - plateWidth/2, groundY - plateHeight, "SUPPORTS"));
        entities.push(engine.createLine(xPlateCenter + plateWidth/2, groundY, xPlateCenter + plateWidth/2, groundY - plateHeight, "SUPPORTS"));
    };

    // Dessin des deux pieds visibles
    drawAngleFoot(-1); // Gauche
    drawAngleFoot(1);  // Droit
}
};