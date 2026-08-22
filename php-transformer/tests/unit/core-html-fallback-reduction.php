<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $label) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($label);
    }
};

$runtime = new Runtime();
$transformer = new HtmlTransformer($runtime);
$source = array(
    array(
        'blockName' => 'core/group',
        'attrs' => array('className' => 'outer'),
        'innerBlocks' => array(
            array('blockName' => 'core/html', 'attrs' => array('content' => '<section class="hero"><h2>Care <em>that works</em></h2><p>Book today</p><img src="assets/hero.jpg" alt="Clinic room"><ul><li>Assessment</li><li>Treatment</li></ul><a href="/book/">Reserve</a></section>'), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array()),
            array('blockName' => 'core/html', 'attrs' => array('content' => '<form class="lead-form"><input name="email"></form>'), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array()),
        ),
        'innerHTML' => '',
        'innerContent' => array(null, null),
    ),
);

$reduced = $transformer->reduceCoreHtmlFallbackBlocks($source);
$serialized = $runtime->serializeBlocks($reduced);
$names = array();
$walk = static function (array $blocks) use (&$walk, &$names): void {
    foreach ($blocks as $block) {
        $names[] = $block['blockName'] ?? '';
        $walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
    }
};
$walk($reduced);
$htmlIslands = array();
$collectHtmlIslands = static function (array $blocks) use (&$collectHtmlIslands, &$htmlIslands): void {
    foreach ($blocks as $block) {
        if ('core/html' === ($block['blockName'] ?? '')) {
            $htmlIslands[] = $block['attrs']['content'] ?? '';
        }
        $collectHtmlIslands(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array());
    }
};
$collectHtmlIslands($reduced);

$assert(1 === count(array_keys($names, 'core/html', true)), 'unsafe-form-remains-a-core-html-island');
$assert(in_array('core/heading', $names, true), 'heading-reduced-by-producer');
$assert(in_array('core/paragraph', $names, true), 'paragraph-reduced-by-producer');
$assert(in_array('core/image', $names, true), 'image-reduced-by-producer');
$assert(in_array('core/list', $names, true), 'list-reduced-by-producer');
$assert(in_array('core/button', $names, true), 'button-reduced-by-producer');
$assert(array('<form class="lead-form"><input name="email"></form>') === $htmlIslands, 'unsafe-island-payload-is-lossless');
$assert('' !== $serialized, 'reduced-output-serializes');
$assert(array() === ($runtime->validateBlockSerialization($reduced)['invalid_blocks'] ?? array()), 'reduced-output-is-editor-valid');

echo "OK: core HTML fallback reduction passed ({$assertions} assertions)\n";
