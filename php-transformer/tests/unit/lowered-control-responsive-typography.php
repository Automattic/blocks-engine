<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/lowered-control-responsive-typography.html');
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'], 'content'));
$failures = array();
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$assert(str_contains($markup, '<!-- wp:button '), 'the link is lowered to a native button');
$document = new DOMDocument();
@$document->loadHTML('<html><body>' . $markup . '</body></html>');
$name = (new DOMXPath($document))->query('//a//b')->item(0);
$assert($name instanceof DOMElement, 'the label retains its unclassed bold descendant');
if ($name instanceof DOMElement) {
    $assert(!str_contains($name->getAttribute('style'), 'font-size:'), 'conditional size is not frozen inline');
    $assert(str_contains($name->getAttribute('style'), 'font-weight:700'), 'unrelated static typography is still projected');
    $responsiveRule = false;
    (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body, array $conditions) use (&$responsiveRule): void {
        if (str_contains($selector, '.wp-block-button__link') && str_contains($selector, ' b')
            && str_contains($body, 'font-size:1.3rem') && in_array('@media (max-width:560px)', $conditions, true)) {
            $responsiveRule = true;
        }
    });
    $assert($responsiveRule, 'the conditional size rule still addresses the lowered button descendant');
}
if ($failures) {
    foreach ($failures as $message) fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $html, 'candidate' => '<style>' . $css . '</style>' . $markup));
} else {
    echo "lowered control responsive typography passed\n";
}
