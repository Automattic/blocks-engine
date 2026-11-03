<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/social-links-captured-svg.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(!str_contains($result['serialized_blocks'], 'wp:social-link'), 'Service recognition must not replace authored SVG geometry.');
$assert(!str_contains(json_encode($result), 'data-blocks-engine-capture-glyph-color'), 'Capture evidence must not leak.');
$assert(0 === $result['metrics']['fallback_count'], 'Source icon links remain editable without HTML fallback.');
$assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'Source icon links remain WordPress-valid.');
$sourceDom = new DOMDocument();
$sourceDom->loadHTML($source);
$dom = new DOMDocument();
$dom->loadHTML($result['serialized_blocks']);
$assert(4 === $dom->getElementsByTagName('a')->length, 'Every link retains its anchor.');
foreach ($sourceDom->getElementsByTagName('a') as $index => $anchor) {
    $actual = $dom->getElementsByTagName('a')->item($index);
    $assert($anchor->getAttribute('href') === $actual->getAttribute('href'), 'Link targets survive.');
    $assert($anchor->getAttribute('aria-label') === $actual->getAttribute('aria-label'), 'Accessible names survive.');
    $assert($anchor->getElementsByTagName('path')->item(0)->getAttribute('d') === $actual->getElementsByTagName('path')->item(0)->getAttribute('d'), 'Path geometry must be identical.');
}
$assert(str_contains($result['serialized_blocks'], 'stroke="currentColor"'), 'Hover paint remains live.');
// A genuine core glyph with no divergent paint keeps the native social block.
$icons = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/wordpress-social-link-icons.json'), true);
$matching = (new HtmlTransformer())->transform('<div class="social"><a href="https://facebook.com/example" aria-label="Facebook">' . $icons['facebook'] . '</a></div>')->toArray();
$assert(str_contains($matching['serialized_blocks'], 'wp:social-link '), 'Matching core artwork keeps existing native output.');
$custom = (new HtmlTransformer())->transform('<div class="social"><a href="https://facebook.com/example" aria-label="Custom Facebook"><svg viewBox="0 0 24 24"><path d="M0 0h12v12z"/></svg></a></div>')->toArray();
$assert(str_contains($custom['serialized_blocks'], 'M0 0h12v12z'), 'Wrong core artwork is avoided even without capture metadata.');
$assert(str_contains($custom['serialized_blocks'], '<a '), 'Uncaptured custom artwork remains linked.');
$unsafe = (new HtmlTransformer())->transform('<div class="social"><a href="javascript:alert(1)" aria-label="Facebook" onclick="alert(1)"><svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script><path d="M0 0h12v12z"/></svg></a></div>')->toArray();
$assert(!preg_match('/javascript:|onclick=|onload=|<script/i', $unsafe['serialized_blocks']), 'Source artwork uses sanitized markup, not raw source HTML.');
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $source, 'result' => $result), JSON_THROW_ON_ERROR);
    exit;
}
echo "Captured social SVG parity passed.\n";
