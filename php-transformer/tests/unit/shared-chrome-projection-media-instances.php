<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$build = static function (bool $mobile): array {
    // A later chrome stylesheet declares type="text/css", the same condition as none.
    $links = '<link rel="stylesheet" href="/assets/css/nav.css">'
        . ($mobile ? '<link rel="stylesheet" href="/assets/css/nav.css" media="(max-width:1000px)">' : '')
        . '<link type="text/css" rel="stylesheet" href="/assets/css/links.css">';
    $document = static fn(string $title): string => '<!doctype html><html><head>' . $links . '</head><body>'
        . '<header class="site-header"><nav class="pill" aria-label="Site"><a href="/">Home</a><a href="/about/">About</a><a href="/#contact">Contact</a></nav></header>'
        . '<main><h1>' . $title . '</h1><section id="contact"><p>' . $title . ' content</p></section></main></body></html>';
    return (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => $document('Home'),
            'about/index.html' => $document('About'),
            'assets/css/nav.css' => array('path' => 'assets/css/nav.css', 'kind' => 'css', 'content' => '.pill{display:flex;gap:24px;background:#ffdac4;border-radius:20px;padding:8px 24px;width:max-content} .pill a{color:#222;text-decoration:none}'),
            'assets/css/links.css' => array('path' => 'assets/css/links.css', 'kind' => 'css', 'content' => '.pill a:hover{color:#900}'),
        ),
    ))->toWordPressSitePlanView()['wordpress_site_plan'];
};

$isCss = static fn(string $type): bool => in_array(strtolower($type), array('', 'text/css'), true);
foreach (array(true, false) as $mobile) {
    $plan = $build($mobile);
    WordPressSitePlan::assertValid($plan);
    $shared = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_contains((string) $asset['target_path'], '/shared-chrome-') && str_starts_with((string) ($asset['content'] ?? ''), '.pill{')));
    $assert(1 === count($shared), 'The shared header pill rules live in one shared-chrome asset.');
    $asset = $shared[0];
    foreach (array('index.html', 'about/index.html') as $document) {
        $instances = array_values(array_filter($asset['stylesheet_instances'] ?? array(), static fn(array $instance): bool => $document === $instance['source_path']));
        $media = array_map(static fn(array $instance): string => in_array($instance['media'], array('', 'all'), true) ? 'all' : $instance['media'], $instances);
        $assert(in_array('all', $media, true), 'Shared-chrome .pill rules retain an unconditional instance on ' . $document . '.');
        // Each slot follows its condition's last contributing link (links.css is last for all).
        $expected = $mobile ? array('(max-width:1000px)', 'all') : array('all');
        $assert($expected === $media, 'Each distinct source media has exactly one shared projection instance on ' . $document . '.');
        $enqueues = array_values(array_filter(WordPressSitePlan::documentStylesheets($plan, $document), static fn(array $row): bool => $asset['target_path'] === $row['target_path']));
        $assert(count($expected) === count($enqueues) && count($expected) === count(array_unique(array_column($enqueues, 'handle'))), 'Projection instances publish separate enqueue handles on ' . $document . '.');
        $assert(array_column($instances, 'media') === array_column($enqueues, 'media'), 'Enqueues preserve each projection instance media.');
        foreach ($instances as $instance) {
            $origins = array();
            foreach ($plan['assets'] as $source) {
                if (str_contains((string) $source['target_path'], '/shared-chrome-')) continue;
                foreach ($source['stylesheet_instances'] ?? array() as $origin) {
                    if ($document === $origin['source_path'] && $instance['media'] === $origin['media'] && $isCss($instance['type']) === $isCss($origin['type'])) $origins[] = $origin['order'];
                }
            }
            $assert(array() !== $origins && max($origins) + 1 === $instance['order'], 'Each projection follows its last contributing media/type instance.');
        }
    }
    $positions = array();
    foreach ($plan['assets'] as $source) foreach ($source['stylesheet_instances'] ?? array() as $instance) $positions[] = $instance['source_path'] . "\0" . $instance['order'];
    $assert(count($positions) === count(array_unique($positions)), 'Document stylesheet orders remain unique after projection placement.');
}

fwrite(STDOUT, "Shared-chrome projection media instances test passed.\n");
