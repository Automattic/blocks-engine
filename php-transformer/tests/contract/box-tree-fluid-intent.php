<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssFluidLength;

// ADR 0005 §4 slice 3b: a carrier lifts the author's fluid expression when it
// reproduces the observed used value at every captured viewport, and keeps
// pixels otherwise. Evidence: n5 is <main>, n6 the <h1> inside it.
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);

/** @param array<string, array{0: string, 1: string}> $observed node.property => [desktop, mobile] */
function fluid_carrier_output(string $html, array $tree, string $author, array $observed): string
{
    $source = str_replace('</head>', '<style>' . $author . '</style></head>', $html);
    $tree['source_hash'] = hash('sha256', $source);
    $tree['viewports']['mobile']['width'] = 402;
    foreach ($observed as $target => [$desktop, $mobile]) {
        [$node, $property] = explode('.', $target, 2);
        $tree['nodes'][$node]['viewports']['desktop']['used_styles'][$property] = $desktop;
        $tree['nodes'][$node]['viewports']['mobile']['used_styles'][$property] = $mobile;
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree))))->toArray();
    $css = implode("\n", array_column($result['assets'], 'content'));
    preg_match_all('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|[^{}@]*\.be-inline-geometry-[^{}]*\{[^{}]*\}/', $css, $rules);
    $carriers = array_values(array_filter($rules[0], static fn(string $rule): bool => str_contains($rule, 'be-inline-geometry-')));

    return $result['serialized_blocks'] . "\n" . implode("\n", $carriers);
}

function fluid_assert(bool $condition, string $message, string $output): void
{
    if (!$condition) throw new RuntimeException($message . "\n" . $output);
    echo 'PASS: ' . $message . "\n";
}

// The evaluator: dimension-checked, every base explicit.
$evaluator = array(
    array('5.2vw', 1440, null, null, 74.88),
    array('max(0.5px, 0.1875 * (clamp(0px, 100 * 1vw * 1, min(9999px, 99999999999px)) - 0px))', 402, null, null, 75.375),
    array('0.8em', 402, null, 75.375, 60.3),
    array('50%', 1440, 200.0, null, 100.0),
    array('50%', 1440, null, null, null),
    array('1cqw', 1440, null, null, null),
    array('2rem', 1440, null, null, null),
    array('calc(10px * 2px)', 1440, null, null, null),
    array('calc(10px + 2)', 1440, null, null, null),
    array('auto', 1440, null, null, null),
);
foreach ($evaluator as [$value, $width, $percent, $em, $expected]) {
    $actual = CssFluidLength::evaluate($value, (float) $width, 900.0, $percent, $em);
    $ok = null === $expected ? null === $actual : null !== $actual && abs($actual - $expected) < 0.001;
    fluid_assert($ok, 'evaluate ' . $value . ' = ' . var_export($expected, true), var_export($actual, true));
}

// [author CSS, observed node.property => [desktop 1440, mobile 402], expected substrings, absent substrings]
$wixFont = ':root{--one-unit:1vw;--browser-zoom:1;--spx-stopper-min:0px;--spx-stopper-max:9999px;--section-max-width:99999999999px;--scrollbar-width:0px;'
    . '--full-viewport:100 * var(--one-unit) * var(--browser-zoom);'
    . '--scaling-factor:clamp(var(--spx-stopper-min), var(--full-viewport), min(var(--spx-stopper-max), var(--section-max-width)));'
    . '--wst-heading-1-font:normal normal 500 max(0.5px, 0.1875 * (var(--scaling-factor) - var(--scrollbar-width)))/0.8em serif}'
    . '@media screen and (max-width: 1023px){:root{--spx-stopper-max:700px}}';
$wixLifted = 'font-size:max(0.5px, 0.1875 * (clamp(0px, 100 * 1vw * 1, min(9999px, 99999999999px)) - 0px))';
// The author narrows the stopper at its own 1023px breakpoint, not at the
// 402px capture, so the narrow expression is banded at 1023px.
$wixLiftedNarrow = 'font-size:max(0.5px, 0.1875 * (clamp(0px, 100 * 1vw * 1, min(700px, 99999999999px)) - 0px))';
$cases = array(
    'vw font-size reproducing both captures lifts' => array(
        'h1{font-size:5.2vw}', array('n6.font-size' => array('75px', '20.9px')), array('font-size:5.2vw'), array('font-size:75px', 'font-size:20.9px'),
    ),
    'vw font-size not reproducing a capture stays in pixels' => array(
        'h1{font-size:5.2vw}', array('n6.font-size' => array('75px', '30px')), array('font-size:75px', 'font-size:30px'), array('5.2vw'),
    ),
    '% width against the parent content width lifts' => array(
        'h1{width:50%}', array('n5.width' => array('200px', '100px'), 'n6.width' => array('100px', '50px')), array('width:50%'), array('width:50px'),
    ),
    'clamp() lifts' => array(
        'h1{font-size:clamp(10px,5.2vw,100px)}', array('n6.font-size' => array('75px', '20.9px')), array('font-size:clamp(10px,5.2vw,100px)'), array('font-size:75px'),
    ),
    // imb-culinary-craft hero: a rem-bounded clamp() with a unitless line
    // height; the 768px width between captures must follow the author.
    'rem bounds lift in root pixels with a unitless line height' => array(
        'h1{font-size:clamp(2.5rem,5vw,4.5rem);line-height:1.05}', array('n6.font-size' => array('72px', '40px'), 'n6.line-height' => array('75.6px', '42px')),
        array('font-size:clamp(40px,5vw,72px)', 'line-height:1.05'), array('font-size:72px', 'font-size:40px', 'line-height:75.6px', 'rem'),
    ),
    'rem resolves against the author root font size' => array(
        'html{font-size:62.5%}h1{font-size:clamp(4rem,5vw,7.2rem)}', array('n6.font-size' => array('72px', '40px')),
        array('font-size:clamp(40px,5vw,72px)'), array('font-size:72px'),
    ),
    'a rem clamp not reproducing a capture stays in pixels' => array(
        'h1{font-size:clamp(2.5rem,5vw,4.5rem)}', array('n6.font-size' => array('72px', '30px')), array('font-size:72px', 'font-size:30px'), array('clamp('),
    ),
    'an invariant unitless line height stays in pixels' => array(
        'h1{font-size:5.2vw;line-height:1.5}', array('n6.font-size' => array('20px', '20px'), 'n6.line-height' => array('30px', '30px')), array('30px'), array('line-height:1.5'),
    ),
    'custom-property math lifts with every var() expanded' => array(
        ':root{--scale:100vw;--scrollbar:0px}h1{font-size:max(0.5px, 0.0410256 * (var(--scale) - var(--scrollbar)))}',
        array('n6.font-size' => array('59.077px', '16.492px')), array('font-size:max(0.5px, 0.0410256 * (100vw - 0px))'), array('var(--scale)', 'var(--scrollbar)'),
    ),
    'a token a width query switches carries each capture\'s proven expression' => array(
        ':root{--max:9999px}@media (max-width:700px){:root{--max:700px}}h1{font-size:calc(0.1875 * min(100vw, var(--max)))}',
        array('n6.font-size' => array('270px', '75.375px')),
        array('font-size:calc(0.1875 * min(100vw, 9999px))', '@media (max-width:700px){.be-inline-geometry-'),
        array('font-size:270px', 'font-size:75.375px', 'var(--max)', '@media (max-width:402px){.be-inline-geometry-664376bcd17819d6'),
    ),
    'bands at different author breakpoints are ordered widest first' => array(
        ':root{--a:9999px;--b:9999px}@media (max-width:1023px){:root{--a:700px}}@media (max-width:800px){:root{--b:700px}}'
            . 'h1{font-size:calc(0.1875 * min(100vw, var(--a)));margin-top:calc(0.1 * min(100vw, var(--b)))}',
        array('n6.font-size' => array('270px', '75.375px'), 'n6.margin-top' => array('144px', '40.2px')),
        array('@media (max-width:1023px){.be-inline-geometry-', '@media (max-width:800px){.be-inline-geometry-', 'margin-top:calc(0.1 * min(100vw, 700px))'),
        array('font-size:270px', 'margin-top:144px'),
    ),
    'a band between captures that no capture proves stays in pixels' => array(
        ':root{--max:9999px}@media (max-width:1023px){:root{--max:9000px}}@media (max-width:700px){:root{--max:700px}}h1{font-size:calc(0.1875 * min(100vw, var(--max)))}',
        array('n6.font-size' => array('270px', '75.375px')),
        array('font-size:270px', 'font-size:75.375px'), array('min(100vw'),
    ),
    'an undefined custom property stays in pixels' => array(
        'h1{font-size:calc(var(--missing) * 2)}', array('n6.font-size' => array('75px', '20.9px')), array('font-size:75px', 'font-size:20.9px'), array('var(--missing)'),
    ),
    'a later pixel winner stays in pixels' => array(
        'h1{font-size:5.2vw}h1{font-size:30px}', array('n6.font-size' => array('75px', '20.9px')), array('font-size:75px'), array('5.2vw'),
    ),
    'interaction states stay out' => array(
        'h1:hover{font-size:5.2vw}', array('n6.font-size' => array('75px', '20.9px')), array('font-size:75px'), array('5.2vw'),
    ),
    'a container-relative unit stays in pixels' => array(
        ':root{--u:1cqw}h1{font-size:calc(5.2 * var(--u))}', array('n6.font-size' => array('75px', '20.9px')), array('font-size:75px'), array('cqw'),
    ),
    'the margin shorthand lifts per side' => array(
        'h1{margin:2vw 0}', array('n6.margin-top' => array('28.8px', '8.04px'), 'n6.margin-bottom' => array('28.8px', '8.04px')),
        array('margin-top:2vw', 'margin-bottom:2vw'), array('margin-top:28.8px', 'margin-top:8.04px'),
    ),
    'a later longhand beats an earlier shorthand' => array(
        'h1{margin:2vw 0;margin-top:28.8px}', array('n6.margin-top' => array('28.8px', '8.04px'), 'n6.margin-bottom' => array('28.8px', '8.04px')),
        array('margin-bottom:2vw', 'margin-top:28.8px'), array('margin-top:2vw'),
    ),
    // sdnunca hero: the font shorthand behind nested var()/clamp() sits on the
    // rich-text parent; the heading inherits it over its user-agent default.
    'a font shorthand behind nested var()/clamp() lifts; the heading inherits it' => array(
        $wixFont . 'main{font:var(--wst-heading-1-font)}',
        array(
            'n5.font-size' => array('270px', '75.375px'), 'n5.line-height' => array('216px', '60.3px'), 'n5.font-weight' => array('500', '500'),
            'n6.font-size' => array('270px', '75.375px'), 'n6.line-height' => array('216px', '60.3px'), 'n6.font-weight' => array('500', '500'),
        ),
        array($wixLifted, $wixLiftedNarrow, '@media (max-width:1023px){', 'line-height:0.8em', 'font-size:inherit', 'line-height:inherit'),
        array('font-size:270px', 'font-size:75.375px', 'line-height:216px', 'line-height:60.3px', 'var(--', 'font-weight:inherit'),
    ),
    'an inherited value differing from the parent at a capture stays in pixels' => array(
        $wixFont . 'main{font:var(--wst-heading-1-font)}',
        array(
            'n5.font-size' => array('270px', '75.375px'), 'n5.line-height' => array('216px', '60.3px'),
            'n6.font-size' => array('270px', '40px'), 'n6.line-height' => array('216px', '60.3px'),
        ),
        array('font-size:270px', 'font-size:40px'), array('font-size:inherit'),
    ),
    'an authored heading size is not replaced by inherit' => array(
        $wixFont . 'main{font:var(--wst-heading-1-font)}h1{font-size:270px}@media (max-width:500px){h1{font-size:75.375px}}',
        array('n5.font-size' => array('270px', '75.375px'), 'n6.font-size' => array('270px', '75.375px')),
        array('font-size:270px', 'font-size:75.375px'), array('font-size:inherit'),
    ),
);
foreach ($cases as $name => [$author, $observed, $present, $absent]) {
    $output = fluid_carrier_output($html, $tree, $author, $observed);
    foreach ($present as $needle) {
        if (!str_contains($output, $needle)) throw new RuntimeException($name . ': missing ' . $needle . "\n" . $output);
    }
    foreach ($absent as $needle) {
        if (str_contains($output, $needle)) throw new RuntimeException($name . ': unexpected ' . $needle . "\n" . $output);
    }
    echo 'PASS: ' . $name . "\n";
}
