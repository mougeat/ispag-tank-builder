(function () {
  function mmToM(v) { return Number(v) / 1000; }

  function createHeadMesh(radius, segments, type, mat) {
    const g = new THREE.Group();
    const geom = new THREE.SphereGeometry(radius, segments, segments, 0, Math.PI * 2, 0, Math.PI / 2);
    if (type === 'flat') {
      const disk = new THREE.CylinderGeometry(radius, radius, 0.01, segments);
      g.add(new THREE.Mesh(disk, mat));
    } else {
      const mesh = new THREE.Mesh(geom, mat);
      const scaleY = (type === 'torispherical') ? 0.75 : 0.6;
      mesh.scale.set(1, scaleY, 1);
      g.add(mesh);
    }
    return g;
  }

  function buildTank(params) {
    const segs = 64;
    const radius = mmToM(params.diameter_mm) / 2;
    const bodyH = mmToM(params.body_height);
    const headH = mmToM(params.head_height);
    const clearance = mmToM(params.ground_clearance);

    let tankMat = new THREE.MeshStandardMaterial({ 
      color: params.material === 'acier' ? 0x777777 : 0xaaaaaa, 
      metalness: 0.5, roughness: 0.5, side: THREE.DoubleSide 
    });

    const tankGroup = new THREE.Group();
    const body = new THREE.Mesh(new THREE.CylinderGeometry(radius, radius, bodyH, segs, 1, true), tankMat);
    tankGroup.add(body);

    const topHead = createHeadMesh(radius, segs, params.head_type, tankMat);
    const botHead = createHeadMesh(radius, segs, params.head_type, tankMat);
    topHead.position.y = bodyH / 2;
    botHead.position.y = -bodyH / 2;
    botHead.rotateX(Math.PI);
    tankGroup.add(topHead, botHead);

    const supportH = bodyH / 2 + headH + clearance;
    if (params.support === '11' || params.support === 'virole') {
      const virole = new THREE.Mesh(new THREE.CylinderGeometry(radius - 0.01, radius - 0.01, supportH, segs, 1, true), tankMat);
      virole.position.y = -supportH / 2;
      tankGroup.add(virole);
    } else {
      for (let i = 0; i < 3; i++) {
        const angle = (i / 3) * Math.PI * 2;
        const foot = new THREE.Mesh(new THREE.CylinderGeometry(radius * 0.06, radius * 0.06, supportH, 16), tankMat);
        foot.position.set(Math.cos(angle) * radius * 0.85, -supportH / 2, Math.sin(angle) * radius * 0.85);
        tankGroup.add(foot);
      }
    }

    const box = new THREE.Box3().setFromObject(tankGroup);
    const offset = -box.min.y;
    tankGroup.position.y = offset;

    const root = new THREE.Group();
    root.add(tankGroup);

    addFittings(tankGroup, params, offset);
    addInsulation(tankGroup, params, offset);

    return root;
  }

  function addFittings(group, params, offset) {
    const radius = mmToM(params.diameter_mm) / 2;
    const shellT = mmToM(params.insulation_mm);
    const bodyH = mmToM(params.body_height);
    const tankMat = new THREE.MeshStandardMaterial({ color: params.material === 'acier' ? 0x777777 : 0xaaaaaa, metalness: 0.5, roughness: 0.5 });

    (params.fittings || []).forEach(fit => {
      const rExt = mmToM(fit.diameter_mm) / 2;
      const isFlange = fit.type === 'flange';
      
      // Longueur : Épaisseur isolation + 5cm si bride, sinon 12cm fixe.
      let l = isFlange ? (shellT + 0.05) : 0.12;
      if (l < 0.1) l = 0.1;

      const yLocal = mmToM(fit.height_from_ground) - offset;
      const angle = (fit.angle_deg || 0) * Math.PI / 180;
      const isOnTop = yLocal > (bodyH / 4); 

      const cyl = new THREE.Mesh(new THREE.CylinderGeometry(rExt, rExt, l, 32), tankMat);

      if (isOnTop) {
        const isCentered = (fit.angle_deg === 0 || !fit.angle_deg);
        const dist = isCentered ? 0 : radius * 0.5;
        // Ancrage dans le métal du dôme
        cyl.position.set(Math.cos(angle) * dist, yLocal + l/2 - 0.01, Math.sin(angle) * dist);
      } else {
        cyl.position.set(Math.cos(angle) * (radius + l/2), yLocal, Math.sin(angle) * (radius + l/2));
        cyl.rotation.z = Math.PI / 2;
        cyl.rotation.y = -angle;
      }
      group.add(cyl);

      if (isFlange) {
        const fR = mmToM(fit.flange_diameter_mm || fit.diameter_mm * 2) / 2;
        const flange = new THREE.Mesh(new THREE.CylinderGeometry(fR, fR, 0.015, 32), tankMat);
        if (isOnTop) {
          flange.position.set(cyl.position.x, yLocal + l - 0.01, cyl.position.z);
        } else {
          flange.position.set(Math.cos(angle) * (radius + l), yLocal, Math.sin(angle) * (radius + l));
          flange.rotation.z = Math.PI / 2;
          flange.rotation.y = -angle;
        }
        group.add(flange);
      }
    });
  }

  function addInsulation(group, params, offset) {
    const shellT = mmToM(params.insulation_mm);
    if (shellT <= 0) return;
    
    // --- LOGIQUE ISPAG : dépasse de l'épaisseur de l'isolation ---
    const tankH = mmToM(params.ground_clearance + (params.head_height * 2) + params.body_height);
    const isoH = tankH + shellT; 
    
    const iso = new THREE.Mesh(
      new THREE.CylinderGeometry(mmToM(params.diameter_mm)/2 + shellT, mmToM(params.diameter_mm)/2 + shellT, isoH, 64, 1, true),
      new THREE.MeshPhysicalMaterial({ color: 0xf3f5c1, transparent: true, opacity: 0.2, roughness: 0.8 })
    );
    
    // Calage : le bas du cylindre reste à y=0, donc on monte le centre de (isoH/2) - offset
    iso.position.y = (isoH / 2) - offset;
    group.add(iso);
  }

  function init(container) {
    const tank = buildTank({
      diameter_mm: Number(container.dataset.diameter),
      body_height: Number(container.dataset.bodyHeight),
      head_height: Number(container.dataset.headHeight),
      ground_clearance: Number(container.dataset.groundClearance),
      head_type: container.dataset.headType,
      support: container.dataset.support,
      insulation_mm: Number(container.dataset.insulation),
      material: container.dataset.material,
      fittings: JSON.parse(container.dataset.fittings || "[]")
    });

    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0xfafafa);
    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(container.clientWidth, container.clientHeight || 450);
    container.appendChild(renderer.domElement);

    const camera = new THREE.PerspectiveCamera(45, container.clientWidth / (container.clientHeight || 450), 0.1, 1000);
    scene.add(new THREE.AmbientLight(0xffffff, 0.9), new THREE.DirectionalLight(0xffffff, 0.4));
    scene.add(tank);

    const controls = new THREE.OrbitControls(camera, renderer.domElement);
    const box = new THREE.Box3().setFromObject(tank);
    const center = box.getCenter(new THREE.Vector3());
    camera.position.set(center.x + 2.5, center.y + 1, center.z + 2.5);
    controls.target.copy(center);

    function animate() { controls.update(); renderer.render(scene, camera); requestAnimationFrame(animate); }
    animate();
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.ispag-tank-3d').forEach(init);
  });
})();