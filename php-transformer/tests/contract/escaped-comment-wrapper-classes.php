<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ClassPublisher;

// A captured document root becomes a layout-shell; its Shopify-style id
// gives the editor anchor a double hyphen escaped by WordPress comment JSON.
// Seed the wrapper's editor hooks at the artifact boundary, as captured markup can.
$anchor = 'shopify-section-template--22509960855844__main';
foreach (array(null, 'shop') as $namespace) {
    $artifact = array('entrypoint' => 'tag-story.html', 'files' => array(
        'tag-story.html' => '<html><head><style>#' . $anchor . '{padding:20px}</style></head><body><div class="data-blocks-engine-capture-desktop-document blocks-engine-editor-anchor-' . $anchor . ' blocks-engine-editor-anchor-unused--hook" data-blocks-engine-capture-document-scope="" id="' . $anchor . '"><main><p>Story</p></main></div></body></html>',
    ));
    if (null !== $namespace) $artifact['class_namespace'] = $namespace;
    $plan = (new ArtifactCompiler())->compile($artifact)->toWordPressSitePlanView()['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    preg_match_all('/<!-- wp:[^\s]+\/layout-shell (.*?) -->\s*<[^>]+\bclass="([^"]*)"/s', $markup, $shells, PREG_SET_ORDER);
    if (1 !== count($shells)) throw new RuntimeException('Fixture must emit a layout-shell.');
    $attributes = json_decode($shells[0][1], true, 512, JSON_THROW_ON_ERROR);
    $comment = $attributes['wrappers'][0]['attributes']['class'];
    $html = html_entity_decode($shells[0][2], ENT_QUOTES | ENT_HTML5);
    if ($comment !== $html) throw new RuntimeException('Decoded wrapper classes must agree with saved HTML: ' . $comment . ' != ' . $html);
    if (str_contains($comment, 'unused') || str_contains($html, 'unused')) throw new RuntimeException('An unreferenced escaped hook must be dropped from both copies.');
    $engine = 'blocks-engine-editor-anchor-' . $anchor;
    $expected = null === $namespace ? $engine : ($plan[ClassPublisher::MAP_KEY][$engine] ?? null);
    if (null === $expected || !in_array($expected, explode(' ', $html), true)) throw new RuntimeException('The delivered id must keep its editor anchor, using the published name when enabled.');
}
echo "escaped-comment-wrapper-classes contract passed\n";
