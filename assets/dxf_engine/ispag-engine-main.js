window.IspagDxfEngine = {
    config: {
        layers: {
            FONDS: { color: 250 }, 
            VIROLE: { color: 250 }, 
            SUPPORTS: { color: 251 },
            PIQUAGES: { color: 250 }, 
            PIQUAGES_ARRIERE: { color: 253 }, 
            COTATIONS: { color: 3 },
            TABLEAU: { color: 250 }, 
            CARTOUCHE: { color: 250 }, 
            CADRE: { color: 250 },
            SOUDURES: { color: 1 }, // Rouge pour les soudures
            INTERNES: { color: 252 }
        }
    },

    /**
     * Point d'entrée principal pour générer le tableau d'entités DXF
     */
    generateEntities: function(specs, project = {}) {
        const dim = specs.dimensions_principales || {};
        const D = parseFloat(dim.Diametre_mm) || 1200;
        const Htot = parseFloat(dim.Hauteur_mm) || 1955;
        const R = D / 2;
        const GC = parseFloat(dim.Ground_clearance) || 330;
        const f = parseFloat(dim.Bottom_Height_mm) || 280;
        const Support = dim.Support;
        const iso = parseFloat(dim.insulationThickness) || 100;
        // On crée un objet de configuration propre pour drawNozzle
        const configGlobal = {
            tank: {
                diametre: parseFloat(dim.Diametre_mm) || 1200,
                hauteurTotale: parseFloat(dim.Hauteur_mm) || 1955,
                groundClearance: parseFloat(dim.Ground_clearance) || 330,
                bottomHeight: parseFloat(dim.Bottom_Height_mm) || 280,
                insulationThickness: parseFloat(dim.insulationThickness) || 100,
                support: dim.Support || 'legs'
            }
        };
        
        let entities = [];

        // Définition des zones du cadre (Standard A0/A1 selon votre config)
        const frameX1 = 40, frameX2 = 4160, frameY1 = 40, frameY2 = 2930;
        const availableW = 2400; 
        
        // 1. Dessin du cadre (via DxfLayout externe si présent)
        if (window.DxfLayout && window.DxfLayout.drawFrame) {
            window.DxfLayout.drawFrame(entities, frameX1, frameX2, frameY1, frameY2);
        }

        // 2. Calcul de l'échelle automatique
        const totalNeededH = Htot + D + 800;
        let scale = Math.min(availableW / (D + 600), 2800 / totalNeededH, 1.0) * 0.8;
        const s = (v) => v * scale;
        
        // 3. Calcul des points de référence verticaux
        const centerX = frameX1 + (availableW / 2);
        const dessusY = frameY1 + s(R) + 300; // Centre vue de dessus
        const faceYBase = dessusY + s(R) + 500; // Niveau du SOL pour la vue de face

        // 4. Génération de la géométrie
        if (window.TankGeometry) {
            // Dessin du corps, des pieds et des cotations principales
            window.TankGeometry.drawVesselBody(this, entities, centerX, faceYBase, R, Htot, f, GC, s, dessusY, Support);
            
            // Dessin de la vue de dessus
            window.TankGeometry.drawTopView(this, entities, centerX, dessusY, R, s);

            // Dans generateEntities, section 4 :
            if (specs.piquages_techniques) {
                specs.piquages_techniques.forEach((p, i) => {
                    if (p.Type_raccord_label === "Welding") {
                        window.TankGeometry.drawWelding(this, entities, centerX, faceYBase, R, parseFloat(p.Elevation_mm), s);
                    } else {
                        // On passe bien l'index 'i' pour avoir les repères 1, 2, 3...
                        window.TankGeometry.drawNozzle(this, entities, centerX, faceYBase, dessusY, R, p, i, s, configGlobal);
                        // Si c'est un bend pipe, on rajoute la partie intérieure
                        if (p.Accessories_label === "bend pipe") {
                            window.TankGeometry.drawBendPipe(this, entities, centerX, faceYBase, R, p, s, configGlobal);
                        }
                    }
                });
            }
        }

        // 5. Ajout des éléments de mise en page (Tableau des buses et Cartouche)
        if (window.DxfLayout) {
            const nozzleData = (specs.piquages_techniques || [])
                .filter(p => p && p.Type_raccord_label !== "Welding")
                .map((p, i) => [
                    (i + 1).toString(), 
                    (p.Description_Complete || "Raccord"), 
                    (p.Elevation_mm || "0") + " mm", 
                    (p.Angle_degres || "0") + "°"
                ]);
             
            if (window.DxfLayout.drawTable) {
                window.DxfLayout.drawTable(entities, frameX2 - 1840, frameY2 - 100, 1200, nozzleData);
            }
            if (window.DxfLayout.drawCartouche) {
                window.DxfLayout.drawCartouche(entities, frameX2 - 1640, frameY1 + 40, 1600, 520, dim, specs, project);
            }
        }

        return entities;
    },

    /* --- FONCTIONS DE CRÉATION D'OBJETS DXF (Compatibles downloadDxfFile) --- */

    createLine: function(x1, y1, x2, y2, layer) {
        return { 
            type: 'LINE', 
            layer: layer, 
            start: { x: x1, y: y1 }, 
            end: { x: x2, y: y2 } 
        };
    },

    createEllipse: function(cx, cy, rx, rat, s, e, layer) {
        return { 
            type: 'ELLIPSE', 
            layer: layer, 
            center: { x: cx, y: cy }, 
            major_axis: { x: rx, y: 0 }, 
            ratio: rat, 
            start_param: s, 
            end_param: e 
        };
    },

    addText: function(ent, x, y, txt, rot, layer, h, att) {
        ent.push({ 
            type: 'MTEXT', 
            layer: layer, 
            point: { x: x, y: y }, 
            text: this.cleanTex(String(txt)), 
            rotation: rot, 
            height: h, 
            attachment: att || 1 
        });
    },
    cleanTex: function(txt) {
        if (txt === null || txt === undefined) return "";
        
        return String(txt)
            // 1. Suppression du caractère parasite Â (souvent lié à l'encodage des symboles)
            .replace(/Â/g, '') 
            .replace(/[\xC2\xA0]/g, '')
            
            // 2. Symboles de diamètres et angles
            .replace(/°/g, ' deg')
            .replace(/Ø/g, ' diam')
            
            // 3. Fractions
            .replace(/½/g, '1/2')
            .replace(/¼/g, '1/4')
            .replace(/¾/g, '3/4')
            
            // 4. Nettoyage technique
            .replace(/[\r\n]+/g, " ") 
            .replace(/\^/g, "\\^")
            .replace(/[^\x00-\x7F]/g, "")
            .trim();
    },

    /**
     * Dessine une ligne de cote avec flèches et texte
     */
    createDimension: function(ent, x1, y1, x2, y2, val, layer) {
        // Ligne principale
        ent.push(this.createLine(x1, y1, x2, y2, layer));
        
        // Dessin des flèches (traits à 45°)
        const g = 30;
        ent.push(this.createLine(x1, y1, x1 + g, y1 + g, layer));
        ent.push(this.createLine(x1, y1, x1 - g, y1 - g, layer));
        ent.push(this.createLine(x2, y2, x2 + g, y2 + g, layer));
        ent.push(this.createLine(x2, y2, x2 - g, y2 - g, layer));
        
        // Texte de la cote
        const mx = (x1 + x2) / 2;
        const my = (y1 + y2) / 2;
        this.addText(ent, mx, my + 35, val, 0, layer, 45, 2);
    }
};
