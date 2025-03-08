#!/bin/bash
AUTHOR=$1
WP_USERNAME=$(curl -s "https://profiles.wordpress.org/wp-json/wporg-github/v1/lookup/$AUTHOR" | jq -r '.slug' || echo "$AUTHOR")
echo "$AUTHOR = $WP_USERNAME <$WP_USERNAME@git.wordpress.org>"
