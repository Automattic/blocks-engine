<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// The header's layout lives behind an attribute selector, so conversion rewrites
// it into a generated, document-namespaced class. The declaration is inline on
// every page, which makes each copy page-scoped.
$css = '[data-chrome=grid]{display:grid;grid-template-columns:200px 1fr;height:120px}.route-grid{display:grid;grid-template-columns:1fr 1fr}@media (max-width:700px){.route-grid{grid-template-columns:1fr}}';
$header = static function (string $home, string $about): string {
    return '<header id="site-chrome" class="site-header" data-chrome="grid">'
        . '<nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav>'
        . '</header>';
};
$document = static function (string $header, string $main) use ($css): string {
    return '<!doctype html><html><head><style>' . $css . '</style><link rel="stylesheet" href="route.css"></head><body>'
        . '<div id="site-root"><div id="masterPage">' . $header
        . '<div id="PAGES_CONTAINER"><div class="route-grid">' . $main . '</div></div></div></div>'
        . '</body></html>';
};

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document($header('index.html', 'guides/about.html'), '<main id="content"><h1>Home</h1></main>'),
        'guides/about.html' => $document($header('../index.html', 'about.html'), '<main id="content"><h1>About</h1></main>'),
        'guides/team.html' => $document($header('../index.html', 'about.html'), '<main id="content"><h1>Team</h1></main>'),
        'route.css' => array('path' => 'route.css', 'kind' => 'css', 'content' => '.route-only{color:#123456}'),
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
$globalCss = '';
$routeScoped = false;
$routeMediaScoped = false;
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) {
        continue;
    }
    $content = (string) ($asset['content'] ?? '');
    if (str_contains($content, '.route-only')) {
        foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== ($scope['kind'] ?? null)) $routeScoped = true;
    }
    if (str_contains($content, '.route-grid') && str_contains($content, '@media (max-width:700px)')) {
        foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== ($scope['kind'] ?? null)) $routeMediaScoped = true;
    }
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
        $globalCss .= $content;
        continue;
    }
    $pageScoped[] = (string) $asset['target_path'];
}

// Shared chrome receives a global projection, while route-owned source rules
// remain page-scoped and therefore cannot leak into unrelated routes.
$assert(0 < $globalCount, 'Shared chrome receives a global projected stylesheet.');
$assert(!str_contains($globalCss, '.route-grid'), 'The global shared projection contains no route-owned layout rule.');
$assert($routeScoped, 'Route-only CSS retains non-global applicability.');

$generatedClass = array_key_first($classes);
$classToken = is_string($generatedClass) ? ltrim($generatedClass, '.') : '';
$classPattern = '/' . CssIdent::classSelectorRegex($classToken) . '(?![\w-])/';
$assert(1 === preg_match($classPattern, '.' . $classToken . '0,.' . $classToken . ':is(.x)'), 'Generated class matching respects CSS identifier boundaries and selector lists.');
$assert(0 === preg_match($classPattern, '.' . $classToken . '0'), 'A generated class does not match its prefix sibling.');
$assert($routeMediaScoped, 'Route media rules retain their non-global cascade boundary.');

$malformed = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><head><style>[data-chrome=grid]{display:flex}:is(.valid,.broken[foo="bar"){color:red}</style></head><body><header data-chrome="grid"><nav>Brand</nav></header><main>Home</main></body></html>',
        'about.html' => '<html><head><style>[data-chrome=grid]{display:flex}:is(.valid,.broken[foo="bar"){color:red}</style></head><body><header data-chrome="grid"><nav>Brand</nav></header><main>About</main></body></html>',
    ),
))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$malformedGlobal = implode('\n', array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($malformed['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && 'global' === ($asset['scopes'][0]['kind'] ?? null))));
$malformedRoute = implode('\n', array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($malformed['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && 'global' !== ($asset['scopes'][0]['kind'] ?? null))));
$assert(!str_contains($malformedGlobal, '.broken') && str_contains($malformedRoute, '.broken'), 'Unparseable selector lists remain on the route asset instead of being dropped or promoted.');

// A shared chrome rule from a linked stylesheet in a subdirectory keeps its
// relative url() resolvable after projection into the generated global
// stylesheet, whose synthetic identity is not the rule's source location.
$linkedDocument = static fn(string $header, string $main): string => '<!doctype html><html><head><link rel="stylesheet" href="/css/chrome.css"></head><body>'
    . '<div id="site-root"><div id="masterPage">' . $header
    . '<div id="PAGES_CONTAINER"><div class="route-grid">' . $main . '</div></div></div></div></body></html>';
$linked = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $linkedDocument($header('index.html', 'guides/about.html'), '<main id="content"><h1>Home</h1></main>'),
        'guides/about.html' => $linkedDocument($header('../index.html', 'about.html'), '<main id="content"><h1>About</h1></main>'),
        'guides/team.html' => $linkedDocument($header('../index.html', 'about.html'), '<main id="content"><h1>Team</h1></main>'),
        'css/chrome.css' => array('path' => 'css/chrome.css', 'kind' => 'css', 'content' => '[data-chrome=grid]{display:grid;grid-template-columns:200px 1fr;height:120px;background-image:url(../img/texture.png)}'),
        'img/texture.png' => array('path' => 'img/texture.png', 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => base64_encode("\x89PNG\r\n\x1a\n")),
    ),
))->toArray();
$linkedPlan = $linked['source_reports']['wordpress_site_plan'] ?? array();
$assert('failed' !== ($linked['status'] ?? null), 'A linked shared chrome stylesheet with a relative url() compiles: ' . json_encode(array_values(array_filter($linked['diagnostics'] ?? array(), static fn(array $d): bool => 'unresolved_local_browser_reference' === ($d['reason'] ?? null)))));
$sharedWrites = array_values(array_filter($linkedPlan['writes'] ?? array(), static fn(array $write): bool => str_contains((string) ($write['target_path'] ?? ''), 'shared-chrome-')));
$assert(array() !== $sharedWrites, 'The linked shared chrome rule is projected into a generated shared stylesheet.');
foreach ($sharedWrites as $write) {
    $payload = (string) ($write['payload']['data'] ?? '');
    $assert(1 === preg_match('/background-image:url\(\{\{wordpress-site-plan:asset:asset-[a-f0-9]{16}\}\}\)/', $payload) && !str_contains($payload, 'texture.png'), 'The projected shared stylesheet tokenizes its url() reference instead of shipping a raw local path.');
}
foreach ($linkedPlan['assets'] ?? array() as $asset) {
    $assert(!array_key_exists('reference_origin', $asset), 'Reference origins are write-time transport, not part of the plan asset contract.');
}

// A generated class named only inside :not() excludes that element; the rule
// still styles the page's own content and must keep its source cascade order.
$exclusion = new ReflectionMethod(WordPressSitePlan::class, 'selectorTargetsGeneratedClass');
$assert(false === $exclusion->invoke(null, '.text-sm:not(:where(.blocks-engine-control-abc-6))', array('blocks-engine-control-abc-6' => true)), 'A selector that only excludes a shared-chrome class stays page-owned.');
$assert(true === $exclusion->invoke(null, '.blocks-engine-control-abc-6 .text-sm:not(.x)', array('blocks-engine-control-abc-6' => true)), 'A selector that targets a shared-chrome class is projected.');
$assert(true === $exclusion->invoke(null, ':not(.a) .blocks-engine-control-abc-6', array('blocks-engine-control-abc-6' => true)), 'A class outside :not() still counts after an earlier :not().');

fwrite(STDOUT, "shared-chrome-stylesheet-scope contract passed\n");
