<?php
/**
 * Breadcrumb Navigation Component
 * @param array $breadcrumbs - Array of ['title' => 'Name', 'url' => 'link'] or ['title' => 'Name'] for current page
 */
function render_breadcrumbs($breadcrumbs) {
    if (empty($breadcrumbs)) {
        return;
    }
?>
<div class="breadcrumbs">
    <?php foreach ($breadcrumbs as $i => $crumb): ?>
        <?php if ($i > 0): ?><span class="breadcrumb-sep">/</span><?php endif; ?>
        <?php if (!empty($crumb['url'])): ?>
            <a href="<?= h($crumb['url']) ?>" class="breadcrumb-link"><?= h($crumb['title']) ?></a>
        <?php else: ?>
            <span class="breadcrumb-current"><?= h($crumb['title']) ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php
}
?>
