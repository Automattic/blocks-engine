<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RouteStateProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SourceShells;

$header = '<header><nav><a href="/">Home</a><a href="/about/">About</a></nav></header>';
$state = htmlspecialchars((string) json_encode(array('rest' => array('aria-current' => null), 'current' => array('aria-current' => 'page'))), ENT_QUOTES);
$annotated = str_replace('<a href="/about/"', '<a ' . RouteStateProjector::RESTING_STATE_ATTRIBUTE . '="' . $state . '" href="/about/"', $header);
$page = static fn(string $shell, int $height): string => '<html><head><style>header{min-height:' . $height . 'px}</style></head><body>' . $shell . '<main><h1>Content</h1></main><footer>Footer</footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => $page($header, 40)),
        array('path' => 'website/about/index.html', 'content' => $page($annotated, 50)),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
if (1 !== count($parts)) throw new RuntimeException('Route-resting transport must not split a shared header.');
if (SourceShells::identity($header) !== SourceShells::identity($annotated)) throw new RuntimeException('Route-resting transport is not source identity.');
if (!str_contains(SourceShells::identity($annotated, false), RouteStateProjector::RESTING_STATE_ATTRIBUTE)) throw new RuntimeException('Emission must preserve the route-resting payload.');
if (SourceShells::identity($header) === SourceShells::identity(str_replace('About', 'Contact', $header))) throw new RuntimeException('Authored differences must remain structural.');
echo "Shared shell route-resting annotation: passed\n";
