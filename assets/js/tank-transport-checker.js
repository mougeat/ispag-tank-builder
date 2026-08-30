// =============================================
// 🌍 TRANSPORT CHECKER - AVEC LOGS DÉTAILLÉS
// =============================================

// 👇 Variables globales
let transportRules = {};
let transportAlertBox = null;
let isTransportCheckerInitialized = false;

// =============================================
// 1. CHARGEMENT DES RÈGLES DE TRANSPORT
// =============================================
async function loadTransportRules() {
  // console.log('📦 [TRANSPORT] Début du chargement des règles de transport...');

  try {
    // console.log('🔍 [TRANSPORT] URL des règles :', ISPAG_TRANSPORT.transportRulesUrl);
    const response = await fetch(ISPAG_TRANSPORT.transportRulesUrl);

    if (!response.ok) {
      throw new Error(`Erreur HTTP ${response.status}: ${response.statusText}`);
    }

    transportRules = await response.json();
    // console.log('✅ [TRANSPORT] Règles de transport chargées avec succès:', transportRules);
    return true;
  } catch (error) {
    console.error('❌ [TRANSPORT] Erreur lors du chargement des règles:', error);

    // 👇 Utiliser des valeurs par défaut
    transportRules = {
      standard_limits: {
        max_width: 2550,
        max_length: 13500,
        max_weight: 40000
      },
      exceptional_limits: {
        simple_permit: {
          max_width: 3000,
          max_length: 25000
        }
      },
      messages: ISPAG_TRANSPORT.messages || {
        standard: "Transport standard possible en Suisse.",
        exceptional_simple: "Transport exceptionnel nécessitant une autorisation simple.",
        exceptional_complex: "Transport exceptionnel nécessitant une autorisation spéciale (escorte possible)."
      }
    };
    // console.warn('⚠️ [TRANSPORT] Utilisation des règles par défaut:', transportRules);
    return false;
  }
}

// =============================================
// 2. CRÉATION DE LA BOÎTE D'ALERTE
// =============================================
function createTransportAlertBox() {
  // console.log('🎨 [TRANSPORT] Création de la boîte d\'alerte...');

  if (transportAlertBox) {
    // console.warn('⚠️ [TRANSPORT] La boîte d\'alerte existe déjà. Annulation.');
    return;
  }

  transportAlertBox = document.createElement('div');
  transportAlertBox.id = 'tank-transport-alert';
  transportAlertBox.style.cssText = `
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: var(--ispag-btn-border-radius);
    padding: 12px;
    margin-top: 15px;
    display: none;
    font-size: 14px;
    color: #856404;
  `;

  const icon = document.createElement('span');
  icon.className = 'dashicons dashicons-warning';
  icon.style.marginRight = '8px';
  icon.style.color = '#ffc107';

  const message = document.createElement('span');
  message.id = 'tank-transport-alert-message';

  transportAlertBox.appendChild(icon);
  transportAlertBox.appendChild(message);

  const dimensionsForm = document.getElementById('tank-dimensions-form');
  if (dimensionsForm) {
    dimensionsForm.prepend(transportAlertBox);
    // console.log('✅ [TRANSPORT] Boîte d\'alerte ajoutée au formulaire.');
  } else {
    console.error('❌ [TRANSPORT] Formulaire tank-dimensions-form introuvable !');
  }
}

// =============================================
// 3. VÉRIFICATION DES RÈGLES DE TRANSPORT
// =============================================
function checkTransportRequirements(diameter, height, volume) {
  // console.log('🔍 [TRANSPORT] Vérification des règles pour:', { diameter, height, volume });

  if (!transportRules.standard_limits) {
    console.error('❌ [TRANSPORT] transportRules.standard_limits est indéfini !');
    return { isExceptional: false, message: "", type: "standard" };
  }

  const width = parseFloat(diameter) || 0;
  const length = parseFloat(height) || 0;
  const estimatedWeight = estimateTankWeight(volume);

  // console.log('📏 [TRANSPORT] Dimensions calculées:', {
  //   width,
  //   length,
  //   estimatedWeight
  // });

  const isWidthStandard = width <= transportRules.standard_limits.max_width;
  const isLengthStandard = length <= transportRules.standard_limits.max_length;
  const isWeightStandard = estimatedWeight <= transportRules.standard_limits.max_weight;

  // console.log('📊 [TRANSPORT] Résultats des vérifications:', {
  //   isWidthStandard,
  //   isLengthStandard,
  //   isWeightStandard
  // });

  if (isWidthStandard && isLengthStandard && isWeightStandard) {
    const result = {
      isExceptional: false,
      message: transportRules.messages.standard,
      type: "standard"
    };
    // console.log('✅ [TRANSPORT] Transport standard:', result);
    return result;
  } else if (
    width <= transportRules.exceptional_limits.simple_permit.max_width &&
    length <= transportRules.exceptional_limits.simple_permit.max_length
  ) {
    const result = {
      isExceptional: true,
      message: transportRules.messages.exceptional_simple,
      type: "exceptional_simple"
    };
    // console.log('⚠️ [TRANSPORT] Transport exceptionnel simple:', result);
    return result;
  } else {
    const result = {
      isExceptional: true,
      message: transportRules.messages.exceptional_complex,
      type: "exceptional_complex"
    };
    // console.log('🚨 [TRANSPORT] Transport exceptionnel complexe:', result);
    return result;
  }
}

// =============================================
// 4. ESTIMATION DU POIDS
// =============================================
function estimateTankWeight(volume) {
  const volumeLiters = parseFloat(volume) || 0;
  const weight = volumeLiters * 1.5;
  // console.log('⚖️ [TRANSPORT] Poids estimé pour volume', volume, ':', weight, 'kg');
  return weight;
}

// =============================================
// 5. MISE À JOUR DE L'ALERTE
// =============================================
function updateTransportAlert() {
  // console.log('🔄 [TRANSPORT] Mise à jour de l\'alerte...');

  const diameterSelect = document.querySelector('select[name="tank[diameter]"]');
  const heightInput = document.querySelector('input[name="tank[height]"]');
  const volumeInput = document.querySelector('input[name="tank[volume]"]');

  if (!diameterSelect || !heightInput || !volumeInput) {
    if (transportAlertBox) transportAlertBox.style.display = 'none';
    return;
  }

  const diameter = diameterSelect.value;
  const height = heightInput.value;
  const volume = volumeInput.value;

  if (!diameter || !height || !volume) {
    if (transportAlertBox) transportAlertBox.style.display = 'none';
    return;
  }

  const result = checkTransportRequirements(diameter, height, volume);

  // Vérifier que transportAlertBox existe, sinon le recréer
  if (!transportAlertBox) {
    createTransportAlertBox();
  }

  const alertMessage = document.getElementById('tank-transport-alert-message');
  if (!alertMessage) {
    console.error('❌ [TRANSPORT] Élément tank-transport-alert-message introuvable !');
    return;
  }

  if (result.isExceptional) {
    alertMessage.textContent = result.message;
    transportAlertBox.style.display = 'block';
    transportAlertBox.className = `tank-transport-alert ${result.type}`;
  } else {
    if (transportAlertBox) transportAlertBox.style.display = 'none';
  }
}

// =============================================
// 6. CONFIGURATION DES ÉCOUTEURS
// =============================================
function setupTransportEventListeners() {
  // console.log('🎧 [TRANSPORT] Configuration des écouteurs d\'événements...');

  // 👇 Écouteur pour les changements (select, input)
  document.addEventListener('change', function(e) {
    if (e.target.matches('select[name="tank[diameter]"], input[name="tank[height]"], input[name="tank[volume]"]')) {
      // console.log('🔄 [TRANSPORT] Changement détecté sur:', e.target.name, '->', e.target.value);
      updateTransportAlert();
    }
  });

  // 👇 Écouteur pour les entrées (input)
  document.addEventListener('input', function(e) {
    if (e.target.matches('input[name="tank[height]"], input[name="tank[volume]"]')) {
      // console.log('✏️ [TRANSPORT] Entrée détectée sur:', e.target.name, '->', e.target.value);
      updateTransportAlert();
    }
  });

  // console.log('✅ [TRANSPORT] Écouteurs configurés avec succès.');
}

// =============================================
// 7. INITIALISATION DU VÉRIFICATEUR
// =============================================
async function initTransportChecker() {
  if (isTransportCheckerInitialized) {
    console.warn('⚠️ [TRANSPORT] Le vérificateur est déjà initialisé. Annulation.');
    return;
  }

  // console.log('🚀 [TRANSPORT] Initialisation du vérificateur...');

  await loadTransportRules();
  createTransportAlertBox();
  setupTransportEventListeners();
  updateTransportAlert();

  isTransportCheckerInitialized = true;
  // console.log('✅ [TRANSPORT] Vérificateur initialisé avec succès.');
}

// =============================================
// 8. RÉINITIALISATION (POUR LES MODALES RÉUTILISÉES)
// =============================================
function resetTransportChecker() {
  // console.log('🔄 [TRANSPORT] Réinitialisation du vérificateur...');
  isTransportCheckerInitialized = false;
  if (transportAlertBox) {
    transportAlertBox.remove();
    transportAlertBox = null;
  }
  // console.log('✅ [TRANSPORT] Vérificateur réinitialisé.');
}

// =============================================
// 9. INITIALISATION POUR LES MODALES DYNAMIQUES
// =============================================
function initializeTransportCheckerForModal() {
  // console.log('🔍 [TRANSPORT] Recherche du formulaire tank-dimensions-form...');

  const checkFormExists = setInterval(() => {
    const dimensionsForm = document.getElementById('tank-dimensions-form');
    if (dimensionsForm) {
      clearInterval(checkFormExists);
      // console.log('✅ [TRANSPORT] Formulaire trouvé ! Initialisation...');
      initTransportChecker();

      // 👇 Ajout de l'écouteur pour modal_loaded
      document.addEventListener('modal_loaded', () => {
        // console.log('🌐 [TRANSPORT] Événement modal_loaded détecté. Mise à jour de l\'alerte...');
        setTimeout(() => {
          updateTransportAlert();
        }, 500); // Délai pour laisser le temps au DOM de se stabiliser
      });
    }
  }, 100);

  setTimeout(() => {
    clearInterval(checkFormExists);
    if (!isTransportCheckerInitialized) {
      console.error('❌ [TRANSPORT] Formulaire tank-dimensions-form non trouvé après 5 secondes !');
    }
  }, 5000);
}

// =============================================
// 10. INITIALISATION AUTOMATIQUE (SI BESOIN)
// =============================================
// Si vous voulez aussi initialiser au chargement de la page (pour les formulaires statiques)
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    // console.log('🌐 [TRANSPORT] DOM chargé. Initialisation automatique...');
    // initializeTransportCheckerForModal();
  });
} else {
  // console.log('🌐 [TRANSPORT] DOM déjà chargé. Initialisation automatique...');
  // initializeTransportCheckerForModal();
}