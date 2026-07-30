<?php
/**
 * Research & Publications Showcase Component
 * Premium 2x2 matrix with subtitles and team member dot separators
 */

require_once __DIR__ . '/../../services/ResearchPublicationsService.php';

$service = new ResearchPublicationsService($conn);
$research = $service->listResearchProjects();
$publications = $service->listPublications();

if (empty($research) && empty($publications)) {
    return;
}
?>

<style>
.rp-showcase {
  padding: 0;
  margin: 0;
}

.rp-section-head {
  margin-bottom: 32px;
}

.rp-section-label {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: #1a6b5a;
}

.rp-section-label::before {
  content: "";
  width: 20px;
  height: 1px;
  background: currentColor;
  display: inline-block;
}

.rp-section-tagline {
  font-size: clamp(1.15rem, 2vw, 1.4rem);
  font-weight: 800;
  letter-spacing: -0.015em;
  color: #1c2a24;
  margin: 10px 0 0;
  line-height: 1.3;
}

.rp-matrix {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 28px;
}

.rp-card {
  background: #ffffff;
  border: 1px solid rgba(26, 107, 90, 0.12);
  border-radius: 16px;
  padding: 26px 30px;
  display: flex;
  flex-direction: column;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
  overflow: hidden;
}

.rp-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, #1a6b5a, #3fa88a);
}

.rp-card:hover {
  transform: translateY(-8px);
  box-shadow: 0 24px 48px rgba(26, 107, 90, 0.15);
  border-color: rgba(26, 107, 90, 0.25);
}

.rp-card-badge {
  display: inline-block;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: #1a6b5a;
  background: rgba(26, 107, 90, 0.08);
  padding: 6px 12px;
  border-radius: 6px;
  margin-bottom: 12px;
  width: fit-content;
}

.rp-card-title {
  font-size: 1.3rem;
  font-weight: 800;
  line-height: 1.25;
  color: #1c2a24;
  margin-bottom: 6px;
  letter-spacing: -0.015em;
}

.rp-card-subtitle {
  font-size: 0.95rem;
  color: #60706a;
  margin-bottom: 20px;
  font-weight: 500;
}

.rp-card-team-badge {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid rgba(26, 107, 90, 0.08);
  font-size: 0.78rem;
  color: #1a6b5a;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.rp-card-description {
  font-size: 0.92rem;
  line-height: 1.55;
  color: #60706a;
  margin-bottom: 14px;
  flex-grow: 1;
}

.rp-card-meta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 16px;
  padding-top: 14px;
  border-top: 1px solid rgba(26, 107, 90, 0.08);
}

.rp-meta-row {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.rp-meta-label {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: #1a6b5a;
}

.rp-meta-value {
  font-size: 0.9rem;
  color: #1c2a24;
  font-weight: 500;
}

.rp-card-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #1a6b5a;
  font-weight: 600;
  text-decoration: none;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid rgba(26, 107, 90, 0.08);
  transition: color 0.2s ease;
  font-size: 0.92rem;
}

.rp-card-link:hover {
  color: #11473b;
}

.rp-card-link::after {
  content: "↗";
  transition: transform 0.2s ease;
}

.rp-card-link:hover::after {
  transform: translateX(2px) translateY(-2px);
}

@media (max-width: 1024px) {
  .rp-matrix {
    grid-template-columns: 1fr;
    gap: 24px;
  }
}
</style>

<section class="l-section">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="eyebrow">Funded research</span>
      <h2>Projects in the field</h2>
      <p>Excellence in convergence research and scholarly publications advancing food system solutions globally.</p>
    </div>

    <div class="rp-showcase">
      <!-- Research Section -->
      <div style="margin-bottom: 72px;">
        <div class="rp-section-head reveal">
          <span class="rp-section-label">Research Projects</span>
          <p class="rp-section-tagline">Convergence research for innovative food systems solutions</p>
        </div>
        <div class="rp-matrix">
          <?php foreach ($research as $project):
            $teamDisplay = !empty($project['team_members']) ? str_replace(', ', ' · ', $project['team_members']) : '';
            // Clean description: remove "Team: " prefix if present
            $desc = $project['description'] ?? '';
            $desc = preg_replace('/\s*Team:\s*.+$/is', '', $desc);
            $desc = trim($desc);
          ?>
          <div class="rp-card reveal">
            <span class="rp-card-badge">Research Project</span>
            <h3 class="rp-card-title"><?= h($project['title']) ?></h3>

            <?php if (!empty($teamDisplay)): ?>
            <div class="rp-card-team-badge"><?= h($teamDisplay) ?></div>
            <?php endif; ?>

            <?php if (!empty($desc)): ?>
            <div class="rp-card-description">
              <?= h(mb_substr($desc, 0, 160)) ?><?= strlen($desc) > 160 ? '…' : '' ?>
            </div>
            <?php endif; ?>

            <div class="rp-card-meta">
              <?php if (!empty($project['status'])): ?>
              <div class="rp-meta-row">
                <span class="rp-meta-label">Status</span>
                <span class="rp-meta-value"><?= h(ucfirst($project['status'])) ?></span>
              </div>
              <?php endif; ?>

              <?php if (!empty($project['start_year']) || !empty($project['end_year'])): ?>
              <div class="rp-meta-row">
                <span class="rp-meta-label">Year</span>
                <span class="rp-meta-value"><?= h($project['start_year'] ?? '') ?><?= (!empty($project['start_year']) && !empty($project['end_year'])) ? '–' : '' ?><?= h($project['end_year'] ?? '') ?></span>
              </div>
              <?php endif; ?>

              <?php if (!empty($project['funder_name'])): ?>
              <div class="rp-meta-row">
                <span class="rp-meta-label">Funder</span>
                <span class="rp-meta-value"><?= h($project['funder_name']) ?></span>
              </div>
              <?php endif; ?>

              <?php if ((float)($project['grant_amount'] ?? 0) > 0): ?>
              <div class="rp-meta-row">
                <span class="rp-meta-label">Amount</span>
                <span class="rp-meta-value">$<?= number_format($project['grant_amount'], 0) ?></span>
              </div>
              <?php endif; ?>
            </div>

            <?php if (!empty($project['url'])): ?>
            <a href="<?= h($project['url']) ?>" target="_blank" rel="noopener noreferrer" class="rp-card-link">
              Learn more
            </a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Publications Section -->
      <div>
        <div class="rp-section-head reveal">
          <span class="rp-section-label">Publications</span>
          <p class="rp-section-tagline">Publications from the FACT Alliance</p>
        </div>
        <div class="rp-matrix">
          <?php foreach ($publications as $pub):
            $teamDisplay = !empty($pub['team_members']) ? str_replace(', ', ' · ', $pub['team_members']) : '';
          ?>
          <div class="rp-card reveal">
            <span class="rp-card-badge">Publication</span>
            <h3 class="rp-card-title"><?= h($pub['title']) ?></h3>

            <?php if (!empty($teamDisplay)): ?>
            <div class="rp-card-team-badge"><?= h($teamDisplay) ?></div>
            <?php endif; ?>

            <?php if (!empty($pub['description'])): ?>
            <div class="rp-card-description">
              <?= h(mb_substr($pub['description'], 0, 160)) ?><?= strlen($pub['description']) > 160 ? '…' : '' ?>
            </div>
            <?php endif; ?>

            <div class="rp-card-meta">
              <?php if (!empty($pub['publication_year'])): ?>
              <div class="rp-meta-row">
                <span class="rp-meta-label">Year</span>
                <span class="rp-meta-value"><?= h($pub['publication_year']) ?></span>
              </div>
              <?php endif; ?>

              <?php if (!empty($pub['funder_name'])): ?>
              <div class="rp-meta-row">
                <span class="rp-meta-label">Funder</span>
                <span class="rp-meta-value"><?= h($pub['funder_name']) ?></span>
              </div>
              <?php endif; ?>

              <?php if ((float)($pub['grant_amount'] ?? 0) > 0): ?>
              <div class="rp-meta-row">
                <span class="rp-meta-label">Amount</span>
                <span class="rp-meta-value">$<?= number_format($pub['grant_amount'], 0) ?></span>
              </div>
              <?php endif; ?>
            </div>

            <?php if (!empty($pub['url'])): ?>
            <a href="<?= h($pub['url']) ?>" target="_blank" rel="noopener noreferrer" class="rp-card-link">
              Read publication
            </a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
