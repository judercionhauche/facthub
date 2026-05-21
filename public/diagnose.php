<?php
$conn = new mysqli('54.221.189.212', 'fact_user', '5310Judy####', 'fact_hub');
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error);

header('Content-Type: text/plain');

echo "=== USER ACCOUNT ===\n";
$uq = $conn->prepare('SELECT id, email, status, created_at FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
$email = 'juderciojosenhauche@gmail.com';
$uq->bind_param('s', $email);
$uq->execute();
$uRow = $uq->get_result()->fetch_assoc();
if ($uRow) {
  echo "ID: {$uRow['id']}\n";
  echo "Email: {$uRow['email']}\n";
  echo "Status: {$uRow['status']}\n";
  echo "Created: {$uRow['created_at']}\n";
} else {
  echo "User NOT found\n";
}

echo "\n=== RESEARCHER PROFILE ===\n";
$rq = $conn->prepare('SELECT id, email, status, user_id, first_name, last_name FROM researchers WHERE LOWER(email) = LOWER(?) LIMIT 1');
$rq->bind_param('s', $email);
$rq->execute();
$rRow = $rq->get_result()->fetch_assoc();
if ($rRow) {
  echo "ID: {$rRow['id']}\n";
  echo "Email: {$rRow['email']}\n";
  echo "Status: {$rRow['status']}\n";
  echo "User ID: {$rRow['user_id']}\n";
  echo "Name: {$rRow['first_name']} {$rRow['last_name']}\n";
} else {
  echo "Researcher NOT found\n";
}

echo "\n=== RESEARCHER STATUS DISTRIBUTION ===\n";
$sq = $conn->query('SELECT status, COUNT(*) as cnt FROM researchers GROUP BY status ORDER BY status ASC');
while ($sr = $sq->fetch_assoc()) {
  echo "{$sr['status']}: {$sr['cnt']}\n";
}

// If researcher exists but user doesn't or vice versa, fix it
if ($rRow && !$uRow) {
  echo "\n=== FIXING: Researcher exists but user does not ===\n";
  echo "Creating user account for researcher...\n";
  // This shouldn't happen, but we can't fix without password
  echo "Cannot auto-fix: no password provided\n";
}

if ($rRow && $uRow && !$rRow['user_id']) {
  echo "\n=== FIXING: user_id is NULL in researcher profile ===\n";
  $upd = $conn->prepare('UPDATE researchers SET user_id = ? WHERE id = ?');
  $upd->bind_param('ii', $uRow['id'], $rRow['id']);
  $upd->execute();
  echo "Fixed: Set researcher.user_id = {$uRow['id']}\n";
}

if ($rRow && $rRow['status'] !== 'active' && $rRow['status'] !== 'pending_approval') {
  echo "\n=== FIXING: Researcher status is {$rRow['status']} (not active/pending_approval) ===\n";
  $upd = $conn->prepare('UPDATE researchers SET status = ? WHERE id = ?');
  $newStatus = 'pending_approval';
  $upd->bind_param('si', $newStatus, $rRow['id']);
  $upd->execute();
  echo "Fixed: Set researcher status to pending_approval\n";
}

echo "\nDone.\n";
?>
