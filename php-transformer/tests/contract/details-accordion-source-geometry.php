<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// The complete acceptance input from #5887, plus a renamed source to guard
// against presentation depending on a site's class or question text.
$fixture = <<<'HTML'
<style>.faq details{padding:20px 0;border-bottom:1px solid #cfd8d2}.faq summary{font:500 18px Georgia,serif;cursor:pointer}.faq details p{font-size:13px;line-height:1.8}</style>
<div class="faq">
<details><summary>How do I request a quotation?</summary><p>Choose a service…</p></details>
<details><summary>Can I enquire from outside the UK?</summary><p>Yes…</p></details>
</div>
HTML;
foreach (array('issue fixture' => $fixture, 'alternate accordion container' => str_replace('faq', 'accordion', $fixture)) as $name => $html) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) $result['serialized_blocks'];
    $css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
    $assert = static function (bool $condition, string $message) use ($name): void {
        if (!$condition) throw new RuntimeException($name . ': ' . $message);
    };
    $blocks = (new Runtime())->parseBlocks($markup);
    $accordion = $blocks[0];
    $assert('core/accordion' === $accordion['blockName'], 'editable core accordion');
    $assert(2 === count($accordion['innerBlocks']), 'both source rows survive');
    foreach ($accordion['innerBlocks'] as $item) {
        $assert('core/accordion-item' === $item['blockName'] && empty($item['attrs']['openByDefault']), 'closed native item');
        $style = $item['attrs']['style'];
        $assert(array('top' => '20px', 'right' => '0', 'bottom' => '20px', 'left' => '0') === $style['spacing']['padding'], 'source details padding');
        $assert(array('width' => '1px', 'style' => 'solid', 'color' => '#cfd8d2') === $style['border']['bottom'], 'source row separator');
        $heading = $item['innerBlocks'][0];
        $assert('core/accordion-heading' === $heading['blockName'], 'editable core heading');
        $type = $heading['attrs']['style']['typography'];
        $assert('18px' === $type['fontSize'] && '500' === $type['fontWeight'] && 'Georgia,serif' === $type['fontFamily'], 'summary typography attributes');
        preg_match('/blocks-engine-accordion-toggle-[a-f0-9]+/', $heading['attrs']['className'], $match);
        $assert(str_contains($css, '.wp-block-accordion-heading.' . $match[0] . '{margin:0}'), 'synthesized heading adds no margins');
        $toggle = '.wp-block-accordion-heading.' . $match[0] . '>.wp-block-accordion-heading__toggle';
        $assert(1 === preg_match('/' . preg_quote($toggle, '/') . '\{([^}]+)\}/', $css, $rule), 'toggle presentation emitted');
        foreach (array('padding:0', 'font-size:18px', 'font-weight:500', 'font-family:Georgia,serif', 'line-height:normal', 'display:block') as $declaration) {
            $assert(str_contains($rule[1], $declaration), 'toggle ' . $declaration);
        }
        $assert(str_contains($css, $toggle . '>.wp-block-accordion-heading__toggle-icon{display:none}'), 'core right plus is hidden');
        // A <button> cannot render a list marker; the title inside it does.
        $assert(str_contains($css, $toggle . '>.wp-block-accordion-heading__toggle-title{display:list-item;list-style:inside disclosure-closed}'), 'title carries the native left marker');
        $assert(str_contains($css, $toggle . '[aria-expanded="true"]>.wp-block-accordion-heading__toggle-title{list-style-type:disclosure-open}'), 'left marker follows native open state');
    }
    $assert(2 === substr_count($markup, 'padding-top:20px;padding-right:0;padding-bottom:20px;padding-left:0'), 'rendered row padding');
    $assert(2 === substr_count($markup, '<button type="button" class="wp-block-accordion-heading__toggle">'), 'core save button has no extra attributes');
    $assert(!str_contains($markup, '<!-- wp:html'), 'no raw HTML fallback');
}
echo "Details accordion source geometry contract passed\n";
