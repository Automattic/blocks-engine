<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

// Reduced issue fixture: the generated asset precedes the source stylesheet.
$html = <<<'HTML'
<!doctype html><html><head><style>.wrap .popup{display:inline-block;width:300px}</style></head>
<body><main><h1>Title</h1><p>Body</p></main>
<div class="wrap"><div id="pop" class="popup" style="background: rgb(40, 36, 87); display: none;"><h2>Stay Updated!</h2><p>Join our newsletter.</p></div></div></body></html>
HTML;

foreach (array('.wrap .popup', '#pop', '#pop#pop#pop.popup') as $authorSelector) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => str_replace('.wrap .popup', $authorSelector, $html)),
    ))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $assert(1 === preg_match('/<[^>]*id="pop"[^>]*>/', $markup, $popup), 'Popup identity survives compilation.');
    $assert(1 === preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $popup[0], $carrier), 'Popup retains a generated carrier.');
    $class = $carrier[0];
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    $assert(1 === preg_match('/([^{}]+)\{display:none\}/', $css, $rule), 'Inline display is emitted in its own normal-priority rule.');
    $assert(str_contains($rule[1], '.' . $class), 'The display rule reaches the popup carrier.');
    $document = new DOMDocument();
    $document->appendChild($document->createElement('html'))->appendChild($document->createElement('body'));
    $target = $document->getElementsByTagName('body')[0]->appendChild($document->createElement('div'));
    $target->setAttribute('class', $class);
    $parsed = CssSelectorMatcher::parse(trim($rule[1]));
    $assert(CssSelectorMatcher::matches($target, $parsed)['matches'], 'The weighted selector still matches the popup.');
    $specificity = array_values(CssSelectorMatcher::specificityCounts($parsed));
    $authorSpecificity = array_values(CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($authorSelector)));
    $inline = array('value' => 'none', 'important' => false, 'inline' => false, 'specificity' => $specificity, 'order' => 0);
    $author = array('value' => 'inline-block', 'important' => false, 'inline' => false, 'specificity' => $authorSpecificity, 'order' => 1);
    $assert(CssCascade::wins($inline, $author), $authorSelector . ': #pop computes display:none even with the source rule loaded later.');
    $author['important'] = true;
    $assert(CssCascade::wins($author, $inline), 'Source !important still beats the projected inline declaration.');
}

// Raising inline layout specificity must not pin stylesheet-owned paint or
// remove its state/viewport rules (the !important pitfall from #3053).
$states = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<link rel="stylesheet" href="site.css"><main><div class="dot" style="display:inline-block"><p>Stateful surface</p></div></main>',
        'site.css' => '.dot{display:block;width:24px;height:4px;background:#f86}.dot:hover{background:#0a0}@media(max-width:600px){.dot{background:#00a}}',
    ),
))->toArray();
$stateCss = implode("\n", array_column($states['assets'] ?? array(), 'content'));
$assert(str_contains($stateCss, ':hover') && str_contains($stateCss, '#0a0') && str_contains($stateCss, '#00a'), 'Hover and responsive paint survive projection.');
$assert(! preg_match('/be-inline-geometry-[^{}]*\{[^}]*background[^}]*!important/', $stateCss), 'A geometry carrier does not freeze stylesheet-owned paint.');

fwrite(STDOUT, "Projected inline precedence contract passed\n");
