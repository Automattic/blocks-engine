<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = require dirname(__DIR__) . '/fixtures/declaration-addressed-chrome.php';
$headers = array();
$baselines = array();
$baselineCssBytes = 0;
foreach (array('index', 'about', 'contact') as $slug) {
    $result = (new HtmlTransformer())->transform($fixture['files'][$slug . '.html'], array('static_css' => $fixture['files']['site.css'] . $fixture['files'][$slug . '.css']))->toArray();
    preg_match('/<header\b.*?<\/header>/s', $result['serialized_blocks'], $header);
    $headers[] = $header[0];
    $baselineCss = '';
    foreach ($result['assets'] as $asset) if ('css' === ($asset['kind'] ?? '')) $baselineCss .= $asset['content'];
    $baselineCssBytes += strlen($baselineCss);
    $baselines[$slug] = '<style>' . $baselineCss . '</style>' . $header[0];
    preg_match('/blocks-engine-control-[a-f0-9]{12}-[0-9]+/', $header[0], $marker);
    $assert(isset($marker[0]), 'Fixture exercises a generated control class, not only retained author classes.');
}
$assert(1 === count(array_unique($headers)), 'Independent public HTML compilations produce byte-identical header markup under different route stylesheets.');
$changed = (new HtmlTransformer())->transform($fixture['files']['index.html'], array('static_css' => $fixture['files']['site.css'] . '.cta{text-align:right}'))->toArray();
preg_match('/blocks-engine-control-[a-f0-9]{12}-[0-9]+/', $changed['serialized_blocks'], $changedMarker);
$assert($marker[0] !== $changedMarker[0], 'A genuinely different generated declaration set gets a different identity.');
$renamed = (new HtmlTransformer())->transform(str_replace('Contact us', 'Get in touch', $fixture['files']['index.html']), array('static_css' => $fixture['files']['site.css']))->toArray();
preg_match('/blocks-engine-control-[a-f0-9]{12}-[0-9]+/', $renamed['serialized_blocks'], $renamedMarker);
$assert($marker[0] === $renamedMarker[0], 'Control copy is not an input to generated declaration identity.');
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$parts = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? '')));
$assert(1 === count($parts), 'All routes use one header template part.');
$markup = $parts[0]['canonical_block_markup'];
preg_match('/blocks-engine-control-[a-f0-9]{12}-[0-9]+/', $markup, $match);
$marker = $match[0];
$owners = array_values(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && str_contains($asset['content'], $marker)));
$assert(1 === count($owners) && str_contains($owners[0]['target_path'], '/shared-chrome-'), 'Header rules compile once into shared chrome, never into a per-route stylesheet.');
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    if (preg_match('~/(index|about|contact)\.css$~', $asset['target_path'])) {
        $assert(!str_contains($asset['content'], 'cta') && !str_contains($asset['content'], $marker), 'No route stylesheet carries authored or generated header rules.');
    }
}
$assert('both' === ($owners[0]['stylesheet_target'] ?? 'both'), 'The same shared rules serve frontend and editor.');
foreach ($plan['templates'] as $template) {
    $assert(str_contains($template['canonical_block_markup'], '"slug":"' . $parts[0]['slug'] . '"'), 'Every template references the single header part.');
}

// Re-ingested generated markup can retain provisional control bindings. The
// navigation editor-state repair is identical until declaration finalization;
// route hover rules then give that binding different declaration addresses.
foreach (array(
    'route-specific editor state' => array('index' => '.7', 'about' => '.9', 'copy' => '.7'),
) as $case => $routes) {
    $site = array('entrypoint' => 'index.html', 'files' => array());
    $states = array();
    foreach ($routes as $slug => $opacity) {
        $site['files'][$slug . '.html'] = '<!doctype html><html><head><link rel="stylesheet" href="' . $slug . '.css"></head><body><header><p class="blocks-engine-control-aaaaaaaaaaaa-0">Menu</p></header><main><h1>Page</h1></main></body></html>';
        $css = '.blocks-engine-control-aaaaaaaaaaaa-0{opacity:0}.blocks-engine-control-aaaaaaaaaaaa-0:hover{opacity:' . $opacity . '}';
        $site['files'][$slug . '.css'] = $css;
    }
    $sitePlan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile($site)->toArray());
    WordPressSitePlan::assertValid($sitePlan);
    foreach ($routes as $slug => $opacity) {
        $page = (new HtmlTransformer())->transform($site['files'][$slug . '.html'], array('static_css' => $site['files'][$slug . '.css']))->toArray();
        $state = current(array_filter($page['assets'], static fn (array $asset): bool => 'editor-static-state' === ($asset['source'] ?? '')));
        $assert(is_array($state), $case . ': fixture materializes navigation editor-state CSS.');
        $assert(str_contains($state['path'], substr(hash('sha256', $state['content']), 0, 16)), $case . ': generated path addresses final bytes.');
        $assert($state['hash'] === hash('sha256', $state['content']) && $state['source_hash'] === $state['hash'] && $state['bytes'] === strlen($state['content']), $case . ': integrity metadata addresses final bytes.');
        $states[$slug] = $state;
    }
    $normalize = static fn (string $css): string => preg_replace('/blocks-engine-control-[a-f0-9]{12}-[0-9]+/', 'CONTROL', $css);
    $assert($normalize($states['index']['content']) === $normalize($states['about']['content']) && $states['index']['content'] !== $states['about']['content'], $case . ': editor-state CSS differs only in finalized declaration addresses.');
    $assert($states['index']['path'] !== $states['about']['path'] && $states['index']['path'] === $states['copy']['path'], $case . ': different bytes diverge and identical bytes share a path.');
    $stateAssets = array_values(array_filter($sitePlan['assets'], static fn (array $asset): bool => 'editor-static-state' === ($asset['source'] ?? '')));
    $assert(2 === count($stateAssets), $case . ': identical final editor-state assets coalesce across routes.');
}

// Use the public site's materialized theme presets and CSS, not compiler state.
$writes = array_column($plan['writes'], null, 'target_path');
$theme = json_decode($writes['theme.json']['payload']['data'], true);
$presets = ':root{';
$paletteCss = '';
foreach ($theme['settings']['color']['palette'] as $color) {
    $presets .= '--wp--preset--color--' . $color['slug'] . ':' . $color['color'] . ';';
    $paletteCss .= '.has-' . $color['slug'] . '-color{color:' . $color['color'] . '}.has-' . $color['slug'] . '-background-color{background-color:' . $color['color'] . '}';
}
foreach ($theme['settings']['spacing']['spacingSizes'] as $space) $presets .= '--wp--preset--spacing--' . $space['slug'] . ':' . $space['size'] . ';';
$siteCss = '';
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? '')) $siteCss .= $asset['content'];
$assert(strlen($siteCss) < $baselineCssBytes, 'Shared site CSS is smaller than the independent per-page baseline.');
$siteCss = $presets . '}' . $paletteCss . $siteCss;
$assetsByPath = array_column($plan['assets'], null, 'target_path');
$routeCss = array();
foreach (array('index', 'about', 'contact') as $slug) {
    $sequence = WordPressSitePlan::documentStylesheets($plan, $slug . '.html');
    $assert(1 === count(array_filter($sequence, static fn (array $sheet): bool => $sheet['target_path'] === $owners[0]['target_path'])), 'Every route activates the shared control stylesheet exactly once.');
    $routeCss[$slug] = $presets . '}' . $paletteCss;
    foreach ($sequence as $sheet) $routeCss[$slug] .= $assetsByPath[$sheet['target_path']]['content'];
}
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $temp = tempnam(sys_get_temp_dir(), 'declaration-chrome-');
    $file = $temp . '.html';
    unlink($temp);
    $profile = sys_get_temp_dir() . '/declaration-chrome-profile-' . getmypid();
    try {
        foreach (array(390, 699, 700, 701, 768, 1440) as $width) {
            foreach (array('index', 'about', 'contact') as $slug) {
                preg_match('/<header\b.*?<\/header>/s', $fixture['files'][$slug . '.html'], $sourceHeader);
                // Isolated documents model the same shared part in frontend and
                // editor canvases, avoiding cross-document selector leakage.
                $documents = array(
                    '<style>' . $fixture['files']['site.css'] . $fixture['files'][$slug . '.css'] . '</style>' . $sourceHeader[0],
                    $baselines[$slug],
                    '<style>' . $routeCss[$slug] . '</style>' . $markup,
                    '<style>' . $siteCss . '</style><div class="editor-styles-wrapper">' . $markup . '</div>',
                );
                $html = '<!doctype html><body>';
                foreach ($documents as $doc) $html .= '<iframe style="border:0;width:' . $width . 'px;height:500px" srcdoc="' . htmlspecialchars('<!doctype html>' . $doc, ENT_QUOTES) . '"></iframe>';
                $html .= '<script>onload=()=>{document.body.dataset.result=JSON.stringify([...document.querySelectorAll("iframe")].map(f=>{const a=[...f.contentDocument.querySelectorAll("header a")].find(a=>a.textContent==="Contact us");const s=f.contentWindow.getComputedStyle(a);return [s.color,s.backgroundColor,s.paddingTop,s.paddingRight,s.paddingBottom,s.paddingLeft,s.borderRadius,s.opacity,s.borderTopWidth,s.borderRightWidth,s.borderBottomWidth,s.borderLeftWidth,s.fontFamily,s.fontSize,s.fontWeight,s.lineHeight,s.textTransform,s.textAlign,s.boxShadow]}))}</script>';
                file_put_contents($file, $html);
                $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($profile) . ' --dump-dom --virtual-time-budget=1000 --window-size=' . $width . ',900 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
                preg_match('/data-result="([^"]+)"/', (string) $output, $data);
                $styles = json_decode(html_entity_decode($data[1] ?? '', ENT_QUOTES), true);
                $assert(4 === count($styles ?? array()), 'Browser measured source, per-page baseline, frontend and editor at ' . $width);
                $assert(($width <= 700 ? '0.9' : '1') === $styles[0][7], 'Fingerprint observes both sides of the source breakpoint.');
                $assert($styles[0] === $styles[1] && $styles[1] === $styles[2] && $styles[2] === $styles[3], 'Computed chrome styles unchanged for ' . $slug . ' at ' . $width . ': ' . json_encode($styles));
            }
        }
    } finally {
        @unlink($file);
        // Chrome profiles are isolated temporary browser state.
        shell_exec('rm -rf ' . escapeshellarg($profile));
    }
}
echo "declaration-addressed-chrome contract passed\n";
