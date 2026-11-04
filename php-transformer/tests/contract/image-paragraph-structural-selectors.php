<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// Paragraphs that only wrap an image unwrap into core/image. Structural rules
// keep matching what they matched in the source, and the unwrapped paragraph's
// own box (its margin) moves onto the block that replaced it.
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/image-paragraph-structural-selectors.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();

$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$xpath = new DOMXPath($document);
$paragraphs = $xpath->query('//p');
$figures = $xpath->query('//figure');
$assert(3 === $paragraphs->length && 2 === $figures->length, 'Text paragraphs stay paragraphs and image paragraphs become figures.');

$css = '';
foreach ($result['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $css .= (string) ($asset['content'] ?? '');
}
preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

// Final declared margin-bottom per element, in source order, ignoring specificity
// ties the fixture does not create.
$marginBottom = static function (DOMElement $element) use ($rules): array {
    $matched = array();
    foreach ($rules as $rule) {
        if (! preg_match('/(?:^|;)\s*margin-bottom\s*:\s*([^;]+)/', $rule[2], $declaration)) continue;
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if ($parsed['supported'] && CssSelectorMatcher::matches($element, $parsed)['matches']) $matched[] = trim($declaration[1]);
        }
    }
    return $matched;
};

$three = $paragraphs->item(2);
$assert($three instanceof DOMElement && 'Three' === $three->textContent, 'Fixture paragraph found.');
$assert(array('2.4rem') === $marginBottom($three), 'The last text paragraph keeps its margin: :last-of-type matched the final image paragraph in the source.');
foreach ($paragraphs as $paragraph) {
    $assert(! in_array('0', $marginBottom($paragraph), true), 'No text paragraph receives the last-of-type margin reset.');
}
$assert(array('2.4rem') === $marginBottom($figures->item(0)), 'The first unwrapped paragraph keeps its paragraph margin on the image block.');
$assert(array('2.4rem', '0') === $marginBottom($figures->item(1)), 'The last unwrapped paragraph receives the last-of-type reset.');

echo "ok\n";
