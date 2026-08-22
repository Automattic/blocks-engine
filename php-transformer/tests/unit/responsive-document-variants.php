<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$artifact = array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'website/index.html',
    'document_variants' => array(
        array(
            'source_path' => 'website/index.html',
            'variants' => array(
                array(
                    'id' => 'mobile',
                    'source_path' => 'website/.variants/mobile/index.html',
                    'media' => '(max-width: 768px)',
                ),
            ),
        ),
    ),
    'files' => array(
        array(
            'path' => 'website/index.html',
            'content' => '<!doctype html><html><head><style>:root{--site-width:980px}body.desktop main{width:var(--site-width)}</style></head><body class="desktop"><main><h1>Desktop</h1></main></body></html>',
        ),
        array(
            'path' => 'website/.variants/mobile/index.html',
            'role' => 'document_variant',
            'content' => '<!doctype html><html><head><style>:root{--site-width:320px}body.mobile main{width:var(--site-width)}</style></head><body class="mobile" style="overflow:hidden"><main><h1>Mobile</h1></main></body></html>',
        ),
    ),
);

$compiler = new ArtifactCompiler();
$result = $compiler->compile($artifact)->toArray();
$blocks = (string) ($result['serialized_blocks'] ?? '');
$assetCss = implode("\n", array_map(
    static fn(array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'] ?? array(), 'is_array')
));

$assert(str_contains($blocks, 'site-document-variant-default'), 'Primary document is wrapped as the default variant.');
$assert(str_contains($blocks, 'site-document-variant-mobile'), 'Mobile document is emitted as an editable variant.');
$assert(str_contains($blocks, '>Desktop<') && str_contains($blocks, '>Mobile<'), 'Both responsive document bodies remain editable block content.');
$assert(str_contains($assetCss, '@media (max-width: 768px)'), 'Variant visibility is controlled by the declared media query.');
$assert(str_contains($assetCss, '.site-document-variant-mobile{--site-width:320px}'), 'Mobile root custom properties are scoped to the mobile document wrapper.');
$assert(str_contains($assetCss, '.site-document-variant-mobile.mobile main'), 'Mobile body selectors are projected onto the mobile document wrapper.');

$sharedPlan = $compiler->prepareShared($artifact);
$pagePlan = $compiler->preparePage($artifact, $sharedPlan, 'website/index.html');
$stagedBlocks = (string) ($compiler->compose($sharedPlan, array($pagePlan))->toArray()['serialized_blocks'] ?? '');
$assert(str_contains($stagedBlocks, 'site-document-variant-default') && str_contains($stagedBlocks, 'site-document-variant-mobile'), 'Staged compilation preserves the same responsive variants.');

$invalid = $artifact;
$invalid['document_variants'][0]['variants'][0]['media'] = '(max-width:768px)}body{display:none}';
try {
    (new ArtifactCompiler())->compile($invalid);
    $assert(false, 'Unsafe variant media is rejected.');
} catch (InvalidArgumentException) {
    $assert(true, 'Unsafe variant media is rejected.');
}

fwrite(STDOUT, "Responsive document variant tests passed.\n");
