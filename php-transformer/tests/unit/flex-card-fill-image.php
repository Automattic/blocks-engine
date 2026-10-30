<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/flex-card-fill-image.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$site = $result['source_reports']['compiled_site'];
$markup = $site['pages'][0]['block_markup'];
$css = implode("\n", array_column(array_filter($site['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert(2 === substr_count($markup, '<!-- wp:custom/layout-shell '), 'Card anchors retain their source layout shells.');
$assert(2 === substr_count($markup, '<!-- wp:image '), 'Both fill images remain native editable images.');
$assert(!str_contains($markup, SourceBlockAttributeProjector::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS), 'Out-of-flow image figures keep real boxes, as in the inline-style case.');
$assert(1 === preg_match('/\.tcard [^{\n]*\.wp-block-image\{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:-2\}/', $css), 'The author stylesheet positions the figure, not just its image leaf, outside flex flow.');
$assert(str_contains($markup, SourceBlockAttributeProjector::SYNTHETIC_FILL_IMAGE_FIGURE_CLASS), 'Two-axis fill treatment is preserved.');

// Optional browser evidence consumes the actual compiled markup and styles.
if ($path = getenv('FLEX_CARD_FILL_ARTIFACT')) {
    file_put_contents($path, json_encode(array('source' => $source, 'native' => '<style>' . $css . '</style>' . preg_replace('/<!--.*?-->/s', '', $markup)), JSON_THROW_ON_ERROR));
}
echo "Flex card fill image projection passed.\n";
