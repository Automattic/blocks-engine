<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

/** @return array<int,array<string,mixed>> */
$sourceAssets = static fn (array $result, string $source): array => array_values(array_filter(
    is_array($result['assets'] ?? null) ? $result['assets'] : array(),
    static fn (array $asset): bool => $source === ($asset['source'] ?? '')
));

$cssFor = static function (array $result, string $source, ?string $placement = null) use ($sourceAssets): string {
    $assets = $sourceAssets($result, $source);
    if ( null !== $placement ) {
        $assets = array_values(array_filter(
            $assets,
            static fn (array $asset): bool => $placement === ($asset['stylesheet_placement'] ?? '')
        ));
    }

    return implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $assets));
};

/** @return array{int,int,int} */
$specificity = static function (string $selector): array {
    // :where() and its arguments always contribute zero specificity.
    $selector = preg_replace('/:where\([^)]*\)/', '', $selector) ?? $selector;
    $ids = preg_match_all('/#[A-Za-z0-9_-]+/', $selector);
    $classes = preg_match_all('/\.[A-Za-z0-9_-]+|\[[^\]]+\]|:(?!:)[A-Za-z0-9_-]+(?:\([^)]*\))?/', $selector);
    $withoutWeightedTokens = preg_replace('/#[A-Za-z0-9_-]+|\.[A-Za-z0-9_-]+|\[[^\]]+\]|::?[A-Za-z0-9_-]+(?:\([^)]*\))?|[>+~*]/', ' ', $selector) ?? $selector;
    $elements = preg_match_all('/(?:^|\s)([A-Za-z][A-Za-z0-9_-]*)/', $withoutWeightedTokens);

    return array( (int) $ids, (int) $classes, (int) $elements );
};

$synthetic = ( new HtmlTransformer() )->transform(
    '<style>p{margin:0}.site-header{display:flex;align-items:center}.brand{font-size:18px;font-weight:700}</style>'
        . '<header class="site-header"><a class="brand" href="/">Verified Artifact</a></header><footer><span>Portable input.</span></footer><p>Source paragraph.</p>'
)->toArray();
$inlineLayout = ( new HtmlTransformer() )->transform(
    '<style>.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > strong{display:block;margin:12px 0 4.8px}.artifact-card .card-label{display:block;grid-column:1 / -1;color:#6040cc;margin:2px 0}</style>'
        . '<div class="artifact-card"><span class="card-label">Input</span><strong>index.html</strong><span class="card-label">styles.css</span></div>'
)->toArray();
$layoutItems = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="styles.css"><main><div class="hero-visual"><div class="artifact-card"><span class="card-label">Input</span><strong>index.html</strong><span>styles.css</span><span>assets/</span></div></div></main>' ),
        array( 'path' => 'styles.css', 'kind' => 'css', 'content' => '.hero-visual{display:grid;gap:2rem}.artifact-card{display:grid;grid-template-columns:1fr auto}.artifact-card > span:not(.card-label){grid-column:2}.artifact-card > strong{grid-column:1}.artifact-card .card-label{grid-column:1 / -1}' ),
    ),
) )->toArray();
$authorMargin = ( new HtmlTransformer() )->transform(
    '<style>.proof-row{display:flex;justify-content:space-between;gap:16px}.authored-margin{margin-top:27px;margin-bottom:9px;margin-block-start:31px;margin-block-end:11px}</style>'
        . '<div class="proof-row"><p class="authored-margin">Authored spacing</p><p>Sibling</p></div>'
)->toArray();

$beforeCss = '';
foreach ( array( $synthetic, $inlineLayout, $layoutItems, $authorMargin ) as $result ) {
    $beforeCss .= "\n" . $cssFor($result, 'engine-support', 'before-author');
}

$layoutRules = array(
    'synthetic paragraph' => ':root :where(.blocks-engine-synthetic-paragraph){margin-top:0;margin-bottom:0}',
    'css-owned flow paragraph' => ':root :where(.blocks-engine-css-owned-flow>p){margin-top:0;margin-bottom:0}',
    'css-owned flow direct children' => ':root :where(.wp-block-group.blocks-engine-css-owned-flow)>*{margin-block-start:0;margin-block-end:0}',
    'css-owned grid direct children' => ':root :where(.blocks-engine-css-owned-grid)>*{margin-block-start:0;margin-block-end:0}',
    'css-owned layout-item direct children' => ':root :where(.wp-block-group.blocks-engine-css-owned-layout-item)>*{margin-block-start:0;margin-block-end:0}',
);

foreach ( $layoutRules as $name => $rule ) {
    $selector = substr($rule, 0, (int) strpos($rule, '{'));
    $assert(str_contains($beforeCss, $rule), 'C2: ' . $name . ' reset uses the core-matching :root specificity pattern');
    $assert(array( 0, 1, 0 ) === $specificity($selector), 'C2: ' . $name . ' reset has specificity (0,1,0)');
    $assert(! str_contains($rule, '!important'), 'C3: ' . $name . ' reset does not use !important');
}

$authorAssets = $sourceAssets($authorMargin, 'author-css');
$supportAssets = array_values(array_filter(
    $sourceAssets($authorMargin, 'engine-support'),
    static fn (array $asset): bool => 'before-author' === ($asset['stylesheet_placement'] ?? '')
));
$orderedAssets = is_array($authorMargin['assets'] ?? null) ? $authorMargin['assets'] : array();
$supportIndex = null;
$authorIndex = null;
foreach ( $orderedAssets as $index => $asset ) {
    if ( 'engine-support' === ($asset['source'] ?? '') && 'before-author' === ($asset['stylesheet_placement'] ?? '') ) {
        $supportIndex = $index;
    }
    if ( 'author-css' === ($asset['source'] ?? '') ) {
        $authorIndex = $index;
    }
}
$authorCss = $cssFor($authorMargin, 'author-css');
$authorSelector = '.authored-margin';
$assert(
    str_contains((string) ($authorMargin['serialized_blocks'] ?? ''), 'blocks-engine-css-owned-flow')
        && str_contains((string) ($authorMargin['serialized_blocks'] ?? ''), 'authored-margin'),
    'C3: authored margin and layout reset target the same generated flow child'
);
$assert(1 === count($supportAssets) && 1 === count($authorAssets) && is_int($supportIndex) && is_int($authorIndex) && $supportIndex < $authorIndex, 'C3: engine reset precedes author CSS');
$assert(array( 0, 1, 0 ) === $specificity($authorSelector), 'C3: authored margin selector matches reset specificity (0,1,0)');
$assert(str_contains($authorCss, '.authored-margin{margin-top:27px;margin-bottom:9px;margin-block-start:31px;margin-block-end:11px}'), 'C3: later author CSS retains its physical and logical margin declarations');
$assert(! str_contains($beforeCss, 'margin-block-start:0!important') && ! str_contains($beforeCss, 'margin-block-end:0!important'), 'C3: normal authored margin can win the equal-specificity source-order tie');

if ( $failures > 0 ) {
    fwrite(STDERR, "Engine support CSS specificity contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Engine support CSS specificity contract passed: {$passes} assertions\n");
