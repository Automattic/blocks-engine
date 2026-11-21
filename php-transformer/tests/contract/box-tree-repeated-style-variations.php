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
    $author = '*{margin:0;padding:0}body{margin:8px;font-family:Arial;font-size:16px;line-height:24px;color:rgb(41,77,75)}.eyebrow{font-weight:600;letter-spacing:3px;text-transform:uppercase}details{border-bottom:1px solid rgb(201,187,172);padding-top:23px;padding-bottom:23px}';
    $page = '<!doctype html><html><head><meta charset="utf-8"><title>Questions</title></head><body><main>';
    for ($i = 0; $i < 10; ++$i) $page .= '<p class="eyebrow">Eyebrow ' . $i . '</p>';
    for ($i = 0; $i < 5; ++$i) $page .= '<details><summary>Question ' . $i . '</summary><p>Answer ' . $i . '</p></details>';
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
    $assert(2 === array_sum(array_map('count', $variations)), 'The evidence route emits exactly two variations. ' . json_encode($variations));
    $cases = array(
        'core/paragraph' => array(10, array('font-weight', 'letter-spacing', 'text-transform')),
        'core/details' => array(5, array('border-bottom', 'padding-top', 'padding-bottom')),
    );
    foreach ($cases as $type => [$count, $properties]) {
        $assert(1 === count($variations[$type] ?? array()), "$type has one shared look.");
        $slug = array_key_first($variations[$type]);
        if ('core/paragraph' === $type) $assert(str_starts_with($slug, 'eyebrow'), 'The eyebrow slug uses shared source evidence.');
        preg_match_all('/<!-- wp:' . substr($type, 5) . ' (\{.*?\}) -->\s*<[^>]+>/s', $markup, $matches);
        $references = 0;
        foreach ($matches[1] as $index => $json) {
            $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!str_contains($attrs['className'] ?? '', 'is-style-' . $slug)) continue;
            ++$references;
            $assert(!isset($attrs['style']['typography']) && !isset($attrs['style']['border']) && !isset($attrs['style']['spacing']['padding']), 'The variation replaces the block style values.');
            foreach ($properties as $property) $assert(!str_contains($matches[0][$index], $property . ':'), "No inline $property remains.");
        }
        $assert($count === $references, "$type references the variation on every repeated block.");
    }
    // Safety cases share the same exact-set grouping path as the evidence route.
    $block = '<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}}} --><p style="font-weight:600">Text</p><!-- /wp:paragraph -->';
    foreach (array(
        'different type selector' => array($block . $block, 'p{font-weight:400}', array(), false),
        'conditional rule' => array($block . $block, '@media(min-width:900px){p{font-weight:400}}', array(), false),
        'element rule' => array($block . $block, '.entry-content p{font-weight:400}', array(), true),
        'runtime anchor' => array($block . $block, '', array($block), false),
        'single instance' => array($block, '', array(), false),
        'repeated set' => array($block . $block, '', array(), true),
    ) as $label => [$input, $rule, $anchors, $expected]) {
        $result = Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RepeatedBlockStyles::hoist(array('page' => array('markup' => $input, 'strippable' => true)), array(), array(array('kind' => 'css', 'content' => $rule)), $anchors);
        $assert($expected === !empty($result['blocks']['core/paragraph']['variations']), "$label preserves the cascade proof.");
    }
    // Match core's zero-specificity variation selector; preserve its source
    // ordering after root layout styles, but before delivered route styles.
    $global = ':root :where(.is-layout-flow)>*{margin-block-start:24px;margin-block-end:0}';
    $flatten = static function (array $style, string $prefix = '') use (&$flatten): array {
        $map = array('fontWeight' => 'font-weight', 'letterSpacing' => 'letter-spacing', 'textTransform' => 'text-transform', 'fontStyle' => 'font-style', 'lineHeight' => 'line-height', 'fontSize' => 'font-size', 'text' => 'color', 'background' => 'background-color');
        $out = array();
        foreach ($style as $key => $value) {
            if (is_array($value)) {
                $next = in_array($key, array('typography', 'color', 'spacing'), true) ? '' : $prefix . $key . '-';
                $out = array_merge($out, $flatten($value, $next));
            } else $out[] = ($map[$key] ?? $prefix . $key) . ':' . preg_replace('/var:preset\|([^|]+)\|([^|]+)/', 'var(--wp--preset--$1--$2)', $value);
        }
        return $out;
    };
    foreach ($theme['styles']['blocks'] as $type => $style) {
        $selector = 'core/paragraph' === $type ? 'p' : '.wp-block-' . substr($type, 5);
        $looks = $style['variations'] ?? array();
        unset($style['variations']);
        $global .= ':root :where(' . $selector . '){' . implode(';', $flatten($style)) . '}';
        foreach ($looks as $slug => $look) $global .= ':root :where(' . $selector . '.is-style-' . $slug . '){' . implode(';', $flatten($look)) . '}';
    }
    $palette = ':root{';
    foreach ($theme['settings']['color']['palette'] ?? array() as $token) $palette .= '--wp--preset--color--' . $token['slug'] . ':' . $token['color'] . ';';
    $palette .= '}';
    $css = '';
    $assets = array_column($plan['assets'], null, 'target_path');
    foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= $assets[$row['target_path']]['content'] ?? '';
    $probe = '<script>const first=document.querySelector("p").getBoundingClientRect();document.body.dataset.result=JSON.stringify([...document.querySelectorAll("p,details,summary")].map(e=>{const b=e.getBoundingClientRect(),s=getComputedStyle(e);return [e.textContent,b.x-first.x,b.y-first.y,b.width,b.height,s.color,s.backgroundColor,s.borderBottomColor,s.borderBottomWidth]}));</script>';
    $before = $browse(str_replace('</head>', '<style>' . $author . '</style></head>', $page) . $probe, 768);
    foreach (array('frontend', 'canvas') as $mode) {
        $after = $browse('<!doctype html><html><head><style>' . $palette . $global . '</style><style>' . $css . '</style></head><body class="' . ('canvas' === $mode ? 'editor-styles-wrapper' : '') . '">' . $markup . $probe . '</body></html>', 768);
        $assert($before === $after, "At 768px $mode geometry and colours equal the source.");
    }
} finally {
    shell_exec('rm -rf ' . escapeshellarg($directory));
}
