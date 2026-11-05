<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'issue' => array('<summary role="button" aria-label="" class="trigger"><span class="button__label"></span></summary><div role="dialog" aria-label="MENU"><ul><li><a href="/a">A</a></li></ul></div>', 'MENU'),
    'visual icon' => array('<summary aria-label="Open"><span class="icon"></span></summary><p>Body</p>', 'Open'),
    'text unchanged' => array('<summary class="trigger">Menu</summary><p>Body</p>', null),
    'summary aria-label first' => array('<summary aria-label="Own" aria-labelledby="name" data-blocks-engine-capture-disclosure-label="Capture"></summary><div id="name" role="region" aria-label="Panel">Referenced</div>', 'Own'),
    'summary labelledby' => array('<summary aria-label="" aria-labelledby="name" data-blocks-engine-capture-disclosure-label="Capture"></summary><div id="name" role="region" aria-label="Panel">Referenced</div>', 'Referenced'),
    'capture before panel' => array('<summary data-blocks-engine-capture-disclosure-label="Capture"></summary><div role="region" aria-label="Panel">Body</div>', 'Capture'),
    'controlled panel labelledby' => array('<summary aria-controls="panel"></summary><div><div id="panel" role="region" aria-labelledby="name"><p id="name">Panel name</p></div></div>', 'Panel name'),
    'no invented label' => array('<summary class="trigger"><span></span></summary><p>Body</p>', ''),
    'unnamed icon' => array('<summary class="trigger"><span class="icon"></span></summary><p>Body</p>', ''),
);
foreach ($cases as $name => [$content, $label]) {
    $result = (new HtmlTransformer())->transform('<style>.icon{display:inline-block;width:16px;height:16px;background:red}</style><details>' . $content . '</details>')->toArray();
    $blocks = (string) ($result['serialized_blocks'] ?? '');
    if (!preg_match('/<summary>(.*?)<\/summary>/s', $blocks, $match)) throw new RuntimeException($name . ': missing details summary');
    $html = $match[1];
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>');
    foreach ($document->getElementsByTagName('body')->item(0)->getElementsByTagName('*') as $element) {
        if ('' === $element->textContent && !in_array($element->tagName, array('mark', 'img', 'br'), true)) throw new RuntimeException($name . ': empty unregistered element: ' . $html);
    }
    if (null !== $label && '' !== $label && !preg_match('/<span class="screen-reader-text"[^>]*>' . preg_quote($label, '/') . '<\/span>/', $html)) throw new RuntimeException($name . ': missing accessible label: ' . $html);
    if ('' === $label && str_contains($html, 'screen-reader-text')) throw new RuntimeException($name . ': invented label');
    if ('text unchanged' === $name && '<span class="trigger">Menu</span>' !== $html) throw new RuntimeException($name . ': changed text output: ' . $html);
    if (in_array($name, array('visual icon', 'unnamed icon'), true) && !preg_match('/<mark[^>]*class="icon\b/', $html)) throw new RuntimeException($name . ': lost visual carrier: ' . $html);
}
// A native marker and its text must share one line: list-item belongs only
// to summary, never to the span carrying the source styling hooks.
$result = (new HtmlTransformer())->transform('<style>.t{font-family:serif;cursor:pointer}</style><details><summary class="t">Title</summary><p>Body</p></details>')->toArray();
$css = implode('', array_map(static fn(array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
if (!str_contains($result['serialized_blocks'], 'blocks-engine-summary-content-carrier')) throw new RuntimeException('Missing summary content carrier');
if (!preg_match_all('/[^{}]*>span\.blocks-engine-summary-content-carrier\)?\{([^}]*)\}/', $css, $rules)) throw new RuntimeException('Missing carrier presentation');
foreach ($rules[1] as $declarations) {
    if (preg_match('/(?:^|;)display:(?!inline(?:!important)?(?:;|$))/', $declarations)) throw new RuntimeException('Carrier must stay inline: ' . $declarations);
}
if (str_contains($css, 'display:list-item')) throw new RuntimeException('Default summary display need not be restated');
if (!str_contains($css, 'font-family:serif')) throw new RuntimeException('Summary typography lost');
echo "Details summary RichText tests passed\n";
