<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

// A shared theme stylesheet carries menu rules for markup this site never
// renders; only arms that can style a source element are replayed. Route-state
// projection has already removed the served item's `current-item` class.
$served = '<nav id="menu"><ul><li><a href="/">Home</a></li></ul></nav>';
$css = '#menu ul li.current-item > a { color: red; }'
    . '#menu ul li.absent > a { color: blue; }'
    . '#menu ul li.js-open > a { color: green; }'
    . '#menu ul li[data-absent] > a { color: purple; }'
    . '#menu ul li > a:hover { color: teal; }';
$out = (new WordPressCompatCss())->css($css, array(array('kind' => 'html', 'content' => $served)), array('el.classList.add("js-open")'));
$expected = array('red' => true, 'blue' => false, 'green' => true, 'purple' => true, 'teal' => true);
foreach ($expected as $color => $replayed) {
    if ($replayed !== str_contains($out, 'color: ' . $color)) {
        throw new RuntimeException(sprintf('Arm painting %s should %sbe replayed:%s%s', $color, $replayed ? '' : 'not ', PHP_EOL, $out));
    }
}

// The matcher also matches an id on the element carrying its editor anchor
// class, so a served copy whose id moved there keeps the id's arms live.
$anchored = '<nav class="' . EngineMarker::editorAnchorClass('menu') . '"><ul><li><a href="/">Home</a></li></ul></nav>';
$out = (new WordPressCompatCss())->css('#menu ul li > a { color: maroon; }', array(array('kind' => 'html', 'content' => $anchored)), array());
if (!str_contains($out, 'color: maroon')) throw new RuntimeException('An id held by its editor anchor class should be replayed:' . PHP_EOL . $out);

// An arm the matcher cannot evaluate stays live whatever classes it names;
// the source index only rules on evaluable arms.
$compat = new WordPressCompatCss();
$compat->css('', array(array('kind' => 'html', 'content' => $served)), array());
$mayMatchSource = new ReflectionMethod($compat, 'mayMatchSource');
$expected = array('.absent:focus-within' => true, '#menu ul li:hover .absent' => true, '#menu ul li.absent > a' => false, '#menu ul li > a' => true);
foreach ($expected as $selector => $live) {
    if ($live !== $mayMatchSource->invoke($compat, $selector)) throw new RuntimeException(sprintf('%s should be %s.', $selector, $live ? 'live' : 'dead'));
}
echo "WordPress compatibility source liveness passed\n";
