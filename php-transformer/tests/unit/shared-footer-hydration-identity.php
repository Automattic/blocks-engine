<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$document = static fn(string $title, string $footer): string => '<!doctype html><html><head><title>' . $title . '</title></head><body><main><h1>' . $title . '</h1></main><footer><p>Contact us</p>' . $footer . '</footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document('Home', '<!--$--><p>Copyright Example</p><!--/$-->'),
        'about.html' => $document('About', '<p>Copyright Example</p>'),
        'privacy.html' => $document('Privacy', '<p class="data-blocks-engine-capture-responsive-counterpart-123456789abc">Copyright Example</p>'),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === $part['area']));
if (1 !== count($footers)) throw new RuntimeException('Hydration and capture projection markers must not split the shared footer.');
// Geometry hooks are not correspondence markers: unlike hydration comments,
// they may select distinct responsive layout rules and must retain identity.
$geometryPlan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document('Home', '<p class="data-blocks-engine-capture-responsive-123456789abc">Copyright Example</p>'),
        'about.html' => $document('About', '<p class="data-blocks-engine-capture-responsive-abcdef123456">Copyright Example</p>'),
        'site.css' => '.data-blocks-engine-capture-responsive-123456789abc{width:200px}.data-blocks-engine-capture-responsive-abcdef123456{width:400px}',
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$geometryFooters = array_values(array_filter($geometryPlan['template_parts'], static fn(array $part): bool => 'footer' === $part['area']));
if (1 === count($geometryFooters)) throw new RuntimeException('Responsive geometry hooks must not collapse distinct footer layouts.');
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], 'Copyright Example')) throw new RuntimeException('Every route must use the shared footer rather than retaining route-local copyright.');
}
fwrite(STDOUT, "Shared footer hydration identity test passed.\n");
