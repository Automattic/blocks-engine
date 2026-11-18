<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #6445: a typography-only <strong> placed directly in a flex column is
// blockified by its parent, so its line box is its own line-height. A
// universal reset (Tailwind preflight) also gives it zero margins, padding and
// border. Those declarations add no box, so the phrasing element must keep the
// boxless inline-layout carrier instead of a synthetic paragraph, whose own
// strut line box made the item 2px taller than the source.
$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

$card = '<div class="card"><div class="mark">ZEN</div><strong>Get Zen with Zach</strong><span>Open the player.</span></div>';
$base = '.card{display:flex;flex-direction:column;justify-content:center;min-height:360px}'
    . '.mark{margin-bottom:24px;font-size:11px}'
    . '.card strong{letter-spacing:-.05em;font-size:clamp(32px,4vw,58px);line-height:1}';
$carrierFor = static function (string $css) use ($card): string {
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main>' . $card . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    return 1 === preg_match('/<p class="([^"]*)"><strong>Get Zen with Zach<\/strong><\/p>/', $markup, $match) ? $match[1] : '';
};

$cases = array(
    'no reset' => '',
    'margin reset' => '*{margin:0}',
    'preflight reset' => '*,:after,:before{box-sizing:border-box;border:0 solid;margin:0;padding:0}',
);
foreach ($cases as $name => $reset) {
    $classes = $carrierFor($reset . $base);
    $assert(str_contains(' ' . $classes . ' ', ' blocks-engine-inline-layout-carrier '), $name . ': the blockified <strong> keeps the boxless inline-layout carrier, got "' . $classes . '"');
    $assert(! str_contains($classes, 'blocks-engine-synthetic-paragraph'), $name . ': the blockified <strong> must not get a synthetic paragraph line box');
}

// A border that paints is real box chrome, not an inert reset.
$painted = $carrierFor('*{border:1px solid}' . $base);
$assert(! str_contains($painted, 'blocks-engine-inline-layout-carrier'), 'painted border: a real box is not treated as a typography-only leaf');

echo "Universal reset flex item phrasing carrier: passed\n";
