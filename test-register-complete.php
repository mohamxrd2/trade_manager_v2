<?php

/**
 * Test complet de l'API register avec tous les cas
 */

$baseUrl = 'http://localhost:8000';
$cookieJar = 'cookies.txt';

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
    
    $defaultHeaders = ['Origin: http://localhost:3000', 'Accept: application/json'];
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
    
    return [
        'code' => $httpCode,
        'body' => substr($response, $headerSize),
        'headers' => substr($response, 0, $headerSize)
    ];
}

echo "🧪 Test Complet de l'API Register\n";
echo "=================================\n\n";

// Nettoyer les cookies
if (file_exists($cookieJar)) {
    unlink($cookieJar);
}

// 1. Récupérer CSRF
echo "1️⃣  Récupération du cookie CSRF...\n";
$response = httpRequest("$baseUrl/sanctum/csrf-cookie", 'GET', null, [], $cookieJar);
if ($response['code'] === 204 || $response['code'] === 200) {
    echo "✅ Cookie CSRF récupéré (HTTP {$response['code']})\n\n";
} else {
    echo "❌ Échec (HTTP {$response['code']})\n";
    exit(1);
}

// Extraire le token CSRF
$xsrfToken = null;
if (preg_match('/XSRF-TOKEN=([^;]+)/', $response['headers'], $matches)) {
    $xsrfToken = urldecode($matches[1]);
}

// 2. Test avec données valides
echo "2️⃣  Test Register - Données VALIDES\n";
echo "-----------------------------------\n";
$validData = [
    'first_name' => 'Test',
    'last_name' => 'User',
    'username' => 'testuser' . rand(1000, 9999),
    'email' => 'test' . rand(1000, 9999) . '@example.com',
    'password' => 'Password123!',
    'password_confirmation' => 'Password123!',
    'company_share' => 100.00
];

$headers = $xsrfToken ? ["X-XSRF-TOKEN: $xsrfToken"] : [];
$response = httpRequest("$baseUrl/api/register", 'POST', $validData, $headers, $cookieJar);

if ($response['code'] === 201) {
    echo "✅ Register réussi (HTTP 201)\n";
    $user = json_decode($response['body'], true);
    echo "   - Email: " . ($user['email'] ?? 'N/A') . "\n";
    echo "   - Username: " . ($user['username'] ?? 'N/A') . "\n";
} else {
    echo "❌ Échec (HTTP {$response['code']})\n";
    echo $response['body'] . "\n";
}
echo "\n";

// 3. Test avec mot de passe invalide
echo "3️⃣  Test Register - Mot de passe INVALIDE\n";
echo "------------------------------------------\n";
// Récupérer un nouveau token CSRF
$response = httpRequest("$baseUrl/sanctum/csrf-cookie", 'GET', null, [], $cookieJar);
if (preg_match('/XSRF-TOKEN=([^;]+)/', $response['headers'], $matches)) {
    $xsrfToken = urldecode($matches[1]);
    $headers = ["X-XSRF-TOKEN: $xsrfToken"];
}

$invalidPasswordData = [
    'first_name' => 'Test',
    'last_name' => 'User',
    'username' => 'testuser' . rand(1000, 9999),
    'email' => 'test' . rand(1000, 9999) . '@example.com',
    'password' => 'password', // Pas de majuscule, chiffre, symbole
    'password_confirmation' => 'password'
];

$response = httpRequest("$baseUrl/api/register", 'POST', $invalidPasswordData, $headers, $cookieJar);

if ($response['code'] === 422) {
    echo "✅ Validation correcte (HTTP 422)\n";
    $errors = json_decode($response['body'], true);
    if (isset($errors['errors']['password'])) {
        echo "   Erreurs password:\n";
        foreach ($errors['errors']['password'] as $error) {
            echo "   - $error\n";
        }
    }
} else {
    echo "❌ Échec inattendu (HTTP {$response['code']})\n";
}
echo "\n";

// 4. Test avec email déjà utilisé
echo "4️⃣  Test Register - Email DÉJÀ UTILISÉ\n";
echo "--------------------------------------\n";
// Récupérer un nouveau token CSRF
$response = httpRequest("$baseUrl/sanctum/csrf-cookie", 'GET', null, [], $cookieJar);
if (preg_match('/XSRF-TOKEN=([^;]+)/', $response['headers'], $matches)) {
    $xsrfToken = urldecode($matches[1]);
    $headers = ["X-XSRF-TOKEN: $xsrfToken"];
}

$duplicateEmailData = [
    'first_name' => 'Test',
    'last_name' => 'User',
    'username' => 'testuser' . rand(1000, 9999),
    'email' => $validData['email'], // Email déjà utilisé
    'password' => 'Password123!',
    'password_confirmation' => 'Password123!'
];

$response = httpRequest("$baseUrl/api/register", 'POST', $duplicateEmailData, $headers, $cookieJar);

if ($response['code'] === 422) {
    echo "✅ Validation correcte (HTTP 422)\n";
    $errors = json_decode($response['body'], true);
    if (isset($errors['errors']['email'])) {
        echo "   Erreur email: " . implode(', ', $errors['errors']['email']) . "\n";
    }
} else {
    echo "❌ Échec inattendu (HTTP {$response['code']})\n";
}
echo "\n";

// 5. Test avec champs manquants
echo "5️⃣  Test Register - Champs MANQUANTS\n";
echo "-------------------------------------\n";
// Récupérer un nouveau token CSRF
$response = httpRequest("$baseUrl/sanctum/csrf-cookie", 'GET', null, [], $cookieJar);
if (preg_match('/XSRF-TOKEN=([^;]+)/', $response['headers'], $matches)) {
    $xsrfToken = urldecode($matches[1]);
    $headers = ["X-XSRF-TOKEN: $xsrfToken"];
}

$missingFieldsData = [
    'first_name' => 'Test',
    // last_name manquant
    // username manquant
    'email' => 'test' . rand(1000, 9999) . '@example.com',
    'password' => 'Password123!',
    'password_confirmation' => 'Password123!'
];

$response = httpRequest("$baseUrl/api/register", 'POST', $missingFieldsData, $headers, $cookieJar);

if ($response['code'] === 422) {
    echo "✅ Validation correcte (HTTP 422)\n";
    $errors = json_decode($response['body'], true);
    if (isset($errors['errors'])) {
        echo "   Champs manquants:\n";
        foreach ($errors['errors'] as $field => $fieldErrors) {
            echo "   - $field: " . implode(', ', $fieldErrors) . "\n";
        }
    }
} else {
    echo "❌ Échec inattendu (HTTP {$response['code']})\n";
}
echo "\n";

// 6. Test avec password_confirmation incorrecte
echo "6️⃣  Test Register - Confirmation mot de passe INCORRECTE\n";
echo "--------------------------------------------------------\n";
// Récupérer un nouveau token CSRF
$response = httpRequest("$baseUrl/sanctum/csrf-cookie", 'GET', null, [], $cookieJar);
if (preg_match('/XSRF-TOKEN=([^;]+)/', $response['headers'], $matches)) {
    $xsrfToken = urldecode($matches[1]);
    $headers = ["X-XSRF-TOKEN: $xsrfToken"];
}

$wrongConfirmationData = [
    'first_name' => 'Test',
    'last_name' => 'User',
    'username' => 'testuser' . rand(1000, 9999),
    'email' => 'test' . rand(1000, 9999) . '@example.com',
    'password' => 'Password123!',
    'password_confirmation' => 'DifferentPassword123!' // Différent
];

$response = httpRequest("$baseUrl/api/register", 'POST', $wrongConfirmationData, $headers, $cookieJar);

if ($response['code'] === 422) {
    echo "✅ Validation correcte (HTTP 422)\n";
    $errors = json_decode($response['body'], true);
    if (isset($errors['errors']['password_confirmation'])) {
        echo "   Erreur: " . implode(', ', $errors['errors']['password_confirmation']) . "\n";
    }
} else {
    echo "❌ Échec inattendu (HTTP {$response['code']})\n";
}
echo "\n";

// 7. Vérifier que l'utilisateur est connecté après register
echo "7️⃣  Vérification - Utilisateur connecté après register\n";
echo "------------------------------------------------------\n";
$response = httpRequest("$baseUrl/api/user", 'GET', null, [], $cookieJar);

if ($response['code'] === 200) {
    echo "✅ Utilisateur connecté (HTTP 200)\n";
    $user = json_decode($response['body'], true);
    echo "   - Email: " . ($user['email'] ?? 'N/A') . "\n";
} else {
    echo "❌ Utilisateur non connecté (HTTP {$response['code']})\n";
}

echo "\n";
echo "=================================\n";
echo "✅ Tests terminés!\n";

