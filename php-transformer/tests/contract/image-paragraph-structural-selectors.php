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

// Several routes share one author stylesheet. The article route unwraps its
// image paragraphs; the other routes keep only text paragraphs, so the
// site-wide copy is projected from them and keeps the live `:last-of-type`.
// Both delivery shapes must leave the article's text paragraphs their margin
// and the last image on its 32px line: route-scoped variants in the shared
// copy, and (forced through `@namespace`, which route scoping declines) a
// site-wide copy plus a per-page copy.
$site = dirname(__DIR__) . '/fixtures/image-paragraph-structural-selectors-site/';
$article = 'nyheder/maerkning-af-katte/index.html';
foreach (array('route-scoped shared copy' => '', 'site-wide copy plus page copy' => '@namespace svg url(http://www.w3.org/2000/svg);') as $label => $preamble) {
    $files = array('screen.css' => array('path' => 'screen.css', 'kind' => 'css', 'content' => $preamble . file_get_contents($site . 'screen.css')));
    foreach (array('index.html', 'om/index.html', $article) as $route) $files[$route] = (string) file_get_contents($site . $route);
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];

    $sharedCss = $routeCss = '';
    $pageCopies = 0;
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        $sources = array_map(static fn(array $scope): string => 'global' === ($scope['kind'] ?? '') ? '*' : (string) ($scope['source_path'] ?? ''), $asset['scopes'] ?? array());
        if (! in_array('*', $sources, true) && ! in_array($article, $sources, true)) continue;
        $content = (string) ($asset['content'] ?? '');
        $routeCss .= $content;
        // Author copies come from the source files; engine support CSS does not.
        if ('files' === ($asset['source'] ?? '')) {
            if (in_array('*', $sources, true)) $sharedCss .= $content;
            else ++$pageCopies;
        }
    }
    $assert('' !== $sharedCss, "$label: the author stylesheet ships a site-wide copy.");
    $assert(('' === $preamble ? 0 : 1) === $pageCopies, "$label: the article route gets a page copy exactly when route scoping declines.");

    $page = array();
    foreach ($plan['pages'] as $row) if ($article === ($row['source_path'] ?? '')) $page = $row;
    $document = new DOMDocument();
    @$document->loadHTML('<html class="' . htmlspecialchars((string) ($page['document_metadata']['root_attributes']['class'] ?? '')) . '"><body>' . (string) ($page['canonical_block_markup'] ?? '') . '</body></html>');
    $xpath = new DOMXPath($document);
    $scope = $xpath->query('//div[contains(concat(" ", @class, " "), " u-rich-text ")]')->item(0);
    $assert($scope instanceof DOMElement, "$label: the article rich-text block is found.");
    $paragraphs = $xpath->query('./p', $scope);
    $figures = $xpath->query('./figure', $scope);
    $assert(3 === $paragraphs->length && 2 === $figures->length, "$label: the article's image paragraphs become figures.");

    $rulesOf = static function (string $css): array {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        return $rules;
    };
    foreach ($paragraphs as $paragraph) {
        $assert('0' !== $cascaded($paragraph, $rulesOf($sharedCss), 'margin-bottom'), "$label: the site-wide copy never resets text paragraph \"{$paragraph->textContent}\" through a live :last-of-type.");
        $assert('2.4rem' === $cascaded($paragraph, $rulesOf($routeCss), 'margin-bottom'), "$label: text paragraph \"{$paragraph->textContent}\" keeps its source margin.");
    }
    $assert('2.4rem' === $cascaded($figures->item(0), $rulesOf($routeCss), 'margin-bottom'), "$label: the first unwrapped paragraph keeps its margin.");
    $assert('0' === $cascaded($figures->item(1), $rulesOf($routeCss), 'margin-bottom'), "$label: the last unwrapped paragraph receives the last-of-type reset.");
    $pixel = $xpath->query('.//img', $figures->item(1))->item(0);
    $assert($pixel instanceof DOMElement && 'inline' === $cascaded($pixel, $rulesOf($routeCss), 'display'), "$label: the last image stays on its paragraph line over the author image bridge, so its 32px margin sizes the figure.");
}

echo "ok\n";
