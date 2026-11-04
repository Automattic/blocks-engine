<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/social-links-icon-font.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));
if (3 !== substr_count($markup, '<!-- wp:social-link ') || str_contains($markup, '<!-- wp:html')) {
    throw new RuntimeException('Icon-font links must remain native editable social links without fallbacks.');
}
if (3 !== substr_count($markup, ' blocks-engine-social-source-glyph')) {
    throw new RuntimeException('Each generated font glyph, not the preceding hidden label, must own the native visual.');
}
foreach (array('Facebook', 'Instagram', 'WhatsApp') as $label) {
    if (!str_contains($markup, '"label":"' . $label . '"')) throw new RuntimeException('Accessible link name lost: ' . $label);
}
foreach (array('display:none', '--blocks-engine-social-glyph-font-size:var(--jw-button-icon-size)', '--blocks-engine-social-glyph-width:19px', '--blocks-engine-social-glyph-height:19px', 'content:"f"', 'font-family:Arial') as $declaration) {
    if (!str_contains($css, $declaration)) throw new RuntimeException('Missing source glyph projection: ' . $declaration);
}
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $source, 'candidate' => $markup, 'css' => $css), JSON_THROW_ON_ERROR);
} else {
    echo "Social icon-font glyphs passed.\n";
}
