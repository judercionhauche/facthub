document.addEventListener('DOMContentLoaded', function () {
  const recipientSelect = document.querySelector('[data-sync-recipient]');
  if (recipientSelect) {
    const typeInput = document.getElementById('recipient_type');
    const emailInput = document.getElementById('recipient_email');
    const nameInput = document.getElementById('recipient_name');
    const syncRecipient = () => {
      const selected = recipientSelect.options[recipientSelect.selectedIndex];
      if (recipientSelect.value === 'network') {
        typeInput.value = 'network';
        emailInput.value = '';
        nameInput.value = '';
      } else {
        typeInput.value = 'individual';
        emailInput.value = recipientSelect.value;
        nameInput.value = selected.getAttribute('data-name') || recipientSelect.value;
      }
    };
    recipientSelect.addEventListener('change', syncRecipient);
    syncRecipient();
  }

  const fundingSelect = document.querySelector('[data-funding-select]');
  if (fundingSelect) {
    const titleInput = document.getElementById('funding_call_title');
    const syncFunding = () => {
      const option = fundingSelect.options[fundingSelect.selectedIndex];
      titleInput.value = option.getAttribute('data-title') || '';
    };
    fundingSelect.addEventListener('change', syncFunding);
    syncFunding();
  }
});

/* ── World Bank Geo Taxonomy ──────────────────────────────────────── */
const WB_GEO = {
  'East Asia & Pacific': [
    'Cambodia','China','Fiji','Indonesia','Kiribati','Korea, Rep.','Lao PDR',
    'Malaysia','Marshall Islands','Micronesia, Fed. Sts.','Mongolia','Myanmar',
    'Nauru','Palau','Papua New Guinea','Philippines','Samoa','Solomon Islands',
    'Thailand','Timor-Leste','Tonga','Tuvalu','Vanuatu','Vietnam'
  ],
  'Europe & Central Asia': [
    'Albania','Armenia','Austria','Azerbaijan','Belarus','Belgium',
    'Bosnia & Herzegovina','Bulgaria','Croatia','Cyprus','Czech Republic',
    'Denmark','Estonia','Finland','France','Georgia','Germany','Greece',
    'Hungary','Iceland','Ireland','Italy','Kazakhstan','Kosovo',
    'Kyrgyz Republic','Latvia','Lithuania','Luxembourg','Moldova',
    'Montenegro','Netherlands','North Macedonia','Norway','Poland',
    'Portugal','Romania','Russia','Serbia','Slovak Republic','Slovenia',
    'Spain','Sweden','Switzerland','Tajikistan','Turkey','Turkmenistan',
    'Ukraine','United Kingdom','Uzbekistan'
  ],
  'Latin America & Caribbean': [
    'Argentina','Belize','Bolivia','Brazil','Chile','Colombia','Costa Rica',
    'Cuba','Dominican Republic','Ecuador','El Salvador','Guatemala','Guyana',
    'Haiti','Honduras','Jamaica','Mexico','Nicaragua','Panama','Paraguay',
    'Peru','Suriname','Trinidad & Tobago','Uruguay','Venezuela'
  ],
  'Middle East & North Africa': [
    'Algeria','Bahrain','Djibouti','Egypt','Iran','Iraq','Jordan','Kuwait',
    'Lebanon','Libya','Malta','Morocco','Oman','Qatar','Saudi Arabia',
    'Syria','Tunisia','United Arab Emirates','West Bank & Gaza','Yemen'
  ],
  'North America': ['Canada','United States'],
  'South Asia': [
    'Afghanistan','Bangladesh','Bhutan','India','Maldives','Nepal',
    'Pakistan','Sri Lanka'
  ],
  'Sub-Saharan Africa': [
    'Angola','Benin','Botswana','Burkina Faso','Burundi','Cabo Verde',
    'Cameroon','Central African Republic','Chad','Comoros','Congo, Dem. Rep.',
    'Congo, Rep.',"Côte d'Ivoire",'Equatorial Guinea','Eritrea','Eswatini',
    'Ethiopia','Gabon','Gambia','Ghana','Guinea','Guinea-Bissau','Kenya',
    'Lesotho','Liberia','Madagascar','Malawi','Mali','Mauritania','Mauritius',
    'Mozambique','Namibia','Niger','Nigeria','Rwanda','São Tomé & Príncipe',
    'Senegal','Sierra Leone','Somalia','South Africa','South Sudan','Sudan',
    'Tanzania','Togo','Uganda','Zambia','Zimbabwe'
  ]
};

function buildGeoItems() {
  const items = [];
  for (const region of Object.keys(WB_GEO)) {
    items.push({ value: region, label: region, group: '\u{1F30D} Regions' });
  }
  for (const [region, countries] of Object.entries(WB_GEO)) {
    for (const c of countries) {
      items.push({ value: c, label: c, group: region });
    }
  }
  return items;
}

const FACT_CATEGORIES = [
  'Food Security, Nutrition & Health',
  'Ecosystems & Biodiversity',
  'Governance & Innovation',
  'Markets & Trade',
  'Crosscutting Themes'
];

/* ── MultiSelect Widget ───────────────────────────────────────────── */
class MultiSelect {
  constructor(container, { name, items = [], selected = [], placeholder = 'Select...' }) {
    this.el = container;
    this.name = name;
    this.items = items;
    this.sel = new Set(selected.map(String));
    this.ph = placeholder;
    this._open = false;
    this._build();
  }

  _mk(tag, cls = '') {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    return e;
  }

  _build() {
    this.el.innerHTML = '';
    this.el.classList.add('msel');

    // Trigger button
    this._btn = this._mk('div', 'msel-btn');
    this._btn.setAttribute('tabindex', '0');
    this._btn.setAttribute('role', 'button');
    this._btnLbl = this._mk('span', 'msel-btn-lbl');
    const arr = this._mk('span', 'msel-btn-arr');
    arr.innerHTML = '&#9660;';
    this._btn.append(this._btnLbl, arr);

    // Dropdown
    this._drop = this._mk('div', 'msel-drop');
    this._srch = this._mk('input', 'msel-srch');
    this._srch.type = 'text';
    this._srch.placeholder = 'Search…';
    this._body = this._mk('div', 'msel-body');
    this._drop.append(this._srch, this._body);

    // Chips + hidden inputs
    this._chips = this._mk('div', 'msel-chips');
    this._hid   = this._mk('div', 'msel-hid');

    this.el.append(this._btn, this._drop, this._chips, this._hid);

    // Events
    this._btn.addEventListener('click', () => this._toggle());
    this._btn.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this._toggle(); }
    });
    this._srch.addEventListener('input', () => this._renderList());
    // Close on outside click — capture so it fires before the button click
    document.addEventListener('click', e => {
      if (!this.el.contains(e.target)) this._closeDrop();
    }, true);
    // Stop propagation inside the dropdown so outside-click doesn't fire immediately
    this._drop.addEventListener('click', e => e.stopPropagation());

    this._refresh();
  }

  _toggle() { this._open ? this._closeDrop() : this._openDrop(); }

  _openDrop() {
    // Close all other open multiselects first
    document.querySelectorAll('.msel-drop.msel-drop-open').forEach(d => {
      if (!this.el.contains(d)) d.classList.remove('msel-drop-open');
    });
    this._open = true;
    this._drop.classList.add('msel-drop-open');
    this._btn.classList.add('msel-open');
    this._srch.value = '';
    this._renderList();
    setTimeout(() => this._srch.focus(), 10);
  }

  _closeDrop() {
    this._open = false;
    this._drop.classList.remove('msel-drop-open');
    this._btn.classList.remove('msel-open');
  }

  _renderLabel() {
    const n = this.sel.size;
    this._btnLbl.textContent = n > 0 ? `${n} selected` : this.ph;
    this._btnLbl.classList.toggle('msel-has-val', n > 0);
  }

  _renderList() {
    const q = this._srch.value.toLowerCase().trim();
    this._body.innerHTML = '';

    const groupMap = new Map();
    const groupOrder = [];
    const ungrouped  = [];

    for (const item of this.items) {
      if (item.group) {
        if (!groupMap.has(item.group)) { groupMap.set(item.group, []); groupOrder.push(item.group); }
        groupMap.get(item.group).push(item);
      } else {
        ungrouped.push(item);
      }
    }

    for (const grp of groupOrder) {
      const grpItems = groupMap.get(grp);
      const match = q
        ? grpItems.filter(i => i.label.toLowerCase().includes(q) || grp.toLowerCase().includes(q))
        : grpItems;
      if (!match.length && !grp.toLowerCase().includes(q)) continue;

      const grpEl = this._mk('div', 'msel-grp');

      // Group header
      const hd    = this._mk('div', 'msel-grp-hd');
      const gChk  = this._mk('input', 'msel-grp-chk');
      gChk.type   = 'checkbox';
      const allSel = match.every(i => this.sel.has(i.value));
      const anySel = match.some(i  => this.sel.has(i.value));
      gChk.checked       = allSel;
      gChk.indeterminate = anySel && !allSel;
      gChk.addEventListener('change', () => {
        match.forEach(i => gChk.checked ? this.sel.add(i.value) : this.sel.delete(i.value));
        this._onChange(); this._renderList();
      });
      const gName = this._mk('span', 'msel-grp-name'); gName.textContent = grp;
      const gCnt  = this._mk('span', 'msel-grp-cnt');
      const sc    = match.filter(i => this.sel.has(i.value)).length;
      if (sc) gCnt.textContent = `${sc}/${match.length}`;
      hd.append(gChk, gName, gCnt);
      grpEl.appendChild(hd);

      match.forEach(item => grpEl.appendChild(this._makeRow(item)));
      this._body.appendChild(grpEl);
    }

    // Ungrouped items
    const filtUG = q ? ungrouped.filter(i => i.label.toLowerCase().includes(q)) : ungrouped;
    filtUG.forEach(item => this._body.appendChild(this._makeRow(item)));

    if (!this._body.children.length) {
      const emp = this._mk('div', 'msel-empty');
      emp.textContent = 'No options found';
      this._body.appendChild(emp);
    }
  }

  _makeRow(item) {
    const lbl = this._mk('label', 'msel-row' + (this.sel.has(item.value) ? ' is-sel' : ''));
    const chk = this._mk('input', '');
    chk.type  = 'checkbox';
    chk.checked = this.sel.has(item.value);
    chk.addEventListener('change', () => {
      chk.checked ? this.sel.add(item.value) : this.sel.delete(item.value);
      lbl.classList.toggle('is-sel', chk.checked);
      this._onChange();
    });
    const sp = this._mk('span'); sp.textContent = item.label;
    lbl.append(chk, sp);
    return lbl;
  }

  _renderChips() {
    this._chips.innerHTML = '';
    for (const val of this.sel) {
      const item  = this.items.find(i => i.value === val);
      const label = item ? item.label : val;
      const chip  = this._mk('span', 'msel-chip');
      const txt   = this._mk('span'); txt.textContent = label;
      const rm    = this._mk('button', 'msel-rm');
      rm.type = 'button'; rm.textContent = '×'; rm.setAttribute('aria-label', `Remove ${label}`);
      rm.addEventListener('click', () => {
        this.sel.delete(val); this._onChange(); this._renderList();
      });
      chip.append(txt, rm);
      this._chips.appendChild(chip);
    }
  }

  _syncHidden() {
    this._hid.innerHTML = '';
    for (const val of this.sel) {
      const inp = this._mk('input', '');
      inp.type = 'hidden'; inp.name = `${this.name}[]`; inp.value = val;
      this._hid.appendChild(inp);
    }
  }

  _onChange()  { this._renderLabel(); this._renderChips(); this._syncHidden(); }
  _refresh()   { this._renderLabel(); this._renderList(); this._renderChips(); this._syncHidden(); }

  get selected() { return [...this.sel]; }
  clear()        { this.sel.clear(); this._onChange(); this._renderList(); }
}

/* ── FACT Subcategory Taxonomy ────────────────────────────────────── */
const FACT_SUBCATEGORIES = {
  'Food Security, Nutrition & Health': [
    'Diet diversity','Affordability of Healthy and Sustainable Diets','Food environments',
    'Forecasting food insecurity/shocks/vulnerabilities',
    'Food issues requiring solutions outside the food system','Alternative proteins',
    'Migration & displacement','Food system tipping points','Safe working environments'
  ],
  'Ecosystems & Biodiversity': [
    'Orphan crops','Water management','Biome Specialization: Drylands',
    'Biome Specialization: Forests','Biome Specialization: Aquatic',
    'Biome Specialization: Grasslands','Biome Specialization: Wetlands',
    'Biome Specialization: Other','Ecosystem services','Ecosystem restoration',
    'Nexus approaches','Commercial forestry','Pests & diseases','Soils'
  ],
  'Governance & Innovation': [
    'Innovation and change',
    'Political economy (practices, institutions, power and/or politics)',
    'Human mobility','Geopolitical crises',
    'Regulatory/reporting frameworks/standards & certification',
    'Land use and land access'
  ],
  'Markets & Trade': [
    'Supply chain risks','Inclusive & resilient value chains',
    'Food industry sustainability tracking','Trade networks & shock propagation',
    'Food trade early warning systems','Energy use'
  ],
  'Crosscutting Themes': [
    'Diversity, gender, equity, and inclusion','Social-ecological systems',
    'Finance and resource mobilization','Co-development of knowledge/co-design',
    'Data ecosystems','Business models','AI / machine learning and digital tools',
    'Stress testing the global/national food system','Scenarios and storylines',
    'Transition management'
  ]
};

/* ── FilterPanel ──────────────────────────────────────────────────── */
function FilterPanel(opts) {
  this.sb  = document.querySelector(opts.sidebarSel);
  this.sel = opts.cardSel;
  this.cnt = document.querySelector(opts.countSel);
  this.key = opts.storageKey || '';
  this._raf = 0;
  if (!this.sb) return;
  this._load();
  this._bind();
  this._syncSub();
  this._run();
}

FilterPanel.prototype._vals = function(g) {
  var out = [];
  this.sb.querySelectorAll('[data-fp-group="' + g + '"]:checked').forEach(function(c) {
    out.push(c.value.toLowerCase());
  });
  return out;
};

FilterPanel.prototype._run = function() {
  var self  = this;
  var se    = this.sb.querySelector('[data-fp="search"]');
  var ce    = this.sb.querySelector('[data-fp="coadvising"]');
  var srch  = se  ? se.value.toLowerCase().trim() : '';
  var coadv = ce  ? ce.checked : false;
  var cats  = this._vals('cats');
  var subs  = this._vals('subcats');
  var geos  = this._vals('geos');
  var tops  = this._vals('topics');
  var insts = this._vals('institutions');

  var vis = 0;
  document.querySelectorAll(this.sel).forEach(function(card) {
    var raw = card.getAttribute('data-filter') || '{}';
    var d;
    try { d = JSON.parse(raw); } catch(e) { d = {}; }

    var ok = true;

    // text search
    if (ok && srch) {
      var hay = ((d.name||'') + ' ' + (d.institution||'') + ' ' + (d.topics||[]).join(' ')).toLowerCase();
      if (hay.indexOf(srch) === -1) ok = false;
    }
    // category (OR logic)
    if (ok && cats.length) {
      var cv = (d.cats||[]).map(function(x){ return x.toLowerCase(); });
      var hit = false;
      for (var i=0;i<cats.length;i++) { if (cv.indexOf(cats[i])!==-1){hit=true;break;} }
      if (!hit) ok = false;
    }
    // subcategory (OR logic)
    if (ok && subs.length) {
      var sv = (d.subcats||[]).map(function(x){ return x.toLowerCase(); });
      var hit = false;
      for (var i=0;i<subs.length;i++) { if (sv.indexOf(subs[i])!==-1){hit=true;break;} }
      if (!hit) ok = false;
    }
    // geography (OR logic)
    if (ok && geos.length) {
      var gv = (d.geos||[]).map(function(x){ return x.toLowerCase(); });
      var hit = false;
      for (var i=0;i<geos.length;i++) { if (gv.indexOf(geos[i])!==-1){hit=true;break;} }
      if (!hit) ok = false;
    }
    // topics (OR logic)
    if (ok && tops.length) {
      var tv = (d.topics||[]).map(function(x){ return x.toLowerCase(); });
      var hit = false;
      for (var i=0;i<tops.length;i++) { if (tv.indexOf(tops[i])!==-1){hit=true;break;} }
      if (!hit) ok = false;
    }
    // institution (OR logic)
    if (ok && insts.length) {
      if (insts.indexOf((d.institution||'').toLowerCase()) === -1) ok = false;
    }
    // co-advising toggle
    if (ok && coadv && !d.coadvising) ok = false;

    card.style.display = ok ? '' : 'none';
    if (ok) vis++;
  });

  if (this.cnt) this.cnt.textContent = vis + (vis === 1 ? ' result' : ' results');
  this._save();
};

FilterPanel.prototype._sched = function() {
  var self = this;
  if (self._raf) cancelAnimationFrame(self._raf);
  self._raf = requestAnimationFrame(function(){ self._raf=0; self._run(); });
};

FilterPanel.prototype._syncSub = function() {
  var selCats = this._vals('cats');
  this.sb.querySelectorAll('[data-subcat-parent]').forEach(function(row) {
    var p = (row.getAttribute('data-subcat-parent') || '').toLowerCase();
    row.style.display = (!selCats.length || selCats.indexOf(p) !== -1) ? '' : 'none';
  });
};

FilterPanel.prototype._bind = function() {
  var self = this;

  // Delegated change — covers ALL checkboxes including JS-added geo items
  this.sb.addEventListener('change', function(e) {
    var el = e.target;
    if (!el || el.tagName !== 'INPUT') return;
    var grp = el.getAttribute('data-fp-group');
    var fp  = el.getAttribute('data-fp');
    if (!grp && !fp) return;
    if (grp === 'cats') self._syncSub();
    self._sched();
  });

  // Delegated input — covers text search
  this.sb.addEventListener('input', function(e) {
    var el = e.target;
    if (!el) return;
    if (el.getAttribute('data-fp') === 'search') self._sched();
    if (el.classList.contains('lk-geo-search')) self._geoFilter(el.value);
  });

  // Delegated click — section collapse + clear all
  this.sb.addEventListener('click', function(e) {
    var t = e.target;

    // Clear all
    var clr = t.closest ? t.closest('[data-fp="clear"]') : null;
    if (!clr && t.getAttribute && t.getAttribute('data-fp') === 'clear') clr = t;
    if (clr) {
      self.sb.querySelectorAll('input[type="checkbox"]').forEach(function(el){ el.checked = false; });
      var se = self.sb.querySelector('[data-fp="search"]');
      if (se) se.value = '';
      var gs = self.sb.querySelector('.lk-geo-search');
      if (gs) { gs.value = ''; self._geoFilter(''); }
      self._syncSub();
      self._run();
      if (self.key) { try { localStorage.removeItem(self.key); } catch(e_){} }
      return;
    }

    // Section collapse
    var hd = t.closest ? t.closest('.lk-section-hd') : null;
    if (hd) {
      hd.classList.toggle('lk-collapsed');
      var body = hd.nextElementSibling;
      if (body && body.classList.contains('lk-section-body')) body.classList.toggle('lk-collapsed');
    }
  });
};

FilterPanel.prototype._geoFilter = function(q) {
  var gi = document.getElementById('lk-geo-items');
  if (!gi) return;
  q = (q || '').toLowerCase().trim();
  var ch = gi.children;
  for (var i = 0; i < ch.length; i++) {
    var el = ch[i];
    if (el.classList.contains('lk-grp-label')) {
      el.style.display = q ? 'none' : '';
    } else {
      var chk = el.querySelector('input');
      el.style.display = (!q || (chk && chk.value.toLowerCase().indexOf(q) !== -1)) ? '' : 'none';
    }
  }
};

FilterPanel.prototype._save = function() {
  if (!this.key) return;
  try {
    var s = {
      search: (this.sb.querySelector('[data-fp="search"]')||{}).value || '',
      cats: this._vals('cats'), subcats: this._vals('subcats'),
      geos: this._vals('geos'), tops: this._vals('topics'),
      insts: this._vals('institutions'),
      coadv: !!(this.sb.querySelector('[data-fp="coadvising"]')||{}).checked
    };
    localStorage.setItem(this.key, JSON.stringify(s));
  } catch(e) {}
};

FilterPanel.prototype._load = function() {
  if (!this.key) return;
  try {
    var s = JSON.parse(localStorage.getItem(this.key) || 'null');
    if (!s) return;
    var se = this.sb.querySelector('[data-fp="search"]');
    if (se && s.search) se.value = s.search;
    var self = this;
    var map = { cats: s.cats, subcats: s.subcats, geos: s.geos, topics: s.tops, institutions: s.insts };
    Object.keys(map).forEach(function(grp) {
      var vals = map[grp];
      if (!vals || !vals.length) return;
      self.sb.querySelectorAll('[data-fp-group="' + grp + '"]').forEach(function(chk) {
        if (vals.indexOf(chk.value.toLowerCase()) !== -1) chk.checked = true;
      });
    });
    var ce = this.sb.querySelector('[data-fp="coadvising"]');
    if (ce && s.coadv) ce.checked = true;
  } catch(e) {}
};
