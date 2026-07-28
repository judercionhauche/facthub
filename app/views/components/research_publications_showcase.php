<?php
/**
 * Research & Publications Showcase Component
 * Displays research projects and publications with multi-member teams
 * Optional fields: funding, timeline
 */

require_once __DIR__ . '/../../services/ResearchPublicationsService.php';

$service = new ResearchPublicationsService($GLOBALS['conn']);
$research = $service->listResearchProjects();
$publications = $service->listPublications();

if (empty($research) && empty($publications)) {
    return; // Don't display if no content
}
?>

<style>
  .research-pub-showcase {
    margin-bottom: 60px;
  }

  .rp-section {
    margin-bottom: 80px;
  }

  .rp-section-head h3 {
    font-weight: 800;
    font-size: 1.8rem;
    line-height: 1.1;
    margin-bottom: 8px;
  }

  .rp-section-head p {
    color: #60706a;
    font-size: 1.05rem;
    max-width: 560px;
  }

  .rp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 24px;
    margin-top: 40px;
  }

  .rp-card {
    background: #ffffff;
    border: 1px solid rgba(26, 107, 90, 0.16);
    border-radius: 12px;
    padding: 32px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
  }

  .rp-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 18px 40px -22px rgba(26, 107, 90, 0.4);
  }

  .rp-card-header {
    margin-bottom: 24px;
  }

  .rp-card-type {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #1a6b5a;
    margin-bottom: 12px;
  }

  .rp-card-title {
    font-size: 1.3rem;
    font-weight: 700;
    line-height: 1.3;
    color: #1c2a24;
    margin-bottom: 12px;
  }

  .rp-card-team {
    font-size: 0.95rem;
    color: #60706a;
    margin-bottom: 16px;
  }

  .rp-card-team strong {
    color: #1c2a24;
    font-weight: 600;
  }

  .rp-card-description {
    font-size: 0.95rem;
    color: #1c2a24;
    line-height: 1.6;
    margin-bottom: 20px;
  }

  .rp-card-meta {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 0.9rem;
    color: #60706a;
    border-top: 1px solid rgba(26, 107, 90, 0.1);
    padding-top: 16px;
  }

  .rp-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .rp-meta-label {
    font-weight: 600;
    color: #1a6b5a;
    min-width: 80px;
  }

  .rp-card-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #1a6b5a;
    font-weight: 600;
    text-decoration: none;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid rgba(26, 107, 90, 0.1);
  }

  .rp-card-link:hover {
    color: #11473b;
  }

  .rp-card-link::after {
    content: "→";
    transition: transform 0.3s ease;
  }

  .rp-card-link:hover::after {
    transform: translateX(4px);
  }

  @media (max-width: 768px) {
    .rp-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="research-pub-showcase">
  <?php if (!empty($research)): ?>
  <div class="rp-section">
    <div class="rp-section-head reveal">
      <span class="eyebrow">Research Portfolio</span>
      <h3>Convergence research for innovative food systems solutions</h3>
      <p>Active and completed research initiatives advancing food system sustainability across the alliance.</p>
    </div>

    <div class="rp-grid">
      <?php foreach ($research as $project): ?>
      <div class="rp-card reveal">
        <div class="rp-card-header">
          <div class="rp-card-type">Research Project</div>
          <h4 class="rp-card-title"><?= htmlspecialchars($project['title']) ?></h4>
        </div>

        <?php if (!empty($project['team_members'])): ?>
        <div class="rp-card-team">
          <strong>Research Team:</strong><br>
          <?= htmlspecialchars($project['team_members']) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($project['description'])): ?>
        <div class="rp-card-description">
          <?= htmlspecialchars(mb_substr($project['description'], 0, 200)) ?>
          <?= strlen($project['description']) > 200 ? '...' : '' ?>
        </div>
        <?php endif; ?>

        <div class="rp-card-meta">
          <?php if (!empty($project['status'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Status:</span>
            <span><?= htmlspecialchars(ucfirst($project['status'])) ?></span>
          </div>
          <?php endif; ?>

          <?php if (!empty($project['start_year']) || !empty($project['end_year'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Timeline:</span>
            <span>
              <?= $project['start_year'] ?? '' ?>
              <?= (!empty($project['start_year']) && !empty($project['end_year'])) ? '–' : '' ?>
              <?= $project['end_year'] ?? '' ?>
            </span>
          </div>
          <?php endif; ?>

          <?php if (!empty($project['funder_name'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Funder:</span>
            <span><?= htmlspecialchars($project['funder_name']) ?></span>
          </div>
          <?php endif; ?>

          <?php if (!empty($project['grant_amount'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Amount:</span>
            <span>$<?= number_format($project['grant_amount'], 0) ?></span>
          </div>
          <?php endif; ?>

          <?php if (!empty($project['grant_id'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Grant ID:</span>
            <span><?= htmlspecialchars($project['grant_id']) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($publications)): ?>
  <div class="rp-section">
    <div class="rp-section-head reveal">
      <span class="eyebrow">Knowledge Generation</span>
      <h3>Publications from the FACT Alliance</h3>
      <p>Research outputs and publications from alliance research teams and collaborators.</p>
    </div>

    <div class="rp-grid">
      <?php foreach ($publications as $pub): ?>
      <div class="rp-card reveal">
        <div class="rp-card-header">
          <div class="rp-card-type">Publication</div>
          <h4 class="rp-card-title"><?= htmlspecialchars($pub['title']) ?></h4>
        </div>

        <?php if (!empty($pub['team_members'])): ?>
        <div class="rp-card-team">
          <strong>Authors:</strong><br>
          <?= htmlspecialchars($pub['team_members']) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($pub['description'])): ?>
        <div class="rp-card-description">
          <?= htmlspecialchars(mb_substr($pub['description'], 0, 200)) ?>
          <?= strlen($pub['description']) > 200 ? '...' : '' ?>
        </div>
        <?php endif; ?>

        <div class="rp-card-meta">
          <?php if (!empty($pub['publication_year'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Year:</span>
            <span><?= htmlspecialchars($pub['publication_year']) ?></span>
          </div>
          <?php endif; ?>

          <?php if (!empty($pub['funder_name'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Funder:</span>
            <span><?= htmlspecialchars($pub['funder_name']) ?></span>
          </div>
          <?php endif; ?>

          <?php if (!empty($pub['grant_amount'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Amount:</span>
            <span>$<?= number_format($pub['grant_amount'], 0) ?></span>
          </div>
          <?php endif; ?>

          <?php if (!empty($pub['grant_id'])): ?>
          <div class="rp-meta-item">
            <span class="rp-meta-label">Grant ID:</span>
            <span><?= htmlspecialchars($pub['grant_id']) ?></span>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($pub['url'])): ?>
        <a href="<?= htmlspecialchars($pub['url']) ?>" target="_blank" rel="noopener noreferrer" class="rp-card-link">
          Read Publication
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
