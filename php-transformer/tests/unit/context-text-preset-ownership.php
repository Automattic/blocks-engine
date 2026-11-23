<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><style>
body{color:#222;background:#fff;font-family:Arial,sans-serif}
.banner p{color:#77716a;font-family:Georgia,serif}.gallery .caption .caption-heading{font-family:Georgia,serif;font-weight:700}.serif{font-family:Georgia,serif}.variant p{color:#77716a}
.banner h2{color:#77716a}.variant p:hover{color:red}.responsive p{color:#77716a}
@media(max-width:600px){.responsive p{color:red}}
</style></head><body><main><div class="banner"><p>Context paint</p><h2>Heading paint</h2></div>
<div class="variant"><p>State paint</p></div>
<div class="gallery"><div class="caption"><div class="caption-heading">Classed paint</div></div></div>
<div class="responsive"><p>Responsive paint</p></div><p class="serif">Serif</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$markup = implode("\n", array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
if (!preg_match('/<p\b([^>]*)>Context paint<\/p>/', $markup, $match)) throw new RuntimeException('Context paragraph stays native.');
if (!str_contains($match[1], 'has-text-color') || !preg_match('/has-[a-z0-9-]+-color/', $match[1])) throw new RuntimeException('Classless context paint must be owned by native block colour support.');
if (!preg_match('/has-[a-z0-9-]+-font-family/', $match[1])) throw new RuntimeException('Classless context font must be owned by native block typography support.');
foreach (array('State paint', 'Responsive paint') as $text) {
    if (!preg_match('/<p\b([^>]*)>' . $text . '<\/p>/', $markup, $match)) throw new RuntimeException('Variant paragraph stays native.');
    if (str_contains($match[1], 'has-text-color') || str_contains($match[1], 'color:')) throw new RuntimeException('Native binding must not freeze a selector variant.');
}
// An author class does not make a descendant selector an element-local
// recipe: its winning contextual paint still needs native ownership.
if (!preg_match('/<p\b([^>]*)>Classed paint<\/p>/', $markup, $match)) throw new RuntimeException('Classed paragraph stays native.');
if (!preg_match('/has-[a-z0-9-]+-font-family|font-family:/', $match[1])) throw new RuntimeException('Classed contextual font must reach native typography support.');
if (!preg_match('/<h2\b([^>]*)>Heading paint<\/h2>/', $markup, $match)) throw new RuntimeException('Context heading stays native.');
if (!str_contains($match[1], 'has-text-color')) throw new RuntimeException('Contextual heading colour must reach native colour support.');
echo "Context text preset ownership passed.\n";
