<?php
require_login();

// Check if user is approved to access matching
if (!is_approved()) {
    set_flash('info', 'Your account is pending admin approval. You can access matching once approved.');
    redirect_to('researchers');
}

// Handle compute_matches action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'compute_matches') {
    $fcId = (int)($_POST['funding_call_id'] ?? 0);
    if ($fcId) {
        enqueue_job($conn, 'compute_matches', ['funding_call_id' => $fcId]);
        set_flash('success', 'AI matching started. Scores appear within a minute — refresh to see results.');
    }
    redirect_to('matching', ['funding_call_id' => $fcId]);
}

$fundingCalls=[]; $researchers=[];
$res=$conn->query('SELECT * FROM funding_calls WHERE deleted_at IS NULL ORDER BY created_at DESC'); while($row=$res->fetch_assoc()) $fundingCalls[]=$row;
$res2=$conn->query("SELECT * FROM researchers WHERE status = 'active' AND deleted_at IS NULL ORDER BY first_name ASC,last_name ASC"); while($row=$res2->fetch_assoc()) $researchers[]=$row;
$selectedId=(int)($_GET['funding_call_id'] ?? 0); $selected=null; foreach($fundingCalls as $fc) if((int)$fc['id']===$selectedId) $selected=$fc;
$results=[];
if($selected){
  $fcTopics=parse_tags($selected['topics'] ?? '');
  $fcGeo=parse_tags($selected['geography'] ?? '');
  foreach($researchers as $r){
    $score=compute_match_score($fcTopics,$fcGeo,parse_tags($r['topics'] ?? ''),parse_tags($r['geography'] ?? ''));
    if($score['totalScore']>0){ $score['researcher']=$r; $results[]=$score; }
  }
  usort($results, fn($a,$b)=>$b['totalScore'] <=> $a['totalScore']);
}

// Load AI scores from DB and merge into results
$aiMap = [];
$hasAiScores = false;
if ($selected) {
    $ms = $conn->prepare('SELECT researcher_id, score_ai, explanation FROM match_scores WHERE funding_call_id=? AND score_ai IS NOT NULL');
    $ms->bind_param('i', $selectedId); $ms->execute();
    foreach ($ms->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $aiMap[(int)$row['researcher_id']] = $row;
    }

    if (!empty($aiMap)) {
        $hasAiScores = true;
        foreach ($results as &$item) {
            $rid = (int)$item['researcher']['id'];
            if (isset($aiMap[$rid])) {
                $item['score_ai']    = (int)$aiMap[$rid]['score_ai'];
                $item['explanation'] = $aiMap[$rid]['explanation'] ?? '';
            }
        }
        unset($item);
        // Re-sort: prefer AI score, fall back to keyword score
        usort($results, fn($a, $b) =>
            ($b['score_ai'] ?? $b['totalScore']) <=> ($a['score_ai'] ?? $a['totalScore'])
        );
    }
}
?>
<style>
.score-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; }
.ai-score { background: #eaf6f0; color: #1a6b5a; }
.kw-score { background: #f0f4f8; color: #374151; }
</style>
<div style="background-image:linear-gradient(135deg, rgba(255,255,255,0.60) 0%, rgba(255,255,255,0.55) 100%), url('wheat.avif');background-size:cover;background-position:center;background-attachment:fixed;">
<div class="panel page-head"><h1>Match Researchers to a Funding Call</h1><div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap"><form method="get" class="filters-grid matching-grid" style="flex:1;min-width:300px"><input type="hidden" name="page" value="matching"><select name="funding_call_id"><option value="">-- choose a funding call --</option><?php foreach($fundingCalls as $fc): ?><option value="<?= (int)$fc['id'] ?>" <?= (int)$fc['id']===$selectedId?'selected':'' ?>><?= h($fc['title']) ?></option><?php endforeach; ?></select><button class="primary-btn" type="submit">Find Matches</button></form><?php if($selected): ?><form method="post" style="display:inline"><input type="hidden" name="action" value="compute_matches"><input type="hidden" name="funding_call_id" value="<?= $selectedId ?>"><?= csrf_input() ?><button class="ghost-btn" type="submit">⚡ Compute AI Matches</button></form><?php endif; ?></div><div class="muted small">Tip: Matching score is explainable: topics = 2 pts, geography = 1 pt.<?php if($selected && !$hasAiScores): ?> No AI scores computed yet — click ⚡ above to start.<?php endif; ?></div></div>
<?php if($selected): ?><div class="panel selected-panel"><div class="title-line"><h3><?= h($selected['title']) ?></h3><span class="badge <?= status_class($selected['status']) ?>"><?= h($selected['status']) ?></span></div><div class="muted">Funder: <?= h($selected['funder']) ?> · Deadline: <?= h(format_deadline($selected['deadline'])) ?></div><div class="tag-row"><?php foreach(parse_tags($selected['topics']) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div><div class="tag-row"><?php foreach(parse_tags($selected['geography']) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div></div><?php endif; ?>
<?php if($selected && !$results): ?><div class="empty-state panel">No researchers match this funding call's topics or geography.</div><?php endif; ?>
<?php foreach($results as $index=>$item): $r=$item['researcher']; $rname=trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')); $subject='Collaboration on: '.($selected['title'] ?? 'Research Collaboration'); ?>
<div class="panel list-card"><div class="card-row"><div class="rank-bubble">#<?= $index+1 ?></div><div class="card-main"><div class="title-line"><h3><?= h($rname) ?></h3><?php $showAi = isset($item['score_ai']); $score = $showAi ? $item['score_ai'] : (int)$item['totalScore']; $label = $showAi ? '% AI' : ' pts'; ?><span class="badge score-badge <?= $showAi ? 'ai-score' : 'kw-score' ?>"><?= $score ?><?= $label ?></span></div><div class="muted"><?= h(implode(' · ', array_filter([$r['title'] ?? '', $r['institution'] ?? '']))) ?></div><div class="match-pills"><?php if($item['topicMatches']>0): ?><span class="pill"><?= (int)$item['topicMatches'] ?> topic match<?= $item['topicMatches']!==1?'es':'' ?></span><?php endif; ?><?php if($item['geographyMatches']>0): ?><span class="pill alt"><?= (int)$item['geographyMatches'] ?> geography match<?= $item['geographyMatches']!==1?'es':'' ?></span><?php endif; ?></div><?php if(!empty($item['explanation'])): ?><p class="muted small" style="margin-top:6px;font-style:italic"><?= h($item['explanation']) ?></p><?php endif; ?><div class="tag-row"><?php foreach(array_slice(parse_tags($r['topics']),0,5) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div><div class="tag-row"><?php foreach(array_slice(parse_tags($r['geography']),0,4) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div></div><div class="card-actions"><a class="primary-btn" href="index.php?page=messages&mode=compose&recipient_email=<?= urlencode($r['email']) ?>&recipient_name=<?= urlencode($rname) ?>&subject=<?= urlencode($subject) ?>">Contact</a></div></div></div>
<?php endforeach; ?>
</div>
