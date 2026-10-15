<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

// A page's stylesheets are walked once, however many form roots ask about them.
$css = '.form{display:flex;gap:8px;--accent:red} @media (max-width:40rem){.form .row{display:grid;--gap:4px}} @layer base{.field{padding:4px}}';
for ( $i = 0; $i < 200; ++$i ) {
    $css .= ".unrelated-{$i}{margin:{$i}px;--u{$i}:1}";
}
$stylesheets = array( array( 'content' => $css, 'source_path' => 'page.css', 'source_hash' => hash('sha256', $css) ) );
$html = '<!doctype html><html><body>';
for ( $i = 0; $i < 12; ++$i ) {
    $html .= "<div class=\"form\"><div class=\"row\"><label class=\"field\">Name {$i} <input type=\"text\" name=\"n{$i}\"></label></div></div>";
}
$html .= '</body></html>';
$document = new DOMDocument();
@$document->loadHTML($html);
$forms = array();
foreach ( $document->getElementsByTagName('div') as $div ) {
    if ( 'form' === $div->getAttribute('class') ) {
        $forms[] = $div;
    }
}
$build = static fn(DOMElement $form): array => (new FormLayoutGraphBuilder())->build($form, $stylesheets);
$build($forms[0]);
$before = CssRuleAnalyzer::stylesheetWalks();
foreach ( array_slice($forms, 1) as $form ) {
    $build($form);
}
$walks = CssRuleAnalyzer::stylesheetWalks() - $before;
$assert(0 === $walks, '11 more form roots over the same stylesheet reuse its walk; walked ' . $walks . ' more times');

// A warm stream answers exactly as the first walk did, including retention,
// limits that leave a condition block, and the selector scan limit.
$limitsCss = '.a{color:red} @media (min-width:1px){.b{color:red} .c{color:red} .d{color:red}} .e{color:red} .f{color:red}';
$sheet = array( array( 'content' => $limitsCss, 'source_path' => 'limits.css', 'source_hash' => 'h' ) );
$keepAll = static fn(array $parsed): bool => true;
$skipC = static fn(array $parsed): bool => ! CssSelectorMatcher::matches((new DOMDocument())->createElement('i'), $parsed)['matches'] && '.c' !== ($parsed['selector'] ?? null);
$cases = array(
    'rule limit inside a condition block leaves only that block' => array( 2, 10, 10, $keepAll ),
    'retention skips do not count against the rule limit' => array( 3, 10, 10, $skipC ),
    'scan limit stops the analysis' => array( 10, 10, 3, $keepAll ),
);
foreach ( $cases as $label => [ $maxRules, $maxSelectors, $maxScanned, $retain ] ) {
    $analyze = static fn(): array => (new CssRuleAnalyzer())->analyze($sheet, '', array( 'color' ), 100000, $maxRules, $maxSelectors, 4, $retain, $maxScanned);
    $first = $analyze();
    $second = $analyze();
    $assert($first === $second, $label . ': warm analysis matches the first walk');
}
$selectors = static fn(array $analysis): array => array_column($analysis['rules'], 'selector');
$limited = (new CssRuleAnalyzer())->analyze($sheet, '', array( 'color' ), 100000, 2, 10, 4, $keepAll, 10);
$assert(array( '.a', '.b' ) === $selectors($limited) && $limited['truncated'] && in_array('css_rule_or_selector_limit', $limited['diagnostics'], true), 'rule limit keeps source order and reports truncation');
$scanned = (new CssRuleAnalyzer())->analyze($sheet, '', array( 'color' ), 100000, 10, 10, 4, $keepAll, 3);
$assert(array( '.a', '.b', '.c' ) === $selectors($scanned) && in_array('css_selector_scan_limit', $scanned['diagnostics'], true), 'scan limit stops after the third scanned selector');

echo "css-rule-analyzer shared stylesheet: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
