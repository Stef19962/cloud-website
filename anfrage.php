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

function itecka_field($data, $key, $maxLen = 1900) {
    $v = isset($data[$key]) ? trim((string) $data[$key]) : '';
    if (function_exists('mb_substr')) {
        $v = mb_substr($v, 0, $maxLen);
    } else {
        $v = substr($v, 0, $maxLen);
    }
    return $v;
}

$firma = itecka_field($data, 'firma');
$ansprechpartner = itecka_field($data, 'ansprechpartner');
$email = itecka_field($data, 'email');
$telefon = itecka_field($data, 'telefon');
$projekt = itecka_field($data, 'projekt');
$produkt = itecka_field($data, 'produkt');
$variante = itecka_field($data, 'variante');
$artikelnummer = itecka_field($data, 'artikelnummer');
$notizen = itecka_field($data, 'notizen');
$stueckzahl = isset($data['stueckzahl']) ? (float) $data['stueckzahl'] : null;

$errors = [];
if ($ansprechpartner === '') $errors[] = 'ansprechpartner';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if ($projekt === '') $errors[] = 'projekt';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'validation_failed', 'fields' => $errors]);
    exit;
}

function itecka_rich_text($text) {
    if ($text === '') return [];
    return [[
        'type' => 'text',
        'text' => ['content' => $text],
    ]];
}

$titleText = $firma !== '' ? $firma : $ansprechpartner;
if ($titleText === '') $titleText = 'Website-Anfrage';

$properties = [
    'Anfrage' => ['title' => itecka_rich_text($titleText)],
    'Status' => ['status' => ['name' => 'Offen']],
    'Quelle' => ['select' => ['name' => 'Website']],
    'Ansprechpartner' => ['rich_text' => itecka_rich_text($ansprechpartner)],
    'E-Mail' => ['email' => $email],
    'Projektbeschreibung' => ['rich_text' => itecka_rich_text($projekt)],
];
if ($firma !== '') $properties['Firma'] = ['rich_text' => itecka_rich_text($firma)];
if ($telefon !== '') $properties['Telefon'] = ['phone_number' => $telefon];
if ($produkt !== '') $properties['Produkt'] = ['rich_text' => itecka_rich_text($produkt)];
if ($variante !== '') $properties['Variante'] = ['rich_text' => itecka_rich_text($variante)];
if ($artikelnummer !== '') $properties['Artikelnummer'] = ['rich_text' => itecka_rich_text($artikelnummer)];
if ($notizen !== '') $properties['Notizen'] = ['rich_text' => itecka_rich_text($notizen)];
if ($stueckzahl !== null && $stueckzahl > 0) $properties['Stückzahl'] = ['number' => $stueckzahl];

$payload = json_encode([
    'parent' => ['database_id' => NOTION_DATABASE_ID],
    'properties' => $properties,
]);

$ch = curl_init('https://api.notion.com/v1/pages');
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
    error_log('ITECKA Anfrage -> Notion failed: HTTP ' . $httpCode . ' ' . $curlError . ' body=' . $response);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'notion_error']);
    exit;
}

echo json_encode(['ok' => true]);
