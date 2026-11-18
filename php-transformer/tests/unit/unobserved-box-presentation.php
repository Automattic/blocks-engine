<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Sparse trees can omit retained media wrappers. Author sheets are retired
// on these routes, so the missing box must carry its own authored layout.
$html = '<style>.frame{position:relative;block-size:100%;overflow:clip}.host{position:absolute;inline-size:100%;block-size:100%}@media(max-width:600px){.host{inline-size:50%}}</style>'
    . '<div class="frame" data-blocks-engine-node-id="missing-frame"><media-frame class="host" data-image-info="{}" data-blocks-engine-node-id="missing-host"><picture><img src="photo.jpg" alt="Photo"></picture></media-frame></div>';
$result = (new HtmlTransformer())->transform($html, array('box_tree' => array('viewports' => array('desktop' => array('width' => 1440, 'height' => 900)), 'nodes' => array())))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $a): bool => 'css' === ($a['kind'] ?? '')), 'content'));
$markup = $result['serialized_blocks'];
$document = new DOMDocument();
@$document->loadHTML($markup);
$host = (new DOMXPath($document))->query('//div[contains(concat(" ", @class, " "), " host ")]')->item(0);
$found = false;
foreach (preg_split('/\s+/', $host?->getAttribute('class') ?? '') as $class) {
    if (preg_match('/\.' . preg_quote($class, '/') . '\{([^}]+)\}/', $css, $rule)
        && str_contains($rule[1], 'position:absolute') && str_contains($rule[1], 'block-size:100%') && str_contains($rule[1], 'inline-size:50%')) $found = true;
}
if (!$found || !str_contains($css, 'inline-size:100%') || !str_contains($css, '@media (min-width:600.01px)')) {
    throw new RuntimeException('Unobserved retained media hosts must carry authored responsive positioning and logical dimensions.');
}
if (!str_contains($markup, '<!-- wp:image ') || str_contains($markup, 'data-blocks-engine-node-id')) {
    throw new RuntimeException('Fallback presentation must retain editable native images without capture identity.');
}
echo "Unobserved box presentation passed\n";
