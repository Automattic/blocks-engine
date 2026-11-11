<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$shape = '<path d="M10 10L1200 20L1240 1100L30 1150Z" fill="#ddd"/>';
foreach (array('' => array(), ' width="80%" height="auto"' => array('80%', 'auto')) as $sizing => $expected) {
    $svg = '<svg viewBox="0 0 1259 1188" preserveAspectRatio="xMidYMid meet"' . $sizing . '>'
        . $shape . '<a href="/london/"><circle cx="300" cy="400" r="6"/><text x="310" y="405">London</text></a></svg>';
    $html = '<!doctype html><html><head><style>.cols{display:flex}.map{padding:30px;flex:1}.areas{flex:1}</style></head><body><main><div class="cols"><div class="map">'
        . $svg . '</div><div class="areas"><p>Areas we serve</p></div></div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
    $artwork = array();
    $walk = static function (array $blocks) use (&$walk, &$artwork): void {
        foreach ($blocks as $block) {
            if ('custom/svg-artwork' === ($block['blockName'] ?? '')) {
                $artwork[] = $block['attrs']['svg'] ?? '';
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk((new Runtime())->parseBlocks($result['serialized_blocks']));
    $assert(1 === count($artwork), 'The public compiler retains the region-linked map as inline SVG artwork.');
    $document = new DOMDocument();
    $document->loadXML($artwork[0]);
    $root = $document->documentElement;
    $assert($root instanceof DOMElement && '0 0 1259 1188' === $root->getAttribute('viewBox'), 'The inline SVG retains its aspect ratio.');
    foreach (array('width', 'height') as $index => $dimension) {
        $assert(isset($expected[$index]) ? $expected[$index] === $root->getAttribute($dimension) : ! $root->hasAttribute($dimension), 'Inline SVG keeps only authored ' . $dimension . ', never viewBox-derived pixels.');
    }
    $assert(str_contains($artwork[0], 'href="/london/"'), 'The inline map keeps its region link.');
}

echo "Inline SVG artwork authored sizing contract passed.\n";
