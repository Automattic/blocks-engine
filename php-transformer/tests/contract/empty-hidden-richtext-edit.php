<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Source sites hide empty text containers (`p:empty{display:none}`). In the
// editor the same paragraph is a live RichText root that is momentarily
// empty while its text is replaced; the browser test retypes it there.
$result = (new HtmlTransformer())->transform(
    '<style>.desc-wrapper p:empty{display:none}.desc-wrapper p{font-size:18px}</style>'
    . '<main><div class="desc-wrapper"><p>Sustainability</p><p>Second line</p></div></main>'
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = array('frontend' => array(), 'editor' => array());
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    $target = (string) ($asset['stylesheet_target'] ?? 'both');
    foreach (array('frontend', 'editor') as $context) {
        if ('both' === $target || $context === $target) $css[$context][] = (string) ($asset['content'] ?? '');
    }
}
if (!str_contains($markup, '>Sustainability</p>')) throw new RuntimeException('The source paragraph lowers to a native paragraph.');
if (!str_contains(implode("\n", $css['frontend']), ':empty')) throw new RuntimeException('The authored empty-state rule is retained on the front end.');
if ($path = getenv('EMPTY_HIDDEN_RICHTEXT_ARTIFACT')) {
    file_put_contents($path, json_encode(array('markup' => $markup, 'frontend' => implode("\n", $css['frontend']), 'editor' => implode("\n", $css['editor'])), JSON_THROW_ON_ERROR));
}
echo "Empty hidden RichText edit contract passed.\n";
