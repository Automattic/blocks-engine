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
            'content' => '<!doctype html><html><head><style>:root{--site-width:980px}body.desktop{display:grid;min-width:980px;overflow:hidden}body.desktop main{width:var(--site-width)}.card{display:grid}</style></head><body class="desktop"><main><h1 class="card">Desktop</h1></main></body></html>',
        ),
        array(
            'path' => 'website/.variants/mobile/index.html',
            'role' => 'document_variant',
            'content' => '<!doctype html><html><head><style>:root{--site-width:320px}body.mobile{display:flex;min-width:320px;overflow:hidden}body.mobile main{width:var(--site-width)}.card{display:block}</style></head><body class="mobile" style="overflow:hidden"><main><h1 class="card">Mobile</h1></main></body></html>',
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
$assert(str_contains($assetCss, '@scope (.site-document-variant-mobile)'), 'Mobile styles are isolated by the mobile document scope.');
$assert(str_contains($assetCss, ':scope{--site-width:320px}'), 'Mobile root custom properties target the mobile document scope.');
$assert(str_contains($assetCss, ':scope.mobile main'), 'Mobile body selectors are projected onto the mobile document scope.');
$assert(str_contains($assetCss, ':scope.mobile{display:flex;min-width:320px;overflow:hidden}'), 'Mobile body geometry stays on its projected wrapper.');
$assert(!str_contains($assetCss, 'display:contents!important'), 'Variant visibility never removes the active body wrapper box.');
$assert(str_contains($assetCss, '@media not all and (max-width: 768px){.site-document-variant-mobile{display:none!important}}'), 'Only inactive variants receive a display override.');
$assert(str_contains($assetCss, '@scope (.site-document-variant-default)') && str_contains($assetCss, '.card{display:grid}'), 'Primary selectors remain editable inside the default document scope.');
$assert(str_contains($assetCss, '.card{display:block}'), 'Mobile selectors remain editable inside the mobile document scope.');
$assert(str_contains($blocks, 'site-document-variant-default desktop'), 'Primary body classes are projected onto the default document wrapper.');

$sharedPlan = $compiler->prepareShared($artifact);
$pagePlan = $compiler->preparePage($artifact, $sharedPlan, 'website/index.html');
$staged = $compiler->compose($sharedPlan, array($pagePlan))->toArray();
$stagedBlocks = (string) ($staged['serialized_blocks'] ?? '');
$assert(str_contains($stagedBlocks, 'site-document-variant-default') && str_contains($stagedBlocks, 'site-document-variant-mobile'), 'Staged compilation preserves the same responsive variants.');
$assert($blocks === $stagedBlocks && ($result['source_reports']['wordpress_site_plan'] ?? array()) === ($staged['source_reports']['wordpress_site_plan'] ?? array()), 'Direct and staged compilation preserve identical responsive document content and site plans.');

$invalid = $artifact;
$invalid['document_variants'][0]['variants'][0]['media'] = '(max-width:768px)}body{display:none}';
try {
    (new ArtifactCompiler())->compile($invalid);
    $assert(false, 'Unsafe variant media is rejected.');
} catch (InvalidArgumentException) {
    $assert(true, 'Unsafe variant media is rejected.');
}

fwrite(STDOUT, "Responsive document variant tests passed.\n");
