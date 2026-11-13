<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Capture saves the after pose. The engine must be able to remove that pose
// and recover the authored paused binding, including through an ancestor
// identity that editable block serialization projects to a marker.
$state = htmlspecialchars(json_encode(array(
    'attributes' => array('data-entered' => array('before' => null, 'after' => 'done')),
    'threshold' => array(0.15),
    'cssAnimations' => array(array('name' => 'reveal')),
), JSON_THROW_ON_ERROR), ENT_QUOTES);
$html = '<main><div data-owner="card"><h2 id="title" class="heading" data-entered="done" data-blocks-engine-capture-viewport-entrance="' . $state . '">Welcome</h2></div></main>';
$css = '@keyframes reveal{from{opacity:0}to{opacity:1}}'
    . "\n[data-owner=\"card\"] :is(.heading):is(#title,[id^=\"title__\"]):not([data-entered=\"done\"]) \n{--entrance:reveal 1s backwards paused}"
    . '.heading{animation:var(--entrance,none)}';
$result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
$found = false;
foreach ($result['assets'] ?? array() as $asset) {
    $content = $asset['content'] ?? '';
    // Editor static-state rules retain the source text as an exclusion; they
    // cannot prove that the frontend binding survived projection.
    if (!str_contains($asset['path'] ?? '', 'editor-static-state')
        && str_contains($content, ':where(#title)')
        && str_contains($content, ':not([data-entered="done"]){--entrance:reveal')) {
        $found = true;
    }
}
if (!$found) {
    throw new RuntimeException('The public transform must preserve the replay-owned live pose selector.');
}
// A keyframe-only var() reader is not an ordinary style-rule consumer.
// Its inline value must survive normalization instead of falling back to
// a less-specific stylesheet definition.
$inline = (new HtmlTransformer())->transform(
    '<div id="scope" style="--clip-start:inset(100%)" data-blocks-engine-capture-viewport-entrance="' . $state . '"><h2>Title</h2></div>',
    array('static_css' => '@keyframes reveal{from{clip-path:var(--clip-start)}to{clip-path:inset(0)}}#scope{--clip-start:inset(50%);animation:reveal 1s backwards paused}')
)->toArray();
$carried = false;
foreach ($inline['assets'] ?? array() as $asset) {
    if (str_contains($asset['content'] ?? '', '--clip-start:inset(100%)')) $carried = true;
}
if (!$carried) throw new RuntimeException('Keyframe custom properties must retain their inline scope.');
echo "Viewport entrance live selector passed.\n";
