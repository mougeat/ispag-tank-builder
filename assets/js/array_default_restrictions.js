var arrayBottomHeight = {};
var restrictions = {};
var conceptionId;

let isDataLoaded = false; // Variable globale

async function setIspagTankRestrictionsValue() {
  if (isDataLoaded) return;

  try {
    const response = await fetch(ISPAG_TANK.jsonUrl);
    if (!response.ok) throw new Error('JSON loading error');

    const data = await response.json();
    arrayBottomHeight = data.arrayBottomHeight;
    restrictions = data.restrictions;

    // Les règles (fournisseurs, valeurs autorisées et par défaut) viennent de la base ; le JSON reste le repli
    try {
      const rulesResponse = await fetch(ISPAG_TANK.ajax_url + '?action=ispag_get_tank_rules');
      const rules = rulesResponse.ok ? await rulesResponse.json() : null;
      const fromDb = rules && rules.success && rules.data ? rules.data.restrictions : null;
      if (fromDb && fromDb.typ && Object.keys(fromDb.typ).length > 0) {
        restrictions = fromDb;
      }
    } catch (e) {
      console.warn('Tank rules : lecture en base impossible, utilisation du JSON', e);
    }

    // 👇 Déclencher un événement personnalisé quand les données sont prêtes
    isDataLoaded = true;
    jQuery(document).trigger('ispag:restrictions_loaded');
  } catch (error) {
    console.error('Error lors du chargement des données:', error);
  }
}