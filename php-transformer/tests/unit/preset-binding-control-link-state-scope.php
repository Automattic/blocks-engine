<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A projected control state rule names its own control's wrapper
// (`.subject-x > .wp-block-button__link:hover`). It must not count as a
// hover colour for every other button link: a link whose class colour has
// no hover rule of its own binds that colour to the button's native text
// colour, so a parent group's colour control is not silently overridden by
// the retained class rule (#7227). A link that does have a hover colour
// keeps it class-owned, since a preset class would mask the hover.
$html = '<html><head><link rel="stylesheet" href="s.css"></head><body><main><section>'
    . '<div class="text-center"><p>Still have questions?</p><a class="inline-flex rounded-md bg-primary px-6 py-2 text-cream hover:bg-primary/90" href="/contact">Contact Us</a></div>'
    . '<div class="text-center"><a class="inline-flex rounded-md bg-white px-6 py-2 text-ink hover:text-primary" href="/menu">See menu</a></div>'
    . '</section></main></body></html>';
$css = 'body{color:#15281f;background:#faf8f5}a{color:inherit;text-decoration:inherit}.text-center{text-align:center}'
    . '.inline-flex{display:inline-flex}.rounded-md{border-radius:10px}.px-6{padding-left:1.5rem;padding-right:1.5rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}'
    . '.bg-primary{background-color:#1c362b}.bg-white{background-color:#ffffff}.text-cream{color:#faf8f5}.text-ink{color:#15281f}'
    . '.hover\:bg-primary\/90:hover{background-color:rgb(28 54 43 / .9)}.hover\:text-primary:hover{color:#1c362b}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => 's.css', 'content' => $css),
)))->toArray();
$markup = implode('', array_column($result['source_reports']['wordpress_site_plan']['pages'], 'canonical_block_markup'));

$buttons = array();
preg_match_all('/<!-- wp:button (\{.*?\}) -->\s*<div[^>]*>\s*<a ([^>]*)>([^<]*)<\/a>/s', $markup, $matches, PREG_SET_ORDER);
foreach ($matches as $match) $buttons[$match[3]] = array(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR), $match[2]);
if (!isset($buttons['Contact Us'], $buttons['See menu'])) {
    throw new RuntimeException('Missing button blocks: ' . $markup);
}

[$attrs, $link] = $buttons['Contact Us'];
if (!isset($attrs['textColor']) || !preg_match('/class="[^"]*\bhas-text-color\b/', $link)) {
    throw new RuntimeException('A link without a hover colour must own its class text colour natively: ' . $markup);
}

[$attrs, $link] = $buttons['See menu'];
if (isset($attrs['textColor']) || str_contains($link, 'has-text-color')) {
    throw new RuntimeException('A link with a hover colour keeps its text colour class-owned: ' . $markup);
}

echo "PASS: control link state rules are scoped to their own control's wrapper\n";
