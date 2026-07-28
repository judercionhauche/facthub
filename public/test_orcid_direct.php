<?php
// Test direct ORCID API call for Greg
echo "<h2>Testing ORCID API Direct Connection</h2>";
echo "<pre>";

$orcidId = '0000-0003-2839-8164';
$url = 'https://orcid.org/v3.0/' . $orcidId . '/works';

echo "Calling: $url\n\n";

$context = stream_context_create(['http' => [
    'timeout' => 5,
    'user_agent' => 'FACT-Hub/1.0 (+https://factalliancehub.mit.edu)',
    'header' => 'Accept: application/json'
]]);

$response = @file_get_contents($url, false, $context);

if ($response === false) {
    echo "❌ API call failed\n";
    echo "Error: " . error_get_last()['message'] . "\n";
} else {
    echo "✅ API call succeeded\n";
    echo "Response length: " . strlen($response) . " bytes\n\n";

    $data = json_decode($response, true);
    if ($data && isset($data['group'])) {
        echo "Groups found: " . count($data['group']) . "\n";
        echo "First group work count: " . count($data['group'][0]['work-summary'] ?? []) . "\n";

        if (isset($data['group'][0]['work-summary'][0])) {
            $firstWork = $data['group'][0]['work-summary'][0];
            echo "First publication title: " . ($firstWork['title']['title']['value'] ?? 'No title') . "\n";
        }
    } else {
        echo "❌ Could not parse JSON or no 'group' field\n";
    }
}

echo "</pre>";
?>
