window.DxfLayout = {
    drawFrame: function(entities, x1, x2, y1, y2) {
        entities.push(window.DxfUtils.createLine(x1, y1, x2, y1, "CADRE"));
        entities.push(window.DxfUtils.createLine(x2, y1, x2, y2, "CADRE"));
        entities.push(window.DxfUtils.createLine(x2, y2, x1, y2, "CADRE"));
        entities.push(window.DxfUtils.createLine(x1, y2, x1, y1, "CADRE"));
    },
    drawTable: function(entities, x, y, w, data) {
        const rowH = 75; 
        const colW = [150, 1150, 250, 250];
        let curY = y;
        const drawRow = (rowData, isHeader) => {
            let curX = x;
            rowData.forEach((txt, i) => {
                // Sécurité : on s'assure que txt est bien une chaîne et on nettoie les symboles
                // let cleanTxt = String(txt).replace('°', ' deg'); 
                // cleanTxt = String(cleanTxt).replace('Ø', ' diam'); 
                
                window.DxfUtils.addText(entities, curX + 20, curY - (rowH/2), txt, 0, "TABLEAU", isHeader ? 26 : 22, 4);
                curX += colW[i];
            });
            curY -= rowH;
            entities.push(window.DxfUtils.createLine(x, curY, x + w, curY, "TABLEAU"));
        };
        drawRow(["ID", "DESCRIPTION", "ELEVATION", "ANGLE"], true);
        data.forEach(d => drawRow(d, false));
        let tx = x;
        entities.push(window.DxfUtils.createLine(tx, y, tx, curY, "TABLEAU"));
        colW.forEach(cw => { tx += cw; entities.push(window.DxfUtils.createLine(tx, y, tx, curY, "TABLEAU")); });
    },
    drawCartouche: function(entities, x, y, w, h, dim, specs, project) {
        const l = "CARTOUCHE";
        const col1 = x + 500;
        const row = h / 5;
        const utils = window.DxfUtils;
        // On nettoie le nom de l'entreprise (on remplace les retours à la ligne par un espace)
        const clientNettoye = (project.nom_entreprise || "---").replace(/[\r\n]+/g, " ");

        // On nettoie aussi l'objet au cas où
        const objetNettoye = (project.ObjetCommande || "CUVE").replace(/[\r\n]+/g, " ");

        entities.push(utils.createLine(x, y, x + w, y, l));
        entities.push(utils.createLine(x, y + h, x + w, y + h, l));
        entities.push(utils.createLine(x, y, x, y + h, l));
        entities.push(utils.createLine(x + w, y, x + w, y + h, l));
        entities.push(utils.createLine(col1, y, col1, y + h, l));

        for(let i=1; i<5; i++) entities.push(utils.createLine(x, y + (i * row), col1, y + (i * row), l));

        utils.addText(entities, x + 20, y + h - (row * 0.5), "PLAN No: " + (specs.tank_id || "---"), 0, l, 22, 4);
        utils.addText(entities, x + 20, y + h - (row * 1.5), "MATIERE: " + (dim.Matiere || "---"), 0, l, 22, 4);
        utils.addText(entities, x + 20, y + h - (row * 2.5), "VOLUME: " + (dim.Volume_L || "---") + " L", 0, l, 22, 4);
        utils.addText(entities, x + 20, y + h - (row * 3.5), "PRESSION : " + (dim.Pression_Max_bar || "---") + " bar", 0, l, 22, 4);

        const rowMid = y + 180;
        entities.push(utils.createLine(col1, rowMid, x + w, rowMid, l));
        utils.addText(entities, col1 + 25, y + h - 45, "CLIENT: " + clientNettoye, 0, l, 20, 4);
        utils.addText(entities, col1 + 25, y + h - 160, "OBJET: " + objetNettoye, 0, l, 40, 4);

        const col3 = col1 + 750;
        entities.push(utils.createLine(col3, y, col3, rowMid, l));
        utils.addText(entities, col1 + 25, rowMid - 40, "DATE: " + new Date().toLocaleDateString(), 0, l, 20, 4);
        utils.addText(entities, col1 + 25, rowMid - 95, "DESSIN: C. Barthel", 0, l, 30, 4);
        utils.addText(entities, col3 + 25, rowMid - 40, "POIDS:", 0, l, 20, 4);
        utils.addText(entities, col3 + 25, rowMid - 95, (dim.Weight_kg || "---") + " kg", 0, l, 30, 4);
    }
};