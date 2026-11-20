<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// A shared `.bar > button` rule reaches native buttons on every page. Each
// page also styles its own buttons, so the buttons match different rule sets
// and keep different control markers. The restatement of the shared rule on
// the button link (guarded by `:not([style*="padding"])` and similar) must
// still be one arm per guard, not one arm per carrier: a site-wide sheet
// would otherwise grow with every button on every page.
$pages = array('index', 'a', 'b', 'c', 'd', 'e');
$css = '.bar{display:flex;gap:8px}.bar>button{padding:10px 18px;border:1px solid #222;background:#f4efe6;color:#222;font:inherit;text-decoration:none}';
$files = array();
foreach ($pages as $index => $page) {
    $css .= '.p-' . $page . ' button{outline-offset:' . ($index + 1) . 'px}';
    $files[$page . '.html'] = '<!doctype html><html><head><title>' . $page . '</title><link rel="stylesheet" href="site.css"></head><body><main class="p-' . $page . '"><h1>' . $page . '</h1><div class="bar"><button type="button">One</button><button type="button">Two</button></div></main></body></html>';
}
$files['site.css'] = $css;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$sheet = '';
foreach ($plan['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? null) && str_ends_with((string) ($asset['target_path'] ?? ''), 'site.css')) {
        $sheet = (string) ($asset['content'] ?? '');
    }
}
$assert('' !== $sheet, 'The shared author stylesheet ships.');
preg_match_all('/blocks-engine-control-[a-f0-9]{12}-[0-9]+/', $sheet, $markers);
$assert(count(array_unique($markers[0])) >= count($pages), 'Each page keeps its own control marker.');

$assert(1 === preg_match('/([^{}]*)\{padding:10px 18px\}/', $sheet, $padding), 'The padding restatement ships.');
$arms = substr_count($padding[1], ':not([style*="padding"])');
$assert(1 === $arms, 'The padding restatement is one arm for every carrier, not ' . $arms . '.');
$assert(1 !== preg_match('/:where\(\.(blocks-engine-control-[a-f0-9]{12}-[0-9]+)\)\.\1/', $sheet), 'No restatement arm repeats its carrier marker for weight.');
// The restatement keeps two class weights on the carrier so it still beats
// generated button styles: the role class every control carrier has.
$assert(str_contains($padding[1], '.blocks-engine-role-control:not(be-t-0.be-c-0.be-c-0)>:where(.wp-block-button__link):not([style*="padding"])'), 'The restatement keeps its weight through the control role class.');

echo "Native button restatement marker lists passed\n";
