window.TankGeometry = {
    drawVesselBody: function(engine, entities, cx, cy, R, Htot, f, GC, s, dessusY, supportType = "feet") {
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
        // Dans drawVesselBody, ajoutez l'argument supportType (récupéré des données)
        this.drawVesselSupports(engine, entities, cx, cy, R, f, GC, s, supportType);

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
    drawNozzle: function(engine, entities, cx, faceY, dessusY, R, p, index, s, GC) {
        // --- FONCTION INTERNE POUR DESSINER UNE ELLIPSE (Contour + Perçages) ---
        const drawEllipsePerspective = (ent, xCenter, yCenter, rx, ry, layer, nTrous = 0, rEntX = 0, rEntY = 0, dT = 0) => {
            const segments = 32;
            for (let i = 0; i < segments; i++) {
                const a1 = (i * 2 * Math.PI) / segments;
                const a2 = ((i + 1) * 2 * Math.PI) / segments;
                ent.push(engine.createLine(
                    xCenter + rx * Math.cos(a1), yCenter + ry * Math.sin(a1),
                    xCenter + rx * Math.cos(a2), yCenter + ry * Math.sin(a2),
                    layer
                ));
            }
            if (nTrous > 0 && dT > 0) {
                const rTH = (s(dT) / 2) * (rx / ry);
                const rTV = s(dT) / 2;
                for (let i = 0; i < nTrous; i++) {
                    const angle = (i * 360 / nTrous + 45) * Math.PI / 180;
                    const tx = xCenter + rEntX * Math.cos(angle);
                    const ty = yCenter + rEntY * Math.sin(angle);
                    for (let j = 0; j < 8; j++) {
                        const b1 = (j * 2 * Math.PI) / 8;
                        const b2 = ((j + 1) * 2 * Math.PI) / 8;
                        ent.push(engine.createLine(
                            tx + rTH * Math.cos(b1), ty + rTV * Math.sin(b1),
                            tx + rTH * Math.cos(b2), ty + rTV * Math.sin(b2),
                            layer
                        ));
                    }
                }
            }
        };

        // 1. Extraction et calculs de base
        const ang = parseFloat(p.Angle_degres || 0);
        const alt = parseFloat(p.Elevation_mm || 0);
        const dInt = parseFloat(p.Bride_Int_mm || 0);
        const dExt = parseFloat(p.Bride_Ext_mm || dInt);
        const epB = parseFloat(p.Epaisseur_Bride_mm || 0);
        const nTrous = parseInt(p.NbDrilling || 0);
        const dTrous = parseFloat(p.DiamDrillings || 0);
        
        if (!dInt && !p.Diametre_Nominal) return;

        // --- LOGIQUE DYNAMIQUE DE LONGUEUR (Récupération depuis l'objet GC) ---
        // On vérifie si GC.tank existe, sinon 100 par défaut
        const iso = (GC && GC.tank && GC.tank.insulationThickness) 
                    ? parseFloat(GC.tank.insulationThickness) 
                    : 100;
        
        const surplus = (epB > 0) ? 20 : 0; 
        const tubeL = iso + surplus; 

        const rad = (ang - 90) * Math.PI / 180;
        const cosA = Math.cos(rad);
        const sinA = Math.sin(rad);
        const yCenter = faceY + s(alt); 

        // --- A. VUE DE FACE ---
        if (Math.abs(cosA) < 0.85 && sinA < 0.1) { 
            // CAS : PERSPECTIVE (Ellipse)
            const xBaseCuve = cx + s(R * cosA);
            const perspectiveX = Math.abs(sinA); 
            
            const xBrideArriere = xBaseCuve + s(tubeL) * cosA;
            const ryExt = s(dExt / 2);
            const rxExt = ryExt * perspectiveX;
            const ryInt = s(dInt / 2);

            // 1. Le Tube (Liaison Cuve -> Bride)
            entities.push(engine.createLine(xBaseCuve, yCenter + ryInt, xBrideArriere, yCenter + ryInt, "PIQUAGES"));
            entities.push(engine.createLine(xBaseCuve, yCenter - ryInt, xBrideArriere, yCenter - ryInt, "PIQUAGES"));

            // 2. La Bride
            const rEntY = s((dExt + dInt) / 4);
            const rEntX = rEntY * perspectiveX;
            drawEllipsePerspective(entities, xBrideArriere, yCenter, rxExt, ryExt, "PIQUAGES", nTrous, rEntX, rEntY, dTrous);
            
            if (epB > 0) {
                const offsetB = s(epB) * cosA;
                const xBrideAvant = xBrideArriere + offsetB;
                drawEllipsePerspective(entities, xBrideAvant, yCenter, rxExt, ryExt, "PIQUAGES");
                entities.push(engine.createLine(xBrideArriere, yCenter + ryExt, xBrideAvant, yCenter + ryExt, "PIQUAGES"));
                entities.push(engine.createLine(xBrideArriere, yCenter - ryExt, xBrideAvant, yCenter - ryExt, "PIQUAGES"));
            }
            engine.addText(entities, xBrideArriere + rxExt + s(30), yCenter, (index + 1), 0, "PIQUAGES", s(40), 1);

        } else {
            // CAS : PROFIL PUR (Rectangles)
            const side = cosA >= 0 ? 1 : -1;
            const xBase = cx + s(R * cosA);
            const xEnd = xBase + s(tubeL) * side;
            
            entities.push(engine.createLine(xBase, yCenter + s(dInt/2), xEnd, yCenter + s(dInt/2), "PIQUAGES"));
            entities.push(engine.createLine(xBase, yCenter - s(dInt/2), xEnd, yCenter - s(dInt/2), "PIQUAGES"));
            
            if (epB > 0) {
                const xFront = xEnd + s(epB) * side;
                const hExt = s(dExt / 2);
                entities.push(engine.createLine(xEnd, yCenter + hExt, xFront, yCenter + hExt, "PIQUAGES"));
                entities.push(engine.createLine(xEnd, yCenter - hExt, xFront, yCenter - hExt, "PIQUAGES"));
                entities.push(engine.createLine(xEnd, yCenter + hExt, xEnd, yCenter - hExt, "PIQUAGES"));
                entities.push(engine.createLine(xFront, yCenter + hExt, xFront, yCenter - hExt, "PIQUAGES"));
            }
            engine.addText(entities, xEnd + s(50) * side, yCenter, (index + 1), 0, "PIQUAGES", s(40), 1);
        }

        // --- B. VUE DE DESSUS ---
        const halfD = s(dInt) / 2;
        const pX = Math.cos(rad + Math.PI / 2);
        const pY = Math.sin(rad + Math.PI / 2);

        const xBL = cx + s(R * cosA) + (pX * halfD);
        const yBL = dessusY + s(R * sinA) + (pY * halfD);
        const xBR = cx + s(R * cosA) - (pX * halfD);
        const yBR = dessusY + s(R * sinA) - (pY * halfD);

        const xEL = cx + s((R + tubeL) * cosA) + (pX * halfD);
        const yEL = dessusY + s((R + tubeL) * sinA) + (pY * halfD);
        const xER = cx + s((R + tubeL) * cosA) - (pX * halfD);
        const yER = dessusY + s((R + tubeL) * sinA) - (pY * halfD);

        entities.push(engine.createLine(xBL, yBL, xEL, yEL, "PIQUAGES"));
        entities.push(engine.createLine(xBR, yBR, xER, yER, "PIQUAGES"));

        if (epB > 0) {
            const hExt = s(dExt) / 2;
            const eBS = s(epB);
            const bx1 = xEL + (pX * (hExt - halfD));
            const by1 = yEL + (pY * (hExt - halfD));
            const bx2 = xER - (pX * (hExt - halfD));
            const by2 = yER - (pY * (hExt - halfD));
            const bx3 = bx2 + (Math.cos(rad) * eBS);
            const by3 = by2 + (Math.sin(rad) * eBS);
            const bx4 = bx1 + (Math.cos(rad) * eBS);
            const by4 = by1 + (Math.sin(rad) * eBS);

            entities.push(engine.createLine(bx1, by1, bx2, by2, "PIQUAGES"));
            entities.push(engine.createLine(bx2, by2, bx3, by3, "PIQUAGES"));
            entities.push(engine.createLine(bx3, by3, bx4, by4, "PIQUAGES"));
            entities.push(engine.createLine(bx4, by4, bx1, by1, "PIQUAGES"));
        } else {
            entities.push(engine.createLine(xEL, yEL, xER, yER, "PIQUAGES"));
        }

        const dTxt = epB > 0 ? tubeL + 80 : tubeL + 60;
        engine.addText(entities, cx + s((R + dTxt) * cosA), dessusY + s((R + dTxt) * sinA), (index + 1), 0, "PIQUAGES", s(35), 1);
    },
    /**
     * Dessine un piquage de type Bend Pipe biseauté à largeur constante dans le DXF.
     * @version 4.0.0
     */
    drawBendPipe: function(engine, entities, cx, faceY, R, p, s, config) {
        const alt = parseFloat(p.Elevation_mm || 0);
        const dInt = parseFloat(p.Bride_Int_mm || 50);
        const Htot = config.tank.hauteurTotale;
        
        const ang = parseFloat(p.Angle_degres || 0);
        const rad = (ang - 90) * Math.PI / 180;
        const cosA = Math.cos(rad);
        const direction = cosA >= 0 ? 1 : -1;
        
        const xBase = cx + s(R * cosA);
        const yBase = faceY + s(alt);
        const r = s(dInt / 2); 

        // Direction : monte si en haut, descend si en bas
        let vDir = (alt > Htot / 2) ? 1 : -1; 

        const L_horiz = s(60);   
        const L_oblique = s(100); 
        const angleAlpha = Math.PI / 4; // 45°

        // 1. Point de pivot central (le "coude" théorique)
        const ax1 = xBase - (L_horiz * direction);
        const ay1 = yBase;

        // 2. Calcul de l'onglet (Miter)
        // Le décalage horizontal pour que la coupe à 45° soit parfaite
        // Décalage = r * tan(22.5°)
        const miterOffset = r * Math.tan(angleAlpha / 2);

        // 3. Points de jonction précis
        // On ajuste le X pour que les lignes horizontales et obliques se connectent pile au bord
        const xJuncHaut = ax1 + (miterOffset * direction * vDir);
        const xJuncBas  = ax1 - (miterOffset * direction * vDir);
        
        const yJuncHaut = ay1 - r;
        const yJuncBas  = ay1 + r;

        // 4. Points de sortie (Extrémité du tube)
        // On calcule la fin de l'axe central
        const ax2 = ax1 - (L_oblique * Math.cos(angleAlpha) * direction);
        const ay2 = ay1 + (L_oblique * Math.sin(angleAlpha) * vDir);

        // Décalage perpendiculaire pour l'extrémité
        const dx = r * Math.sin(angleAlpha);
        const dy = r * Math.cos(angleAlpha);

        const xEndHaut = ax2 - (dx * direction * vDir);
        const yEndHaut = ay2 - (dy * vDir);
        const xEndBas  = ax2 + (dx * direction * vDir);
        const yEndBas  = ay2 + (dy * vDir);

        // --- DESSIN ---

        // Lignes horizontales (du réservoir jusqu'à l'onglet)
        entities.push(engine.createLine(xBase, yJuncHaut, xJuncHaut, yJuncHaut, "PIQUAGES"));
        entities.push(engine.createLine(xBase, yJuncBas,  xJuncBas,  yJuncBas,  "PIQUAGES"));

        // Lignes obliques (de l'onglet jusqu'à la fin)
        entities.push(engine.createLine(xJuncHaut, yJuncHaut, xEndHaut, yEndHaut, "PIQUAGES"));
        entities.push(engine.createLine(xJuncBas,  yJuncBas,  xEndBas,  yEndBas,  "PIQUAGES"));

        // Ligne d'onglet (le pli du tube) -> Rend le dessin beaucoup plus "pro"
        entities.push(engine.createLine(xJuncHaut, yJuncHaut, xJuncBas, yJuncBas, "PIQUAGES"));

        // Bouchon final (perpendiculaire à l'axe oblique)
        entities.push(engine.createLine(xEndHaut, yEndHaut, xEndBas, yEndBas, "PIQUAGES"));
    },
    /**
     * Gestion dynamique des supports
     */
    drawVesselSupports: function(engine, entities, cx, cy, R, f, GC, s, supportType = "feet") {
        const groundY = cy;
        const shellBottomY = cy + s(GC); 
        const vesselBottomY = cy + s(GC + f);

        // CAS 1 : VIROLE DE SUPPORT (Ring / Skirt)
        if (supportType === "ring" || supportType === "virole") {
            // La virole est 200mm moins large que la cuve => R - 100 de chaque côté
            const ringR = s(R - 100);
            
            // La virole remonte sur la cuve (on simule une soudure sur le fond bombé)
            // On la fait monter un peu plus haut que le point de tangence pour l'effet visuel
            const ringTopY = shellBottomY + s(f * 0.5); 

            // Lignes verticales de la virole
            entities.push(engine.createLine(cx - ringR, groundY, cx - ringR, ringTopY, "SUPPORTS"));
            entities.push(engine.createLine(cx + ringR, groundY, cx + ringR, ringTopY, "SUPPORTS"));
            
            // Ligne de sol (base)
            entities.push(engine.createLine(cx - ringR, groundY, cx + ringR, groundY, "SUPPORTS"));
            
            // Passage pour la vidange (Ground Clearance)
            // Si GC est faible (ex: 50mm), on dessine une ouverture plus large
            const ventW = s(200);
            const ventH = s(Math.min(GC * 0.8, 100)); // S'adapte au Ground Clearance
            
            if (GC > 20) { // On ne dessine l'ouverture que s'il y a assez d'espace
                entities.push(engine.createLine(cx - ventW/2, groundY, cx - ventW/2, groundY + ventH, "SUPPORTS"));
                entities.push(engine.createLine(cx + ventW/2, groundY, cx + ventW/2, groundY + ventH, "SUPPORTS"));
                entities.push(engine.createLine(cx - ventW/2, groundY + ventH, cx + ventW/2, groundY + ventH, "SUPPORTS"));
            }
            return;
        }

        // CAS 2 : PIEDS CONSOLES (Inversés vers l'intérieur)
        const footWidth = s(120);  
        const plateWidth = s(160); 
        const plateHeight = s(10);

        const drawSideConsole = (side) => {
            const xOuter = cx + (side * s(R)); 
            const xInner = xOuter - (side * footWidth);
            const weldTopY = shellBottomY + s(150);
            const contactFondY = vesselBottomY - s(f/3);

            // Face verticale extérieure contre virole
            entities.push(engine.createLine(xOuter, groundY, xOuter, weldTopY, "SUPPORTS"));
            // Face verticale intérieure
            entities.push(engine.createLine(xInner, groundY, xInner, contactFondY, "SUPPORTS"));
            // Pente vers l'intérieur
            entities.push(engine.createLine(xInner, contactFondY, xOuter, shellBottomY, "SUPPORTS"));

            const pX = xOuter - (side * (footWidth / 2));
            entities.push(engine.createLine(pX - plateWidth/2, groundY, pX + plateWidth/2, groundY, "SUPPORTS"));
            entities.push(engine.createLine(pX - plateWidth/2, groundY - plateHeight, pX + plateWidth/2, groundY - plateHeight, "SUPPORTS"));
        };

        const drawCenterFoot = () => {
            const xL = cx - (footWidth / 2);
            const xR = cx + (footWidth / 2);
            const yTop = vesselBottomY;
            entities.push(engine.createLine(xL, groundY, xL, yTop, "SUPPORTS"));
            entities.push(engine.createLine(xR, groundY, xR, yTop, "SUPPORTS"));
            entities.push(engine.createLine(xL, yTop, xR, yTop, "SUPPORTS"));
            entities.push(engine.createLine(cx - plateWidth/2, groundY, cx + plateWidth/2, groundY, "SUPPORTS"));
        };

        drawSideConsole(-1); 
        drawSideConsole(1);  
        drawCenterFoot();    
    }
};