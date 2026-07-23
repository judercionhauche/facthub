<?php
// Impact dashboard for logged-in users — shows full impact data
// Reuses all landing page styling and data loading

require_login();

// Fetch impact data (same as landing page)
if (function_exists('apply_impact_data_schema')) {
    apply_impact_data_schema($conn);
}

$landProjects = $landProposals = $landStudents = [];
$landInstitutions = 0; $landCountries = 0;

try {
    $r = $conn->query("SELECT funder, program, title, description, amount, start_year, end_year, fact_members FROM funded_projects ORDER BY amount DESC");
    if ($r) while ($row = $r->fetch_assoc()) $landProjects[] = $row;
} catch (Throwable $e) { error_log('[Impact] funded_projects fetch error: ' . $e->getMessage()); }

try {
    $r = $conn->query("SELECT funder, program, amount FROM submitted_proposals WHERE status = 'in_review'");
    if ($r) while ($row = $r->fetch_assoc()) $landProposals[] = $row;
} catch (Throwable $e) { error_log('[Impact] submitted_proposals fetch error: ' . $e->getMessage()); }

try {
    $r = $conn->query("SELECT name, level, institution, advisors FROM fact_students ORDER BY display_order, id");
    if ($r) while ($row = $r->fetch_assoc()) $landStudents[] = $row;
} catch (Throwable $e) { error_log('[Impact] fact_students fetch error: ' . $e->getMessage()); }

try {
    $r = $conn->query("SELECT metric_key, metric_value FROM impact_metrics WHERE metric_key IN ('partner_institutions','countries_represented')");
    if ($r) while ($row = $r->fetch_assoc()) {
        if ($row['metric_key'] === 'partner_institutions')  $landInstitutions = (int)$row['metric_value'];
        if ($row['metric_key'] === 'countries_represented') $landCountries = (int)$row['metric_value'];
    }
} catch (Throwable $e) { error_log('[Impact] impact_metrics fetch error: ' . $e->getMessage()); }

$fundingSecured = 0; foreach ($landProjects as $p)  $fundingSecured += (int)$p['amount'];
$pipelineAmt    = 0; foreach ($landProposals as $p) $pipelineAmt    += (int)$p['amount'];
$phdCount = 0; $mscCount = 0;
foreach ($landStudents as $s) { if ($s['level'] === 'PhD') $phdCount++; else $mscCount++; }
$studentCount = count($landStudents);
$projectCount = count($landProjects);

function land_money(int $v): string {
    if ($v >= 1000000) return '$' . rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'M';
    if ($v >= 1000)    return '$' . round($v / 1000) . 'k';
    return '$' . $v;
}
$fundingSecuredNum = round($fundingSecured / 1000000, 1);
$pipelineNum       = round($pipelineAmt / 1000000, 1);
?>

<style>
  /* Reuse all landing page styles */
  .landing{
    --ink:#1c2a24;
    --pine:#1a6b5a;
    --pine-deep:#11473b;
    --leaf:#3fa88a;
    --mint:#a9d6c6;
    --gold:#c8a85a;
    --paper:#eef3ef;
    --paper-2:#e2eae3;
    --card:#ffffff;
    --l-line:rgba(26,107,90,.16);
    --l-line-strong:rgba(26,107,90,.3);
    --l-muted:#60706a;
    --maxw:1180px;
    font-family:"Work Sans",Arial,sans-serif;
    background:var(--paper);
    color:var(--ink);
    line-height:1.55;
    font-size:15px;
    -webkit-font-smoothing:antialiased;
  }
  .landing a{color:inherit;text-decoration:none}
  .landing .wrap{max-width:var(--maxw);margin:0 auto;padding:0 32px}
  .landing .eyebrow{
    font-size:12px;letter-spacing:.2em;text-transform:uppercase;font-weight:700;
    color:var(--pine);display:inline-flex;align-items:center;gap:10px;
  }
  .landing .eyebrow::before{content:"";width:20px;height:1px;background:currentColor;display:inline-block}
  .l-section{padding:92px 0}
  .section-head{max-width:640px;margin-bottom:52px}
  .section-head h2{
    font-weight:800;font-size:clamp(1.8rem,3.5vw,2.7rem);line-height:1.08;letter-spacing:-.02em;margin:18px 0 0;
  }
  .section-head p{color:var(--l-muted);font-size:1.05rem;margin:16px 0 0;max-width:560px}
  .kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
  .kpi{
    background:var(--card);border:1px solid var(--l-line);border-radius:18px;
    padding:30px 26px 26px;position:relative;overflow:hidden;
    transition:transform .3s ease,box-shadow .3s ease;
  }
  .kpi:hover{transform:translateY(-4px);box-shadow:0 18px 40px -22px rgba(26,107,90,.4)}
  .kpi .tick{position:absolute;top:0;left:0;width:100%;height:4px}
  .kpi:nth-child(1) .tick{background:var(--gold)}
  .kpi:nth-child(2) .tick{background:var(--pine)}
  .kpi:nth-child(3) .tick{background:var(--leaf)}
  .kpi:nth-child(4) .tick{background:var(--mint)}
  .kpi .num{font-weight:800;font-size:clamp(2.3rem,4vw,3rem);line-height:1;letter-spacing:-.02em;color:var(--pine-deep)}
  .kpi .lbl{font-size:11.5px;letter-spacing:.13em;text-transform:uppercase;font-weight:700;color:var(--l-muted);margin-top:14px}
  .kpi .sub{font-size:13.5px;color:var(--l-muted);margin-top:10px;line-height:1.4}
  .charts{display:grid;grid-template-columns:1.35fr 1fr;gap:24px;margin-top:8px}
  .l-panel{background:var(--card);border:1px solid var(--l-line);border-radius:20px;padding:32px}
  .l-panel h3{font-weight:800;font-size:1.3rem;letter-spacing:-.015em;margin:0}
  .l-panel .cap{font-size:11px;letter-spacing:.15em;text-transform:uppercase;font-weight:700;color:var(--l-muted);margin-bottom:6px}
  .bars{margin-top:26px;display:flex;flex-direction:column;gap:18px}
  .bar-row .bar-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:7px;gap:14px}
  .bar-row .bar-name{font-size:14px;font-weight:500}
  .bar-row .bar-val{font-size:13px;font-weight:800;color:var(--pine);white-space:nowrap}
  .bar-track{height:12px;background:var(--paper-2);border-radius:999px;overflow:hidden}
  .bar-fill{height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,var(--gold),#dcc084);transition:width 1.1s cubic-bezier(.22,1,.36,1)}
  .bar-row:first-child .bar-fill{background:linear-gradient(90deg,var(--pine),var(--leaf))}
  .lg-legend{display:flex;gap:18px;margin-top:6px;flex-wrap:wrap}
  .lg-legend span{display:inline-flex;align-items:center;gap:7px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:600;color:var(--l-muted)}
  .lg-dot{width:10px;height:10px;border-radius:3px;display:inline-block}
  .pipe{margin-top:24px;display:flex;flex-direction:column;gap:22px}
  .pipe-item .pipe-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px}
  .pipe-item .pipe-lbl{font-size:14px;font-weight:500}
  .pipe-item .pipe-amt{font-weight:800;font-size:1.5rem;letter-spacing:-.02em}
  .pipe-track{height:16px;border-radius:999px;background:var(--paper-2);overflow:hidden}
  .pipe-fill{height:100%;width:0;border-radius:999px;transition:width 1.2s cubic-bezier(.22,1,.36,1)}
  .students-wrap{display:grid;grid-template-columns:.8fr 1.2fr;gap:24px;align-items:start}
  .donut-panel{background:var(--pine);color:#fff;border-radius:20px;padding:34px;position:relative;overflow:hidden}
  .donut-panel .cap{color:rgba(255,255,255,.65)}
  .donut-wrap{display:flex;align-items:center;gap:26px;margin-top:20px}
  .donut-center{position:relative;width:150px;height:150px;flex:none}
  .donut-center .dc-num{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
  .donut-center .dc-num b{font-weight:800;font-size:2.6rem;line-height:1}
  .donut-center .dc-num small{font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;font-weight:600;color:rgba(255,255,255,.65);margin-top:4px}
  .donut-legend{display:flex;flex-direction:column;gap:14px}
  .donut-legend .dl{display:flex;align-items:center;gap:10px}
  .donut-legend .dl b{font-size:1.3rem;font-weight:800}
  .donut-legend .dl span{font-size:13px;color:rgba(255,255,255,.8)}
  .donut-legend .dl i{width:11px;height:11px;border-radius:3px;flex:none}
  .student-cards{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .scard{background:var(--card);border:1px solid var(--l-line);border-radius:16px;padding:22px;transition:transform .25s,border-color .25s}
  .scard:hover{transform:translateY(-3px);border-color:var(--l-line-strong)}
  .scard .lvl{font-size:10px;letter-spacing:.14em;text-transform:uppercase;padding:4px 10px;border-radius:999px;display:inline-block;font-weight:800}
  .lvl.phd{background:rgba(26,107,90,.12);color:var(--pine)}
  .lvl.msc{background:rgba(200,168,90,.18);color:#8a6f2e}
  .scard h4{font-weight:800;font-size:1.18rem;margin:14px 0 4px;letter-spacing:-.015em}
  .scard .inst{font-size:13.5px;color:var(--l-muted)}
  .scard .adv{font-size:12px;color:var(--l-muted);margin-top:14px;padding-top:12px;border-top:1px solid var(--l-line);line-height:1.6}
  .scard .adv b{color:var(--pine);font-weight:800;letter-spacing:.1em;text-transform:uppercase;font-size:10px;display:block;margin-bottom:3px}
  .proj-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
  .pcard{background:var(--card);border:1px solid var(--l-line);border-radius:18px;padding:28px;display:flex;flex-direction:column;transition:transform .25s,box-shadow .25s}
  .pcard:hover{transform:translateY(-4px);box-shadow:0 20px 44px -26px rgba(26,107,90,.45)}
  .pcard.feature{background:var(--pine);color:#fff;position:relative;overflow:hidden;border-color:var(--pine)}
  .pcard.feature .p-funder,.pcard.feature .p-meta{color:rgba(255,255,255,.72)}
  .pcard.feature::after{content:"";position:absolute;right:-40px;bottom:-40px;width:160px;height:160px;border-radius:50%;background:radial-gradient(circle,rgba(169,214,198,.3),transparent 70%)}
  .p-funder{font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:700;color:var(--l-muted)}
  .p-amt{font-weight:800;font-size:2rem;letter-spacing:-.02em;margin:14px 0 2px;color:var(--pine-deep)}
  .pcard.feature .p-amt{color:var(--gold)}
  .p-title{font-size:15px;font-weight:700;line-height:1.35;margin:6px 0 0}
  .p-desc{font-size:13.5px;color:var(--l-muted);margin-top:10px;line-height:1.5;flex:1}
  .pcard.feature .p-desc{color:rgba(255,255,255,.8)}
  .p-meta{font-size:11.5px;letter-spacing:.04em;color:var(--l-muted);margin-top:18px;padding-top:14px;border-top:1px solid var(--l-line)}
  .pcard.feature .p-meta{border-top-color:rgba(255,255,255,.2)}
  .reveal{opacity:0;transform:translateY(26px);transition:opacity .7s ease,transform .7s cubic-bezier(.22,1,.36,1)}
  .reveal.in{opacity:1;transform:none}
  @media(max-width:960px){
    .kpi-grid{grid-template-columns:1fr 1fr}
    .charts{grid-template-columns:1fr}
    .students-wrap{grid-template-columns:1fr}
    .proj-grid{grid-template-columns:1fr 1fr}
  }
  @media(max-width:640px){
    .landing .wrap{padding:0 20px}
    .l-section{padding:64px 0}
    .kpi-grid,.proj-grid,.student-cards{grid-template-columns:1fr}
    .donut-wrap{flex-direction:column;align-items:flex-start}
  }
  @media(prefers-reduced-motion:reduce){
    .landing *{animation:none!important;transition:none!important}
    .reveal{opacity:1;transform:none}
    .bar-fill,.pipe-fill{transition:none}
  }
</style>

<div class="landing">

<!-- ===================== IMPACT KPIs ===================== -->
<section class="l-section">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="eyebrow">Impact at a glance</span>
      <h2>What the alliance has built together</h2>
      <p>A live view of the funding won, the researchers supported, and the institutions working side by side across the network.</p>
    </div>
    <div class="kpi-grid">
      <div class="kpi reveal"><div class="tick"></div><div class="num" data-count="<?= h($fundingSecuredNum) ?>" data-prefix="$" data-suffix="M"><?= h(land_money($fundingSecured)) ?></div><div class="lbl">Research funding secured</div><div class="sub">Across <?= $projectCount ?> funded project<?= $projectCount === 1 ? '' : 's' ?> with FACT members leading.</div></div>
      <div class="kpi reveal"><div class="tick"></div><div class="num" data-count="<?= $projectCount ?>"><?= $projectCount ?></div><div class="lbl">Funded projects</div><div class="sub">From smallholder systems to global food-trade modelling.</div></div>
      <div class="kpi reveal"><div class="tick"></div><div class="num" data-count="<?= $studentCount ?>"><?= $studentCount ?></div><div class="lbl">Students advised</div><div class="sub">PhD and Masters researchers mentored by FACT advisors.</div></div>
      <div class="kpi reveal"><div class="tick"></div><div class="num" data-count="<?= $landInstitutions ?>"><?= $landInstitutions ?></div><div class="lbl">Member institutions</div><div class="sub">Universities and labs across <?= $landCountries ?> countries.</div></div>
    </div>

    <?php if ($landProjects): ?>
    <div class="charts" style="margin-top:60px">
      <div class="l-panel reveal">
        <div class="cap">Funded research</div>
        <h3>Where the funding comes from</h3>
        <div class="bars" id="funderBars"></div>
      </div>
      <div class="l-panel reveal">
        <div class="cap">Cumulative</div>
        <h3>Funding secured over time</h3>
        <svg id="growthChart" viewBox="0 0 420 260" style="width:100%;height:auto;margin-top:18px" aria-label="Cumulative funding growth by year"></svg>
        <div class="lg-legend">
          <span><i class="lg-dot" style="background:var(--leaf)"></i>Cumulative funding ($M)</span>
        </div>
      </div>
    </div>

    <div class="l-panel reveal" style="margin-top:24px">
      <div class="cap">Secured vs. in review</div>
      <h3>An active pipeline</h3>
      <div class="pipe">
        <div class="pipe-item">
          <div class="pipe-top"><span class="pipe-lbl">Secured funding</span><span class="pipe-amt" style="color:var(--pine)"><?= h(land_money($fundingSecured)) ?></span></div>
          <div class="pipe-track"><div class="pipe-fill" data-w="100" style="background:linear-gradient(90deg,var(--pine),var(--leaf))"></div></div>
        </div>
        <?php if ($pipelineAmt > 0): ?>
        <div class="pipe-item">
          <div class="pipe-top"><span class="pipe-lbl">Proposals in review</span><span class="pipe-amt" style="color:var(--gold)"><?= h(land_money($pipelineAmt)) ?></span></div>
          <div class="pipe-track"><div class="pipe-fill" data-w="<?= $fundingSecured > 0 ? round($pipelineAmt / $fundingSecured * 100) : 100 ?>" style="background:linear-gradient(90deg,var(--gold),#dcc084)"></div></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ===================== STUDENTS ===================== -->
<?php if ($landStudents): ?>
<section class="l-section" id="network" style="padding-top:0">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="eyebrow">Researchers we support</span>
      <h2>The next generation of the field</h2>
      <p>FACT advisors mentor doctoral and Masters researchers across the alliance — the people who will carry this work forward.</p>
    </div>
    <div class="students-wrap">
      <div class="donut-panel reveal">
        <div class="cap">Students by level</div>
        <div class="donut-wrap">
          <div class="donut-center">
            <svg id="donut" viewBox="0 0 42 42" style="width:150px;height:150px;transform:rotate(-90deg)"></svg>
            <div class="dc-num"><b data-count="<?= $studentCount ?>"><?= $studentCount ?></b><small>advised</small></div>
          </div>
          <div class="donut-legend">
            <div class="dl"><i style="background:var(--leaf)"></i><span><b><?= $phdCount ?></b> &nbsp;PhD candidates</span></div>
            <div class="dl"><i style="background:var(--gold)"></i><span><b><?= $mscCount ?></b> &nbsp;Masters students</span></div>
          </div>
        </div>
      </div>
      <div class="student-cards" id="studentCards"></div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ===================== FUNDED PROJECTS ===================== -->
<?php if ($landProjects): ?>
<section class="l-section" style="padding-top:0">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="eyebrow">Funded research</span>
      <h2>Projects in the field right now</h2>
      <p>A selection of the research the alliance has helped fund, from post-harvest loss to global food-trade vulnerability.</p>
    </div>
    <div class="proj-grid" id="projCards"></div>
  </div>
</section>
<?php endif; ?>

</div><!-- /.landing -->

<script>
(function(){
const DATA = {
  fundedProjects: <?= json_encode(array_map(static function ($p) {
      return [
          'funder'  => $p['funder'],
          'program' => $p['program'],
          'title'   => $p['title'],
          'desc'    => $p['description'],
          'amount'  => (int)$p['amount'],
          'start'   => (int)$p['start_year'],
          'end'     => (int)$p['end_year'],
          'members' => $p['fact_members'],
      ];
  }, $landProjects), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  students: <?= json_encode(array_map(static function ($s) {
      return [
          'name'     => $s['name'],
          'level'    => $s['level'],
          'inst'     => $s['institution'],
          'advisors' => $s['advisors'],
      ];
  }, $landStudents), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmtMoney = v => v>=1e6 ? "$"+(v/1e6).toFixed(1).replace(/\.0$/,"")+"M" : "$"+Math.round(v/1e3)+"k";

/* Funder bars */
(function(){
  const el = document.getElementById('funderBars');
  if(!el || !DATA.fundedProjects.length) return;
  const rows = [...DATA.fundedProjects].sort((a,b)=>b.amount-a.amount);
  const max = rows[0].amount || 1;
  el.innerHTML = rows.map(r=>`
    <div class="bar-row">
      <div class="bar-top">
        <span class="bar-name">${esc(r.funder)}</span>
        <span class="bar-val">${fmtMoney(r.amount)}</span>
      </div>
      <div class="bar-track"><div class="bar-fill" data-w="${(r.amount/max*100).toFixed(1)}"></div></div>
    </div>`).join('');
})();

/* Student cards */
(function(){
  const el = document.getElementById('studentCards');
  if(!el || !DATA.students.length) return;
  el.innerHTML = DATA.students.map(s=>{
    const isPhd = /phd/i.test(s.level);
    return `<div class="scard reveal">
      <span class="lvl ${isPhd?'phd':'msc'}">${isPhd?'PhD':'Masters'}</span>
      <h4>${esc(s.name)}</h4>
      <div class="inst">${esc(s.inst)}</div>
      <div class="adv"><b>Advised by</b>${esc(s.advisors)}</div>
    </div>`;
  }).join('');
})();

/* Project cards */
(function(){
  const el = document.getElementById('projCards');
  if(!el || !DATA.fundedProjects.length) return;
  const rows = [...DATA.fundedProjects].sort((a,b)=>b.amount-a.amount).slice(0,6);
  el.innerHTML = rows.map((r,i)=>`
    <div class="pcard ${i===0?'feature':''} reveal">
      <div class="p-funder">${esc(r.funder)}${r.program?' · '+esc(r.program):''}</div>
      <div class="p-amt">${fmtMoney(r.amount)}</div>
      <div class="p-title">${esc(r.title)}</div>
      <div class="p-desc">${esc(r.desc)}</div>
      <div class="p-meta">${r.start||''}–${r.end||''} &nbsp;·&nbsp; ${esc(r.members)}</div>
    </div>`).join('');
})();

/* Growth chart */
(function(){
  const svg = document.getElementById('growthChart');
  if(!svg || !DATA.fundedProjects.length) return;
  const byYear = {};
  DATA.fundedProjects.forEach(p=>{ if(p.start) byYear[p.start]=(byYear[p.start]||0)+p.amount; });
  const years = Object.keys(byYear).map(Number).sort((a,b)=>a-b);
  if(!years.length){ svg.parentNode.style.display='none'; return; }
  let cum=0; const pts = years.map(y=>{ cum+=byYear[y]; return {y, v:cum/1e6}; });
  const W=420,H=260,padL=44,padR=16,padT=18,padB=40;
  const maxV = Math.max(1, Math.ceil(pts[pts.length-1].v));
  const x = i => padL + (W-padL-padR)*(pts.length===1?0.5:i/(pts.length-1));
  const yv = v => padT + (H-padT-padB)*(1 - v/maxV);
  let g='';
  for(let k=0;k<=maxV;k+=Math.max(1,Math.round(maxV/4))){
    const yy=yv(k);
    g+=`<line x1="${padL}" y1="${yy}" x2="${W-padR}" y2="${yy}" stroke="rgba(26,107,90,.12)" stroke-width="1"/>`;
    g+=`<text x="${padL-8}" y="${yy+4}" text-anchor="end" font-family="Work Sans, Arial, sans-serif" font-size="10" fill="#60706a">$${k}M</text>`;
  }
  pts.forEach((p,i)=>{ g+=`<text x="${x(i)}" y="${H-padB+20}" text-anchor="middle" font-family="Work Sans, Arial, sans-serif" font-size="10" fill="#60706a">${p.y}</text>`; });
  const line = pts.map((p,i)=>`${i?'L':'M'}${x(i).toFixed(1)},${yv(p.v).toFixed(1)}`).join(' ');
  const area = `M${x(0).toFixed(1)},${yv(0).toFixed(1)} ` + pts.map((p,i)=>`L${x(i).toFixed(1)},${yv(p.v).toFixed(1)}`).join(' ') + ` L${x(pts.length-1).toFixed(1)},${yv(0).toFixed(1)} Z`;
  const dots = pts.map((p,i)=>`<circle cx="${x(i).toFixed(1)}" cy="${yv(p.v).toFixed(1)}" r="4" fill="#3fa88a" stroke="#fff" stroke-width="2"/>`).join('');
  svg.innerHTML = `
    <defs><linearGradient id="ga" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#3fa88a" stop-opacity=".28"/>
      <stop offset="1" stop-color="#3fa88a" stop-opacity="0"/>
    </linearGradient></defs>
    ${g}
    <path d="${area}" fill="url(#ga)" class="growth-area" style="opacity:0;transition:opacity 1s ease .3s"/>
    <path d="${line}" fill="none" stroke="#1a6b5a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="growth-line" pathLength="1" style="stroke-dasharray:1;stroke-dashoffset:1;transition:stroke-dashoffset 1.3s cubic-bezier(.4,0,.2,1)"/>
    <g class="growth-dots" style="opacity:0;transition:opacity .6s ease .9s">${dots}</g>`;
})();

/* Donut */
(function(){
  const svg = document.getElementById('donut');
  if(!svg || !DATA.students.length) return;
  const phd = DATA.students.filter(s=>/phd/i.test(s.level)).length;
  const pct = phd/DATA.students.length*100;
  svg.innerHTML = `
    <circle cx="21" cy="21" r="15.9" fill="none" stroke="#c8a85a" stroke-width="5.5"/>
    <circle cx="21" cy="21" r="15.9" fill="none" stroke="#3fa88a" stroke-width="5.5"
      stroke-dasharray="${pct} ${100-pct}" stroke-dashoffset="25"
      class="donut-seg" style="stroke-dasharray:0 100;transition:stroke-dasharray 1.2s cubic-bezier(.4,0,.2,1)" data-d="${pct} ${100-pct}"/>`;
})();

/* Count-up */
function animateCount(el){
  const target=parseFloat(el.dataset.count);
  const prefix=el.dataset.prefix||'', suffix=el.dataset.suffix||'';
  const dur=1300, start=performance.now();
  const dec = (target%1!==0)?1:0;
  function step(now){
    const p=Math.min((now-start)/dur,1);
    const e=1-Math.pow(1-p,3);
    const val=target*e;
    el.textContent=prefix+(dec?val.toFixed(1):Math.round(val))+suffix;
    if(p<1) requestAnimationFrame(step);
    else el.textContent=prefix+(dec?target.toFixed(1):target)+suffix;
  }
  requestAnimationFrame(step);
}

/* Reveal + triggers */
const io=new IntersectionObserver((entries)=>{
  entries.forEach(e=>{
    if(!e.isIntersecting) return;
    const el=e.target;
    el.classList.add('in');
    el.querySelectorAll?.('[data-count]').forEach(n=>{ if(!n._done){n._done=1;animateCount(n);} });
    if(el.dataset && el.dataset.count!==undefined && !el._done){ el._done=1; animateCount(el); }
    io.unobserve(el);
  });
},{threshold:.2});
document.querySelectorAll('.landing .reveal').forEach(el=>io.observe(el));
document.querySelectorAll('.landing [data-count]').forEach(n=>io.observe(n));

const chartIO=new IntersectionObserver((entries)=>{
  entries.forEach(e=>{
    if(!e.isIntersecting) return;
    e.target.querySelectorAll?.('.bar-fill,.pipe-fill').forEach(b=>{ b.style.width=b.dataset.w+'%'; });
    const seg=e.target.querySelector?.('.donut-seg'); if(seg) seg.style.strokeDasharray=seg.dataset.d;
    const gl=e.target.querySelector?.('.growth-line'); if(gl) gl.style.strokeDashoffset='0';
    const ga=e.target.querySelector?.('.growth-area'); if(ga) ga.style.opacity='1';
    const gd=e.target.querySelector?.('.growth-dots'); if(gd) gd.style.opacity='1';
    chartIO.unobserve(e.target);
  });
},{threshold:.3});
document.querySelectorAll('.landing .l-panel,.landing .donut-panel').forEach(p=>chartIO.observe(p));
})();
</script>
