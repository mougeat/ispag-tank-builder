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
        // Une règle absente en base (aucune valeur autorisée enregistrée pour un type, ni isolation) retombe sur le JSON :
        // sans cela, un type sans liste en base perdrait toutes ses restrictions (ex. un chauffe-eau accepterait l'acier)
        const json = data.restrictions || {};
        Object.keys(fromDb.typ).forEach(function (id) {
          const entry = fromDb.typ[id];
          if (!entry.restrictions && json.typ && json.typ[id] && json.typ[id].restrictions) {
            entry.restrictions = json.typ[id].restrictions;
          }
          if (!entry.default && json.typ && json.typ[id] && json.typ[id].default) {
            entry.default = json.typ[id].default;
          }
        });
        if (!fromDb.insulation || Object.keys(fromDb.insulation).length === 0) {
          fromDb.insulation = json.insulation || {};
        }
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