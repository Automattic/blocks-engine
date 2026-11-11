<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$cssFor = static function (string $html): string {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    return implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
};
$fixture = (string) file_get_contents(__DIR__ . '/fixtures/navigation-margin-cascade.html');
$css = $cssFor($fixture);
$repair = '.wp-block-navigation:where(.blocks-engine-list-navigation).menu{margin-left:auto}';
$assert(str_contains($css, $repair), 'the non-important repair has specificity 0,2,0, below the projected tablet rule');
$assert(! str_contains($css, 'margin-left:auto!important'), 'no important auto can defeat authored margins');
$assert(1 === preg_match('/@media \(min-width:768px\) and \(max-width:1279px\)\{\.is-multiline \.menu:not\(\.[^()]+\)\{margin-left:-22px\}\}/', $css), 'the authored tablet margin survives under its gate at specificity 0,3,0 and beats the repair');

$staticOverride = $cssFor(str_replace('@media (min-width:768px) and (max-width:1279px){.is-multiline .menu{margin-left:-22px}}', '.is-multiline .menu{margin-left:-22px}', $fixture));
$assert(1 === preg_match('/\.is-multiline \.menu:not\(\.[^()]+\)\{margin-left:-22px\}/', $staticOverride), 'a static higher-specificity authored margin also survives and outranks the repair');

// The actual Core stylesheet, not a made-up reset: 0,1,1 and no !important.
$coreCss = (string) file_get_contents(dirname(__DIR__) . '/fixtures/core-navigation-style.css');
$assert(1 === preg_match('/\.wp-block-navigation ul\s*\{([^}]+)\}/', $coreCss, $coreRule), 'Core still has its navigation list reset');
$assert(1 === preg_match('/margin-left:\s*0\s*;/', $coreRule[1]), 'Core reset is non-important, so the 0,2,0 repair wins');

// #896: the original one-sided shorthand still moves the flex-item host.
$endAligned = $cssFor('<style>nav{display:flex;gap:20px}.navlinks{list-style:none;display:flex;gap:16px;padding:0;margin:0 0 0 auto}</style>'
    . '<nav aria-label="Primary"><a class="mark" href="/"><span>Harbor</span><span>Pilots</span></a><ul class="navlinks"><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul></nav>');
$assert(str_contains($endAligned, '.wp-block-navigation:where(.blocks-engine-list-navigation).navlinks{margin-left:auto;margin-right:0}'), 'the original end-aligned menu keeps its host auto and opposite-side zero without important');

// #5429: a class reset on the item (0,1,0) loses to the typed descendant rule
// in the source, plain (0,1,2) or runtime-scoped (0,2,2). The projected rules
// must keep that outcome.
$ruleFor = static function (string $css, string $declarations) use ($assert): array {
    $assert(1 === preg_match_all('/([^{}]+)\{' . preg_quote($declarations, '/') . '\}/', $css, $found, PREG_OFFSET_CAPTURE), "exactly one projected rule carries {$declarations} in: {$css}");
    $parsed = CssSelectorMatcher::parse(trim($found[1][0][0]));
    $assert((bool) $parsed['supported'], 'projected selector parses: ' . $found[1][0][0]);
    $weight = CssSelectorMatcher::specificityCounts($parsed);
    return array('selector' => trim($found[1][0][0]), 'specificity' => array($weight['ids'], $weight['classes'], $weight['types']), 'offset' => $found[1][0][1]);
};
$itemCases = array(
    'typed' => '<style>.txt{margin:0}.menu--desktop ul li{margin:0 16px}</style><nav class="menu--desktop"><ul><li class="txt"><a href="/">Home</a></li></ul></nav>',
    'runtime-scoped typed' => '<style>.txt{margin:0}.menu--desktop ul li[data-v-1a2b3c]{margin:0 16px}</style><nav class="menu--desktop" data-v-1a2b3c><ul data-v-1a2b3c><li class="txt" data-v-1a2b3c><a href="/" data-v-1a2b3c>Home</a></li></ul></nav>',
);
foreach ($itemCases as $label => $reduced) {
    $projected = $cssFor($reduced);
    $reset = $ruleFor($projected, 'margin:0');
    $spacing = $ruleFor($projected, 'margin:0 16px');
    $order = $spacing['specificity'] <=> $reset['specificity'];
    $assert(1 === $order || (0 === $order && $spacing['offset'] > $reset['offset']), sprintf(
        '%s item margin %s (%s) must outrank class reset %s (%s), as it does in the source',
        $label, $spacing['selector'], implode(',', $spacing['specificity']), $reset['selector'], implode(',', $reset['specificity'])
    ));
}

// Same class, separate gates: neither may be flattened or deduplicated away.
$conditional = $cssFor(str_replace(
    '.menu{margin-left:auto}',
    '@media (min-width:1280px){.menu{margin-left:auto}}@media (max-width:600px){.menu{margin-right:auto}}',
    $fixture
));
$assert(str_contains($conditional, '@media (min-width:1280px){' . $repair . '}'), 'conditional left auto retains its media gate');
$assert(str_contains($conditional, '@media (max-width:600px){.wp-block-navigation:where(.blocks-engine-list-navigation).menu{margin-right:auto}}'), 'a second gate on the same class is not overwritten');
$assert(1 === substr_count($conditional, $repair), 'conditional left auto has no unconditional repair copy');
echo "ok\n";
