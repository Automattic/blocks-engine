<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A box hidden at rest and shown by a media rule at the desktop reference
// viewport still renders its text there. A class colour on that text must bind
// to the paragraph's native colour support, so a parent's colour control is not
// silently overridden by the retained author class (#7227).
$html = '<html><head><link rel="stylesheet" href="s.css"></head><body><main><section>'
    . '<h2 class="ink">Visible heading</h2><p class="accent">Visible accent</p>'
    . '<div class="hidden sm:block"><p class="font-serif ink">Desktop ink</p><p class="mt-2 accent">Desktop accent</p></div>'
    . '</section></main></body></html>';
$css = 'body{color:#2a2a28}.hidden{display:none}@media (min-width:640px){.sm\:block{display:block}}'
    . '.font-serif{font-family:Georgia,serif}.mt-2{margin-top:.5rem}'
    . '.ink{--tw-text-opacity:1;color:rgb(29 29 27 / var(--tw-text-opacity, 1))}.accent{color:#667551}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
    array('path' => 's.css', 'content' => $css),
)))->toArray();
$markup = implode('', array_column($result['source_reports']['wordpress_site_plan']['pages'], 'canonical_block_markup'));

foreach (array('Desktop ink', 'Desktop accent') as $text) {
    if (!preg_match('/<!-- wp:paragraph (\{[^}]*\}) -->\s*<p\b([^>]*)>' . preg_quote($text, '/') . '<\/p>/', $markup, $match)) {
        throw new RuntimeException("Missing paragraph {$text}: {$markup}");
    }
    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    if (!isset($attrs['textColor']) || !str_contains($match[2], 'has-text-color')) {
        throw new RuntimeException("Desktop-only class colour must be block-owned for {$text}: {$markup}");
    }
}

// An image caption bypasses paragraph lowering. Its class-coloured inline text
// must still own its colour inline, as paragraph RichText does, instead of
// leaving a retained author class to override the block's colour control.
$caption = '<html><head><link rel="stylesheet" href="s.css"></head><body><main><section>'
    . '<figure class="relative"><img src="portrait.jpg" alt="Portrait"><figcaption class="absolute"><span class="font-serif sand">Caption ink</span> <strong>plain</strong></figcaption></figure>'
    . '</section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $caption),
    array('path' => 's.css', 'content' => 'body{color:#2a2a28}.relative{position:relative}.absolute{position:absolute}.font-serif{font-family:Georgia,serif}.sand{--tw-text-opacity:1;color:rgb(250 248 243 / var(--tw-text-opacity, 1))}'),
)))->toArray();
$markup = implode('', array_column($result['source_reports']['wordpress_site_plan']['pages'], 'canonical_block_markup'));
if (!preg_match('/<figcaption class="wp-element-caption">(.*?)<\/figcaption>/s', $markup, $match)
    || !preg_match('/<(?:span|mark)\b[^>]*(?:style="[^"]*\bcolor:[^"]+"|class="[^"]*\bhas-[\w-]*color\b)[^>]*>Caption ink</', $match[1])) {
    throw new RuntimeException('Caption class colour must be owned by the caption text: ' . $markup);
}
if (!str_contains($match[1], '<strong>plain</strong>')) {
    throw new RuntimeException('Caption text without its own colour must stay untouched: ' . $markup);
}
// An inline layout carrier keeps its source inline (a flex row label, a
// pushed-right arrow) so its classes keep the layout. The text it holds must
// still own its class colour, so a parent group's colour control is not
// silently overridden by the retained class.
$carrier = '<html><head><link rel="stylesheet" href="s.css"></head><body><main><section><ul>'
    . '<li class="row"><span class="flex items-center gap-2 muted"><svg viewBox="0 0 24 24" class="icon"><circle cx="12" cy="12" r="10"></circle></svg>Monday</span><span class="strong">9 AM</span></li>'
    . '</ul><a class="card" href="/more"><h3>More</h3><span class="ml-auto accent">&#8594;</span></a></section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $carrier),
    array('path' => 's.css', 'content' => 'body{color:#2a2a28}.row{display:flex;justify-content:space-between;gap:1rem}.card{display:flex;align-items:center;gap:1rem}'
        . '.flex{display:flex}.items-center{align-items:center}.gap-2{gap:.5rem}.ml-auto{margin-left:auto}.icon{width:1rem;height:1rem}'
        . '.muted{color:rgb(21 40 31 / .75)}.strong{font-weight:600}.accent{--tw-text-opacity:1;color:rgb(28 54 43 / var(--tw-text-opacity, 1))}'),
)))->toArray();
$markup = implode('', array_column($result['source_reports']['wordpress_site_plan']['pages'], 'canonical_block_markup'));
foreach (array('Monday', '→') as $text) {
    if (!preg_match('/<p class="[^"]*blocks-engine-inline-layout-carrier[^"]*">(.*?)<\/p>/s', $markup, $unused) || !str_contains($markup, $text)) {
        throw new RuntimeException("Missing inline layout carrier for {$text}: {$markup}");
    }
    if (!preg_match('/<(?:span|mark)\b[^>]*(?:style="[^"]*\bcolor:[^"]+"|class="[^"]*\bhas-[\w-]*color\b)[^>]*>(?:<img[^>]*>)?' . preg_quote($text, '/') . '</u', $markup)) {
        throw new RuntimeException("Inline layout carrier text must own its class colour for {$text}: {$markup}");
    }
}
echo "PASS: desktop-only, caption and inline layout carrier text own their class colour natively\n";
