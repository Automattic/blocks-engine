<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/css-painted-toggle.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = (string) $result['serialized_blocks'];
$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="utf-8" ?><body>' . Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup) . '</body>');
$xpath = new DOMXPath($document);
$button = $xpath->query('//button[@id="menu-toggle"]')->item(0);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert($button instanceof DOMElement, 'The imported toggle renders as a native button.');
$assert('Open menu' === $button->getAttribute('aria-label'), 'The toggle keeps its accessible name as aria-label.');
$assert(1 === $xpath->query('.//span[contains(@class,"icon-bars")]', $button)->length, 'The CSS-painted child stays inside the button.');
$assert('drawer' === $button->getAttribute('aria-controls'), 'The toggle keeps its target.');
$assert(1 === $xpath->query('//dialog[@id="drawer"][@data-blocks-engine-triggers="menu-toggle"]')->length, 'The dialog remains bound to the toggle.');
$assert(!str_contains($markup, '<!-- wp:button '), 'The icon-only control does not become an empty core/button.');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'The saved blocks remain valid.');

$css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(1 === preg_match('/(?:^|})\s*\.icon-bars\s*,\s*\.icon-bars::before/', $css), 'The icon paint selector still addresses its rendered source class.');
$assert(str_contains($css, '.icon-bars::after'), 'The pseudo-element paint survives.');

// Optional browser artifact uses the generated dialog runtime, not a test click handler.
if ($path = getenv('CSS_PAINTED_TOGGLE_ARTIFACT')) {
    file_put_contents($path, json_encode(array('markup' => $markup, 'css' => $css, 'generated' => $result['source_reports']['generated_blocks'] ?? array()), JSON_THROW_ON_ERROR));
}
echo "css-painted-toggle: ok\n";
