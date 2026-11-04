<?php

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$apiKey = $_ENV['CLICKUP_API_KEY'];
$teamId = $_ENV['CLICKUP_TEAM_ID'] ?? $_ENV['CLICKUP_WORKSPACE_ID'];
$apiBaseUrl = $_ENV['CLICKUP_API_BASE_URL'] ?? 'https://api.clickup.com/api/v2';

echo "Testing ClickUp API connection...\n";
echo "API Key: " . substr($apiKey, 0, 10) . "...\n";
echo "Team ID: $teamId\n\n";

$client = new Client([
    'headers' => [
        'Authorization' => $apiKey,
        'Content-Type' => 'application/json',
    ],
]);

// Test different endpoints
$endpoints = [
    "/team/$teamId/space",
    "/team/$teamId/space?archived=false",
    "/team/$teamId",
    "/user",
    "/team"
];

foreach ($endpoints as $endpoint) {
    echo "Testing endpoint: $endpoint\n";
    try {
        $response = $client->get("$apiBaseUrl$endpoint");
        $data = json_decode($response->getBody()->getContents(), true);
        echo "✓ Success! Response keys: " . implode(', ', array_keys($data)) . "\n";
        if (isset($data['spaces'])) {
            echo "  Found " . count($data['spaces']) . " spaces\n";
        }
        echo "\n";
    } catch (Exception $e) {
        echo "✗ Failed: " . $e->getMessage() . "\n\n";
    }
}
