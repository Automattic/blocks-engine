<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #4328: the frame intentionally overflows a zero-width absolute containing
// block. A synthesized percentage max-width collapses its authored pixel box.
$html = <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><title>Overflowing image box</title>
<style>
.stage{position:relative;min-height:300px}
.item{position:absolute;left:50%;top:20px;margin-left:-200px}
.layer{position:absolute;left:0;top:0;right:0;bottom:0}
.frame{position:relative;overflow:hidden}
</style></head>
<body>
<div class="stage">
  <div class="item"><div class="layer"><div class="frame" style="width: 400px; height: 240px"><img src="red.png" alt="Red square" style="width: 400px; height: 250px" width="400" height="250"></div></div></div>
</div>
</body></html>
HTML;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$compile = static function (string $source): array {
    return (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $source ),
    ))->toArray();
};

$frameRule = static function (array $result) use ($assert): string {
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(
        1 === preg_match('/<div\b[^>]*class="[^"]*\bframe\b[^"]*"[^>]*>/', $markup, $frame),
        'the authored frame remains a group box'
    );
    $assert(
        1 === preg_match('/\bbe-inline-geometry-[0-9a-f-]+\b/', $frame[0], $carrier),
        'the frame references an inline geometry carrier'
    );
    $css = '';
    foreach ( (array) ($result['assets'] ?? array()) as $asset ) {
        $css .= (string) ($asset['content'] ?? '');
    }
    $assert(
        1 === preg_match('/\.' . preg_quote($carrier[0], '/') . '\{([^}]*width:400px[^}]*)\}/', $css, $rule),
        'the frame carrier restates its authored 400px width'
    );
    $assert(str_contains($rule[1], 'height:240px'), 'the frame carrier retains its authored 240px height');

    return $rule[1];
};

$rule = $frameRule($compile($html));
$assert(! str_contains($rule, 'max-width:'), 'the frame carrier must not synthesize max-width:100%: ' . $rule);

// An authored bound still belongs to the box carrier.
$rule = $frameRule($compile(str_replace('width: 400px; height: 240px', 'width: 400px; height: 240px; max-width: 100%', $html)));
$assert(str_contains($rule, 'max-width:100%'), 'the frame retains an authored max-width:100%');

// A viewport-conditioned bound stays in the author cascade, not frozen in
// an important geometry declaration that would clamp the box at every width.
$responsive = $compile(str_replace('</style>', '@media(max-width:600px){.frame{max-width:100%}}</style>', $html));
$rule = $frameRule($responsive);
$assert(! str_contains($rule, 'max-width:'), 'a responsive source clamp must not become an unconditional carrier clamp');
$css = implode("\n", array_column((array) ($responsive['assets'] ?? array()), 'content'));
$assert(
    1 === preg_match('/@media\s*\(max-width:\s*600px\)\s*\{[^}]*\.frame[^}]*max-width:\s*100%/', $css),
    'the authored narrow-viewport clamp survives in the stylesheet'
);

echo "fixed-width overflowing frame ok\n";
