<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$document = static fn(string $title): string => '<!DOCTYPE html><html><head><title>' . $title . '</title><style>
header{display:flex;justify-content:space-between}.actions{display:flex;gap:16px}
@media(min-width:768px){.md\\:hidden{display:none}}
</style></head><body><header><a href="index.html">Brand</a><nav><a href="index.html">Home</a><a href="about.html">About</a></nav><div class="actions"><a href="cart.html">Cart</a><button class="md:hidden" aria-label="Menu">Menu</button></div></header><main><h1>' . $title . '</h1></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $document('Home'), 'about.html' => $document('About'),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$shared = array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with($asset['source_path'], '.shared-chrome'));
$css = implode("\n", array_column($shared, 'content'));
// Both the link's display and its synthetic wrappers must leave layout on
// every route adopting the header, not only the chosen control's source route.
if (!preg_match_all('/[^{}]*\.wp-block-buttons:has\([^{}]*\)\{display:none!important\}/', $css, $rules)) {
    throw new RuntimeException('Missing responsive control wrapper visibility rule');
}
foreach ($rules[0] as $rule) {
    if (str_contains($rule, ':root:is(') && !preg_match('/:root:is\(\.be-r-[a-f0-9]+,\.be-r-[a-f0-9]+\)/', $rule)) {
        throw new RuntimeException('Shared control wrapper visibility must cover both adopting routes: ' . $rule);
    }
}
echo "Shared chrome control visibility passed\n";
