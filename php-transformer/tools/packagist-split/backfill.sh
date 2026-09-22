#!/usr/bin/env bash
# Backfill the php-transformer subtree mirror with every historical
# php-transformer-v* release tag (translated to vX.Y.Z), then publish the
# current origin/trunk split to the mirror's trunk.
#
# Usage:
#   php-transformer/tools/packagist-split/backfill.sh [--dry-run]
#
# Run from anywhere inside a full (non-shallow) clone of
# Automattic/blocks-engine with origin/trunk fetched. Safe to re-run: subtree
# Homeboy subtree publications are deterministic, so re-runs reproduce
# identical mirror history. This is also the disaster-recovery path if the
# mirror repository is lost.
set -euo pipefail

DRY_RUN=0

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        *) echo "Usage: $0 [--dry-run]" >&2; exit 64 ;;
    esac
done

cd "$(git rev-parse --show-toplevel)"

if [ "$(git rev-parse --is-shallow-repository)" = "true" ]; then
    echo "Refusing to run from a shallow clone: historical subtree publication needs full history." >&2
    exit 1
fi

tag_count=0
while IFS= read -r tag; do
    tag_count=$((tag_count + 1))
    version="v${tag#php-transformer-v}"
    echo "${tag} -> ${version}"
    if [ "$DRY_RUN" = "1" ]; then
        homeboy git subtree php-transformer "$tag" --path php-transformer --version "${version#v}"
    else
        homeboy git subtree php-transformer "$tag" --apply --path php-transformer --version "${version#v}"
    fi
done < <(git tag --list 'php-transformer-v*' | sort -V)

if [ "$tag_count" -eq 0 ]; then
    echo "No php-transformer-v* tags found." >&2
    exit 1
fi

echo "origin/trunk -> trunk"
if [ "$DRY_RUN" = "1" ]; then
    echo "DRY RUN: no refs will be updated"
    homeboy git subtree php-transformer origin/trunk --path php-transformer --branch-only
else
    homeboy git subtree php-transformer origin/trunk --apply --path php-transformer --branch-only
fi
