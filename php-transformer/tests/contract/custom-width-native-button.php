<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$html = <<<'HTML'
<!doctype html><html><head><style>.wp-block-button .wp-block-button__link{background:#f1634c;border-radius:30px;padding:10px 25px;color:#fff;display:inline-block}.aligncenter{text-align:center}</style></head>
<body><main><h1>T</h1><div class="wp-block-buttons is-layout-flex"><div class="wp-block-button has-custom-width wp-block-button__width-25 aligncenter"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact</a></div></div></main></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) $result['serialized_blocks'];
$assert(1 === preg_match_all('/<!-- wp:group(?: |-->)/', $markup) && str_contains($markup, '"tagName":"main"'), 'Only the semantic main may become a Group, not the native button wrapper.');
$assert(1 === preg_match_all('/<!-- wp:buttons(?: |-->)/', $markup), 'The source row has exactly one core/buttons.');
$assert(1 === preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $matches), 'The row holds exactly one core/button.');
$attrs = json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR);
$assert(25 === ($attrs['width'] ?? null) || str_contains($attrs['className'] ?? '', 'wp-block-button__width-25'), 'Native width intent is retained on core/button.');
$assert(str_contains($attrs['className'] ?? '', 'aligncenter'), 'Alignment stays on the button wrapper.');
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
$links = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," wp-block-buttons ")]/div[contains(concat(" ",normalize-space(@class)," ")," wp-block-button ")]/a');
$assert(1 === $links->length, 'Rendered topology is buttons > button > link.');
$link = $links->item(0);
$assert('/contact/' === $link->getAttribute('href') && 'Contact' === trim($link->textContent), 'Link identity survives.');
// Core button CSS sizes this wrapper to 25%; its full-width link can only
// fill that wrapper, never the entire buttons row.
$assert(str_contains($link->parentNode->getAttribute('class'), 'wp-block-button__width-25'), 'Rendered link is constrained by the native quarter-width wrapper.');
$assert(str_contains($link->getAttribute('style'), 'background-color:#f1634c'), 'Descendant-owned paint still reaches the link.');
echo "Custom-width native button contract passed.\n";
