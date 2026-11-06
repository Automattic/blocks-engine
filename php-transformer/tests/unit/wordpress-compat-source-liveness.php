<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;

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
echo "WordPress compatibility source liveness passed\n";
