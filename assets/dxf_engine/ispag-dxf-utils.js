window.DxfUtils = {
    createLine: (x1, y1, x2, y2, layer) => ({
        type: "LINE", layer, start: {x: Number(x1), y: Number(y1)}, end: {x: Number(x2), y: Number(y2)}
    }),
    createEllipse: (cx, cy, rx, ratio, s, e, layer) => ({
        type: "ELLIPSE", layer, center: {x: Number(cx), y: Number(cy)}, major_axis: {x: Number(rx), y: 0}, ratio: Number(ratio), start_param: Number(s), end_param: Number(e)
    }),
    createUniversalEllipse: (cx, cy, rV, sq, layer) => ({
        type: "ELLIPSE", layer, center: {x: Number(cx), y: Number(cy)}, major_axis: {x: 0, y: Number(rV)}, ratio: Math.max(0.001, Number(sq)), start_param: 0, end_param: 6.283
    }),
    addText: (entities, x, y, txt, rot, layer, h, attach) => {
        entities.push({
            type: "MTEXT", layer, point: {x: Number(x), y: Number(y)}, height: Number(h), text: String(txt), rotation: Number(rot), attachment: attach, style: "ARIAL"
        });
    }
};