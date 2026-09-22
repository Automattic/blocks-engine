<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// The header's layout lives behind an attribute selector, so conversion rewrites
// it into a generated, document-namespaced class. The declaration is inline on
// every page, which makes each copy page-scoped.
$css = '[data-chrome=grid]{display:grid;grid-template-columns:200px 1fr;height:120px}';
$header = static function (string $home, string $about): string {
    return '<header id="site-chrome" class="site-header" data-chrome="grid">'
        . '<nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav>'
        . '</header>';
};
$document = static function (string $header, string $main) use ($css): string {
    return '<!doctype html><html><head><style>' . $css . '</style></head><body>'
        . '<div id="site-root"><div id="masterPage">' . $header
        . '<div id="PAGES_CONTAINER">' . $main . '</div></div></div>'
        . '</body></html>';
};

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document($header('index.html', 'guides/about.html'), '<main id="content"><h1>Home</h1></main>'),
        'guides/about.html' => $document($header('../index.html', 'about.html'), '<main id="content"><h1>About</h1></main>'),
        'guides/team.html' => $document($header('../index.html', 'about.html'), '<main id="content"><h1>Team</h1></main>'),
    ),
))->toArray()['source_reports']['wordpress_site_plan'];

$shared = array_values(array_filter(
    $plan['template_parts'],
    static fn (array $part): bool => in_array($part['placement']['kind'] ?? '', array('shared_shell', 'inline_shared_shell'), true)
));
$assert(array() !== $shared, 'The identical header across pages is extracted as a shared template part.');

$classes = array();
foreach ($shared as $part) {
    preg_match_all('/blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/', (string) $part['canonical_block_markup'], $matches);
    foreach ($matches[0] as $class) {
        $classes[$class] = true;
    }
}
$assert(array() !== $classes, 'Shared chrome markup carries generated, document-namespaced classes.');

$pageScoped = array();
$globalCount = 0;
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) {
        continue;
    }
    $content = (string) ($asset['content'] ?? '');
    $defines = false;
    foreach (array_keys($classes) as $class) {
        if (str_contains($content, '.' . $class)) {
            $defines = true;
            break;
        }
    }
    if (! $defines) {
        continue;
    }
    $isGlobal = false;
    foreach ($asset['scopes'] ?? array() as $scope) {
        if ('global' === ($scope['kind'] ?? null)) $isGlobal = true;
    }
    if ($isGlobal) {
        ++$globalCount;
        continue;
    }
    $pageScoped[] = (string) $asset['target_path'];
}

// Shared chrome renders on every page that binds the part, but authored CSS
// must retain the ownership and cascade boundary of the source document.
$assert(0 === $globalCount, 'Shared chrome CSS must retain its owning page scope.');
$assert(0 < count($pageScoped), 'Shared chrome stylesheet retains page ownership: ' . implode(', ', $pageScoped));

fwrite(STDOUT, "shared-chrome-stylesheet-scope contract passed\n");
