<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome'));
if ('' === $chrome) throw new RuntimeException('Chrome required.');
$directory = sys_get_temp_dir() . '/repeated-variations-' . bin2hex(random_bytes(6));
mkdir($directory);
$browse = static function (string $html, int $width) use ($directory, $chrome): array {
    file_put_contents($directory . '/page.html', $html);
    $output = (string) shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($directory . '/profile') . ' --window-size=' . $width . ',900 --dump-dom --virtual-time-budget=1000 ' . escapeshellarg('file://' . $directory . '/page.html') . ' 2>/dev/null');
    if (1 !== preg_match('/data-result="([^"\n]+)"/', $output, $match)) throw new RuntimeException('Missing browser result: ' . $output);
    return json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
};
try {
    // Each previously rejected look sits beside the eyebrow/details evidence:
    // a margin-only paragraph set inside a flow group, a list margin and
    // padding set, heading typography (with and without margins), and
    // bordered cards whose delivered geometry rule restates their border.
    $author = '*{margin:0;padding:0}body{margin:8px;font-family:Arial;font-size:16px;line-height:24px;color:rgb(41,77,75)}.eyebrow{font-weight:600;letter-spacing:3px;text-transform:uppercase}details{border-bottom:1px solid rgb(201,187,172);padding-top:23px;padding-bottom:23px}'
        . '.gap{margin:13px 0}ul.list{margin:12px 0;padding-left:20px}h2.title{font-style:normal;font-weight:400;text-transform:none;margin:30px 0}h3.title{font-style:italic;font-weight:400;margin:10px 0}.card{border:1px solid rgb(201,187,172);padding:20px;border-radius:8px}';
    $page = '<!doctype html><html><head><meta charset="utf-8"><title>Questions</title></head><body><main>';
    for ($i = 0; $i < 10; ++$i) $page .= '<p class="eyebrow">Eyebrow ' . $i . '</p>';
    $page .= '<section>';
    for ($i = 0; $i < 3; ++$i) $page .= '<p class="gap">Spaced ' . $i . '</p>';
    $page .= '</section>';
    for ($i = 0; $i < 3; ++$i) $page .= '<ul class="list"><li>Item ' . $i . '</li><li>Next ' . $i . '</li></ul>';
    for ($i = 0; $i < 2; ++$i) $page .= '<h2 class="title">Title ' . $i . '</h2>';
    for ($i = 0; $i < 2; ++$i) $page .= '<h3 class="title">Subtitle ' . $i . '</h3>';
    for ($i = 0; $i < 5; ++$i) $page .= '<details><summary>Question ' . $i . '</summary><p>Answer ' . $i . '</p></details>';
    for ($i = 0; $i < 3; ++$i) $page .= '<div class="card"><p>Card ' . $i . '</p></div>';
    $page .= '</main></body></html>';
    $dom = new DOMDocument();
    $dom->loadHTML($page, LIBXML_NOERROR);
    $xpath = new DOMXPath($dom);
    $id = 0;
    foreach ($xpath->query('//*') as $element) $element->setAttribute(BoxTree::NODE_ATTRIBUTE, 'n' . ++$id);
    $source = '<!doctype html>' . $dom->saveHTML($dom->documentElement);
    $capture = '<script>const nodes={};for(const e of document.querySelectorAll("[' . BoxTree::NODE_ATTRIBUTE . ']")){const b=e.getBoundingClientRect(),s=getComputedStyle(e);nodes[e.getAttribute("' . BoxTree::NODE_ATTRIBUTE . '")]={border_box:{x:b.x,y:b.y,width:b.width,height:b.height},used_styles:Object.fromEntries(' . json_encode(BoxTree::PROPERTIES) . '.map(p=>[p,s.getPropertyValue(p)]))};}document.body.dataset.result=JSON.stringify(nodes);</script>';
    $viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 402, 'height' => 900));
    $nodes = array();
    foreach ($viewports as $name => $viewport) {
        $observed = $browse(str_replace('</head>', '<style>' . $author . '</style></head>', $source) . $capture, $viewport['width']);
        foreach ($xpath->query('//*') as $element) {
            $node = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
            $nodes[$node]['children'] = array_values(array_map(static fn(DOMElement $child): string => $child->getAttribute(BoxTree::NODE_ATTRIBUTE), array_filter(iterator_to_array($element->childNodes), static fn($child): bool => $child instanceof DOMElement)));
            $nodes[$node]['viewports'][$name] = $observed[$node];
        }
    }
    $tree = array('schema' => BoxTree::SCHEMA, 'viewports' => $viewports, 'nodes' => $nodes, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source));
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR))))->toWordPressSitePlanView()['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $theme = json_decode(array_column($plan['writes'], null, 'target_path')['theme.json']['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    $variations = array();
    foreach ($theme['styles']['blocks'] ?? array() as $type => $styles) foreach ($styles['variations'] ?? array() as $slug => $look) $variations[$type][$slug] = $look;
    // Table-driven looks: block type, the shared source class the slug starts
    // with, the number of blocks, and the inline properties the variation replaces.
    $cases = array(
        'eyebrow paragraph' => array('core/paragraph', 'eyebrow', 10, array('font-weight', 'letter-spacing', 'text-transform')),
        'margin-only paragraph in a flow group' => array('core/paragraph', 'gap', 3, array('margin-top', 'margin-bottom')),
        'list margin and padding' => array('core/list', 'list', 3, array('margin-top', 'margin-bottom', 'padding-left', 'padding-right')),
        'heading typography' => array('core/heading', 'title', 2, array('font-style', 'font-weight', 'text-transform'), 2),
        'heading typography and margins' => array('core/heading', 'title', 2, array('font-style', 'font-weight', 'margin-top', 'margin-bottom'), 3),
        'bordered details' => array('core/details', 'look', 5, array('border-bottom', 'padding-top', 'padding-bottom')),
        'bordered card' => array('core/group', 'card', 3, array('border-width', 'border-radius', 'border-top-style', 'border-bottom-color', 'padding-top', 'padding-left')),
    );
    foreach ($cases as $label => $case) {
        [$type, $prefix, $count, $properties] = $case;
        $level = $case[4] ?? null;
        $references = array();
        $slugs = array_values(array_filter(array_keys($variations[$type] ?? array()), static fn(string $slug): bool => str_starts_with($slug, $prefix . '-')));
        preg_match_all('/<!-- wp:' . substr($type, 5) . ' (\{.*?\}) -->\s*<[^>]+>/s', $markup, $matches);
        foreach ($slugs as $slug) {
            foreach ($matches[1] as $index => $json) {
                $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (!in_array('is-style-' . $slug, explode(' ', $attrs['className'] ?? ''), true) || (null !== $level && $level !== ($attrs['level'] ?? null))) continue;
                $references[$slug][] = $index;
                $assert(!isset($attrs['style']['typography']) && !isset($attrs['style']['border']) && !isset($attrs['style']['spacing']), "$label: the variation replaces the block style values.");
                foreach ($properties as $property) $assert(!str_contains($matches[0][$index], $property . ':'), "$label: no inline $property remains.");
            }
        }
        $assert(1 === count($references) && $count === count(current($references)), "$label: $count blocks reference one shared $type variation. " . json_encode(array_keys($variations[$type] ?? array())));
    }
    // Safety cases share the same exact-set grouping path as the evidence route.
    $block = '<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} --><p style="font-weight:600">Text</p><!-- /wp:paragraph -->';
    $spaced = '<!-- wp:paragraph {"style":{"spacing":{"margin":{"top":"13px","bottom":"13px"}}}} --><p style="margin-top:13px;margin-bottom:13px">Text</p><!-- /wp:paragraph -->';
    $heading = '<!-- wp:heading {"style":{"typography":{"fontWeight":"400","textTransform":"none"}}} --><h2 class="wp-block-heading" style="font-weight:400;text-transform:none">Title</h2><!-- /wp:heading -->';
    $figure = '<!-- wp:image {"style":{"spacing":{"margin":{"top":"0","right":"0","bottom":"0","left":"0"}}}} --><figure class="wp-block-image" style="margin-top:0;margin-right:0;margin-bottom:0;margin-left:0"><img src="a.png" alt=""/></figure><!-- /wp:image -->';
    $card = '<!-- wp:group {"style":{"border":{"width":"1px","bottom":{"style":"solid","color":"rgb(201, 187, 172)"}},"spacing":{"padding":{"top":"20px"}}}} --><div class="wp-block-group" style="border-width:1px;border-bottom-color:rgb(201, 187, 172);border-bottom-style:solid;padding-top:20px"></div><!-- /wp:group -->';
    $flow = array('styles' => array('spacing' => array('blockGap' => false)));
    // Raw selector members keep every case off the site-wide block-type hoist.
    $observed = array('<!-- wp:image --><figure class="wp-block-image"><img src="b.png" alt=""/></figure><!-- /wp:image --><p>Raw</p><h2 class="wp-block-heading">Raw</h2>');
    foreach (array(
        'different type selector' => array($block . $block, 'p{font-weight:400}', array(), false),
        'conditional rule' => array($block . $block, '@media(min-width:900px){p{font-weight:400}}', array(), false),
        'element rule' => array($block . $block, '.entry-content p{font-weight:400}', array(), true),
        'runtime anchor' => array($block . $block, '', array($block), false),
        'single instance' => array($block, '', array(), false),
        'repeated set' => array($block . $block, '', array(), true),
        // Margin-only sets: opt-in exposure, proven per block against layout.
        'margin-only set in a flow parent' => array($spaced . $spaced, '', array(), true, 'core/paragraph', $flow),
        'margin-only set under the root block gap' => array($spaced . $spaced, '', array(), false, 'core/paragraph', array()),
        'margin-only set under a parent gap' => array('<!-- wp:group {"style":{"spacing":{"blockGap":"24px"}}} --><div class="wp-block-group">' . $spaced . $spaced . '</div><!-- /wp:group -->', '', array(), false, 'core/paragraph', $flow),
        'margin-only set with a competing class margin' => array($spaced . $spaced, '.x p{margin:0}', array(), true, 'core/paragraph', $flow),
        'margin-only set with a reaching shorthand' => array($spaced . $spaced, 'main p, p{margin:0 0 13px}', array(), false, 'core/paragraph', $flow),
        'image figure margins beside a raw figure' => array($figure . $figure, '', array(), true, 'core/image', $flow),
        // Headings: top-level element styles print at 0-0-1 and lose.
        'heading set' => array($heading . $heading, '', array(), true, 'core/heading', array('styles' => array('elements' => array('h2' => array('typography' => array('fontWeight' => '700')))))),
        'heading set under a block-scoped element style' => array($heading . $heading, '', array(), false, 'core/heading', array('styles' => array('blocks' => array('core/group' => array('elements' => array('h2' => array('typography' => array('fontWeight' => '700')))))))),
        'heading set beside a block-scoped link style' => array($heading . $heading, '', array(), true, 'core/heading', array('styles' => array('blocks' => array('core/group' => array('elements' => array('h2' => array('color' => array('text' => '#000')))))))),
        // Borders: delivered rules compare per longhand.
        'bordered card with a restating shorthand' => array($card . $card, '.wp-block-group{border-bottom:1px solid rgb(201,187,172);padding:20px 0 0}', array(), true, 'core/group'),
        'bordered card with an unrelated border longhand' => array($card . $card, '.wp-block-group{border-radius:4px;border-top-width:1px}', array(), true, 'core/group'),
        'bordered card with a different colour' => array($card . $card, '.wp-block-group{border-color:red}', array(), false, 'core/group'),
        'bordered card with an unparsed border' => array($card . $card, '.wp-block-group{border-bottom:var(--edge)}', array(), false, 'core/group'),
        // A pseudo-element box inherits the element's computed values.
        'bordered card beside a pseudo-element rule' => array($card . $card, '.wp-block-group::after{border:0;padding:0;margin:0}', array(), true, 'core/group'),
        'bordered card beside the same element rule' => array($card . $card, '.wp-block-group{border:0;padding:0;margin:0}', array(), false, 'core/group'),
    ) as $label => $row) {
        [$input, $rule, $anchors, $expected] = $row;
        $type = $row[4] ?? 'core/paragraph';
        $result = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RepeatedBlockStyles::hoist(array('page' => array('markup' => $input, 'strippable' => true)), $observed, array(array('kind' => 'css', 'content' => $rule)), $anchors, $row[5] ?? array());
        $assert($expected === !empty($result['blocks'][$type]['variations']), "$label preserves the cascade proof.");
    }
    // Match core's zero-specificity variation selector; preserve its source
    // ordering after root layout styles, but before delivered route styles.
    $global = ':root :where(.is-layout-flow)>*{margin-block-start:24px;margin-block-end:0}';
    $flatten = static function (array $style, string $prefix = '') use (&$flatten): array {
        $map = array('fontWeight' => 'font-weight', 'letterSpacing' => 'letter-spacing', 'textTransform' => 'text-transform', 'fontStyle' => 'font-style', 'lineHeight' => 'line-height', 'fontSize' => 'font-size', 'fontFamily' => 'font-family', 'text' => 'color', 'background' => 'background-color');
        $out = array();
        foreach ($style as $key => $value) {
            if (is_array($value)) {
                $next = in_array($key, array('typography', 'color', 'spacing'), true) ? '' : $prefix . $key . '-';
                $out = array_merge($out, $flatten($value, $next));
            } else $out[] = ($map[$key] ?? $prefix . $key) . ':' . preg_replace('/var:preset\|([^|]+)\|([^|]+)/', 'var(--wp--preset--$1--$2)', $value);
        }
        return $out;
    };
    // Top-level element styles print bare (`h2`, 0-0-1), before block nodes.
    $elements = array('heading' => 'h1, h2, h3, h4, h5, h6', 'h1' => 'h1', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h4', 'h5' => 'h5', 'h6' => 'h6');
    foreach ($theme['styles']['elements'] ?? array() as $element => $style) if (isset($elements[$element])) $global .= $elements[$element] . '{' . implode(';', $flatten($style)) . '}';
    $selectors = array('core/paragraph' => 'p', 'core/list-item' => '.wp-block-list > li');
    foreach ($theme['styles']['blocks'] as $type => $style) {
        $selector = $selectors[$type] ?? '.wp-block-' . substr($type, 5);
        $looks = $style['variations'] ?? array();
        unset($style['variations']);
        $global .= ':root :where(' . $selector . '){' . implode(';', $flatten($style)) . '}';
        foreach ($looks as $slug => $look) $global .= ':root :where(' . $selector . '.is-style-' . $slug . '){' . implode(';', $flatten($look)) . '}';
    }
    $palette = ':root{';
    foreach ($theme['settings']['color']['palette'] ?? array() as $token) $palette .= '--wp--preset--color--' . $token['slug'] . ':' . $token['color'] . ';';
    foreach (array('font-size' => 'fontSizes', 'font-family' => 'fontFamilies') as $kind => $key) foreach ($theme['settings']['typography'][$key] ?? array() as $token) $palette .= '--wp--preset--' . $kind . '--' . $token['slug'] . ':' . ($token['size'] ?? $token['fontFamily']) . ';';
    $palette .= '}';
    $css = '';
    $assets = array_column($plan['assets'], null, 'target_path');
    foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= $assets[$row['target_path']]['content'] ?? '';
    $probe = '<script>const first=document.querySelector("p").getBoundingClientRect();document.body.dataset.result=JSON.stringify([...document.querySelectorAll("p,h2,h3,ul,li,details,summary,.card")].map(e=>{const b=e.getBoundingClientRect(),s=getComputedStyle(e);return [e.tagName,e.textContent,b.x-first.x,b.y-first.y,b.width,b.height,s.color,s.backgroundColor,s.borderTopColor,s.borderTopWidth,s.borderBottomColor,s.borderBottomWidth,s.borderRadius,s.fontFamily,s.fontSize,s.fontWeight,s.fontStyle,s.textTransform,s.letterSpacing]}));</script>';
    $before = $browse(str_replace('</head>', '<style>' . $author . '</style></head>', $page) . $probe, 768);
    // WordPress renders default-layout groups with their flow layout class.
    $rendered = (string) preg_replace('/class="wp-block-group\b/', 'class="wp-block-group is-layout-flow', $markup);
    foreach (array('frontend', 'canvas') as $mode) {
        $after = $browse('<!doctype html><html><head><style>' . $palette . $global . '</style><style>' . $css . '</style></head><body class="' . ('canvas' === $mode ? 'editor-styles-wrapper' : '') . '">' . $rendered . $probe . '</body></html>', 768);
        $assert($before === $after, "At 768px $mode geometry and colours equal the source.");
    }
} finally {
    shell_exec('rm -rf ' . escapeshellarg($directory));
}
