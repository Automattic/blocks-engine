# PHP Transformer Install Proof

This proof verifies that `automattic/blocks-engine-php-transformer` installs from a clean throwaway Composer project through a Composer path repository. The automated proof mirrors the package into the throwaway project with `symlink: false` so tests catch missing package files, incorrect autoload paths, and monorepo-root assumptions.

## Package Verification

Run from the transformer package directory:

```sh
cd "$BLOCKS_ENGINE_WORKTREE/php-transformer"
composer install
composer validate --strict
composer test
git diff --check
```

Observed result on 2026-06-21:

| Command | Result |
| --- | --- |
| `composer install` | Installed packages from `composer.lock` and generated autoload files. |
| `composer validate --strict` | `./composer.json is valid`. |
| `composer test` | Passed runtime contracts, 37 parity fixtures, and clean package-install proof. |
| `git diff --check` | Passed with no whitespace errors. |

## Automated Clean Path Repository Install

Run from the transformer package directory:

```sh
composer test:packaging
```

The test creates a throwaway Composer project under the system temp directory, configures a non-symlinked path repository that points at `php-transformer`, requires `automattic/blocks-engine-php-transformer:*@dev`, and runs a PHP autoload smoke check against `HtmlTransformer`.

## Manual Clean Path Repository Install

Run from an empty throwaway directory outside the repository:

```sh
export BLOCKS_ENGINE_WORKTREE="/path/to/blocks-engine-worktree"
export PROOF_DIR="/tmp/php-transformer-install-proof"

mkdir "$PROOF_DIR"
cd "$PROOF_DIR"
composer init --no-interaction --name=proof/php-transformer-install
composer config repositories.php-transformer '{"type":"path","url":"'"$BLOCKS_ENGINE_WORKTREE"'/php-transformer","options":{"symlink":false}}' --json
composer config minimum-stability dev
composer config prefer-stable true
composer require "automattic/blocks-engine-php-transformer:*@dev"
composer validate --no-check-publish
composer install
php -r 'require __DIR__ . "/vendor/autoload.php"; $result = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform("<h1>Proof</h1>")->toArray(); if (($result["serialized_blocks"] ?? "") === "") { fwrite(STDERR, "missing serialized_blocks\n"); exit(1); } print $result["status"] . "\n";'
```

Observed result on 2026-06-19 using a throwaway project under the local temp workspace:

| Command | Result |
| --- | --- |
| `composer init --no-interaction --name=proof/php-transformer-install` | Created a new root `composer.json`. |
| `composer config repositories.php-transformer ... --json` | Added a path repository pointing at `php-transformer` with `symlink: false`. |
| `composer require "automattic/blocks-engine-php-transformer:*@dev"` | Locked the local path package and mirrored it instead of symlinking it. |
| `composer validate --no-check-publish` | Valid for an unpublished throwaway project; only the expected no-license warning was reported for the proof root package. |
| `composer install` | Verified the lock file and reported `Nothing to install, update or remove`. |
| PHP autoload smoke command | Printed `success`, proving the installed package autoloads `HtmlTransformer` and returns serialized block output. |

The package is untagged on review branches, so the proof allows the local dev package explicitly. A tagged release can replace the path repository and dev constraint with the published version constraint.
