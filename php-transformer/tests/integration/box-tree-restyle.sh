#!/usr/bin/env bash
set -euo pipefail
# Reuses the site-plan installer; requires a served, disposable WordPress site.
root="$(realpath "$(dirname "$0")/../..")"
: "${RESTYLE_WP_CLI:?Required disposable-site wp-cli command}"
: "${RESTYLE_EVIDENCE_DIR:?Required evidence directory}"
mkdir -p "$RESTYLE_EVIDENCE_DIR"
export RESTYLE_DISPOSABLE=1
export RESTYLE_FIXTURE="$root/tests/fixtures/box-tree/artifact.php"
export RESTYLE_ROUTES="$RESTYLE_EVIDENCE_DIR/box-tree-routes.json"
RESTYLE_PHASE=install $RESTYLE_WP_CLI eval-file "$root/tests/integration/design-system-restyle-install.php"
node "$root/tests/integration/box-tree-restyle-browser.mjs"
$RESTYLE_WP_CLI eval-file "$root/tests/integration/box-tree-editor-color.php"
BOX_TREE_EDITED=1 node "$root/tests/integration/box-tree-restyle-browser.mjs"
