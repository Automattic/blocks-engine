<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/document-scoped-drawer.html');
$failures = array();
// Exercise both class-declared capture documents and named device documents.
foreach (array(false, true) as $named) {
    $html = $source;
    if ($named) {
        foreach (array('desktop' => 'wide', 'mobile' => 'compact') as $device => $name) {
            $html = str_replace('class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope=""',
                'data-blocks-engine-capture-device-document="' . $name . '" data-blocks-engine-capture-document-scope=""', $html);
        }
    }
    $artifact = array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $html,
        'second.html' => str_replace('screen content', 'route content', $html),
    ));
    $compiler = new ArtifactCompiler();
    $shared = $compiler->prepareShared($artifact);
    $receipts = $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared));
    // Workers exchange JSON, not live compiler state.
    $roundTrip = static fn(array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $results = array(
        'whole' => $compiler->compile($artifact),
        'staged' => (new ArtifactCompiler())->compose($roundTrip($shared), $roundTrip($receipts)),
    );
    foreach ($results as $mode => $result) {
        $plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
        $menus = array();
        foreach ($plan['menus'] as $menu) $menus['{{wordpress-site-plan:navigation:' . $menu['token'] . '}}'] = $menu['block_markup'];
        $parts = array();
        foreach ($plan['template_parts'] as $part) $parts[$part['slug'] ?? $part['target_slug'] ?? ''] = $part['canonical_block_markup'];
        $expand = static function (string $markup) use (&$expand, $menus, $parts): string {
            $markup = preg_replace_callback('/<!-- wp:template-part (\{.*?\}) \/-->/s', static function (array $match) use (&$expand, $parts): string {
                $attrs = json_decode($match[1], true);
                return $expand($parts[$attrs['slug']] ?? '');
            }, $markup);
            return preg_replace_callback('/<!-- wp:navigation (\{.*?\}) \/-->/s', static function (array $match) use ($menus): string {
                $attrs = json_decode($match[1], true);
                $inner = $menus[$attrs['ref'] ?? ''] ?? '';
                unset($attrs['ref']);
                return '<!-- wp:navigation ' . json_encode($attrs) . ' -->' . $inner . '<!-- /wp:navigation -->';
            }, $markup);
        };
        foreach ($plan['pages'] as $page) {
            $markup = $expand($page['canonical_block_markup']);
            // Use the existing stable render proxy for Core's comment-only
            // navigation tree; assert actual anchors in each document scope.
            $dom = new DOMDocument();
            @$dom->loadHTML(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup));
            $xpath = new DOMXPath($dom);
            $scope = $named ? '//*[@data-blocks-engine-capture-device-document="compact"]' : '//*[contains(@class,"data-blocks-engine-capture-mobile-document")]';
            foreach (array('/studio' => 'Studio', '/workshop' => 'Workshop') as $href => $label) {
                $links = $xpath->query($scope . '//nav//a[@href="' . $href . '"]');
                if (1 !== $links->length || $label !== trim($links->item(0)->textContent)) {
                    $failures[] = "$mode / named=$named / {$page['source_path']}: missing rendered $label link in the mobile document.";
                }
            }
            if ($xpath->query($scope . '//button')->length || !str_contains($markup, '"overlayMenu":"mobile"')) {
                $failures[] = "$mode: source toggle must be consumed by native navigation overlay.";
            }
        }
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
