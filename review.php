<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// Honeypot: real users never fill this hidden field, bots often do.
if (!empty($data['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

function itecka_review_field($data, $key, $maxLen = 1900) {
    $v = isset($data[$key]) ? trim((string) $data[$key]) : '';
    if (function_exists('mb_substr')) {
        $v = mb_substr($v, 0, $maxLen);
    } else {
        $v = substr($v, 0, $maxLen);
    }
    return $v;
}

$slug = itecka_review_field($data, 'slug', 200);
$produktname = itecka_review_field($data, 'produktname', 200);
$name = itecka_review_field($data, 'name', 120);
$email = itecka_review_field($data, 'email', 200);
$kommentar = itecka_review_field($data, 'kommentar');
$sterne = isset($data['sterne']) ? (int) $data['sterne'] : 0;

$errors = [];
if ($slug === '') $errors[] = 'slug';
if ($name === '') $errors[] = 'name';
if ($sterne < 1 || $sterne > 5) $errors[] = 'sterne';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'validation_failed', 'fields' => $errors]);
    exit;
}

function itecka_review_rich_text($text) {
    if ($text === '') return [];
    return [[
        'type' => 'text',
        'text' => ['content' => $text],
    ]];
}

function itecka_notion_curl($url, $payload) {
    $ch = curl_init($url);
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
    return [$response, $httpCode, $curlError];
}

// One review per email address per product: check for an existing entry
// (any status - including still-pending ones) before creating a new one.
$dupPayload = json_encode([
    'filter' => [
        'and' => [
            ['property' => 'E-Mail', 'email' => ['equals' => $email]],
            ['property' => 'Produkt / Bezug', 'rich_text' => ['ends_with' => $slug]],
        ],
    ],
    'page_size' => 1,
]);
list($dupResponse, $dupHttpCode) = itecka_notion_curl(
    'https://api.notion.com/v1/databases/' . NOTION_REVIEWS_DATABASE_ID . '/query',
    $dupPayload
);
if ($dupHttpCode >= 200 && $dupHttpCode < 300) {
    $dupBody = json_decode((string) $dupResponse, true);
    if (is_array($dupBody) && !empty($dupBody['results'])) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'already_reviewed']);
        exit;
    }
}

$titleText = $produktname !== '' ? ($produktname . ' – ' . $name . ' (' . $sterne . '★)') : ($name . ' (' . $sterne . '★)');
$bezug = ($produktname !== '' ? $produktname . ' · ' : '') . $slug;
$sterneLabel = $sterne === 1 ? '1 Stern' : $sterne . ' Sterne';

$properties = [
    'Bewertung' => ['title' => itecka_review_rich_text($titleText)],
    'Produkt / Bezug' => ['rich_text' => itecka_review_rich_text($bezug)],
    'Name / Kunde' => ['rich_text' => itecka_review_rich_text($name)],
    'Bewertung / Sterne' => ['select' => ['name' => $sterneLabel]],
    'Status' => ['status' => ['name' => 'Neu']],
    'Quelle' => ['select' => ['name' => 'Website']],
    'Freigabe für Website' => ['checkbox' => false],
];
if ($kommentar !== '') $properties['Kommentar'] = ['rich_text' => itecka_review_rich_text($kommentar)];
if ($email !== '') $properties['E-Mail'] = ['email' => $email];

$payload = json_encode([
    'parent' => ['database_id' => NOTION_REVIEWS_DATABASE_ID],
    'properties' => $properties,
]);

list($response, $httpCode, $curlError) = itecka_notion_curl('https://api.notion.com/v1/pages', $payload);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    error_log('ITECKA Bewertung -> Notion failed: HTTP ' . $httpCode . ' ' . $curlError . ' body=' . $response);
    $notionMessage = '';
    $decoded = json_decode((string) $response, true);
    if (is_array($decoded) && isset($decoded['message'])) {
        $notionMessage = $decoded['message'];
    } elseif ($curlError !== '') {
        $notionMessage = $curlError;
    }
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'notion_error',
        'debug' => 'HTTP ' . $httpCode . ($notionMessage !== '' ? ': ' . mb_substr($notionMessage, 0, 200) : ''),
    ]);
    exit;
}

echo json_encode(['ok' => true]);
