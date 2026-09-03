<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$configFile = __DIR__ . '/anfrage-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_not_configured']);
    exit;
}
require $configFile;

if (!defined('NOTION_REVIEWS_DATABASE_ID')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_not_configured']);
    exit;
}

$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
if ($slug === '' || strlen($slug) > 200) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_slug']);
    exit;
}

$payload = json_encode([
    'filter' => [
        'and' => [
            ['property' => 'Produkt / Bezug', 'rich_text' => ['ends_with' => $slug]],
            ['property' => 'Freigabe für Website', 'checkbox' => ['equals' => true]],
            ['property' => 'Status', 'status' => ['equals' => 'Freigegeben']],
        ],
    ],
    'sorts' => [
        ['property' => 'Erstellt am', 'direction' => 'descending'],
    ],
    'page_size' => 50,
]);

$ch = curl_init('https://api.notion.com/v1/databases/' . NOTION_REVIEWS_DATABASE_ID . '/query');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . NOTION_TOKEN,
        'Notion-Version: 2022-06-28',
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    error_log('ITECKA Bewertungen <- Notion failed: HTTP ' . $httpCode . ' ' . $curlError . ' body=' . $response);
    // Fail soft: the site should keep working even if Notion is briefly unreachable.
    echo json_encode(['ok' => true, 'count' => 0, 'avg' => 0, 'reviews' => []]);
    exit;
}

$body = json_decode($response, true);
$results = is_array($body) && isset($body['results']) ? $body['results'] : [];

function itecka_plain_text($richTextArray) {
    if (!is_array($richTextArray)) return '';
    $out = '';
    foreach ($richTextArray as $part) {
        $out .= isset($part['plain_text']) ? $part['plain_text'] : '';
    }
    return $out;
}

$reviews = [];
$sum = 0;
foreach ($results as $page) {
    $props = isset($page['properties']) ? $page['properties'] : [];
    $sterneLabel = isset($props['Bewertung / Sterne']['select']['name']) ? $props['Bewertung / Sterne']['select']['name'] : '';
    $sterne = 0;
    if (preg_match('/(\d+)/', $sterneLabel, $m)) {
        $sterne = (int) $m[1];
    }
    if ($sterne < 1 || $sterne > 5) continue;
    $name = itecka_plain_text($props['Name / Kunde']['rich_text'] ?? []);
    $kommentar = itecka_plain_text($props['Kommentar']['rich_text'] ?? []);
    $datum = isset($props['Erstellt am']['created_time']) ? $props['Erstellt am']['created_time'] : '';
    $reviews[] = [
        'name' => $name !== '' ? $name : 'Anonym',
        'sterne' => $sterne,
        'kommentar' => $kommentar,
        'datum' => $datum,
    ];
    $sum += $sterne;
}

$count = count($reviews);
$avg = $count > 0 ? round($sum / $count, 1) : 0;

echo json_encode(['ok' => true, 'count' => $count, 'avg' => $avg, 'reviews' => $reviews]);
