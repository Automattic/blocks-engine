<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$cases = array(
    'inline' => '<main><button aria-label="Open options"><div style="display: none"><span>Open options</span></div><div class="icon"><span class="glyph"></span></div></button></main>',
    'author rule' => '<style>.launcher .label{display:none}.launcher .icon{background:blue}</style><main><button class="launcher" aria-label="Open options"><div class="label"><span>Open options</span></div><div class="icon"><span class="glyph"></span></div></button></main>',
);
foreach ($cases as $name => $html) {
    $result = (new HtmlTransformer())->transform($html, array())->toArray();
    $markup = $result['serialized_blocks'];
    $document = new DOMDocument();
    $document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $button = $xpath->query('//button')->item(0);
    $assert($button instanceof DOMElement, "$name: one native button survives");
    if (!$button instanceof DOMElement) continue;
    $assert('Open options' === $button->getAttribute('aria-label'), "$name: accessible name survives");
    $assert(1 === $xpath->query('./div[contains(concat(" ",normalize-space(@class)," ")," icon ")]/span[contains(concat(" ",normalize-space(@class)," ")," glyph ")]', $button)->length, "$name: visible icon subtree and classes survive");
    $hidden = $xpath->query('./div[1]', $button)->item(0);
    $assert($hidden instanceof DOMElement && 'Open options' === trim($hidden->textContent), "$name: label stays in its original descendant box");
    if (!$hidden instanceof DOMElement) continue;
    if ('inline' === $name) {
        $assert('display: none' === $hidden->getAttribute('style'), 'inline: hidden label stays display:none');
    } else {
        $css = implode("\n", array_column($result['assets'], 'content'));
        $assert(str_contains($css, '.launcher .label{display:none}'), 'author rule: hiding selector still targets the retained descendant');
        $assert(str_contains($hidden->getAttribute('class'), 'label'), 'author rule: selector target remains on the retained label');
    }
    // The only label text remains inside the hidden box, not flattened next
    // to the artwork or duplicated as a core/button label.
    $button->removeChild($hidden);
    $assert('' === trim($button->textContent), "$name: visible label text is empty");
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Hidden button labels passed\n";
