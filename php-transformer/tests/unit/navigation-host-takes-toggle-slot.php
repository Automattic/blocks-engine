<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// An unwired glyph toggle is superseded by native navigation. The host,
// not Core's child opener, must occupy its grid cell and margin box.
$html = <<<'HTML'
<style>body{margin:0}.bar{display:grid;grid-template:151px / 320px}.brand{grid-area:1/1/2/2;align-self:start;margin:50px 0 0 20px}
.bar button{display:none;border:0;background:none;font-size:27px;width:50px;height:50px}
.bar nav{display:flex;gap:22px}
@media(max-width:1000px){.bar button{display:block;grid-area:1/1/2/2;justify-self:start;align-self:start;margin:61px 0 0 257px;position:relative}.bar nav{display:none}.bar nav.open{display:flex}}</style>
<header><div class="bar"><a class="brand" href="#home">Brand</a><button>☰</button><nav><a href="#home">Home</a><a href="#about">About</a><a href="#work">Work</a></nav></div></header><main><section id="home"><h1>Hello</h1></section></main>
HTML;
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) {
        $css .= ($asset['content'] ?? '') . "\n";
    }
}
$failures = array();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (!str_contains($markup, '"overlayMenu":"mobile"')
    || !preg_match('/blocks-engine-native-navigation-toggle-[a-f0-9]+/', $markup, $marker)) {
    $failures[] = 'The glyph toggle must be replaced by native mobile navigation.';
}
$host = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation.' . ($marker[0] ?? 'missing');
$hostBody = '';
if (preg_match('/@media\s*\(max-width:1000px\)\s*\{\s*' . preg_quote($host, '/') . '\{([^{}]*)\}/', $css, $match)) {
    $hostBody = $match[1];
} else {
    $failures[] = 'The native host rule must be inside the source max-width:1000px collapse query.';
}
foreach (array('grid-area' => '1/1/2/2', 'justify-self' => 'start', 'align-self' => 'start', 'margin' => '61px 0 0 257px') as $property => $value) {
    if (!str_contains($hostBody, $property . ':' . $value . '!important')) {
        $failures[] = "The native host must carry $property:$value at the collapsed viewport.";
    }
}
preg_match_all('/[^{}]*\.wp-block-navigation__responsive-container-open\s*\{([^{}]*)\}/', $css, $openRules);
if (array() === $openRules[1]) {
    $failures[] = 'The native open button must have generated presentation rules.';
}
foreach ($openRules[1] as $body) {
    if (preg_match('/(?:^|;)\s*(?:grid-area|justify-self|align-self)\s*:|(?:^|;)\s*margin(?:-[a-z]+)?\s*:[^;]*61px/', $body)) {
        $failures[] = 'The source grid slot and margins must not be applied to Core\'s open button.';
    }
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
if (array() !== $failures) {
    exit(1);
}
echo "navigation host takes toggle slot passed\n";
