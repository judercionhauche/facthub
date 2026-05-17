#!/bin/bash

# Test all main pages for HTTP response
echo "Testing all pages..."
echo ""

pages=("login" "researchers" "funding" "admin" "profile" "account" "matching" "institutions" "messages")

for page in "${pages[@]}"; do
    response=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/fact_hub2/public/index.php?page=$page")
    if [ "$response" == "200" ] || [ "$response" == "302" ]; then
        echo "✓ $page: HTTP $response"
    else
        echo "✗ $page: HTTP $response (ERROR)"
    fi
done

echo ""
echo "Done!"
