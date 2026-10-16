#!/usr/bin/env bash
set -euo pipefail

# Proves in WordPress and Chromium that converted sites follow a Global
# Styles palette edit (#2894). For each fixture: install it in the disposable
# site, render every route, give every palette entry a new value, render
# again, and require that at least RESTYLE_MINIMUM (default 0.8) of the text
# elements changed colour.
#
# Requires: RESTYLE_WP_CLI (wp-cli command, e.g. "php wp-cli.phar --path=site")
# for a disposable site served at its home URL, RESTYLE_EVIDENCE_DIR, and
# PLAYWRIGHT_MODULE.
root="$(realpath "$(dirname "$0")/../..")"
: "${RESTYLE_WP_CLI:?RESTYLE_WP_CLI must run wp-cli against the disposable site}"
: "${RESTYLE_EVIDENCE_DIR:?RESTYLE_EVIDENCE_DIR must name an evidence directory}"
mkdir -p "$RESTYLE_EVIDENCE_DIR"
export RESTYLE_DISPOSABLE=1
for fixture in ${RESTYLE_FIXTURES:-tailwind-v3 tailwind-v4 tailwind-shell}; do
    export RESTYLE_FIXTURE="$root/tests/fixtures/design-system/$fixture.php"
    export RESTYLE_ROUTES="$RESTYLE_EVIDENCE_DIR/$fixture-routes.json"
    export RESTYLE_EVIDENCE="$RESTYLE_EVIDENCE_DIR/$fixture-restyle.json"
    RESTYLE_PHASE=install $RESTYLE_WP_CLI eval-file "$root/tests/integration/design-system-restyle-install.php"
    RESTYLE_PHASE=before node "$root/tests/integration/design-system-restyle-browser.mjs"
    RESTYLE_PHASE=recolor $RESTYLE_WP_CLI eval-file "$root/tests/integration/design-system-restyle-install.php"
    RESTYLE_PHASE=after node "$root/tests/integration/design-system-restyle-browser.mjs"
done
