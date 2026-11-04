<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

// An image that was inline content in the source (an inline svg in an
// inline-block wrapper, an img alone in a paragraph) sat on its parent's line
// box: the strut sizes the wrapper and the image's margins stay inside the
// line. Lowered to a core/image figure, the image must stay on a line, even
// where an author image rule projects the figure bridge's block display, and
// keep the alignment the source declared.
$compile = static function (string $fixture): array {
    $html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $document = new DOMDocument();
    @$document->loadHTML('<html><body>' . $result['serialized_blocks'] . '</body></html>');
    $css = '';
    foreach ($result['assets'] as $asset) {
        if ('css' === ($asset['kind'] ?? '')) $css .= (string) ($asset['content'] ?? '');
    }
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    return array(new DOMXPath($document), $rules);
};

// The cascaded value of one property on an element: highest specificity,
// then latest in source order. core/image's own `.wp-block-image img` rule
// (vertical-align:bottom) is the baseline the projection has to override.
$cascaded = static function (DOMElement $element, array $rules, string $property): ?string {
    $winner = null;
    $winnerSpecificity = -1;
    $core = array(array('', '.wp-block-image img', 'vertical-align:bottom'));
    foreach (array_merge($core, $rules) as $rule) {
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
    // The issue's reduced page: a 25px inline svg in an inline-block span.
    // Source: the span is 30px (svg plus strut) and the text column is
    // 152px; a block image left the span 25px and the column 157px.
    'inline-block icon wrapper' => array(
        'fixture' => 'inline-image-line-box-icon.html',
        'scope' => '//div[contains(concat(" ", @class, " "), " icon ")]',
        'figures' => 1,
    ),
    // The real-site shape from the issue comment: images alone in rich-text
    // paragraphs, set inline-block with `margin:32px 0 0` (shorthand) under a
    // global `img{vertical-align:middle}`. Source blocks are 434px and 33px;
    // blockified figures were 402px and 1px, the column 48px shorter.
    'rich-text image paragraphs' => array(
        'fixture' => 'inline-image-line-box-rich-text.html',
        'scope' => '//div[contains(concat(" ", @class, " "), " rich-text ")]',
        'figures' => 2,
    ),
);
foreach ($rows as $label => $row) {
    [$xpath, $rules] = $compile($row['fixture']);
    $scope = $xpath->query($row['scope'])->item(0);
    $assert($scope instanceof DOMElement, "$label: the image's source parent is found.");
    $figures = $xpath->query('.//figure[contains(concat(" ", @class, " "), " wp-block-image ")]', $scope);
    $assert($row['figures'] === $figures->length, "$label: the inline images lower to core/image figures.");
    foreach ($figures as $figure) {
        $image = $xpath->query('.//img', $figure)->item(0);
        $assert($image instanceof DOMElement, "$label: the figure holds its image.");
        $display = $cascaded($image, $rules, 'display');
        $assert(in_array($display, array('inline', 'inline-block'), true), "$label: the image stays on its parent's line, not a block box; got " . var_export($display, true) . '.');
        $align = $cascaded($image, $rules, 'vertical-align');
        $assert('middle' === $align, "$label: the image keeps the source's middle alignment on that line; got " . var_export($align, true) . '.');
    }
}

echo "ok\n";
