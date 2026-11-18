<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    '<big>x</big>' => 'x',
    '<div><big>x</big></div>' => 'x',
    '<div>a <big>x</big></div>' => 'a x',
    '<section><big>x</big></section>' => 'x',
);

foreach ($cases as $html => $expectedText) {
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $markup = (string) $result['serialized_blocks'];
    if (array() !== $result['fallbacks'] || '' === $markup || str_contains($markup, '<!-- wp:html')) {
        fwrite(STDERR, "FAIL: {$html} must convert without fallbacks\n" . json_encode($result['fallbacks']) . "\n");
        exit(1);
    }
    $document = new DOMDocument();
    $document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $preserved = false;
    foreach ($document->getElementsByTagName('p') as $paragraph) {
        if ($expectedText !== trim($paragraph->textContent)) {
            continue;
        }
        foreach ($xpath->query('.//big | .//*[@style]', $paragraph) as $inline) {
            $larger = 'larger' === ($inline instanceof DOMElement
                ? CssValueInspector::declared(
                    CssRuleAnalyzer::declarations($inline->getAttribute('style'), array('font-size')),
                    'font-size'
                ) : '');
            if ('x' === trim($inline->textContent) && ('big' === $inline->nodeName || $larger)) {
                $preserved = true;
            }
        }
    }
    if (!$preserved) {
        fwrite(STDERR, "FAIL: {$html} must retain enlarged text in a native paragraph\n{$markup}\n" . json_encode($result['fallbacks']) . "\n");
        exit(1);
    }
}

echo "big phrasing element tests: passed\n";
