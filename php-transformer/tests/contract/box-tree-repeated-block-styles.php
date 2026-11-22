<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

// A long text page under a global reset (`*{margin:0;padding:0}`) keeps the
// vertical zero margins of every paragraph, list item and heading over the
// user agent's 1em (#7099). Repeated on each block they are one block-type
// value: the site plan writes it once as a theme.json block style and the
// blocks carry no inline zero margin (#7120). A block whose margin differs
// keeps its own. The source and the compiled frontend, with the global
// styles WordPress prints for that theme.json after a root layout gap, must
// render the same boxes.
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . $detail));
    echo "PASS: $message\n";
};

$author = '*{margin:0;padding:0}body{font-family:Arial;font-size:16px;line-height:24px;color:#294d4b}'
    . 'h2{font-size:27px;line-height:32px;font-weight:600;margin-bottom:12px}p{margin:0 0 18px}ul{padding-left:25px}.note{margin-bottom:30px}';
$paragraphs = array('First policy paragraph.', 'Second policy paragraph.', 'Third policy paragraph.', 'Fourth policy paragraph.', 'Fifth policy paragraph.');
$items = array('Alpha item', 'Beta item', 'Gamma item', 'Delta item');
// The capture's used values carry the author cascade; the browser source
// renders it from the stylesheet.
$page = '<!doctype html><html><head><meta charset="utf-8"><title>Policy</title></head><body><main><article>'
    . '<h2>Section heading</h2>'
    . implode('', array_map(static fn(string $text): string => '<p>' . $text . '</p>', $paragraphs))
    . '<p class="note">Spaced note paragraph.</p>'
    . '<ul>' . implode('', array_map(static fn(string $text): string => '<li>' . $text . '</li>', $items)) . '</ul>'
    . '<h2>Second heading</h2>'
    . '<p>Closing paragraph.</p>'
    . '</article></main></body></html>';
$html = str_replace('</head>', '<style>' . $author . '</style></head>', $page);

// Used values for the reset page at each capture width.
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 844));
$styles = static function (array $overrides = array()): array {
    $used = array_fill_keys(BoxTree::PROPERTIES, 'none');
    foreach (array('padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left') as $box) $used[$box] = '0px';
    foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) $used[$border] = '0px none rgb(41, 77, 75)';
    return array_merge($used, array(
        'display' => 'block', 'position' => 'static', 'overflow-x' => 'visible', 'overflow-y' => 'visible',
        'background-color' => 'rgba(0, 0, 0, 0)', 'opacity' => '1', 'mix-blend-mode' => 'normal',
        'border-radius' => '0px', 'letter-spacing' => 'normal', 'font-style' => 'normal', 'text-transform' => 'none',
        'color' => 'rgb(41, 77, 75)', 'font-family' => 'Arial', 'font-size' => '16px', 'font-weight' => '400', 'line-height' => '24px',
        'list-style-type' => 'disc', 'list-style-position' => 'outside',
    ), $overrides);
};
$dom = new DOMDocument();
$dom->loadHTML($page, LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$id = 0;
foreach ($xpath->query('//*') as $element) $element->setAttribute(BoxTree::NODE_ATTRIBUTE, 'n' . ++$id);
$source = '<!doctype html>' . $dom->saveHTML($dom->documentElement);
$nodes = array();
foreach ($viewports as $name => $viewport) {
    $y = 0;
    $width = $viewport['width'];
    foreach ($xpath->query('//*') as $element) {
        $node = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
        $tag = $element->tagName;
        $box = array('x' => 0, 'y' => $y, 'width' => $width, 'height' => 0);
        $used = $styles();
        if (in_array($tag, array('head', 'title', 'meta', 'style'), true)) $used['display'] = 'none';
        if ('h2' === $tag) { $used = $styles(array('font-size' => '27px', 'line-height' => '32px', 'font-weight' => '600', 'margin-bottom' => '12px')); $box['height'] = 32; }
        if ('p' === $tag) {
            $box['height'] = 24;
            $used['margin-bottom'] = 'note' === $element->getAttribute('class') ? '30px' : '18px';
        }
        if ('ul' === $tag) { $used['padding-left'] = '25px'; $box['height'] = 24 * count($items); }
        if ('li' === $tag) { $used['display'] = 'list-item'; $box = array('x' => 25, 'y' => $y, 'width' => $width - 25, 'height' => 24); }
        if (in_array($tag, array('h2', 'p', 'li'), true)) $y += $box['height'] + (int) $used['margin-bottom'];
        $nodes[$node]['children'] = array_values(array_map(static fn(DOMElement $child): string => $child->getAttribute(BoxTree::NODE_ATTRIBUTE), array_filter(iterator_to_array($element->childNodes), static fn($child): bool => $child instanceof DOMElement)));
        $nodes[$node]['viewports'][$name] = array('border_box' => $box, 'used_styles' => $used);
    }
    // Containers span their content.
    foreach (array('//html', '//body', '//main', '//article') as $query) {
        $node = $xpath->query($query)->item(0)->getAttribute(BoxTree::NODE_ATTRIBUTE);
        $nodes[$node]['viewports'][$name]['border_box']['height'] = $y;
    }
}
$tree = array('schema' => BoxTree::SCHEMA, 'viewports' => $viewports, 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source));
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR))));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'The box-tree route is engine-owned.');
$markup = (string) $plan['pages'][0]['canonical_block_markup'];
$theme = null;
foreach ($plan['writes'] as $write) if ('theme.json' === ($write['target_path'] ?? null)) $theme = json_decode((string) $write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
$assert(is_array($theme), 'The plan writes theme.json.');

// One block's opening delimiter through its root tag.
$opening = static function (string $text) use ($markup): string {
    $at = strpos($markup, '>' . $text . '<');
    if (false === $at) throw new RuntimeException("Missing block text: $text\n$markup");
    $start = (int) strrpos(substr($markup, 0, $at), '<!-- wp:');
    return substr($markup, $start, $at + 1 - $start);
};
$margin = static function (string $block): array {
    if (1 !== preg_match('/^<!-- wp:[a-z\/-]+ (\{.*?\}) -->/s', $block, $match)) return array();
    $attrs = json_decode($match[1], true);
    return is_array($attrs['style']['spacing']['margin'] ?? null) ? $attrs['style']['spacing']['margin'] : array();
};
$blocks = array_merge(array_fill_keys($paragraphs, 'paragraph'), array('Closing paragraph.' => 'paragraph'), array_fill_keys($items, 'list item'), array('Section heading' => 'heading', 'Second heading' => 'heading'));
foreach ($blocks as $text => $kind) {
    $block = $opening($text);
    $assert(array() === array_intersect_key($margin($block), array('top' => true, 'bottom' => true)), "The $kind \"$text\" carries no vertical margin attribute.", $block);
    $assert(0 === preg_match('/margin-(?:top|bottom):/', $block), "The $kind \"$text\" serializes no inline vertical margin.", $block);
}
$note = $opening('Spaced note paragraph.');
$assert(isset($margin($note)['bottom']) && str_contains($note, 'margin-bottom:'), 'A paragraph whose margin differs keeps its own value inline.', $note);

$blockStyles = $theme['styles']['blocks'] ?? array();
foreach (array('core/paragraph', 'core/list-item', 'core/heading') as $type) {
    $sides = $blockStyles[$type]['spacing']['margin'] ?? array();
    $assert('0px' === ($sides['top'] ?? null) && null !== ($sides['bottom'] ?? null), "theme.json styles.blocks.$type holds the block type's vertical margins.", json_encode($blockStyles));
}

// Table-driven regressions for the real-import blockers. These exercise the
// site-wide planner with emitted shell markup, not a permissive CSS reset.
$cases = array(
    'paragraph with delivered shell reset' => array('paragraph', 'p', 'blocks-engine-synthetic-paragraph', ':root :where(.blocks-engine-synthetic-paragraph){margin-top:0;margin-bottom:0}', '', false),
    'group with excluded descendant reset' => array('group', 'div', 'wp-block-group', ':root :where(.wp-block-group.flow)>:not(.zero){margin-block-start:revert;margin-block-end:revert}', 'zero', false),
    'group under zero parent gap' => array('group', 'div', 'wp-block-group', '', '', true),
    'center aligned image with same-value reset' => array('image', 'figure', 'wp-block-image', '.wp-block-image{margin:0}', '', false),
    'details with sound block selector' => array('details', 'details', 'wp-block-details', '', '', false),
);
$caseBoxes = array();
foreach ($cases as $label => [$name, $tag, $class, $rule, $extra, $zeroGap]) {
    $attrs = array('style' => array('spacing' => array('margin' => array('top' => '0px', 'bottom' => '0px'))));
    if ('image' === $name) $attrs['align'] = 'center';
    $block = '<!-- wp:' . $name . ' ' . Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes::encode($attrs) . ' --><' . $tag . ' class="' . $class . ' ' . $extra . '" style="margin-top:0px;margin-bottom:0px">' . ('details' === $name ? '<summary>Question</summary>' : 'Text') . '</' . $tag . '><!-- /wp:' . $name . ' -->';
    $before = $block . $block;
    if ('paragraph' === $name) $before .= '<!-- wp:paragraph {"className":"blocks-engine-synthetic-paragraph"} --><p class="blocks-engine-synthetic-paragraph">Shell</p><!-- /wp:paragraph -->';
    if ($zeroGap) $before = '<!-- wp:group {"style":{"spacing":{"blockGap":"0px"}}} --><div class="wp-block-group" style="margin-top:0px;margin-bottom:0px">' . $before . '</div><!-- /wp:group -->';
    $result = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RepeatedBlockStyles::hoist(array('page' => array('markup' => $before, 'strippable' => true)), array(), array(array('kind' => 'css', 'content' => $rule)));
    $assert(array('top' => '0px', 'bottom' => '0px') === ($result['blocks']['core/' . $name]['spacing']['margin'] ?? null), "$label hoists both vertical sides.", json_encode($result));
    $strippedMarkup = $zeroGap ? substr($result['markup']['page'], strpos($result['markup']['page'], '<!-- wp:group ', 1)) : $result['markup']['page'];
    $assert(!str_contains($strippedMarkup, 'margin-top:') && !str_contains($strippedMarkup, 'margin-bottom:'), "$label emits no inline vertical margins.");
    $caseBoxes[$label] = array($before, $result['markup']['page'], $rule, $tag, $name);
}
$blocked = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RepeatedBlockStyles::hoist(array('page' => array('markup' => str_replace('"blockGap":"0px"', '"blockGap":"24px"', $caseBoxes['group under zero parent gap'][0]), 'strippable' => true)), array(), array());
$assert(empty($blocked['blocks']), 'A nonzero parent gap still protects inline zero margins.');
// Real conflicts, conditional resets, raw selector exposure, floats and
// runtime anchors must not be mistaken for the newly safe situations.
foreach (array(
    'conditional reset' => array($caseBoxes['paragraph with delivered shell reset'][0], '@media(min-width:900px){:root :where(.blocks-engine-synthetic-paragraph){margin-top:0;margin-bottom:0}}', array()),
    'different class margin' => array($caseBoxes['center aligned image with same-value reset'][0], '.wp-block-image{margin-top:12px;margin-bottom:12px}', array()),
    'floating image' => array(str_replace('"align":"center"', '"align":"left"', $caseBoxes['center aligned image with same-value reset'][0]), '', array()),
    'raw details exposure' => array($caseBoxes['details with sound block selector'][0] . '<details class="wp-block-details"><summary>Raw</summary></details>', '', array()),
    'runtime anchor' => array($caseBoxes['details with sound block selector'][0], '', array($caseBoxes['details with sound block selector'][0])),
) as $label => [$before, $rule, $anchors]) {
    $result = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RepeatedBlockStyles::hoist(array('page' => array('markup' => $before, 'strippable' => true)), array(), array(array('kind' => 'css', 'content' => $rule)), $anchors);
    $assert(empty($result['blocks']), "$label still blocks hoisting.");
}
$index = array_column($plan['templates'], 'canonical_block_markup', 'slug')['index'];
$assert(str_contains($index, 'margin-top:1em;margin-bottom:1em'), 'The live index no-results paragraph preserves its own UA margins.');

// The global styles WordPress prints: the root layout gap of a theme with a
// global block gap, then each block-type style at the same 0-1-0 weight.
$selectors = array('core/paragraph' => 'p', 'core/heading' => '.wp-block-heading', 'core/list-item' => '.wp-block-list > li', 'core/image' => '.wp-block-image', 'core/group' => '.wp-block-group', 'core/details' => '.wp-block-details');
$globalStyles = ':root :where(.is-layout-flow) > *,:root :where(.is-layout-constrained) > *{margin-block-start:24px;margin-block-end:0}'
    . ':root :where(.is-layout-flow) > :first-child,:root :where(.is-layout-constrained) > :first-child{margin-block-start:0}';
// Repeated looks print as block style variations after their block type's style.
$declarations = static function (array $style, string $prefix = '') use (&$declarations): array {
    $names = array('fontWeight' => 'font-weight', 'letterSpacing' => 'letter-spacing', 'textTransform' => 'text-transform', 'fontStyle' => 'font-style', 'lineHeight' => 'line-height', 'fontSize' => 'font-size', 'fontFamily' => 'font-family');
    $out = array();
    foreach ($style as $key => $value) {
        if (is_array($value)) $out = array_merge($out, $declarations($value, in_array($key, array('typography', 'spacing'), true) ? '' : $prefix . $key . '-'));
        else $out[] = ($names[$key] ?? $prefix . $key) . ':' . preg_replace('/var:preset\|([^|]+)\|([^|]+)/', 'var(--wp--preset--$1--$2)', $value);
    }
    return $out;
};
foreach ($blockStyles as $type => $style) {
    if (!isset($selectors[$type])) continue;
    if (is_array($style['spacing']['margin'] ?? null)) $globalStyles .= ':root :where(' . $selectors[$type] . '){' . implode(';', array_map(static fn(string $side, string $value): string => "margin-$side:$value", array_keys($style['spacing']['margin']), $style['spacing']['margin'])) . '}';
    foreach ($style['variations'] ?? array() as $slug => $look) $globalStyles .= ':root :where(' . $selectors[$type] . '.is-style-' . $slug . '){' . implode(';', $declarations($look)) . '}';
}
$css = '';
$assets = array_column($plan['assets'], null, 'target_path');
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= "\n" . ($assets[$row['target_path']]['content'] ?? '');
// WordPress renders groups with their layout class and post content as a constrained layout.
$rendered = (string) preg_replace('/class="wp-block-group\b/', 'class="wp-block-group is-layout-flow', $markup);
$rendered = '<div class="entry-content wp-block-post-content is-layout-constrained">' . $rendered . '</div>';

$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome'));
if ('' === $chrome) throw new RuntimeException('Browser required for the repeated block style geometry contract.');
$directory = sys_get_temp_dir() . '/box-tree-repeated-styles-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    // Boxes relative to the first heading: the page body's own margin is not under test.
    $probe = '<script>const h=document.querySelector("h2").getBoundingClientRect();document.body.dataset.boxes=JSON.stringify([...document.querySelectorAll("h2,p,li")].map(e=>{const b=e.getBoundingClientRect();return [e.textContent,Math.round(b.y-h.y),b.height,Math.round(b.x-h.x)]}));</script>';
    file_put_contents($directory . '/source.html', $html . $probe);
    file_put_contents($directory . '/compiled.html', '<!doctype html><html><head><meta charset="utf-8"><style>' . $globalStyles . '</style><style>' . $css . '</style></head><body>' . $rendered . $probe . '</body></html>');
    $boxes = array();
    foreach (array('source', 'compiled') as $document) {
        $output = (string) shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome)
            . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --window-size=768,900 --dump-dom --virtual-time-budget=1000 '
            . escapeshellarg('file://' . $directory . '/' . $document . '.html') . ' 2>/dev/null');
        if (1 !== preg_match('/data-boxes="([^"\n]+)"/', $output, $match)) throw new RuntimeException("Missing $document browser boxes: $output");
        $boxes[$document] = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 32, JSON_THROW_ON_ERROR);
    }
    // The same zero defaults are delivered to the editor canvas. Assert
    // both the frontend and canvas cascade for every newly hoisted situation.
    foreach ($caseBoxes as $label => [$before, $after, $rule, $tag, $name]) {
        $measurements = array();
        foreach (array('before', 'frontend', 'canvas') as $mode) {
            $style = 'body{margin:0} :root :where(.is-layout-flow)>*{margin-block-start:24px;margin-block-end:0}';
            if ('before' !== $mode) $style .= ':root :where(' . $selectors['core/' . $name] . '){margin-top:0px;margin-bottom:0px}';
            $style .= $rule;
            $probeCase = '<script>document.body.dataset.boxes=JSON.stringify([...document.querySelectorAll("' . $tag . '")].map(e=>{const b=e.getBoundingClientRect();const s=getComputedStyle(e);return [b.x,b.y,b.width,b.height,s.marginTop,s.marginBottom]}));</script>';
            file_put_contents($directory . '/case.html', '<!doctype html><html><head><style>' . $style . '</style></head><body class="' . ('canvas' === $mode ? 'editor-styles-wrapper' : '') . '"><div class="is-layout-flow">' . ('before' === $mode ? $before : $after) . '</div>' . $probeCase . '</body></html>');
            $output = (string) shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($directory . '/profile') . ' --dump-dom ' . escapeshellarg('file://' . $directory . '/case.html') . ' 2>/dev/null');
            if (1 !== preg_match('/data-boxes="([^"\n]+)"/', $output, $match)) throw new RuntimeException('Missing case geometry: ' . $label);
            $measurements[$mode] = html_entity_decode($match[1], ENT_QUOTES);
        }
        $assert($measurements['before'] === $measurements['frontend'] && $measurements['before'] === $measurements['canvas'], "$label preserves frontend and canvas geometry.", json_encode($measurements));
    }
    $assert(count($blocks) + 1 === count($boxes['source']), 'The source renders every text block.', json_encode($boxes['source']));
    $assert($boxes['source'] === $boxes['compiled'], 'At 768px every heading, paragraph and list item keeps its source box in the compiled frontend.', json_encode($boxes, JSON_PRETTY_PRINT));
} finally {
    shell_exec('rm -rf ' . escapeshellarg($directory));
}
