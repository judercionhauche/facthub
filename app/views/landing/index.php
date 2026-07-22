<?php
// Public landing page — impact dashboard for non-logged-in visitors.
// Data lives in funded_projects / submitted_proposals / fact_students,
// plus two headline values from impact_metrics. All editable in Admin → Impact Data.

if (is_logged_in()) {
    $redirectPage = ($_SESSION['user_role'] ?? '') === 'funder' ? 'funding' : 'researchers';
    redirect_to($redirectPage);
}

$landProjects = $landProposals = $landStudents = [];
$landInstitutions = 0; $landCountries = 0;
try {
    $r = $conn->query("SELECT funder, program, title, description, amount, start_year, end_year, fact_members FROM funded_projects ORDER BY amount DESC");
    if ($r) while ($row = $r->fetch_assoc()) $landProjects[] = $row;

    $r = $conn->query("SELECT funder, program, amount FROM submitted_proposals WHERE status = 'in_review'");
    if ($r) while ($row = $r->fetch_assoc()) $landProposals[] = $row;

    $r = $conn->query("SELECT name, level, institution, advisors FROM fact_students ORDER BY display_order, id");
    if ($r) while ($row = $r->fetch_assoc()) $landStudents[] = $row;

    $r = $conn->query("SELECT metric_key, metric_value FROM impact_metrics WHERE metric_key IN ('partner_institutions','countries_represented')");
    if ($r) while ($row = $r->fetch_assoc()) {
        if ($row['metric_key'] === 'partner_institutions')  $landInstitutions = (int)$row['metric_value'];
        if ($row['metric_key'] === 'countries_represented') $landCountries = (int)$row['metric_value'];
    }
} catch (Throwable $e) {
    error_log('[Landing] Impact data fetch error: ' . $e->getMessage());
}

$fundingSecured = 0; foreach ($landProjects as $p)  $fundingSecured += (int)$p['amount'];
$pipelineAmt    = 0; foreach ($landProposals as $p) $pipelineAmt    += (int)$p['amount'];
$phdCount = 0; $mscCount = 0;
foreach ($landStudents as $s) { if ($s['level'] === 'PhD') $phdCount++; else $mscCount++; }
$studentCount = count($landStudents);
$projectCount = count($landProjects);

// $5.7M-style short money format
function land_money(int $v): string {
    if ($v >= 1000000) return '$' . rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'M';
    if ($v >= 1000)    return '$' . round($v / 1000) . 'k';
    return '$' . $v;
}
$fundingSecuredNum = round($fundingSecured / 1000000, 1); // for count-up (in $M)
$pipelineNum       = round($pipelineAmt / 1000000, 1);
?>
<style>
  /* Break out of the app shell: full-bleed page, no topbar/sidebar */
  .topbar{display:none}
  .page-wrap.auth-wrap{max-width:none;padding:0;display:block}
  .main-area{min-width:0}
  .footer{position:static !important}

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
    overflow-x:hidden;
  }
  .landing a{color:inherit;text-decoration:none}
  .landing .wrap{max-width:var(--maxw);margin:0 auto;padding:0 32px}
  .landing .eyebrow{
    font-size:12px;letter-spacing:.2em;text-transform:uppercase;font-weight:700;
    color:var(--pine);display:inline-flex;align-items:center;gap:10px;
  }
  .landing .eyebrow::before{content:"";width:20px;height:1px;background:currentColor;display:inline-block}

  /* ---------- NAV ---------- */
  .l-nav{
    position:sticky;top:0;z-index:50;
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 32px;
    background:rgba(238,243,239,.92);backdrop-filter:blur(12px);
    box-shadow:0 1px 0 var(--l-line);
  }
  .l-nav .brand{display:flex;align-items:center;gap:12px;color:var(--ink)}
  .l-nav .brand img{height:34px;width:auto;display:block}
  .l-nav .brand-sub{font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--l-muted);font-weight:600;display:block;margin-top:2px}
  .l-nav .nav-actions{display:flex;align-items:center;gap:22px}
  .l-nav .nav-link{font-size:14px;font-weight:500;color:var(--ink);transition:color .2s}
  .l-nav .nav-link:hover{color:var(--pine)}
  .l-btn{
    font-family:inherit;font-size:14px;font-weight:600;
    padding:11px 20px;border-radius:999px;border:1px solid transparent;
    cursor:pointer;transition:transform .15s ease,background .2s,color .2s,border-color .2s;
    display:inline-flex;align-items:center;gap:8px;
  }
  .l-btn:hover{transform:translateY(-1px)}
  .l-btn-primary{background:var(--pine);color:#fff}
  .l-btn-primary:hover{background:var(--pine-deep);color:#fff}
  .l-btn-gold{background:var(--gold);color:#231c0d}
  .l-btn-gold:hover{background:#d8bc74;color:#231c0d}
  .l-btn-ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.45)}
  .l-btn-ghost:hover{border-color:var(--mint);color:var(--mint)}
  .l-btn-lg{padding:15px 28px;font-size:15px}

  /* ---------- HERO ---------- */
  .l-hero{
    position:relative;background:var(--pine);color:#fff;
    padding:110px 0 100px;overflow:hidden;isolation:isolate;
  }
  .l-hero::after{
    content:"";position:absolute;inset:0;z-index:-1;
    background:radial-gradient(120% 90% at 78% 12%,rgba(169,214,198,.18),transparent 55%),
               linear-gradient(180deg,rgba(17,71,59,0),rgba(17,71,59,.55));
  }
  #contours{position:absolute;inset:0;width:100%;height:100%;z-index:-2;opacity:.5}
  .l-hero-inner{position:relative;max-width:820px}
  .l-hero h1{
    font-weight:800;font-size:clamp(2.4rem,6vw,4.4rem);line-height:1.04;letter-spacing:-.025em;
    margin:22px 0 0;
  }
  .l-hero h1 em{font-style:italic;font-weight:600;color:var(--mint)}
  .l-hero p.lead{
    font-size:clamp(1.05rem,1.6vw,1.28rem);max-width:600px;
    color:rgba(255,255,255,.85);margin:26px 0 0;font-weight:400;
  }
  .l-hero .eyebrow{color:var(--mint)}
  .hero-cta{display:flex;gap:14px;flex-wrap:wrap;margin-top:38px}
  .hero-strip{
    display:flex;flex-wrap:wrap;margin-top:60px;
    border-top:1px solid rgba(255,255,255,.18);padding-top:26px;
  }
  .hero-strip .cell{padding-right:44px;margin-right:44px;border-right:1px solid rgba(255,255,255,.18)}
  .hero-strip .cell:last-child{border-right:none;margin-right:0;padding-right:0}
  .hero-strip .num{font-weight:800;font-size:2rem;line-height:1;color:#fff;letter-spacing:-.02em}
  .hero-strip .lbl{font-size:11px;letter-spacing:.15em;text-transform:uppercase;font-weight:600;color:rgba(255,255,255,.65);margin-top:8px}

  /* ---------- SECTION BASE ---------- */
  .l-section{padding:92px 0}
  .section-head{max-width:640px;margin-bottom:52px}
  .section-head h2{
    font-weight:800;font-size:clamp(1.8rem,3.5vw,2.7rem);line-height:1.08;letter-spacing:-.02em;margin:18px 0 0;
  }
  .section-head p{color:var(--l-muted);font-size:1.05rem;margin:16px 0 0;max-width:560px}

  /* ---------- KPI CARDS ---------- */
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

  /* ---------- CHART ROW ---------- */
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

  /* ---------- STUDENTS ---------- */
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

  /* ---------- FUNDED PROJECTS ---------- */
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

  /* ---------- FEATURES ---------- */
  .reach{background:var(--paper-2)}
  .feat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-top:8px}
  .feat{padding:28px;background:var(--card);border:1px solid var(--l-line);border-radius:16px}
  .feat .fi{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:var(--pine);margin-bottom:16px}
  .feat h4{font-weight:800;font-size:1.15rem;letter-spacing:-.015em;margin:0}
  .feat p{font-size:14px;color:var(--l-muted);margin:8px 0 0;line-height:1.5}

  /* ---------- CTA ---------- */
  .l-cta{position:relative;background:var(--pine-deep);color:#fff;padding:104px 0;overflow:hidden;isolation:isolate}
  #contours2{position:absolute;inset:0;width:100%;height:100%;z-index:-1;opacity:.45}
  .cta-inner{max-width:720px;position:relative}
  .l-cta .eyebrow{color:var(--mint)}
  .l-cta h2{font-weight:800;font-size:clamp(2rem,4.2vw,3.2rem);line-height:1.05;letter-spacing:-.02em;margin:18px 0 0}
  .l-cta h2 em{font-style:italic;color:var(--mint)}
  .l-cta p{color:rgba(255,255,255,.82);font-size:1.1rem;margin:22px 0 36px;max-width:520px}
  .cta-actions{display:flex;gap:18px;flex-wrap:wrap;align-items:center}
  .l-cta .txtlink{color:rgba(255,255,255,.88);font-weight:500;font-size:15px;border-bottom:1px solid rgba(255,255,255,.4);padding-bottom:2px}
  .l-cta .txtlink:hover{color:var(--mint);border-color:var(--mint)}

  /* ---------- REVEAL ---------- */
  .reveal{opacity:0;transform:translateY(26px);transition:opacity .7s ease,transform .7s cubic-bezier(.22,1,.36,1)}
  .reveal.in{opacity:1;transform:none}

  /* ---------- RESPONSIVE ---------- */
  @media(max-width:960px){
    .kpi-grid{grid-template-columns:1fr 1fr}
    .charts{grid-template-columns:1fr}
    .students-wrap{grid-template-columns:1fr}
    .proj-grid{grid-template-columns:1fr 1fr}
    .feat-grid{grid-template-columns:1fr}
  }
  @media(max-width:640px){
    .landing .wrap{padding:0 20px}
    .l-nav{padding:12px 20px}
    .l-nav .nav-link.hide-sm{display:none}
    .l-hero{padding:80px 0 70px}
    .hero-strip .cell{border-right:none;padding-right:0;margin-right:0;flex:1 0 45%;margin-bottom:22px}
    .kpi-grid,.proj-grid,.student-cards{grid-template-columns:1fr}
    .l-section{padding:64px 0}
    .donut-wrap{flex-direction:column;align-items:flex-start}
  }
  @media(prefers-reduced-motion:reduce){
    .landing *{animation:none!important;transition:none!important}
    .reveal{opacity:1;transform:none}
    .bar-fill,.pipe-fill{transition:none}
  }
</style>

<div class="landing">

<!-- ===================== NAV ===================== -->
<div class="l-nav">
  <a href="index.php?page=landing" class="brand">
    <img src="assets/fact-alliance-logo.png" alt="FACT Alliance">
    <span class="brand-sub hide-sm">An initiative of MIT J-WAFS</span>
  </a>
  <div class="nav-actions">
    <a href="#impact" class="nav-link hide-sm">Impact</a>
    <a href="#network" class="nav-link hide-sm">The network</a>
    <a href="index.php?page=login" class="nav-link">Log in</a>
    <a href="index.php?page=register" class="l-btn l-btn-primary">Join the network</a>
  </div>
</div>

<!-- ===================== HERO ===================== -->
<header class="l-hero" id="top">
  <svg id="contours" preserveAspectRatio="xMidYMid slice" aria-hidden="true"></svg>
  <div class="wrap l-hero-inner">
    <span class="eyebrow">Food security · Climate resilience · Sustainable systems</span>
    <h1>Where research on food and climate <em>finds its people.</em></h1>
    <p class="lead">The FACT Alliance Hub connects researchers, students, institutions and funders across the network — so the right collaboration, and the right funding, no longer take years to find.</p>
    <div class="hero-cta">
      <a href="index.php?page=register" class="l-btn l-btn-gold l-btn-lg">Explore the network</a>
      <a href="#impact" class="l-btn l-btn-ghost l-btn-lg">See the impact</a>
    </div>
    <div class="hero-strip">
      <div class="cell"><div class="num" data-count="<?= h($fundingSecuredNum) ?>" data-prefix="$" data-suffix="M"><?= h(land_money($fundingSecured)) ?></div><div class="lbl">Funding secured</div></div>
      <div class="cell"><div class="num" data-count="<?= $studentCount ?>"><?= $studentCount ?></div><div class="lbl">Students advised</div></div>
      <div class="cell"><div class="num" data-count="<?= $landInstitutions ?>"><?= $landInstitutions ?></div><div class="lbl">Partner institutions</div></div>
      <div class="cell"><div class="num" data-count="<?= $landCountries ?>"><?= $landCountries ?></div><div class="lbl">Countries</div></div>
    </div>
  </div>
</header>

<!-- ===================== IMPACT KPIs ===================== -->
<section class="l-section" id="impact">
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
      <div class="kpi reveal"><div class="tick"></div><div class="num" data-count="<?= $landInstitutions ?>"><?= $landInstitutions ?></div><div class="lbl">Partner institutions</div><div class="sub">Universities and labs across <?= $landCountries ?> countries.</div></div>
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

<!-- ===================== INSIDE THE HUB ===================== -->
<section class="l-section reach">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="eyebrow">Inside the hub</span>
      <h2>Built to shorten the distance between people and funding</h2>
    </div>
    <div class="feat-grid">
      <div class="feat reveal">
        <div class="fi">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="#a9d6c6" stroke-width="1.8"/><path d="m16 16 4 4" stroke="#a9d6c6" stroke-width="1.8" stroke-linecap="round"/></svg>
        </div>
        <h4>Semantic search</h4>
        <p>Ask in plain language and find the researchers, projects and calls that fit — no rigid filters required.</p>
      </div>
      <div class="feat reveal">
        <div class="fi">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 12h16M12 4l8 8-8 8" stroke="#a9d6c6" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <h4>Smart matching</h4>
        <p>Get suggested collaborators and funding calls matched to your research focus and eligibility.</p>
      </div>
      <div class="feat reveal">
        <div class="fi">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M5 5h14v14H5z" stroke="#a9d6c6" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 9h6v6H9z" stroke="#a9d6c6" stroke-width="1.8"/></svg>
        </div>
        <h4>Funding, in one place</h4>
        <p>Browse active calls filtered by topic and geography, and bookmark the ones worth returning to.</p>
      </div>
    </div>
  </div>
</section>

<!-- ===================== CTA ===================== -->
<section class="l-cta">
  <svg id="contours2" preserveAspectRatio="xMidYMid slice" aria-hidden="true"></svg>
  <div class="wrap cta-inner reveal">
    <span class="eyebrow">Join the alliance</span>
    <h2>Bring your research <em>into the network.</em></h2>
    <p>Create a profile, add your ORCID and Google Scholar, and let the hub connect you with collaborators and funding across food and climate systems.</p>
    <div class="cta-actions">
      <a href="index.php?page=register" class="l-btn l-btn-gold l-btn-lg">Register as a researcher</a>
      <a href="index.php?page=login" class="txtlink">Already a member? Log in</a>
    </div>
  </div>
</section>

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

/* ---------- FUNDER BARS ---------- */
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

/* ---------- STUDENT CARDS ---------- */
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

/* ---------- PROJECT CARDS ---------- */
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

/* ---------- GROWTH LINE (cumulative funding by year) ---------- */
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

/* ---------- DONUT (PhD vs Masters) ---------- */
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

/* ---------- TOPOGRAPHIC CONTOURS ---------- */
function blob(cx,cy,r,seed,wob){
  const N=64; let d='';
  for(let i=0;i<=N;i++){
    const t=i/N*Math.PI*2;
    const rr=r*(1 + wob*Math.sin(t*3+seed) + wob*0.5*Math.cos(t*5+seed*1.7));
    d+=(i?'L':'M')+(cx+rr*Math.cos(t)).toFixed(1)+','+(cy+rr*Math.sin(t)).toFixed(1)+' ';
  }
  return d+'Z';
}
function drawContours(id,cx,cy){
  const svg=document.getElementById(id);
  if(!svg) return;
  svg.setAttribute('viewBox','0 0 1200 700');
  let s='';
  for(let k=1;k<=11;k++){
    s+=`<path d="${blob(cx,cy,40+k*46,k*0.6,0.05)}" fill="none" stroke="#a9d6c6" stroke-width="1" opacity="${(0.5-k*0.03).toFixed(2)}"/>`;
  }
  svg.innerHTML=`<g class="contour-grp">${s}</g>`;
}
drawContours('contours',930,150);
drawContours('contours2',980,520);

if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches){
  let t=0;
  (function loop(){
    t+=0.0016;
    document.querySelectorAll('.contour-grp').forEach((g,i)=>{
      const s=1+0.02*Math.sin(t + i);
      g.setAttribute('transform',`translate(${(6*Math.cos(t*0.7+i)).toFixed(2)} ${(5*Math.sin(t*0.9+i)).toFixed(2)}) scale(${s.toFixed(4)})`);
      g.style.transformOrigin='center';
    });
    requestAnimationFrame(loop);
  })();
}

/* ---------- COUNT-UP ---------- */
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

/* ---------- REVEAL + TRIGGERS ---------- */
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
