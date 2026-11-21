<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// Issue #7334: the phone capture opens the page's own nav, not a new panel.
$links = '<a href="/index.html#stories">The Stories</a><a href="/index.html#approach">Approach</a><a href="/index.html#collections">Collections</a><a href="/index.html#about">About</a><a class="nav-cta" href="/index.html#enquire">Get in touch ↗</a>';
$header = '<header class="nav"><a href="/index.html#top" class="brand">mdzefry.</a><button class="menu" aria-label="Open navigation">☰</button><nav class="links">' . $links . '</nav></header>';
$css = '.links{display:flex;gap:32px;align-items:center}
.menu{display:none;border:0;background:transparent;color:white;font-size:24px}
@media (max-width:767px){ .menu{display:block} .links{display:none;position:absolute;top:65px;left:0;right:0;background:#24201e;padding:25px 7%;flex-direction:column;align-items:flex-start} .links.show{display:flex} }';
$open = '<nav class="links show" style="display: flex !important; visibility: visible !important;">' . str_replace('/index.html#', '/#', $links) . '</nav>';
$selector = 'body > header > button';
// Mirror the state-owned probe evidence supplied by captured-dialog contracts.
$evidence = array('status' => 'captured',
    'trigger' => array('selector' => $selector, 'tag' => 'button', 'label' => 'Open navigation', 'dataBindings' => array()),
    'dialog' => array('tag' => 'nav', 'html' => $open, 'htmlBytes' => strlen($open), 'htmlTruncated' => false));
$component = array('selector' => $selector, 'kind' => 'menu', 'initial' => 'closed', 'status' => 'captured',
    'states' => array(array('id' => 'closed', 'html' => ''), array('id' => 'open', 'html' => $open, 'evidence' => $evidence)),
    'transitions' => array(array('from' => 'closed', 'to' => 'open', 'trigger' => array('selector' => $selector, 'action' => 'click'))));
$failures = array();
foreach (array('without component' => array(), 'with component' => array($component)) as $case => $components) {
    $artifact = array('entrypoint' => 'index.html', 'files' => array(
        array('path' => 'index.html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body>' . $header . '</body></html>'),
        array('path' => 'style.css', 'content' => $css),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v3', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => $components)))))
    ));
    $result = (new ArtifactCompiler())->compile($artifact)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $blocks = (new Runtime())->parseBlocks($markup);
    $navigation = array();
    $walk = static function (array $blocks) use (&$walk, &$navigation, &$failures, $case): void {
        foreach ($blocks as $block) {
            if ('core/navigation' === ($block['blockName'] ?? '')) $navigation[] = $block;
            if (preg_match('/(?:display|visibility)[^!]*!important/i', (string) json_encode($block['attrs'] ?? array()))) $failures[] = $case . ': forced pose in block attributes';
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($blocks);
    if (1 !== count($navigation) || 'mobile' !== ($navigation[0]['attrs']['overlayMenu'] ?? null)) $failures[] = $case . ': expected one responsive core/navigation';
    foreach (array('The Stories', 'Approach', 'Collections', 'About', 'Get in touch ↗') as $label) {
        if (!str_contains($markup, $label)) $failures[] = $case . ': missing ' . $label;
    }
    if (str_contains($markup, 'data-blocks-engine-capture-dialog')) $failures[] = $case . ': captured dialog survived';
    // Core renders overlayMenu=mobile dynamically: is-responsive and the
    // responsive-container-open/-close buttons are not stored in block markup.
    $runtime = new Runtime();
    if (!$blocks || $runtime->serializeBlocks($blocks) !== $markup) $failures[] = $case . ': block markup does not round-trip';
    if (5 !== count($navigation[0]['innerBlocks'] ?? array())) $failures[] = $case . ': expected five native navigation links';
    if (isset($baseline) && $baseline !== $markup) $failures[] = 'menu evidence must not change native navigation output';
    $baseline = $markup;
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit($failures ? 1 : 0);
