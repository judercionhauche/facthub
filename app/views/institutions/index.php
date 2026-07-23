<?php
require_login();
$search=trim($_GET['search'] ?? '');
$researchers=[]; $res=$conn->query("SELECT * FROM researchers WHERE status = 'active' AND deleted_at IS NULL ORDER BY institution ASC, first_name ASC"); while($row=$res->fetch_assoc()) $researchers[]=$row;
$map=[]; foreach($researchers as $r){ $inst=trim($r['institution'] ?? '') ?: 'Unknown Institution'; $map[$inst][]=$r; }
uksort($map, function($a,$b) use($map){ return count($map[$b]) <=> count($map[$a]); });
?>
<div style="background-image:linear-gradient(135deg, rgba(255,255,255,0.60) 0%, rgba(255,255,255,0.55) 100%), url('wheat.avif');background-size:cover;background-position:center;background-attachment:fixed;">
<div class="panel page-head"><h1>Institutions Directory</h1><form method="get" class="filters-grid one-row"><input type="hidden" name="page" value="institutions"><input type="text" name="search" value="<?= h($search) ?>" placeholder="Search institution..."><button class="ghost-btn" type="submit">Search</button></form></div>
<?php $shown=0; foreach($map as $inst=>$members): if($search!=='' && !str_contains(strtolower($inst), strtolower($search))) continue; $shown++; ?>
<details class="accordion panel" <?= $shown===1?'open':'' ?>><summary><span><strong><?= h($inst) ?></strong><span class="muted"> · <?= count($members) ?> researcher<?= count($members)!==1?'s':'' ?></span></span><span class="badge badge-outline"><?= count($members) ?></span></summary><div class="accordion-body"><?php foreach($members as $r): ?><div class="member-row"><div class="avatar"><?= h(strtoupper(substr(($r['first_name'] ?: $r['last_name'] ?: '?'),0,1))) ?></div><div class="member-main"><div><strong><?= h(trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''))) ?></strong></div><div class="muted small"><?= h($r['title']) ?><?= $r['department'] ? ' · '.h($r['department']) : '' ?></div><div class="tag-row"><?php foreach(array_slice(parse_tags($r['topics']),0,3) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div><div class="tag-row"><?php foreach(array_slice(parse_tags($r['geography']),0,3) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div></div><?php if($r['email']): ?><a href="mailto:<?= h($r['email']) ?>" class="muted small"><?= h($r['email']) ?></a><?php endif; ?></div><?php endforeach; ?></div></details>
<?php endforeach; if($shown===0): ?><div class="empty-state panel">No institutions found.</div><?php endif; ?>
</div>
