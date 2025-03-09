#!/bin/bash
set -e

# Ensure we're in the correct working directory
cd /__w/bbp-action-test2/bbp-action-test2 || exit 1

# Configure Git authentication
git config --global credential.helper store
echo "https://x-access-token:ghs_RXbggudlRZMvf3uZ9rj8fIIQ2IxN4e3IcIYN@github.com" > ~/.git-credentials
git config --global user.name "github-actions[bot]"
git config --global user.email "github-actions[bot]@users.noreply.github.com"

# Ensure correct Git remote is set
if ! git remote | grep -q "origin"; then
  git remote add origin "https://github.com/JJJ/bbp-action-test2.git"
else
  git remote set-url origin "https://github.com/JJJ/bbp-action-test2.git"
fi

# Push only if there are changes
if [ -n "$(git status --porcelain)" ]; then
  git push --all origin --force
  git push --tags origin --force
else
  echo "No new commits to push."
fi
