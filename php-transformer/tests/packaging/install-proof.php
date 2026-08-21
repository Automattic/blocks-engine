<?php
/**
 * Verify the package installs from php-transformer/ as a Composer package root.
 *
 * This catches monorepo path/autoload drift before a release operator tags or
 * asks Packagist/subtree automation to index the package.
 *
 * @package BlocksEnginePhpTransformer
 */

declare(strict_types=1);

$packageRoot = dirname(__DIR__, 2);
$proofRoot   = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'blocks-engine-php-transformer-install-proof-' . bin2hex(random_bytes(6));

mkdir($proofRoot, 0777, true);

try {
    $repositoryConfig = json_encode(
        array(
            'type'    => 'path',
            'url'     => $packageRoot,
            'options' => array(
                'symlink' => false,
            ),
        ),
        JSON_THROW_ON_ERROR
    );

    run($proofRoot, array('composer', 'init', '--no-interaction', '--name=proof/php-transformer-install'));
    run($proofRoot, array('composer', 'config', 'repositories.php-transformer', $repositoryConfig, '--json'));
    run($proofRoot, array('composer', 'config', 'minimum-stability', 'dev'));
    run($proofRoot, array('composer', 'config', 'prefer-stable', 'true'));
    run($proofRoot, array('composer', 'require', 'automattic/blocks-engine-php-transformer:*@dev', '--no-interaction', '--prefer-dist', '--no-progress'));

    $smoke = <<<'PHP'
require __DIR__ . '/vendor/autoload.php';
$packageRoot = __DIR__ . '/vendor/automattic/blocks-engine-php-transformer';
$requiredFiles = array(
    'docs/contracts/php-transformer-visual-parity-fixture.schema.json',
    'docs/contracts/php-transformer-visual-parity-report.schema.json',
    'docs/contracts/visual-parity-report.md',
    'resources/wordpress-6.6-core-block-attributes.json',
    'resources/wordpress-6.6-core-block-supports.json',
    'tools/visual-parity/package-lock.json',
    'tools/visual-parity/package.json',
    'tools/visual-parity/bin/visual-parity.mjs',
    'tools/visual-parity/tests/fixtures/source.html',
    'tools/visual-parity/tests/fixtures/target.html',
    'tools/visual-parity/tests/smoke.mjs',
);
foreach ( $requiredFiles as $requiredFile ) {
    if ( ! is_file($packageRoot . '/' . $requiredFile) ) {
        fwrite(STDERR, "php-transformer install proof missing package file: {$requiredFile}\n");
        exit(1);
    }
}
$transformer = new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer();
$result = $transformer->transform('<h1>Proof</h1>')->toArray();
if ('success' !== ($result['status'] ?? '') || '' === ($result['serialized_blocks'] ?? '')) {
    fwrite(STDERR, "php-transformer install proof failed\n");
    exit(1);
}
$group = $transformer->transform('<section style="border-left:2px solid red"><p>Native</p></section>')->toArray();
$groupBorder = $group['blocks'][0]['attrs']['style']['border']['left'] ?? null;
if (array('width' => '2px', 'style' => 'solid', 'color' => 'red') !== $groupBorder) {
    fwrite(STDERR, "php-transformer install proof failed native border support\n");
    exit(1);
}
$quote = $transformer->transform('<blockquote style="border-left:2px solid red">Fallback</blockquote>')->toArray();
$quoteAttrs = $quote['blocks'][0]['attrs'] ?? array();
$quoteCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $quote['assets'] ?? array()));
if (isset($quoteAttrs['style']['border']) || ! str_contains((string) ($quoteAttrs['className'] ?? ''), 'be-inline-geometry-') || ! str_contains($quoteCss, 'border-left-width:2px !important')) {
    fwrite(STDERR, "php-transformer install proof failed unsupported border fallback\n");
    exit(1);
}
PHP;

    run($proofRoot, array(PHP_BINARY, '-r', $smoke));
} finally {
    removeTree($proofRoot);
}

fwrite(STDOUT, "php-transformer package install proof passed\n");

/**
 * @param list<string> $command Command and arguments.
 */
function run(string $cwd, array $command): void
{
    $env                          = getenv();
    $env['COMPOSER_ROOT_VERSION'] = '1.0.0';

    $process = proc_open(
        $command,
        array(
            0 => array('pipe', 'r'),
            1 => STDOUT,
            2 => STDERR,
        ),
        $pipes,
        $cwd,
        $env
    );

    if ( ! is_resource($process) ) {
        throw new RuntimeException('Failed to start command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $status = proc_close($process);

    if ( 0 !== $status ) {
        throw new RuntimeException('Command failed with status ' . $status . ': ' . implode(' ', $command));
    }
}

function removeTree(string $path): void
{
    if ( ! file_exists($path) ) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $items as $item ) {
        if ( $item->isDir() && ! $item->isLink() ) {
            rmdir($item->getPathname());
            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($path);
}
