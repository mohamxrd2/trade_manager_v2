<?php

/**
 * Script de test pour l'API de login
 * Usage: php test-login.php
 */

$baseUrl = 'http://localhost:8000';
$cookieJar = 'cookies.txt';

// Fonction pour faire une requête HTTP
function httpRequest($url, $method = 'GET', $data = null, $headers = [], $cookieFile = null) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    
    $defaultHeaders = [
        'Origin: http://localhost:3000',
        'Accept: application/json',
    ];
    
    if ($method === 'POST' || $method === 'PUT') {
        $defaultHeaders[] = 'Content-Type: application/json';
    }
    
    $allHeaders = array_merge($defaultHeaders, $headers);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    curl_close($ch);
    
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body
    ];
}

// Nettoyer le fichier de cookies
if (file_exists($cookieJar)) {
    unlink($cookieJar);
}

echo "🧪 Test de l'API Login\n";
echo "=====================\n\n";

// 1. Récupérer le cookie CSRF
echo "1️⃣  Récupération du cookie CSRF...\n";
$response = httpRequest("$baseUrl/sanctum/csrf-cookie", 'GET', null, [], $cookieJar);

if ($response['code'] === 204 || $response['code'] === 200) {
    echo "✅ Cookie CSRF récupéré (HTTP {$response['code']})\n\n";
} else {
    echo "❌ Échec (HTTP {$response['code']})\n";
    echo $response['body'] . "\n\n";
    exit(1);
}

// 2. Lire le cookie XSRF-TOKEN
$xsrfToken = null;
if (file_exists($cookieJar)) {
    $cookieContent = file_get_contents($cookieJar);
    // Le format du cookie jar peut varier, essayons plusieurs formats
    if (preg_match('/XSRF-TOKEN\s+([^\s]+)/', $cookieContent, $matches)) {
        $xsrfToken = urldecode($matches[1]);
    } elseif (preg_match('/XSRF-TOKEN.*?([a-zA-Z0-9%_\-]+)/', $cookieContent, $matches)) {
        $xsrfToken = urldecode($matches[1]);
    }
    
    if ($xsrfToken) {
        echo "🔑 Token CSRF extrait: " . substr($xsrfToken, 0, 50) . "...\n\n";
    } else {
        echo "⚠️  Token CSRF non trouvé dans le cookie, mais le cookie sera envoyé automatiquement\n\n";
    }
}

// 3. Tester le login avec email
echo "2️⃣  Test login avec EMAIL...\n";
$loginData = [
    'login' => 'test@example.com',
    'password' => 'password123'
];

$headers = [];
if ($xsrfToken) {
    $headers[] = "X-XSRF-TOKEN: $xsrfToken";
}

$response = httpRequest("$baseUrl/api/login", 'POST', $loginData, $headers, $cookieJar);
echo "HTTP Code: {$response['code']}\n";

if ($response['code'] === 200) {
    echo "✅ Login réussi!\n";
    $userData = json_decode($response['body'], true);
    if ($userData) {
        echo "👤 Utilisateur connecté:\n";
        echo "   - ID: " . ($userData['id'] ?? 'N/A') . "\n";
        echo "   - Email: " . ($userData['email'] ?? 'N/A') . "\n";
        echo "   - Username: " . ($userData['username'] ?? 'N/A') . "\n";
    } else {
        echo "Réponse: " . substr($response['body'], 0, 200) . "\n";
    }
} else {
    echo "❌ Échec du login\n";
    $errorData = json_decode($response['body'], true);
    if ($errorData && isset($errorData['message'])) {
        echo "Message: " . $errorData['message'] . "\n";
    } else {
        echo "Réponse: " . substr($response['body'], 0, 500) . "\n";
    }
}
echo "\n";

// 4. Tester le login avec username (si l'utilisateur existe)
echo "3️⃣  Test login avec USERNAME...\n";
$loginData = [
    'login' => 'testuser',
    'password' => 'password123'
];

$response = httpRequest("$baseUrl/api/login", 'POST', $loginData, $headers, $cookieJar);
echo "HTTP Code: {$response['code']}\n";

if ($response['code'] === 200) {
    echo "✅ Login réussi avec username!\n";
    $userData = json_decode($response['body'], true);
    if ($userData) {
        echo "👤 Utilisateur connecté:\n";
        echo "   - ID: " . ($userData['id'] ?? 'N/A') . "\n";
        echo "   - Email: " . ($userData['email'] ?? 'N/A') . "\n";
        echo "   - Username: " . ($userData['username'] ?? 'N/A') . "\n";
    }
} else {
    echo "❌ Échec du login\n";
    $errorData = json_decode($response['body'], true);
    if ($errorData && isset($errorData['message'])) {
        echo "Message: " . $errorData['message'] . "\n";
    }
}
echo "\n";

// 5. Vérifier l'utilisateur connecté
echo "4️⃣  Vérification de l'utilisateur connecté...\n";
$response = httpRequest("$baseUrl/api/user", 'GET', null, [], $cookieJar);
echo "HTTP Code: {$response['code']}\n";

if ($response['code'] === 200) {
    echo "✅ Utilisateur récupéré avec succès!\n";
    $userData = json_decode($response['body'], true);
    if ($userData) {
        echo "👤 Utilisateur:\n";
        echo "   - ID: " . ($userData['id'] ?? 'N/A') . "\n";
        echo "   - Email: " . ($userData['email'] ?? 'N/A') . "\n";
        echo "   - Username: " . ($userData['username'] ?? 'N/A') . "\n";
    }
} else {
    echo "❌ Échec (HTTP {$response['code']})\n";
    $errorData = json_decode($response['body'], true);
    if ($errorData && isset($errorData['message'])) {
        echo "Message: " . $errorData['message'] . "\n";
    }
}

echo "\n";
echo "✅ Tests terminés!\n";

