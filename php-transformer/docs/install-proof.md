# PHP Transformer Install Proof

This proof verifies that `automattic/blocks-engine-php-transformer` installs from a clean throwaway Composer project through a Composer path repository. The proof uses path variables so the command shape is reusable and does not rely on reviewer-facing local links.

## Package Verification

Run from the transformer package directory:

```sh
cd "$BLOCKS_ENGINE_WORKTREE/php-transformer"
composer install
composer validate
composer test
git diff --check
```

Observed result on 2026-06-19:

| Command | Result |
| --- | --- |
| `composer install` | Installed 9 packages from `composer.lock` and generated autoload files. |
| `composer validate` | `./composer.json is valid`. |
| `composer test` | Passed runtime no-WP, runtime stubs, HTML-to-blocks contract, format bridge scaffold, downstream examples smoke, and 15 parity fixtures. |
| `git diff --check` | Passed with no whitespace errors. |

## Clean Path Repository Install

Run from an empty throwaway directory outside the repository:

```sh
export BLOCKS_ENGINE_WORKTREE="/path/to/blocks-engine-worktree"
export PROOF_DIR="/tmp/php-transformer-install-proof"

mkdir "$PROOF_DIR"
cd "$PROOF_DIR"
composer init --no-interaction --name=proof/php-transformer-install
composer config repositories.php-transformer '{"type":"path","url":"'"$BLOCKS_ENGINE_WORKTREE"'/php-transformer","options":{"symlink":false}}' --json
composer require "automattic/blocks-engine-php-transformer:dev-cook/php-transformer-package-install-proof as 0.1.x-dev"
composer validate --no-check-publish
composer install
php -r 'require __DIR__ . "/vendor/autoload.php"; $result = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform("<h1>Proof</h1>")->toArray(); if (($result["serialized_blocks"] ?? "") === "") { fwrite(STDERR, "missing serialized_blocks\n"); exit(1); } print $result["status"] . "\n";'
```

Observed result on 2026-06-19 using a throwaway project under the local temp workspace:

| Command | Result |
| --- | --- |
| `composer init --no-interaction --name=proof/php-transformer-install` | Created a new root `composer.json`. |
| `composer config repositories.php-transformer ... --json` | Added a path repository pointing at `php-transformer` with `symlink: false`. |
| `composer require "automattic/blocks-engine-php-transformer:dev-cook/php-transformer-package-install-proof as 0.1.x-dev"` | Locked 10 installs, including `automattic/blocks-engine-php-transformer` at `dev-cook/php-transformer-package-install-proof` with path dist reference `f11cbf91c9b73172c7a25ac6c4c17e60633732d9`; Composer mirrored the package instead of symlinking it. |
| `composer validate --no-check-publish` | Valid for an unpublished throwaway project; only the expected no-license warning was reported for the proof root package. |
| `composer install` | Verified the lock file and reported `Nothing to install, update or remove`. |
| PHP autoload smoke command | Printed `success`, proving the installed package autoloads `HtmlTransformer` and returns serialized block output. |

The package is untagged on this branch, so the proof uses an explicit dev branch constraint with an inline `0.1.x-dev` alias. A tagged release can replace the path repository and dev constraint with the published version constraint.
