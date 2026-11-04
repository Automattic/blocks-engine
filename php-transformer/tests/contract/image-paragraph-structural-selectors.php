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
// keep matching what they matched in the source, the unwrapped paragraph's own
// box (its margin) moves onto the block that replaced it, and an inline image
// with a vertical margin keeps sizing that block's line box.
$compile = static function (string $fixture): array {
    $html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $document = new DOMDocument();
    @$document->loadHTML($result['serialized_blocks']);
    $css = '';
    foreach ($result['assets'] as $asset) {
        if ('css' === ($asset['kind'] ?? '')) $css .= (string) ($asset['content'] ?? '');
    }
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    return array(new DOMXPath($document), $rules);
};

// The cascaded value of one property on an element: highest specificity,
// then latest in source order.
$cascaded = static function (DOMElement $element, array $rules, string $property): ?string {
    $winner = null;
    $winnerSpecificity = -1;
    foreach ($rules as $rule) {
        if (! preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+)/', $rule[2], $declaration)) continue;
        foreach (CssStylesheetTransformer::splitSelectorList(trim($rule[1])) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (! $parsed['supported'] || ! CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
            $specificity = CssSelectorMatcher::specificity($parsed);
            if ($specificity >= $winnerSpecificity) {
                $winner = trim($declaration[1]);
                $winnerSpecificity = $specificity;
            }
        }
    }
    return $winner;
};

$rows = array(
    // The issue's reduced page.
    'reduced page' => array('fixture' => 'image-paragraph-structural-selectors.html', 'scope' => 0),
    // The real-site shape: the same rule also matches a text paragraph in
    // another rich-text block, so it cannot be dropped to per-element arms.
    'shared rich-text scope' => array('fixture' => 'image-paragraph-structural-selectors-shared-scope.html', 'scope' => 1),
);
foreach ($rows as $label => $row) {
    [$xpath, $rules] = $compile($row['fixture']);
    $scope = $xpath->query('//div[contains(concat(" ", @class, " "), " rich-text ")]')->item($row['scope']);
    $assert($scope instanceof DOMElement, "$label: rich-text block found.");
    $paragraphs = $xpath->query('./p', $scope);
    $figures = $xpath->query('./figure', $scope);
    $assert(3 === $paragraphs->length && 2 === $figures->length, "$label: text paragraphs stay paragraphs and image paragraphs become figures.");

    foreach ($paragraphs as $paragraph) {
        $assert('2.4rem' === $cascaded($paragraph, $rules, 'margin-bottom'), "$label: text paragraph \"{$paragraph->textContent}\" keeps its margin; :last-of-type matched the final image paragraph in the source.");
    }
    $assert('2.4rem' === $cascaded($figures->item(0), $rules, 'margin-bottom'), "$label: the first unwrapped paragraph keeps its paragraph margin on the image block.");
    $assert('0' === $cascaded($figures->item(1), $rules, 'margin-bottom'), "$label: the last unwrapped paragraph receives the last-of-type reset.");

    foreach ($figures as $figure) {
        $image = $xpath->query('.//img', $figure)->item(0);
        $assert($image instanceof DOMElement && 'inline' === $cascaded($image, $rules, 'display'), "$label: the image keeps the paragraph's line box, so its margin-top cannot collapse through the figure.");
    }

    if (1 === $row['scope']) {
        $other = $xpath->query('//div[contains(concat(" ", @class, " "), " rich-text ")]')->item(0);
        $texts = $xpath->query('./p', $other);
        $assert('2.4rem' === $cascaded($texts->item(0), $rules, 'margin-bottom'), "$label: an unchanged rich-text block keeps its margins.");
        $assert('0' === $cascaded($texts->item(1), $rules, 'margin-bottom'), "$label: an unchanged rich-text block keeps its live last-of-type reset.");
    }
}

echo "ok\n";
