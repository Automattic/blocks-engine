<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Identical chrome with page-sampled stagger delays and entrance evidence on
// just one title. Navigation lowering carries the delays in anchor metadata.
foreach (array('transition-delay', 'animation-delay', 'entrance', 'transition-entrance', 'combined') as $case) {
    $files = array();
    foreach (array('index.html' => 'Home', 'about.html' => 'About', 'contact.html' => 'Contact') as $route => $title) {
        $routeIndex = count($files) + 1;
        $links = '';
        $linkIndex = 0;
        foreach (array('index.html' => 'Home', 'about.html' => 'About', 'contact.html' => 'Contact') as $href => $label) {
            $delay = (++$linkIndex) * $routeIndex / 100;
            $style = 'transition:opacity .3s';
            if ('entrance' !== $case) $style .= ';' . (in_array($case, array('transition-entrance', 'combined'), true) ? 'transition-delay' : $case) . ':' . $delay . 's';
            if ('combined' === $case) $style .= ';animation-delay:' . ($delay * 2) . 's';
            $links .= '<a href="' . $href . '" style="' . $style . '">' . $label . '</a>';
        }
        $evidence = 2 === $routeIndex && in_array($case, array('entrance', 'transition-entrance', 'combined'), true)
            ? ' data-blocks-engine-capture-viewport-entrance="{&quot;attributes&quot;:{}}"' : '';
        $files[$route] = '<!doctype html><html><body><header id="masthead"><h2' . $evidence . '>Studio</h2><nav>' . $links
            . '</nav></header><main><h1>' . $title . '</h1><p>Content for ' . $title . '</p></main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
    $inline = count(array_filter($plan['pages'], static fn (array $page): bool => str_contains($page['canonical_block_markup'], 'masthead')));
    echo $case . ': header parts=' . count($headers) . ', inline headers=' . $inline . "\n";
    $assert(1 === count($headers) && 0 === $inline, $case . ': every route must use one shared header.');
    $assert('shared_shell' === ($headers[0]['placement']['kind'] ?? null), $case . ': header is shared chrome.');
}

// Identity-only normalization must not erase authored presentation or targets.
$identity = static fn (string $style): string => ShellExtraction::identityMarkup('<a href="about.html" style="' . $style . '">About</a>');
$assert($identity('transition-delay:.1s;color:red') === $identity('transition-delay:.2s;color:red'), 'Stagger timing is incidental.');
$assert($identity('color:red') !== $identity('color:blue'), 'Authored color remains structural.');
$assert($identity('transition:opacity .3s') !== $identity('transition:transform .3s'), 'Transition behavior remains structural.');

$navigation = static fn (string $style): string => '<!-- wp:navigation {"metadata":{"blocksEngineNavigationItems":[{"blocksEngineNavigationAnchor":{"style":"' . $style . '"}}]}} /-->';
$assert(ShellExtraction::identityMarkup($navigation('transition:opacity .3s;transition-delay:.1s;animation-delay:.2s')) === ShellExtraction::identityMarkup($navigation('transition:opacity .3s;transition-delay:.3s;animation-delay:.4s')), 'Nested navigation anchor metadata ignores both delays.');
$assert(ShellExtraction::identityMarkup($navigation('color:red')) !== ShellExtraction::identityMarkup($navigation('color:blue')), 'Nested navigation anchor paint is preserved.');

echo "Shared shell motion timing contract passed.\n";
