<?php
/**
 * Pagination UI Component
 * @param Paginator $paginator
 * @param string $pageParam - URL parameter name (usually 'p')
 * @param string $baseUrl - Base URL for pagination links
 * @param array $extraParams - Additional URL parameters to preserve
 */
function render_pagination($paginator, $pageParam = 'p', $baseUrl = '', $extraParams = []) {
    if ($paginator->getTotalPages() <= 1) {
        return;
    }

    if (!$baseUrl) {
        $baseUrl = 'index.php?page=' . ($_GET['page'] ?? 'researchers');
    }

    function build_url($p, $baseUrl, $extraParams, $pageParam) {
        $params = array_merge($extraParams, [$pageParam => $p]);
        $query = http_build_query($params);
        return $baseUrl . (strpos($baseUrl, '?') ? '&' : '?') . $query;
    }

    $current = $paginator->getCurrentPage();
    $total = $paginator->getTotalPages();
    $range = $paginator->getPageRange();
?>
<div class="pagination-wrap">
    <nav class="pagination">
        <?php if ($paginator->hasPrevPage()): ?>
            <a href="<?= build_url(1, $baseUrl, $extraParams, $pageParam) ?>" class="pagination-link" title="First">«</a>
            <a href="<?= build_url($current - 1, $baseUrl, $extraParams, $pageParam) ?>" class="pagination-link">‹ Prev</a>
        <?php endif; ?>

        <?php foreach ($range as $p): ?>
            <?php if ($p == $current): ?>
                <span class="pagination-current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= build_url($p, $baseUrl, $extraParams, $pageParam) ?>" class="pagination-link"><?= $p ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($paginator->hasNextPage()): ?>
            <a href="<?= build_url($current + 1, $baseUrl, $extraParams, $pageParam) ?>" class="pagination-link">Next ›</a>
            <a href="<?= build_url($total, $baseUrl, $extraParams, $pageParam) ?>" class="pagination-link" title="Last">»</a>
        <?php endif; ?>
    </nav>
    <p class="pagination-info">
        Page <?= $current ?> of <?= $total ?> • Showing items
        <?= ($paginator->getOffset() + 1) ?> to <?= min($paginator->getOffset() + $paginator->getLimit(), $paginator->getTotalItems()) ?>
        of <?= $paginator->getTotalItems() ?>
    </p>
</div>
<?php
}
?>
