<?php
// Output exact JSON that would be sent to Claude for Greg search
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

require_once __DIR__ . '/../app/services/OrcidService.php';
require_once __DIR__ . '/../app/core/helpers.php';

$orcid = new OrcidService($conn);

echo "<h2>Exact JSON Response for Greg Search</h2>";
echo "<pre style='background:#f0f0f0; padding:20px; overflow:auto; max-height:800px;'>";

// Get Greg
$gregResult = $conn->query("SELECT * FROM researchers WHERE first_name='Greg' AND last_name LIKE 'Sixt%' LIMIT 1");
if ($gregResult && $gregResult->num_rows > 0) {
    $r = $gregResult->fetch_assoc();
    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

    // PRIORITY: Fetch ORCID publications first
    $orcidPubs = [];
    $orcidCount = 0;
    if (!empty($r['orcid_id']) && $orcid) {
        $orcidData = $orcid->getEnrichedResearcher((int)$r['id'], $r['orcid_id']);
        if ($orcidData && !empty($orcidData['publications'])) {
            $orcidPubs = array_slice($orcidData['publications'], 0, 10);
            $orcidCount = count($orcidData['publications']);
        }
    }

    // Fallback: Fetch local publications if no ORCID
    $publications = $orcidPubs;
    if (empty($publications)) {
        $pubStmt = $conn->prepare(
            'SELECT title, publication_year, journal_name FROM researcher_publications
             WHERE researcher_id = ? ORDER BY publication_year DESC LIMIT 5'
        );
        $pubStmt->bind_param('i', $r['id']);
        $pubStmt->execute();
        $publications = $pubStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // Build response item
    $rJsonItem = [
        'id' => (int)$r['id'],
        'entity_type' => 'researcher',
        'name' => h($name),
        'institution' => h($r['institution'] ?? ''),
        'topics' => parse_tags($r['topics'] ?? ''),
        'geography' => parse_tags($r['geography'] ?? ''),
        'publications' => array_map(fn($p) => [
            'title' => h(is_string($p['title']) ? $p['title'] : ($p['title'] ?? '')),
            'year' => (int)(is_string($p['year']) ? $p['year'] : ($p['publication_year'] ?? 0)),
            'journal' => h(is_string($p['journal']) ? $p['journal'] : ($p['journal_name'] ?? ''))
        ], $publications),
        'publication_source' => !empty($orcidPubs) ? 'ORCID' : 'local',
        'orcid_publication_count' => $orcidCount,
        'orcid_id' => h($r['orcid_id'] ?? ''),
        'destination_url' => 'http://example.com/researchers/42',
    ];

    echo "JSON Object for Greg:\n\n";
    echo json_encode($rJsonItem, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    echo "\n\n=== KEY FIELDS ===\n";
    echo "publication_source: " . $rJsonItem['publication_source'] . "\n";
    echo "orcid_publication_count: " . $rJsonItem['orcid_publication_count'] . "\n";
    echo "publications array count: " . count($rJsonItem['publications']) . "\n";
    echo "ORCID ID: " . $rJsonItem['orcid_id'] . "\n";
}

$conn->close();
echo "</pre>";
?>
