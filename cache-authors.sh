#!/bin/bash
set -e

AUTHORS_FILE="authors.json"

# Extract authors from Git history if an argument is provided (otherwise, get all)
if [ -n "$1" ]; then
  AUTHORS=$(git log --format='%aN' -- $1 | sort -u)
else
  AUTHORS=$(git log --format='%aN' | sort -u)
fi

# Store known usernames in memory to avoid duplicate API calls
declare -A USERNAME_CACHE

for AUTHOR in $AUTHORS; do
  if jq -e ".\"$AUTHOR\"" "$AUTHORS_FILE" >/dev/null; then
    continue
  fi

  # Check cache first
  if [[ -n "${USERNAME_CACHE[$AUTHOR]}" ]]; then
    WP_USERNAME=${USERNAME_CACHE[$AUTHOR]}
  else
    # Fetch from API with retries
    WP_USERNAME=""
    for i in {1..3}; do
      WP_USERNAME=$(curl -s "https://profiles.wordpress.org/wp-json/wporg-github/v1/lookup/$AUTHOR" | jq -r '.slug' || echo "null")
      if [[ "$WP_USERNAME" != "null" ]]; then
        USERNAME_CACHE[$AUTHOR]=$WP_USERNAME
        break
      fi
      sleep $((2 ** i))
    done
  fi

  if [[ "$WP_USERNAME" != "null" ]]; then
    jq --arg author "$AUTHOR" --arg wp_user "$WP_USERNAME" '. + {($author): $wp_user}' "$AUTHORS_FILE" > authors_tmp.json && mv authors_tmp.json "$AUTHORS_FILE"
  fi
done

echo "Updated author cache in $AUTHORS_FILE"
