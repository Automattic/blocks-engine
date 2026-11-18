<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\MissingMediaRecovery;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A JSON-valued data attribute is opaque, not a media slot. The layout shell
// must carry the same value in its block model and its saved wrapper markup.
$value = '{"url":"media/missing.mp4"}';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<main><div data-x=\'' . $value . '\'><h1>Welcome</h1><p>A neutral page.</p></div></main><script>document.querySelectorAll("[data-x]").forEach(function(el){JSON.parse(el.getAttribute("data-x"));});</script>'),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
WordPressSitePlan::assertValid($plan);
$markup = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');

preg_match_all('~<!-- wp:[^\s]+/layout-shell (\{.*?\}) -->~s', $markup, $openers);
$attribute = null;
foreach ($openers[1] as $opener) {
    $model = json_decode($opener, true, 512, JSON_THROW_ON_ERROR);
    foreach ($model['wrappers'] ?? array() as $wrapper) {
        if (isset($wrapper['attributes']['data-x'])) $attribute = $wrapper['attributes']['data-x'];
    }
}
preg_match('/data-x="([^"]*)"/', $markup, $mirror);
$saved = isset($mirror[1]) ? html_entity_decode($mirror[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
if ($attribute !== $value || $saved !== $value || $attribute !== $saved) {
    throw new RuntimeException('The serialized layout-shell data-x and saved markup must preserve the identical opaque value. Attribute: ' . var_export($attribute, true) . '; saved: ' . var_export($saved, true));
}
if (!str_contains($attribute, 'media/missing.mp4') || str_contains($markup, MissingMediaRecovery::placeholderReference()) || str_contains($markup, 'placeholder.svg')) {
    throw new RuntimeException('An opaque data attribute must retain the missing path, not a media placeholder.');
}
echo "Opaque data attribute missing media contract passed\n";
