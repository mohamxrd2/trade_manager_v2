<?php

/**
 * Script de test pour l'API register
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

echo "🧪 Test de l'API Register\n";
echo "========================\n\n";

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

// 2. Extraire le token XSRF depuis les headers Set-Cookie
$xsrfToken = null;
if (preg_match('/XSRF-TOKEN=([^;]+)/', $response['headers'], $matches)) {
    $xsrfToken = urldecode($matches[1]);
    echo "🔑 Token CSRF extrait depuis headers: " . substr($xsrfToken, 0, 50) . "...\n\n";
} else {
    // Essayer de lire depuis le cookie jar
    if (file_exists($cookieJar)) {
        $cookieContent = file_get_contents($cookieJar);
        $lines = explode("\n", $cookieContent);
        foreach ($lines as $line) {
            if (strpos($line, 'XSRF-TOKEN') !== false && !empty(trim($line))) {
                $parts = explode("\t", $line);
                if (count($parts) >= 7) {
                    $xsrfToken = urldecode($parts[6]);
                    echo "🔑 Token CSRF extrait depuis cookie jar: " . substr($xsrfToken, 0, 50) . "...\n\n";
                    break;
                }
            }
        }
    }
}

if (!$xsrfToken) {
    echo "⚠️  Token CSRF non trouvé, mais on va essayer quand même\n\n";
}

// 3. Tester le register
echo "2️⃣  Test register...\n";
$registerData = [
    'first_name' => 'Test',
    'last_name' => 'User',
    'username' => 'testuser' . rand(1000, 9999),
    'email' => 'test' . rand(1000, 9999) . '@example.com',
    'password' => 'Password123!',
    'password_confirmation' => 'Password123!',
    'company_share' => 100.00
];

$headers = [];
if ($xsrfToken) {
    $headers[] = "X-XSRF-TOKEN: $xsrfToken";
}

echo "Données: " . json_encode($registerData, JSON_PRETTY_PRINT) . "\n\n";

$response = httpRequest("$baseUrl/api/register", 'POST', $registerData, $headers, $cookieJar);
echo "HTTP Code: {$response['code']}\n";

if ($response['code'] === 201 || $response['code'] === 200) {
    echo "✅ Register réussi!\n";
    $userData = json_decode($response['body'], true);
    if ($userData) {
        echo "👤 Utilisateur créé:\n";
        if (isset($userData['data']['user'])) {
            $user = $userData['data']['user'];
            echo "   - ID: " . ($user['id'] ?? 'N/A') . "\n";
            echo "   - Email: " . ($user['email'] ?? 'N/A') . "\n";
            echo "   - Username: " . ($user['username'] ?? 'N/A') . "\n";
            echo "   - Nom: " . ($user['first_name'] ?? 'N/A') . " " . ($user['last_name'] ?? 'N/A') . "\n";
        } else {
            echo "Réponse complète: " . json_encode($userData, JSON_PRETTY_PRINT) . "\n";
        }
    }
} else {
    echo "❌ Échec du register\n";
    $errorData = json_decode($response['body'], true);
    if ($errorData) {
        if (isset($errorData['message'])) {
            echo "Message: " . $errorData['message'] . "\n";
        }
        if (isset($errorData['errors'])) {
            echo "Erreurs de validation:\n";
            foreach ($errorData['errors'] as $field => $errors) {
                echo "   - $field: " . implode(', ', $errors) . "\n";
            }
        }
    } else {
        echo "Réponse brute: " . substr($response['body'], 0, 500) . "\n";
    }
}
echo "\n";

// 4. Vérifier que l'utilisateur est connecté après register
echo "3️⃣  Vérification de l'utilisateur connecté après register...\n";
$response = httpRequest("$baseUrl/api/user", 'GET', null, [], $cookieJar);
echo "HTTP Code: {$response['code']}\n";

if ($response['code'] === 200) {
    echo "✅ Utilisateur connecté automatiquement après register!\n";
    $userData = json_decode($response['body'], true);
    if ($userData) {
        echo "👤 Utilisateur:\n";
        echo "   - ID: " . ($userData['id'] ?? 'N/A') . "\n";
        echo "   - Email: " . ($userData['email'] ?? 'N/A') . "\n";
    }
} else {
    echo "❌ Utilisateur non connecté après register (HTTP {$response['code']})\n";
    $errorData = json_decode($response['body'], true);
    if ($errorData && isset($errorData['message'])) {
        echo "Message: " . $errorData['message'] . "\n";
    }
}

echo "\n";
echo "✅ Tests terminés!\n";

