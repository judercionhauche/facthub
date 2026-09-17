<?php
require_login();
$search=trim($_GET['search'] ?? '');
$researchers=[]; $res=$conn->query("SELECT * FROM researchers WHERE status = 'active' AND deleted_at IS NULL ORDER BY institution ASC, first_name ASC"); while($row=$res->fetch_assoc()) $researchers[]=$row;
// Group by canonical institution, not the raw column. The field is free text
// filled from the signup form, the ORCID sync and bulk imports, so one
// university arrives as a bare domain, a faculty-qualified name and an
// abbreviation all at once — which split colleagues into separate groups.
// Sub-units fold into the parent; each member row still shows its department.
$domainMap = institution_domain_map($conn);
$map = [];
foreach ($researchers as $r) {
    $inst = canonical_institution((string)($r['institution'] ?? ''), (string)($r['email'] ?? ''), $domainMap);
    if ($inst === '') $inst = 'Unknown Institution';
    $map[$inst][] = $r;
}
// Merged groups are the concatenation of several already-sorted lists, so the
// combined list has to be re-sorted or it reads A–Z twice over.
foreach ($map as &$members) {
    usort($members, function ($a, $b) {
        return strcasecmp(
            trim(($a['last_name'] ?? '') . ' ' . ($a['first_name'] ?? '')),
            trim(($b['last_name'] ?? '') . ' ' . ($b['first_name'] ?? ''))
        );
    });
}
unset($members);
uksort($map, function($a,$b) use($map){ return count($map[$b]) <=> count($map[$a]); });
?>
<div style="background-image:linear-gradient(135deg, rgba(255,255,255,0.60) 0%, rgba(255,255,255,0.55) 100%), url('wheat.avif');background-size:cover;background-position:center;">
<div class="panel page-head"><h1>Institutions Directory</h1><form method="get" class="filters-grid one-row"><input type="hidden" name="page" value="institutions"><input type="text" name="search" value="<?= h($search) ?>" placeholder="Search institution..."><button class="ghost-btn" type="submit">Search</button></form></div>
<?php $shown=0; foreach($map as $inst=>$members): if($search!=='' && !str_contains(strtolower($inst), strtolower($search))) continue; $shown++; ?>
<details class="accordion panel" <?= $shown===1?'open':'' ?>><summary><span><strong><?= h($inst) ?></strong><span class="muted"> · <?= count($members) ?> researcher<?= count($members)!==1?'s':'' ?></span></span><span class="badge badge-outline"><?= count($members) ?></span></summary><div class="accordion-body"><?php foreach($members as $r): ?><div class="member-row"><?php $mName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')); $mInit = strtoupper(mb_substr($r['first_name'] ?? '', 0, 1) . mb_substr($r['last_name'] ?? '', 0, 1)); if ($mInit === '') $mInit = '?'; ?><div class="r-ava r-ava-sm r-ava-<?= abs(crc32($mName)) % 5 ?>"><?= h($mInit) ?></div><div class="member-main"><div><strong><?= h(trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''))) ?></strong></div><div class="muted small"><?= h($r['title']) ?><?= $r['department'] ? ' · '.h($r['department']) : '' ?></div><div class="tag-row"><?php foreach(array_slice(parse_tags($r['topics']),0,3) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div><div class="tag-row"><?php foreach(array_slice(parse_tags($r['geography']),0,3) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div></div><?php if($r['email']): ?><a href="mailto:<?= h($r['email']) ?>" class="muted small"><?= h($r['email']) ?></a><?php endif; ?></div><?php endforeach; ?></div></details>
<?php endforeach; if($shown===0): ?><div class="empty-state panel">No institutions found.</div><?php endif; ?>
</div>
