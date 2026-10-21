<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-intrinsic-height.html');
$output = (new HtmlTransformer())->transform($fixture)->toArray();
$css = implode('', array_column(array_filter($output['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$markup = $output['serialized_blocks'];
foreach (array('Decrease', 'Increase', 'Stretched', 'type="number"') as $content) {
    if (!str_contains($markup, $content)) {
        fwrite(STDERR, "FAIL: source control content must survive: {$content}\n");
        exit(1);
    }
}
$neutral = preg_quote(SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS, '/');
if (!preg_match('/:where\(\.' . $neutral . '\)>\.wp-block-button__link\{[^}]*height:auto!important/', $css)) {
    fwrite(STDERR, "FAIL: flattened controls must reset core's percentage height\n{$css}\n");
    exit(1);
}
if (!preg_match('/wp-block-button__link[^{}]*\{height:100%!important[;}]/', $css)) {
    fwrite(STDERR, "FAIL: authored stretch must override the intrinsic-height reset\n{$css}\n");
    exit(1);
}
echo "Intrinsic button height ownership: passed\n";
