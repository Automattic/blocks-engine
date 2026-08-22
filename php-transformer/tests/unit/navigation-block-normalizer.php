<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/HtmlToBlocks/NavigationBlockNormalizer.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\NavigationBlockNormalizer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$normalizer = new NavigationBlockNormalizer(static fn (string $label): string => trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$document->loadHTML('<body><ul><li id="services"><a>Services</a></li><li id="services"><a>Services</a><div><ul><li><a>Design</a></li></ul></div></li></ul></body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$body = $document->getElementsByTagName('body')->item(0);
if ( ! $body instanceof DOMElement ) {
    fwrite(STDERR, "FAIL: unable to create navigation fixture DOM\n");
    exit(1);
}
$normalizer->hydrateDuplicateSubmenus($body);
$items = $body->getElementsByTagName('li');
$assert(2 === $items->item(0)?->getElementsByTagName('a')->length, 'hydrates the shallow duplicate item with the complete submenu tree');

$link = static fn (string $label, string $url): array => array('blockName' => 'core/navigation-link', 'attrs' => array('label' => $label, 'url' => $url), 'innerBlocks' => array());
$navigation = static fn (int $provenanceId, string $label = 'Home'): array => array(
    'blockName' => 'core/navigation',
    '_source_provenance_id' => $provenanceId,
    'innerBlocks' => array($link($label, '/')),
    'innerContent' => array('<nav>', null, '</nav>'),
    'innerHTML' => '<nav></nav>',
);

$sourceProvenance = array(
    1 => array('source_attributes' => array('class' => 'desktop-nav'), 'context' => array('class_names' => array('desktop-nav'))),
    2 => array('source_attributes' => array('class' => 'mobile-nav'), 'context' => array('class_names' => array('mobile-nav'))),
);
$normalized = $normalizer->normalize(array($navigation(2), $navigation(1)), $sourceProvenance, array(2 => true));
$assert(1 === count($normalized) && 1 === ($normalized[0]['_source_provenance_id'] ?? null), 'prefers the visible sibling before duplicate removal');

$normalized = $normalizer->normalize(array($navigation(1), $navigation(2)), $sourceProvenance, array());
$assert(1 === count($normalized) && 1 === ($normalized[0]['_source_provenance_id'] ?? null), 'removes a mobile duplicate after preserving the first canonical navigation');

$group = array(
    'blockName' => 'core/group',
    'innerBlocks' => array($link('One', '/one'), $link('Two', '/two')),
    'innerContent' => array('<div>', null, '</div>'),
    'innerHTML' => '<div></div>',
);
$normalized = $normalizer->normalize(array($group), array(), array());
$assert(array('<div>', null, null, '</div>') === $normalized[0]['innerContent'], 'repairs serialized child placeholders after recursive normalization');

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation block normalizer contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation block normalizer contract passed: {$passes} assertions\n";
