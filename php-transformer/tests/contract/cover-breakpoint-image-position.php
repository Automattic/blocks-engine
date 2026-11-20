<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixtures = array();
foreach (array(
    'single position' => array('38% center', true),
    'per-layer position' => array('center, 38% center', true),
    'unchanged position' => array('42% center', false),
    'unchanged image layer' => array('left, 42% center', false),
    'no breakpoint position' => array('', false),
) as $name => [$position, $changes]) {
    $source = '<style>.card{min-height:360px;padding:34px;color:#fff;background-image:linear-gradient(90deg,#0008,#0004),url(hero.jpg)!important;background-size:cover!important;background-position:42% center!important}'
        . '@media(max-width:600px){.card{' . ('' === $position ? '' : 'background-position:' . $position . '!important;') . 'padding:26px!important}}</style>'
        . '<main><section class="card"><h2>Request a session</h2><p>Pick a slot and we will confirm.</p></section></main>';
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!str_contains($json, '"focalPoint":{"x":0.42,"y":0.5}')) {
        throw new RuntimeException($name . ': base focal point must remain unchanged.');
    }
    $hasRule = 1 === preg_match('/@media\s*\(max-width:600px\)\{\.blocks-engine-cover-position-[a-f0-9]+>\.wp-block-cover__image-background\{object-position:38% 50%!important\}\}/', $json);
    if ($changes !== $hasRule || (!$changes && str_contains($json, 'blocks-engine-cover-position-'))) {
        throw new RuntimeException($name . ': only changed image positions need a scoped media override.');
    }
    $fixtures[$name] = array('source' => $source, 'result' => $result, 'expected' => $changes ? '38% 50%' : '42% 50%');
}

if (in_array('--browser-fixtures', $argv, true)) {
    echo json_encode($fixtures, JSON_THROW_ON_ERROR);
} else {
    echo "Cover breakpoint image position contract passed.\n";
}
