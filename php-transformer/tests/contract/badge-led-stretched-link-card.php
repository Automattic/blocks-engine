<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A headingless product card whose content box leads with a badge span before
// its authored paragraphs (#5249). The badge lowers to a synthetic paragraph,
// which never names the card, so the destination belongs on the first
// authored paragraph and the card stays a native group, not a layout shell.
$out = (new HtmlTransformer())->transform(
    '<main><a class="group border bg-card" href="/product/kit/">'
    . '<div class="relative overflow-hidden"><img src="kit.jpg" alt="Kit" class="aspect-[4/3] w-full object-cover"></div>'
    . '<div class="p-5"><span class="inline-block border px-2 text-[10px] uppercase">Universal</span>'
    . '<p class="mt-3 text-xs">Atelier Care</p><p class="mt-1 text-lg">Ceramic Coating Kit</p><p class="mt-2">R 1 899,00</p></div>'
    . '</a></main>'
)->toArray();
$markup = $out['serialized_blocks'];
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="utf-8"?>' . $markup);
$xpath = new DOMXPath($dom);
$link = $xpath->query('//a[@href="/product/kit/"]');
if (str_contains($markup, '/layout-shell')
    || 1 !== $xpath->query('//div[contains(@class,"blocks-engine-stretched-link")]')->length
    || 1 !== $link->length
    || 'Atelier Care' !== trim((string) $link->item(0)?->textContent)
    || !str_contains((string) $link->item(0)?->parentNode?->attributes?->getNamedItem('class')?->nodeValue, 'mt-3')) {
    throw new RuntimeException('A badge-led product card needs one native destination on its first authored paragraph: ' . $markup);
}
echo "Badge-led stretched-link card contract passed.\n";
