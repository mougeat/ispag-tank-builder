/**
 * ISPAG DXF ENGINE - Version 8.2.0
 * - LOGIQUE : Auto-Scaling dynamique pour tenir dans le cadre
 * - VUES : Face (en haut) et Dessus (en bas) alignées à gauche
 * - TABLES : Tableau et Cartouche style Loddo (fixes à droite)
 * - FIX : Rétablissement de l'intégralité des fonctions de dessin
 */
const IspagDxfEngine = {
    config: {
        layers: {
            FONDS: { color: 250 },
            VIROLE: { color: 250 },
            SUPPORTS: { color: 251 },
            PIQUAGES: { color: 250 },
            PIQUAGES_ARRIERE: { color: 253 },
            COTATIONS: { color: 252 },
            TABLEAU: { color: 250 },
            CARTOUCHE: { color: 250 },
            CADRE: { color: 250 },
            SOUDURES: { color: 1 },
            INTERNES: { color: 252, lineType: "DASHED" }
        },
        frameW: 4200,
        frameH: 2970,
        nozzleLength: 100
    },

    generateEntities: function(specs, project = {}) {
        const dim = specs.dimensions_principales || {};
        const D = parseFloat(dim.Diametre_mm) || 1200;
        const Htot = parseFloat(dim.Hauteur_mm) || 1855;
        const R = D / 2;
        const GC = parseFloat(dim.Ground_clearance) || 50;
        const f = parseFloat(dim.Bottom_Height_mm) || 260;
        const supportType = (dim.Support || '').toLowerCase();
        
        let entities = [];

        // 1. DÉFINITION DU CADRE (A3 étendu)
        const frameX1 = 40, frameX2 = 4160, frameY1 = 40, frameY2 = 2930;
        const availableW = 2500; // Zone de dessin à gauche
        const availableH = 2800; 
        this.drawFrame(entities, frameX1, frameX2, frameY1, frameY2);

        // 2. CALCUL DU SCALE (Auto-ajustement)
        // Encombrement : Htot (face) + D (dessus) + marges
        const totalNeededH = Htot + D + 600;
        const totalNeededW = D + 600;
        
        let scale = 1.0;
        const scaleW = availableW / totalNeededW;
        const scaleH = availableH / totalNeededH;
        if (scaleW < 1 || scaleH < 1) {
            scale = Math.min(scaleW, scaleH) * 0.9;
        }

        const s = (val) => val * scale;
        const centerX = frameX1 + (availableW / 2);
        
        // Positionnement vertical : Face en haut, Dessus en bas
        const dessusY = frameY1 + s(R) + 150;
        const faceYBase = dessusY + s(R) + 300; 

        // 3. DESSIN CUVE (VUE DE FACE)
        entities.push(this.createEllipse(centerX, faceYBase + s(GC + f), s(R), f/R, Math.PI, 2 * Math.PI, "FONDS"));
        entities.push(this.createEllipse(centerX, faceYBase + s(Htot - f), s(R), f/R, 0, Math.PI, "FONDS"));
        entities.push(this.createLine(centerX - s(R), faceYBase + s(GC + f), centerX - s(R), faceYBase + s(Htot - f), "VIROLE"));
        entities.push(this.createLine(centerX + s(R), faceYBase + s(GC + f), centerX + s(R), faceYBase + s(Htot - f), "VIROLE"));

        // 4. PIQUAGES (FACE & DESSUS)
        const angleRegistry = {};
        if (specs.piquages_techniques && Array.isArray(specs.piquages_techniques)) {
            specs.piquages_techniques.forEach((p, index) => {
                const descFull = (p.Description_Complete || "").toLowerCase();
                const ang = parseFloat(p.Angle_degres || 0);
                const alt = parseFloat(p.Elevation_mm || 0);
                const dI = parseFloat(p.Bride_Int_mm || 50);
                
                const radF = (ang * Math.PI) / 180;
                const cosF = Math.cos(radF);
                const sinF = Math.sin(radF);
                const layer = cosF >= -0.001 ? "PIQUAGES" : "PIQUAGES_ARRIERE";

                // FACE
                if (alt >= Htot - 10) {
                    const xp = centerX + s(R * sinF * 0.4);
                    entities.push(this.createLine(xp - s(dI/2), faceYBase + s(Htot), xp - s(dI/2), faceYBase + s(Htot + 100), layer));
                    entities.push(this.createLine(xp + s(dI/2), faceYBase + s(Htot), xp + s(dI/2), faceYBase + s(Htot + 100), layer));
                    this.addText(entities, xp, faceYBase + s(Htot + 130), (index + 1), 0, layer, s(22), 4);
                } else {
                    const xBase = centerX + s(R * sinF);
                    const side = sinF >= 0 ? 1 : -1;
                    const xf = xBase + s(this.config.nozzleLength * (1 - Math.abs(cosF)) * side);
                    entities.push(this.createLine(xBase, faceYBase + s(alt + dI/2), xf, faceYBase + s(alt + dI/2), layer));
                    entities.push(this.createLine(xBase, faceYBase + s(alt - dI/2), xf, faceYBase + s(alt - dI/2), layer));
                    entities.push(this.createUniversalEllipse(xf, faceYBase + s(alt), s(dI/2), Math.abs(cosF), layer));
                    this.addText(entities, xf + s(35 * side), faceYBase + s(alt), (index + 1), 0, layer, s(20), 4);
                }

                // DESSUS
                const radD = ((ang - 90) * Math.PI) / 180;
                const cD = Math.cos(radD), sD = Math.sin(radD);
                entities.push(this.createLine(centerX + s(R * cD), dessusY + s(R * sD), centerX + s((R + 80) * cD), dessusY + s((R + 80) * sD), "PIQUAGES"));
                if (!angleRegistry[ang]) angleRegistry[ang] = 0;
                const distTxt = s(R + 130 + (angleRegistry[ang] * 60));
                this.addText(entities, centerX + distTxt * cD, dessusY + distTxt * sD, (index + 1), 0, "PIQUAGES", s(20), 4);
                angleRegistry[ang]++;
            });
        }

        // 5. FINITION
        entities.push(this.createUniversalEllipse(centerX, dessusY, s(R), 1, "VIROLE"));
        this.addText(entities, centerX + s(R) + 100, faceYBase + s(Htot/2), Htot + " mm", 90, "COTATIONS", s(28), 4);
        this.addText(entities, centerX, faceYBase - s(150), "%%c " + D + " mm", 0, "COTATIONS", s(28), 4);

        // 6. TABLES FIXES
        const nozzleData = (specs.piquages_techniques || []).map((p, index) => ({
            id: (index + 1).toString(),
            desc: p.Description_Complete || p.Diametre_Nominal,
            h: p.Elevation_mm + " mm",
            a: p.Angle_degres + "°"
        }));
        this.drawNozzleTable(entities, frameX2 - 1200, frameY2, 1200, nozzleData);
        this.drawLoddoCartouche(entities, 2560, 40, 1600, 520, dim, specs, project);

        return entities;
    },

    // --- FONCTIONS DE DESSIN COMPLETES ---

    drawNozzleTable: function(entities, x, y, w, data) {
        const l = "TABLEAU";
        const rowH = 75; 
        const colWidths = [150, 550, 250, 250]; 
        const headers = ["ID", "Description", "Hauteur", "Angle"];
        let currentY = y;
        entities.push(this.createLine(x, currentY, x + w, currentY, l));
        const drawRow = (rowData, isHeader) => {
            const hText = isHeader ? 26 : 22;
            let curX = x;
            rowData.forEach((txt, i) => {
                this.addText(entities, curX + 20, currentY - (rowH / 2), txt, 0, l, hText, 4);
                curX += colWidths[i];
            });
            currentY -= rowH;
            entities.push(this.createLine(x, currentY, x + w, currentY, l));
        };
        drawRow(headers, true);
        data.forEach(item => drawRow([item.id, item.desc, item.h, item.a], false));
        let curX = x;
        entities.push(this.createLine(curX, y, curX, currentY, l));
        colWidths.forEach(cw => {
            curX += cw;
            entities.push(this.createLine(curX, y, curX, currentY, l));
        });
    },

    drawLoddoCartouche: function(entities, x, y, w, h, dim, specs, project) {
        const l = "CARTOUCHE";
        const col1 = x + 500;
        const row = h / 5;
        entities.push(this.createLine(x, y, x + w, y, l));
        entities.push(this.createLine(x, y + h, x + w, y + h, l));
        entities.push(this.createLine(x, y, x, y + h, l));
        entities.push(this.createLine(x + w, y, x + w, y + h, l));
        entities.push(this.createLine(col1, y, col1, y + h, l));
        for(let i=1; i<5; i++) entities.push(this.createLine(x, y + (i*row), col1, y + (i*row), l));
        this.addText(entities, x + 20, y + h - (row*0.5), "PLAN No: " + (specs.tank_id || "---"), 0, l, 22, 4);
        this.addText(entities, x + 20, y + h - (row*1.5), "MATIERE: " + (dim.Matiere || "---"), 0, l, 22, 4);
        this.addText(entities, x + 20, y + h - (row*2.5), "VOLUME: " + (dim.Volume_L || "---") + " L", 0, l, 22, 4);
        this.addText(entities, x + 20, y + h - (row*3.5), "PRESSION : " + (dim.Pression_Max_bar || "---") + " bar", 0, l, 22, 4);
        const rowMid = y + 180;
        entities.push(this.createLine(col1, rowMid, x + w, rowMid, l));
        this.addText(entities, col1 + 25, y + h - 45, "CLIENT: " + (project.nom_entreprise || "---"), 0, l, 20, 4);
        this.addText(entities, col1 + 25, y + h - 120, "OBJET: " + (project.ObjetCommande || "CUVE"), 0, l, 40, 4);
        const col3 = col1 + 750;
        entities.push(this.createLine(col3, y, col3, rowMid, l));
        this.addText(entities, col1 + 25, rowMid - 40, "DATE: " + new Date().toLocaleDateString(), 0, l, 20, 4);
        this.addText(entities, col1 + 25, rowMid - 95, "DESSIN: C. Barthel", 0, l, 30, 4);
        this.addText(entities, col3 + 25, rowMid - 40, "POIDS:", 0, l, 20, 4);
        this.addText(entities, col3 + 25, rowMid - 95, (dim.Weight_kg || "---") + " kg", 0, l, 30, 4);
    },

    drawFrame: function(entities, x1, x2, y1, y2) {
        entities.push(this.createLine(x1, y1, x2, y1, "CADRE"));
        entities.push(this.createLine(x2, y1, x2, y2, "CADRE"));
        entities.push(this.createLine(x2, y2, x1, y2, "CADRE"));
        entities.push(this.createLine(x1, y2, x1, y1, "CADRE"));
    },

    createLine: function(x1, y1, x2, y2, layer) {
        return { type: "LINE", layer, color: (this.config.layers[layer] || {color:250}).color, start: { x: Number(x1), y: Number(y1) }, end: { x: Number(x2), y: Number(y2) } };
    },

    createEllipse: function(cx, cy, rx, ratio, s, e, layer) {
        return { type: "ELLIPSE", layer, color: (this.config.layers[layer] || {color:250}).color, center: { x: Number(cx), y: Number(cy) }, major_axis: { x: Number(rx), y: 0 }, ratio: Number(ratio), start_param: Number(s), end_param: Number(e) };
    },

    createUniversalEllipse: function(cx, cy, rV, sq, layer) {
        return { type: "ELLIPSE", layer, color: (this.config.layers[layer] || {color:250}).color, center: { x: Number(cx), y: Number(cy) }, major_axis: { x: 0, y: Number(rV) }, ratio: Math.max(0.001, Number(sq)), start_param: 0, end_param: 6.283 };
    },

    addText: function(entities, x, y, text, rot, layer, h, attachment = 1) {
        if (!text) return;
        entities.push({ 
            type: "MTEXT", layer, color: (this.config.layers[layer] || {color:250}).color, 
            point: { x: Number(x), y: Number(y) }, height: Number(h), 
            text: String(text).replace(/[^\x20-\x7E]/g, ""), rotation: Number(rot), 
            attachment: attachment, style: "ARIAL" 
        });
    }
};