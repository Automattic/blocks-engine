<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A RichText icon image replaces an inline <svg>. It must size like that SVG:
// no compressible `img{max-width:100%}` box (which drops its min-content
// width), and as a flex item no content-based automatic minimum. Authored
// minimums and limits still win.
$icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="icon" fill="currentColor"><path d="M2 2h20v20H2z"/></svg>';
$cases = array(
    'flex item' => array('.cta{display:flex;align-items:center;gap:8px;padding:10px 20px;background:#059669;color:#fff}', array('max-width:none', 'min-width:0', 'min-height:0'), array()),
    'inline text' => array('.cta{color:#047857}', array('max-width:none'), array('min-width:0')),
    'authored limits' => array('.cta{display:flex;gap:8px;padding:10px;background:#059669;color:#fff}.icon{max-width:12px;min-width:10px}', array('max-width:12px', 'min-width:10px', 'min-height:0'), array('max-width:none', 'min-width:0')),
);
foreach ($cases as $case => [$css, $present, $absent]) {
    $result = (new HtmlTransformer())->transform(
        '<style>.icon{width:1rem;height:1rem}' . $css . '</style><header><a class="cta" href="https://example.com/chat">' . $icon . '<span>Chat with us</span></a></header>'
    )->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    if (1 !== preg_match('/<img[^>]*class="[^"]*(be-inline-geometry-[0-9a-f]+)/', $markup, $carrier)) {
        throw new RuntimeException($case . ': the icon must become a RichText image with a geometry carrier: ' . $markup);
    }
    $styles = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    if (1 !== preg_match('/\.' . $carrier[1] . '\{([^}]*)\}/', $styles, $rule)) {
        throw new RuntimeException($case . ': missing geometry rule for ' . $carrier[1]);
    }
    $declarations = explode(';', $rule[1]);
    foreach ($present as $declaration) {
        if (! in_array($declaration, $declarations, true)) throw new RuntimeException($case . ': expected ' . $declaration . ' in {' . $rule[1] . '}');
    }
    foreach ($absent as $declaration) {
        if (in_array($declaration, $declarations, true)) throw new RuntimeException($case . ': unexpected ' . $declaration . ' in {' . $rule[1] . '}');
    }
}
echo "richtext-svg-image-sizes-like-svg: ok\n";
