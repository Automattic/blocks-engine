<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #4400: the link absorbs a nested painted box, not just its label.
$html = <<<'HTML'
<style>
.rmwidget{position:absolute}
.slot{position:relative;height:40px}
.common-button{position:absolute;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;box-sizing:border-box;border-style:solid;overflow:hidden}
.common-button .text,.common-button .icon{display:none}
</style>
<style class="button_styles">.rmwidget.widget-button .common-button[data-id="b1"]{background-color:rgba(255,255,255,0);border-radius:100px;border-width:2px;border-color:rgb(153,5,143);font-family:Verdana;font-weight:700;color:rgb(153,5,143);font-size:12px}
.rmwidget.widget-button .common-button[data-id="b1"].current{border-radius:5px;border-width:0;color:rgb(60,71,170)}</style>
<div class="slot"><a class="maglink" href="/services/"><div class="rmwidget widget-button" data-id="b1" style="border-radius:100px;left:0;top:0;width:155px;height:21px"><div class="common-button" data-id="b1" style="flex-direction:row"><div class="icon" style="display:none"></div><div class="text" style="display:inline-block;line-height:21px;height:21px">Our Services</div></div></div></a></div>
HTML;
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$markup = $result['serialized_blocks'];
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $markup);
$link = (new DOMXPath($dom))->query('//a[contains(@class,"wp-block-button__link")]')->item(0);
if (!$link instanceof DOMElement || '/services/' !== $link->getAttribute('href') || 'Our Services' !== $link->textContent) {
    throw new RuntimeException('The source must become one editable button link.');
}
foreach (array('border-width:2px', 'border-style:solid', 'border-color:rgb(153,5,143)', 'border-radius:100px', 'background-color:rgba(255,255,255,0)', 'color:rgb(153,5,143)', 'font-family:Verdana', 'font-weight:700', 'font-size:12px') as $expected) {
    if (!str_contains($link->getAttribute('style'), $expected)) {
        throw new RuntimeException('Missing absorbed button presentation: ' . $expected . "\n" . $markup);
    }
}
// Issue #4450: an absorbed label whose box values resolve to CSS-wide
// keywords (Framer's `var(--…, initial)`) must not erase the link's padding.
$framer = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => <<<'HTML'
<style>
.cta{display:flex;padding:16px 24px;background:#c6ff4d;color:#101010;text-decoration:none}
p.t{padding:var(--text-bg-padding,initial);border-radius:var(--text-bg-radius,initial);margin:0}
</style>
<a class="cta" href="/kontakt/"><div><p class="t">Projekt besprechen</p></div></a>
HTML),
))->toArray()['serialized_blocks'];
if (!preg_match('/<!-- wp:button (\{.*?\}) -->/', $framer, $button)) {
    throw new RuntimeException("The Framer CTA must become one button.\n" . $framer);
}
$padding = json_decode($button[1], true)['style']['spacing']['padding'] ?? null;
if (array('top' => '16px', 'right' => '24px', 'bottom' => '16px', 'left' => '24px') !== $padding || preg_match('/"(?:initial|inherit|unset|revert)"/', $button[1])) {
    throw new RuntimeException('Absorbed keyword values replaced the link padding: ' . $button[1]);
}
// Readymag labels declare `font-*: inherit`; the box's authored typography wins.
$readymag = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => <<<'HTML'
<style>
.widget-button .common-button[data-id="b1"]{border-radius:100px;border-width:2px;border-color:rgb(153,5,143);font-family:Montserrat;font-weight:700;color:rgb(153,5,143);font-size:12px}
.common-button{display:flex;border-style:solid}
.common-button .text{padding:0;margin:0;border:none;background-color:transparent;font-family:inherit;font-weight:inherit;color:inherit;font-size:inherit}
</style>
<a class="maglink" href="/our-coop/"><div class="rmwidget widget-button" data-id="b1" style="border-radius:100px;width:155px;height:21px"><div class="common-button" data-id="b1" style="flex-direction:row"><div class="text" style="display:inline-block;line-height:21px">Our Cooperative</div></div></div></a>
HTML),
))->toArray()['serialized_blocks'];
if (!preg_match('/<!-- wp:button (\{.*?\}) -->/', $readymag, $button)) {
    throw new RuntimeException("The Readymag CTA must become one button.\n" . $readymag);
}
$typography = json_decode($button[1], true)['style']['typography'] ?? array();
if ('Montserrat' !== ($typography['fontFamily'] ?? null) || '700' !== (string) ($typography['fontWeight'] ?? '') || '12px' !== ($typography['fontSize'] ?? null) || str_contains($button[1], '"inherit"')) {
    throw new RuntimeException('Absorbed inherit replaced the box typography: ' . $button[1]);
}
echo "Absorbed button box passed\n";
