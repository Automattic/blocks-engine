<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Issue #4677: an inline colour must not collapse the link's flex items.
$html = '<style>:root{--bg:30 50% 90%}img,svg{display:block}.contact{background:#3b2219}.inline-flex{display:inline-flex}.items-center{align-items:center}.gap{gap:.5rem}</style>'
    . '<main><section class="contact"><ul>'
    . '<li><a href="tel:5550100" class="inline-flex items-center gap" style="color:hsl(var(--bg))"><svg width="20" height="20" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg><span>555-0100</span></a></li>'
    . '<li><a href="mailto:hello@example.com" class="inline-flex items-center gap" style="color:#f5ebe0"><svg width="20" height="20" viewBox="0 0 24 24"><rect width="20" height="16" x="2" y="4"/></svg><span>hello@example.com</span></a></li>'
    . '</ul></section></main>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="utf-8" ?><body>' . $markup . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
foreach (array('tel:5550100' => 'hsl(var(--bg))', 'mailto:hello@example.com' => '#f5ebe0') as $href => $color) {
    $link = $xpath->query('//a[@href="' . $href . '"]')->item(0);
    if (!$link instanceof DOMElement
        || 1 !== $xpath->query('./img', $link)->length
        || 1 !== $xpath->query('./span', $link)->length
        || 1 !== $xpath->query('./span/mark[contains(@style,"color:' . $color . '")]', $link)->length
        || 0 === $xpath->query('ancestor::*[contains(@style,"color:' . $color . '")]', $link)->length
        || 0 !== $xpath->query('./mark', $link)->length) {
        fwrite(STDERR, "FAIL: direct icon and label flex items retain link colour: {$href}\n{$markup}\n");
        exit(1);
    }
}
echo "Inline-flex link colour tests passed\n";
