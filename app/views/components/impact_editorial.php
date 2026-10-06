<?php
/**
 * Shared visual direction for the two Impact views — the public one inside
 * app/views/landing/index.php and the member one in app/views/impact/index.php.
 *
 * Both pages were built from the same vocabulary: a centred heading block
 * stacked on a grid of rounded cards, repeated three times. That rhythm is what
 * made them read as generic, so this layer changes the structure rather than
 * the palette:
 *
 *   - a masthead that opens the page like a report rather than a section
 *   - headline numbers set as a hairline-ruled data strip, not four boxes
 *   - section headings moved into a sticky left rail so the page reads as two
 *     columns — commentary beside evidence — instead of heading-then-grid
 *   - numbered sections, and a serif display face against the Work Sans body
 *
 * Loaded after each page's own <style>, so these rules win on source order and
 * the existing CSS stays untouched.
 */
?>
<style>
  .landing{
    --display:"Iowan Old Style","Charter","Bitstream Charter","Sitka Text",Cambria,Georgia,serif;
    --rule:rgba(26,107,90,.18);
  }

  /* Decorative dashes beside eyebrows — removed on both pages and in the
     research showcase, which carried its own copy of the rule. */
  .landing .eyebrow::before,
  .landing .rp-section-label::before{content:none!important}

  /* ---------- MASTHEAD ---------- */
  .ed-masthead{padding:76px 0 0}
  .ed-masthead .wrap{display:block}
  .ed-meta{
    display:flex;align-items:center;gap:14px;
    font-size:11px;letter-spacing:.22em;text-transform:uppercase;font-weight:700;
    color:var(--pine);
  }
  .ed-meta::after{content:"";flex:1;height:1px;background:var(--rule)}
  .ed-masthead h1{
    font-family:var(--display);
    font-weight:600;
    font-size:clamp(2.2rem,4vw,3.4rem);
    line-height:1.04;
    letter-spacing:-.022em;
    margin:28px 0 0;
    max-width:17ch;
  }
  .ed-masthead h1 em{font-style:italic;color:var(--pine)}
  .ed-lede{
    font-size:1.12rem;line-height:1.62;color:var(--l-muted);
    margin:22px 0 0;max-width:54ch;
  }

  /* ---------- HEADLINE NUMBERS AS A DATA STRIP ---------- */
  /* The figures carry more authority ruled off against each other than they do
     floating in four separate rounded cards. */
  .ed-strip{
    margin-top:54px;
    border-top:1px solid var(--rule);
    border-bottom:1px solid var(--rule);
    display:grid;grid-template-columns:repeat(4,1fr);
    background:none;
  }
  .ed-strip .kpi{
    background:none;border:0;border-radius:0;
    border-left:1px solid var(--rule);
    padding:30px 26px 28px;
    box-shadow:none;transform:none;
  }
  .ed-strip .kpi:first-child{border-left:0;padding-left:0}
  .ed-strip .kpi:hover{transform:none;box-shadow:none;border-color:var(--rule)}
  .ed-strip .kpi .tick{display:none}
  .ed-strip .kpi .num{
    font-family:var(--display);font-weight:600;
    font-size:clamp(2.1rem,3.4vw,3rem);letter-spacing:-.03em;color:var(--pine-deep);
  }
  .ed-strip .kpi .lbl{margin-top:10px;color:var(--ink);opacity:.72}
  .ed-strip .kpi .sub{margin-top:7px;max-width:26ch}

  /* ---------- TWO-COLUMN SECTIONS ---------- */
  /* .wrap already holds exactly two children — the heading block and the
     content — so the rail needs no markup change beyond a class on the
     section itself. */
  .ed-rail{counter-increment:edsec;padding:84px 0}
  .ed-rail > .wrap{
    display:grid;grid-template-columns:clamp(190px,22%,270px) 1fr;
    gap:clamp(32px,5vw,72px);align-items:start;
  }
  .ed-rail > .wrap > .section-head{
    position:sticky;top:96px;
    margin-bottom:0;max-width:none;
    padding-top:4px;
  }
  .ed-rail > .wrap > .section-head::before{
    content:counter(edsec,decimal-leading-zero);
    display:block;font-family:var(--display);font-size:13px;font-weight:600;
    color:var(--pine);opacity:.5;letter-spacing:.08em;margin-bottom:16px;
    padding-bottom:12px;border-bottom:1px solid var(--rule);
  }
  .ed-rail > .wrap > .section-head h2{
    font-family:var(--display);font-weight:600;
    font-size:clamp(1.55rem,2.3vw,2.05rem);line-height:1.14;letter-spacing:-.018em;
    margin:14px 0 0;
  }
  .ed-rail > .wrap > .section-head p{font-size:.95rem;line-height:1.62;margin-top:14px}

  /* The rail already names the section, so the showcase's own inner heading
     only needs to label the band beneath it. This is also what was opening a
     large gap above the first row of cards. */
  .ed-rail .rp-showcase > div{margin-bottom:56px!important}
  .ed-rail .rp-showcase > div:last-child{margin-bottom:0!important}
  .ed-rail .rp-section-head{
    margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--rule);
    display:flex;align-items:baseline;gap:16px;flex-wrap:wrap;
  }
  .ed-rail .rp-section-tagline{margin:0;font-size:12.5px}

  /* The rail already takes a column, so the students block stacks inside it:
     donut as a full-width summary band, cards below. Keeping the original
     side-by-side split here squeezed each card to about 170px. */
  .ed-rail .students-wrap{grid-template-columns:1fr;gap:20px}
  .ed-rail .donut-panel{padding:26px 30px}
  .ed-rail .donut-wrap{margin-top:14px;gap:34px}
  .ed-rail .donut-center,.ed-rail .donut-center svg{width:116px;height:116px}
  .ed-rail .donut-center .dc-num b{font-size:2rem}
  .ed-rail .student-cards{grid-template-columns:repeat(2,1fr)}
  @media(min-width:1500px){.ed-rail .student-cards{grid-template-columns:repeat(3,1fr)}}

  /* Section counter has to start on the page root, not inside a section */
  .landing{counter-reset:edsec}

  @media(max-width:900px){
    .ed-rail > .wrap{grid-template-columns:1fr;gap:28px}
    .ed-rail > .wrap > .section-head{position:static;top:auto}
    .ed-strip{grid-template-columns:1fr 1fr}
    .ed-strip .kpi:nth-child(odd){border-left:0;padding-left:0}
    .ed-strip .kpi:nth-child(n+3){border-top:1px solid var(--rule)}
  }
  @media(max-width:560px){
    .ed-strip{grid-template-columns:1fr}
    .ed-strip .kpi{border-left:0;padding-left:0;border-top:1px solid var(--rule)}
    .ed-strip .kpi:first-child{border-top:0}
    .ed-masthead{padding-top:52px}
  }
  @media(prefers-reduced-motion:reduce){
    .ed-rail > .wrap > .section-head{position:static}
  }
</style>
