<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// The neutral two-route reduced fixture from #6260, including desktop links,
// live mobile links, and the captured mobile panel on the burger trigger.
foreach (array('active', 'is-current', 'aria-current', 'active with node ids', 'node ids only', 'control') as $case) {
    $files = array();
    foreach (array('index.html', 'about/index.html') as $i => $path) {
        $dom = new DOMDocument();
        $dom->loadHTML((string) file_get_contents(dirname(__DIR__) . '/fixtures/header-panel-state/' . $path));
        $adapt = static function (DOMDocument $document, bool $snapshot = false) use ($case, $i): void {
            foreach ($document->getElementsByTagName('*') as $node) {
                if ($snapshot && 1 === $i && str_contains($case, 'node ids')) {
                    $node->setAttribute('data-blocks-engine-node-id', 'route-' . $i . '-' . $node->getNodePath());
                    $node->setAttribute('style', '');
                }
                if (!in_array('active', preg_split('/\s+/', $node->getAttribute('class')) ?: array(), true)) continue;
                if ('active' === $case || 'active with node ids' === $case) continue;
                $node->setAttribute('class', str_replace(' active', 'is-current' === $case ? ' is-current' : '', $node->getAttribute('class')));
                if ('aria-current' === $case) $node->setAttribute('aria-current', 'page');
            }
        };
        $adapt($dom);
        foreach ($dom->getElementsByTagName('*') as $node) {
            $attribute = 'data-blocks-engine-capture-dialog-panel-state';
            if (!$node->hasAttribute($attribute)) continue;
            $state = json_decode($node->getAttribute($attribute), true);
            $panel = new DOMDocument();
            $panel->loadHTML('<html><body><div id="snapshot-root">' . $state['html'] . '</div></body></html>');
            $adapt($panel, true);
            $state['html'] = '';
            foreach ($panel->getElementById('snapshot-root')->childNodes as $child) $state['html'] .= $panel->saveHTML($child);
            $node->setAttribute($attribute, (string) json_encode($state));
        }
        $files[] = array('path' => 'website/' . $path, 'content' => $dom->saveHTML());
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'provenance' => array('source_url' => 'https://example.test/'), 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
    $assert(1 === count($headers) && 'header' === $headers[0]['slug'], $case . ': one shared header part');
    foreach ($plan['pages'] as $page) {
        $assert(!str_contains($page['canonical_block_markup'], 'navbar') && !str_contains($page['canonical_block_markup'], 'wp:navigation'), $case . ': page content does not carry navigation');
    }
    foreach ($plan['templates'] as $template) {
        $assert(str_contains($template['canonical_block_markup'], '"slug":"header"'), $case . ': templates reference the shared header');
    }
    if (!in_array($case, array('control', 'node ids only'), true)) {
        foreach ($plan['menus'] as $menu) {
            preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $menu['block_markup'], $links);
            foreach ($links[1] as $json) {
                $link = json_decode($json, true);
                $source = match ($link['label']) { 'Home' => 'website/index.html', 'About' => 'website/about/index.html', default => null };
                if (null === $source) continue;
                $assert(array($source) === ($link['metadata']['blocksEngineCurrentSources'] ?? null), $case . ': current item remains route-specific for ' . $link['label']);
                $assert(!str_contains($link['className'] ?? '', 'active') && !str_contains($link['className'] ?? '', 'current'), $case . ': no frozen current class on shared links');
            }
        }
        $assert(2 <= count($plan['menus']), $case . ': desktop and mobile menus retain current-route metadata');
    }
    echo $case . ": shared header\n";
}
