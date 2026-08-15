<?php
declare(strict_types=1);

/**
 * Contract for inline display declarations which override class-owned layout.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/** @return array<int, array<string, mixed>> */
$sourceAssets = static fn (array $result, string $source): array => array_values(array_filter(
    is_array($result['assets'] ?? null) ? $result['assets'] : array(),
    static fn (array $asset): bool => $source === ($asset['source'] ?? '')
));

$cssFor = static function (array $result, string $source) use ($sourceAssets): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        $sourceAssets($result, $source)
    ));
};

/** @return array{int, int, int} */
$specificity = static function (string $selector): array {
    $ids = preg_match_all('/#[A-Za-z0-9_-]+/', $selector);
    $classes = preg_match_all('/\.[A-Za-z0-9_-]+|\[[^\]]+\]|:(?!:)[A-Za-z0-9_-]+(?:\([^)]*\))?/', $selector);
    $withoutWeightedTokens = preg_replace('/#[A-Za-z0-9_-]+|\.[A-Za-z0-9_-]+|\[[^\]]+\]|::?[A-Za-z0-9_-]+(?:\([^)]*\))?|[>+~*]/', ' ', $selector) ?? $selector;
    $elements = preg_match_all('/(?:^|\s)([A-Za-z][A-Za-z0-9_-]*)/', $withoutWeightedTokens);

    return array( (int) $ids, (int) $classes, (int) $elements );
};

$cases = array(
    'block' => array(
        'class' => 'display-owner-block',
        'style' => 'display:block',
        'declarations' => 'display:block',
    ),
    'inline-block' => array(
        'class' => 'display-owner-inline-block',
        'style' => 'display:inline-block',
        'declarations' => 'display:inline-block',
    ),
    'flex' => array(
        'class' => 'display-owner-flex',
        'style' => 'display:flex;flex-direction:column;flex-wrap:wrap;align-items:center;justify-content:center;gap:2rem',
        'declarations' => 'display:flex;flex-direction:column;flex-wrap:wrap;align-items:center;justify-content:center;gap:2rem',
    ),
);

foreach ( $cases as $name => $case ) {
    $className = $case['class'];
    $html = '<style>.' . $className . '{display:grid;align-items:start;gap:3rem}</style>'
        . '<div class="' . $className . '" style="' . $case['style'] . '"><p>First</p><p>Second</p></div>';
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $serialized = (string) ($result['serialized_blocks'] ?? '');
    $engineCss = $cssFor($result, 'engine-support');
    $authorCss = $cssFor($result, 'author-css');
    preg_match('/\b(be-inline-geometry-[a-f0-9-]+)\b/', $serialized, $carrierMatch);
    $carrierClass = (string) ($carrierMatch[1] ?? '');
    $selector = ':root .' . $carrierClass;
    $expectedRule = $selector . '{' . $case['declarations'] . '}';
    $ruleMatch = array();
    if ( '' !== $carrierClass ) {
        preg_match('/(' . preg_quote($selector, '/') . ')\{([^}]*)\}/', $engineCss, $ruleMatch);
    }

    $assert('core/group' === ($result['blocks'][0]['blockName'] ?? ''), $name . ': source wrapper remains a core/group', (string) ($result['blocks'][0]['blockName'] ?? '(none)'));
    $assert(str_contains($serialized, $className), $name . ': transformed markup retains the class whose grid declaration is being neutralized', $serialized);
    $assert(str_contains($authorCss, '.' . $className . '{display:grid'), $name . ': author CSS retains the competing class-owned display:grid rule', $authorCss);
    $assert('' !== $carrierClass, $name . ': transformed markup receives a geometry carrier class', $serialized);
    $assert(str_contains($engineCss, $expectedRule), $name . ': engine-support carries the complete inline layout override', $engineCss);
    $assert(1 !== preg_match('/be-inline-geometry-[a-f0-9-]+/', $authorCss), $name . ': generated carrier rule does not leak into author-css', $authorCss);
    $assert(array( 0, 2, 0 ) === $specificity((string) ($ruleMatch[1] ?? '')), $name . ': carrier selector has specificity (0,2,0)', (string) ($ruleMatch[1] ?? '(missing)'));
    $assert('' !== (string) ($ruleMatch[2] ?? '') && ! str_contains((string) $ruleMatch[2], '!important'), $name . ': inline layout carrier rule does not use !important', (string) ($ruleMatch[2] ?? '(missing)'));
}

$gridResult = ( new HtmlTransformer() )->transform(
    '<section class="source-grid" style="display:grid;grid-template-columns:260px 1fr;align-items:center;justify-content:space-between;gap:32px"><div>Rail</div><div>Body</div></section>',
    array()
)->toArray();
$gridSerialized = (string) ($gridResult['serialized_blocks'] ?? '');
$gridCss = $cssFor($gridResult, 'engine-support');
preg_match('/\b(be-inline-geometry-[a-f0-9-]+)\b/', $gridSerialized, $gridCarrierMatch);
$gridCarrier = (string) ($gridCarrierMatch[1] ?? '');
$gridRule = '.' . $gridCarrier . '{display:grid !important;grid-template-columns:260px 1fr !important;align-items:center !important;justify-content:space-between !important;gap:32px !important}';

$assert(str_contains($gridSerialized, 'blocks-engine-css-owned-grid') && '' !== $gridCarrier, 'grid control: legitimate inline grid remains on the existing css-owned-grid carrier path', $gridSerialized);
$assert(str_contains($gridCss, $gridRule), 'grid control: existing grid display and companion declaration bytes remain unchanged', $gridCss);

if ( $failures > 0 ) {
    fwrite(STDERR, "Inline display carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Inline display carrier contract passed: {$passes} assertions\n");
