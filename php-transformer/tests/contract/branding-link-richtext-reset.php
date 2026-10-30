<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// The text-painted brand mark becomes a RichText carrier, not a raw retained
// span. Publishing the whole logo subtree as retained used to leave the mark
// outside the author's universal border-box reset, expanding its 30px border
// box to 32px and shifting the wordmark in both the header and footer.
$fixture = dirname(__DIR__, 3) . '/fixtures/solved/89-static-site-importer-architecture/';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => file_get_contents($fixture . 'index.html'),
        'css/site.css' => file_get_contents($fixture . 'css/site.css'),
    ),
))->toArray();

$document = new DOMDocument();
$document->loadHTML('<!doctype html><body>' . $result['serialized_blocks'] . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$marks = array();
foreach ($document->getElementsByTagName('*') as $element) {
    if (CssSelectorMatcher::matches($element, CssSelectorMatcher::parse('.brand-mark'))['matches'] ?? false) {
        $marks[] = $element;
    }
}
$assert(2 === count($marks), 'Both header and footer retain an addressable brand mark.');
$resetMatches = array_fill(0, count($marks), false);
$geometryMatches = $resetMatches;
$plan = $result['source_reports']['wordpress_site_plan'];
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '') || ! in_array($asset['stylesheet_target'] ?? 'both', array('frontend', 'both'), true)) {
        continue;
    }
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'] ?? '', static function (string $selector, string $body, array $conditions) use ($marks, &$resetMatches, &$geometryMatches): void {
        if (array() !== $conditions) {
            return;
        }
        $reset = 1 === preg_match('/box-sizing\s*:\s*border-box/', $body);
        $geometry = 1 === preg_match('/width\s*:\s*30px/', $body)
            && 1 === preg_match('/height\s*:\s*30px/', $body)
            && 1 === preg_match('/border\s*:\s*1px solid/', $body);
        if (! $reset && ! $geometry) {
            return;
        }
        foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
            foreach ($marks as $index => $mark) {
                if (CssSelectorMatcher::matches($mark, CssSelectorMatcher::parse($arm))['matches'] ?? false) {
                    $resetMatches[$index] = $resetMatches[$index] || $reset;
                    $geometryMatches[$index] = $geometryMatches[$index] || $geometry;
                }
            }
        }
    });
}
$assert(! in_array(false, $geometryMatches, true), 'Both marks receive the authored 30px dimensions and 1px border.');
$assert(! in_array(false, $resetMatches, true), 'The author universal border-box reset still reaches both emitted RichText brand marks.');

echo "Branding link RichText reset contract passed.\n";
