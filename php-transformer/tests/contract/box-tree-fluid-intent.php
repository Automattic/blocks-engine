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
    // #6580: a pixel length the author switches at its own breakpoint is
    // banded there, so widths between the captures follow the author.
    'a pixel length switched at an author breakpoint is banded there' => array(
        'h1{margin-top:40px}@media (max-width:900px){h1{margin-top:20px}}', array('n6.margin-top' => array('40px', '20px')),
        array('margin-top:40px', '@media (max-width:900px){.be-inline-geometry-', 'margin-top:20px'), array('@media (max-width:402px)'),
    ),
    'a pixel length no capture reproduces stays in capture bands' => array(
        'h1{margin-top:40px}', array('n6.margin-top' => array('40px', '20px')),
        array('margin-top:40px', '@media (max-width:402px)', 'margin-top:20px'), array('@media (max-width:900px)'),
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
    // Slice 3b part 2: sizing the author left intrinsic stays intrinsic.
    'an auto-width block keeps no captured width' => array(
        'h1{width:auto}', array('n6.width' => array('1440px', '402px')), array('h1 ;width:auto'), array('width:1440px', 'width:402px'),
    ),
    'an absent width restates the initial keyword' => array(
        'h1{color:red}', array('n6.width' => array('1440px', '402px'), 'n6.height' => array('80px', '120px')),
        array('h1 ;width:auto', 'h1 ;height:auto'), array('width:1440px', 'width:402px', 'height:80px', 'height:120px'),
    ),
    'an intrinsic sizing keyword lifts as written' => array(
        'h1{width:fit-content;max-width:none}', array('n6.width' => array('300px', '200px')), array('width:fit-content'), array('width:300px', 'width:200px'),
    ),
    'a declared width keeps its pixels' => array(
        'h1{width:300px}', array('n6.width' => array('300px', '300px')), array('h1 ;width:300px'), array('h1 ;width:auto'),
    ),
    'a width a query sets between captures keeps its pixels' => array(
        'h1{width:auto}@media (max-width:500px){h1{width:300px}}', array('n6.width' => array('1440px', '300px')),
        array('width:1440px', 'width:300px'), array('h1 ;width:auto'),
    ),
    // sdnunca header menu: the author switches the width's kind at a query.
    'a % width an author query switches to max-content lifts per author band' => array(
        'h1{width:85.57%}@media (max-width:1023px){h1{width:max-content}}',
        array('n5.width' => array('1000px', '402px'), 'n6.width' => array('855.7px', '30.92px')),
        array('h1 ;width:85.57%', '@media (max-width:1023px){', 'width:max-content'), array('width:855.7px', 'width:30.92px'),
    ),
    'a band neither lifter proves keeps its pixels' => array(
        'h1{width:85.57%}@media (max-width:1023px){h1{width:30.92px}}',
        array('n5.width' => array('1000px', '402px'), 'n6.width' => array('855.7px', '30.92px')),
        array('width:855.7px', 'width:30.92px'), array('width:85.57%', 'width:max-content'),
    ),
    'a logical size alias keeps its pixels' => array(
        'h1{inline-size:300px}', array('n6.width' => array('300px', '300px')), array('h1 ;width:300px'), array('h1 ;width:auto'),
    ),
    'a flex shorthand basis keeps its pixels' => array(
        'main{display:flex}h1{flex:0 0 300px}',
        array('n5.display' => array('flex', 'flex'), 'n6.flex-basis' => array('300px', '300px')), array('h1 ;flex-basis:300px'), array('h1 ;flex-basis:auto'),
    ),
    'a track list sized by fr and auto lifts as written' => array(
        'main{display:grid;grid-template-columns:minmax(0px,1fr);grid-template-rows:auto auto}',
        array('n5.display' => array('grid', 'grid'), 'n5.grid-template-columns' => array('1440px', '402px'), 'n5.grid-template-rows' => array('40px 20px', '60px 30px')),
        array('grid-template-columns:minmax(0px,1fr)', 'grid-template-rows:auto auto'), array('grid-template-columns:1440px', 'grid-template-columns:402px', 'grid-template-rows:40px'),
    ),
    'a percentage track list lifts as written' => array(
        'main{display:grid;grid-template-columns:50% 50%}',
        array('n5.display' => array('grid', 'grid'), 'n5.grid-template-columns' => array('720px 720px', '201px 201px')),
        array('grid-template-columns:50% 50%'), array('grid-template-columns:720px 720px', 'grid-template-columns:201px 201px'),
    ),
    'a fixed-length track list keeps its pixels' => array(
        'main{display:grid;grid-template-columns:100px 200px}',
        array('n5.display' => array('grid', 'grid'), 'n5.grid-template-columns' => array('100px 200px', '100px 200px')),
        array('grid-template-columns:100px 200px'), array('grid-template-columns:none'),
    ),
    'a minmax track list behind var() lifts with every var() expanded' => array(
        ':root{--scale:100vw;--scrollbar:0px}main{display:grid;grid-template-rows:minmax(max(0.5px, 0.29 * (var(--scale) - var(--scrollbar))), auto) minmax(0px, auto)}',
        array('n5.display' => array('grid', 'grid'), 'n5.grid-template-rows' => array('418.047px 285.469px', '300px 200px')),
        array('grid-template-rows:minmax(max(0.5px, 0.29 * (100vw - 0px)), auto) minmax(0px, auto)'),
        array('grid-template-rows:418.047px', 'grid-template-rows:300px', 'var(--scale)'),
    ),
    'an absent grid-auto-rows restates the initial keyword' => array(
        'main{display:grid}',
        array('n5.display' => array('grid', 'grid'), 'n5.grid-auto-rows' => array('40px', '60px')),
        array('grid-auto-rows:auto'), array('grid-auto-rows:40px', 'grid-auto-rows:60px'),
    ),
    'an in-flow % height lifts as written' => array(
        'h1{height:100%}', array('n6.height' => array('703.516px', '615.875px')),
        array('h1 ;height:100%'), array('height:703.516px', 'height:615.875px'),
    ),
    'a declared pixel height keeps its pixels' => array(
        'h1{height:120px}', array('n6.height' => array('120px', '120px')), array('h1 ;height:120px'), array('h1 ;height:auto'),
    ),
    // Percentages resolve against the CSS containing block.
    // n8 is a display:contents wrapper between <main> and the <h1>.
    'a % width under a display:contents parent lifts against the grandparent' => array(
        'h1{width:50%}',
        array('n8.display' => array('contents', 'contents'), 'n8.width' => array('auto', 'auto'), 'n5.width' => array('200px', '100px'), 'n6.width' => array('100px', '50px')),
        array('width:50%'), array('width:100px', 'width:50px'), true,
    ),
    'a % width under a display:contents parent not matching the grandparent keeps pixels' => array(
        'h1{width:50%}',
        array('n8.display' => array('contents', 'contents'), 'n5.width' => array('300px', '100px'), 'n6.width' => array('100px', '50px')),
        array('width:100px', 'width:50px'), array('width:50%'), true,
    ),
    'a % width against a border-box parent uses its content box' => array(
        'h1{width:50%}',
        array('n5.box-sizing' => array('border-box', 'border-box'), 'n5.width' => array('220px', '120px'), 'n5.padding-left' => array('10px', '10px'),
            'n5.padding-right' => array('10px', '10px'), 'n5.padding-top' => array('0px', '0px'), 'n5.padding-bottom' => array('0px', '0px'),
            'n6.width' => array('100px', '50px')),
        array('width:50%'), array('width:100px', 'width:50px'),
    ),
    'a left:50% absolute box lifts against the positioned ancestor padding box' => array(
        'main{position:relative}h1{position:absolute;left:50%;top:50%}',
        array('n5.position' => array('relative', 'relative'), 'n5.width' => array('200px', '100px'), 'n5.height' => array('50px', '30px'),
            'n5.padding-left' => array('10px', '10px'), 'n5.padding-right' => array('10px', '10px'), 'n5.padding-top' => array('0px', '0px'), 'n5.padding-bottom' => array('0px', '0px'),
            'n6.position' => array('absolute', 'absolute'), 'n6.left' => array('110px', '60px'), 'n6.top' => array('25px', '15px'),
            'n6.right' => array('110px', '60px'), 'n6.bottom' => array('25px', '15px')),
        // The four offsets fold into one inset shorthand, in top/right/bottom/left order.
        array('h1 inset:50% auto auto 50%'), array('h1 110px', 'h1 60px', 'h1 25px', 'h1 15px'),
    ),
    'a calc(% - px) offset lifts through the inset shorthand' => array(
        'main{position:relative}h1{position:absolute;inset:0 auto auto calc(50% - 24px)}',
        array('n5.position' => array('relative', 'relative'), 'n5.width' => array('200px', '100px'), 'n5.height' => array('50px', '30px'),
            'n5.padding-left' => array('0px', '0px'), 'n5.padding-right' => array('0px', '0px'), 'n5.padding-top' => array('0px', '0px'), 'n5.padding-bottom' => array('0px', '0px'),
            'n6.position' => array('absolute', 'absolute'), 'n6.left' => array('76px', '26px')),
        array('left:calc(50% - 24px)'), array('left:76px', 'left:26px'),
    ),
    'an absolute offset not matching the containing block keeps pixels' => array(
        'main{position:relative}h1{position:absolute;left:50%}',
        array('n5.position' => array('relative', 'relative'), 'n5.width' => array('200px', '100px'), 'n5.height' => array('50px', '30px'),
            'n5.padding-left' => array('10px', '10px'), 'n5.padding-right' => array('10px', '10px'), 'n5.padding-top' => array('0px', '0px'), 'n5.padding-bottom' => array('0px', '0px'),
            'n6.position' => array('absolute', 'absolute'), 'n6.left' => array('100px', '50px')),
        array('left:100px', 'left:50px'), array('left:50%'),
    ),
    'an absolute box with no positioned ancestor lifts against the viewport' => array(
        'h1{position:absolute;left:50%}',
        array('n6.position' => array('absolute', 'absolute'), 'n6.left' => array('720px', '201px')),
        array('left:50%'), array('left:720px', 'left:201px'),
    ),
    // Box-model percentages resolve against the containing block width, in their author @media context.
    'a % margin an author query switches lifts in its author band' => array(
        'h1{margin-left:38.188%}@media screen and (max-width:1023px){h1{margin-left:6.1538%}}',
        array('n5.width' => array('1440px', '402px'), 'n6.margin-left' => array('549.907px', '24.738px')),
        array('h1 ;margin-left:38.188%', '@media (max-width:1023px){', 'margin-left:6.1538%'), array('margin-left:549.907px', 'margin-left:24.738px'),
    ),
    'a vertical % padding resolves against the containing block width' => array(
        'h1{padding-top:5%}', array('n5.width' => array('200px', '100px'), 'n6.padding-top' => array('10px', '5px')),
        array('padding-top:5%'), array('padding-top:10px', 'padding-top:5px'),
    ),
    'a % margin not matching the containing block keeps pixels' => array(
        'h1{margin-left:10%}', array('n5.width' => array('200px', '100px'), 'n6.margin-left' => array('30px', '10px')),
        array('margin-left:30px', 'margin-left:10px'), array('margin-left:10%'),
    ),
    'an auto margin restates auto' => array(
        'h1{margin:0 auto}', array('n6.margin-left' => array('570px', '51px'), 'n6.margin-right' => array('570px', '51px')),
        array('h1 ;margin-left:auto', 'h1 ;margin-right:auto'), array('margin-left:570px', 'margin-left:51px'),
    ),
    'an absent margin keeps its pixels' => array(
        'h1{color:red}', array('n6.margin-left' => array('20px', '10px')), array('margin-left:20px', 'margin-left:10px'), array('margin-left:auto'),
    ),
    'a % column gap through the gap shorthand lifts against the own content box' => array(
        'main{display:flex;gap:0 10%}',
        array('n5.display' => array('flex', 'flex'), 'n5.width' => array('1000px', '400px'), 'n5.column-gap' => array('100px', '40px')),
        array('gap:none 10%'), array('column-gap:100px', 'column-gap:40px', 'gap:none 100px', 'gap:none 40px'),
    ),
);
/** The heading's carrier rules: needles prefixed `h1 ` are matched there only. */
function fluid_heading_carrier(string $output): string
{
    if (!preg_match('/<h1 class="[^"]*\b(be-inline-geometry-[0-9a-f]+)/', $output, $match)) return '';
    return implode("\n", array_filter(explode("\n", $output), static fn(string $line): bool => str_contains($line, '.' . $match[1] . '.')));
}
$wrappedHtml = str_replace(array('<h1 ', '</h1>'), array('<div data-blocks-engine-node-id="n8"><h1 ', '</h1></div>'), $html);
$wrappedTree = $tree;
$wrappedTree['nodes']['n8'] = $tree['nodes']['n6'];
$wrappedTree['nodes']['n8']['children'] = array('n6');
$wrappedTree['nodes']['n5']['children'] = array('n8', 'n7');
foreach ($cases as $name => $case) {
    [$author, $observed, $present, $absent] = $case;
    $wrapped = $case[4] ?? false;
    $output = fluid_carrier_output($wrapped ? $wrappedHtml : $html, $wrapped ? $wrappedTree : $tree, $author, $observed);
    $heading = fluid_heading_carrier($output);
    foreach ($present as $needle) {
        $haystack = str_starts_with($needle, 'h1 ') ? $heading : $output;
        if (!str_contains($haystack, preg_replace('/^h1 /', '', $needle))) throw new RuntimeException($name . ': missing ' . $needle . "\n" . $output);
    }
    foreach ($absent as $needle) {
        $haystack = str_starts_with($needle, 'h1 ') ? $heading : $output;
        if (str_contains($haystack, preg_replace('/^h1 /', '', $needle))) throw new RuntimeException($name . ': unexpected ' . $needle . "\n" . $output);
    }
    echo 'PASS: ' . $name . "\n";
}
