<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// core/button serializes its padding onto the inner link, while className lands
// on the wrapper. A global reset's zero padding must stay in the button's own
// attributes; moved to a wrapper marker it leaves the link to the theme's
// `.wp-element-button` padding and shifts the label sideways.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        's.css' => '*,:after,:before{box-sizing:border-box;border:0 solid;margin:0;padding:0}button{background-color:transparent;padding:0}'
            . '.q{display:flex;width:100%;align-items:center;justify-content:space-between;padding-top:1.25rem;padding-bottom:1.25rem;text-align:left}',
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="s.css"></head><body><main><section><div class="item">'
            . '<button class="q"><span>What types of events do you cater for?</span><span>+</span></button>'
            . '<div><p>We cater for weddings.</p></div></div></section></main></body></html>',
    ),
))->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(1 === preg_match('/<!-- wp:button (\{.*?\}) -->/', $markup, $match), 'The question converts to a core/button');
$attrs = json_decode($match[1], true);
$padding = $attrs['style']['spacing']['padding'] ?? array();
$assert('1.25rem' === ($padding['top'] ?? null) && '1.25rem' === ($padding['bottom'] ?? null), 'Authored vertical padding stays on the button');
$assert(isset($padding['left'], $padding['right']) && '0' === preg_replace('/px$/', '', (string) $padding['left']) && '0' === preg_replace('/px$/', '', (string) $padding['right']), 'Reset horizontal padding stays in the button attributes that reach its link: ' . json_encode($padding));
$assert(!str_contains($markup, 'blocks-engine-zero-core-button-padding'), 'No wrapper marker carries link padding');
fwrite(STDOUT, "Button zero padding stays on link contract passed\n");
