#!/bin/bash

# Script de test pour les API d'authentification Laravel Sanctum
# Usage: ./test-auth-api.sh

BASE_URL="http://localhost:8000"
COOKIE_FILE="cookies.txt"

echo "🧪 Test des API d'authentification Sanctum"
echo "=========================================="
echo ""

# Couleurs pour l'output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Fonction pour afficher les résultats
print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ️  $1${NC}"
}

# Nettoyer le fichier de cookies
rm -f $COOKIE_FILE

echo "1️⃣  Test: GET /sanctum/csrf-cookie"
echo "-----------------------------------"
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "$BASE_URL/sanctum/csrf-cookie" \
  -H "Origin: http://localhost:3000" \
  -H "Accept: application/json" \
  -c $COOKIE_FILE)

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" == "204" ] || [ "$HTTP_CODE" == "200" ]; then
    print_success "Cookie CSRF récupéré (HTTP $HTTP_CODE)"
    if [ -f "$COOKIE_FILE" ]; then
        print_info "Cookies sauvegardés dans $COOKIE_FILE"
        grep -i "xsrf" $COOKIE_FILE || print_info "Cookie XSRF présent dans les headers"
    fi
else
    print_error "Échec (HTTP $HTTP_CODE)"
    echo "$BODY"
fi
echo ""

# Extraire le token CSRF du cookie
XSRF_TOKEN=$(grep -i "xsrf" $COOKIE_FILE | awk '{print $7}' | tail -n1)
if [ -z "$XSRF_TOKEN" ]; then
    print_info "Note: Le token CSRF sera dans les headers Set-Cookie"
fi
echo ""

echo "2️⃣  Test: POST /api/register"
echo "-----------------------------"
REGISTER_DATA='{
  "first_name": "Test",
  "last_name": "User",
  "username": "testuser",
  "email": "test@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}'

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/register" \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:3000" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF_TOKEN" \
  -d "$REGISTER_DATA" \
  -b $COOKIE_FILE \
  -c $COOKIE_FILE)

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" == "201" ] || [ "$HTTP_CODE" == "200" ]; then
    print_success "Inscription réussie (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    print_info "Session créée, cookie laravel_session défini"
else
    print_error "Échec de l'inscription (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
fi
echo ""

echo "3️⃣  Test: POST /api/login"
echo "-------------------------"
# Essayer d'abord avec un utilisateur existant
LOGIN_DATA='{
  "login": "test@example.com",
  "password": "password123"
}'

print_info "Tentative de connexion avec email..."
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/login" \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:3000" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF_TOKEN" \
  -d "$LOGIN_DATA" \
  -b $COOKIE_FILE \
  -c $COOKIE_FILE)

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" == "200" ]; then
    print_success "Connexion réussie (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    print_info "Cookie de session défini"
else
    print_error "Échec de la connexion (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    print_info "Note: Si l'utilisateur n'existe pas, créez-le d'abord avec /api/register"
fi
echo ""

echo "4️⃣  Test: GET /api/user"
echo "-----------------------"
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "$BASE_URL/api/user" \
  -H "Origin: http://localhost:3000" \
  -H "Accept: application/json" \
  -b $COOKIE_FILE)

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" == "200" ]; then
    print_success "Utilisateur récupéré (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    print_info "✅ Session valide - Pas d'erreur 401"
else
    print_error "Échec (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    if [ "$HTTP_CODE" == "401" ]; then
        print_error "Session non valide ou expirée"
    fi
fi
echo ""

echo "5️⃣  Test: POST /api/logout"
echo "--------------------------"
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/logout" \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:3000" \
  -H "Accept: application/json" \
  -H "X-XSRF-TOKEN: $XSRF_TOKEN" \
  -d '{}' \
  -b $COOKIE_FILE \
  -c $COOKIE_FILE)

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" == "200" ]; then
    print_success "Déconnexion réussie (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    print_info "Session invalidée, cookies supprimés"
else
    print_error "Échec de la déconnexion (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
fi
echo ""

echo "6️⃣  Test: GET /api/user (après logout)"
echo "--------------------------------------"
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "$BASE_URL/api/user" \
  -H "Origin: http://localhost:3000" \
  -H "Accept: application/json" \
  -b $COOKIE_FILE)

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" == "401" ]; then
    print_success "Correctement déconnecté (HTTP 401 attendu)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
else
    print_error "Problème: Devrait retourner 401 après logout (HTTP $HTTP_CODE)"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
fi
echo ""

echo "=========================================="
echo "✅ Tests terminés"
echo ""
echo "📋 Résumé:"
echo "  - Cookie CSRF: $(if [ -f "$COOKIE_FILE" ]; then echo '✅'; else echo '❌'; fi)"
echo "  - Fichier cookies: $COOKIE_FILE"
echo ""
echo "💡 Pour tester avec un autre utilisateur:"
echo "   Modifiez les variables LOGIN_DATA et REGISTER_DATA dans ce script"

