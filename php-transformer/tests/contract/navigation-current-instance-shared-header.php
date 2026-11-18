<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// Neutral two-route reduction: no brand link, and the same resting subjects
// are discovered in a different order when aria-current swaps anchors.
$css = 'header{display:flex;justify-content:space-between;padding:12px 24px}nav ul{display:flex;gap:4px;list-style:none;margin:0;padding:0}.item{display:block;padding:8px 16px;border-radius:6px;font-weight:600;text-decoration:none}.idle{color:#334155}.active{background:#073b83;color:#fff}';
$page = static fn(string $title, bool $homeCurrent): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body><header><span>Brand</span><nav><ul>'
    . '<li><a class="item ' . ($homeCurrent ? 'active' : 'idle') . '" href="/"' . ($homeCurrent ? ' aria-current="page"' : '') . '>Home</a></li>'
    . '<li><a class="item ' . ($homeCurrent ? 'idle' : 'active') . '" href="/about"' . ($homeCurrent ? '' : ' aria-current="page"') . '>About</a></li>'
    . '</ul></nav></header><main><h1>' . $title . '</h1><p>Body copy for ' . $title . '.</p></main></body></html>';
$files = array('style.css' => $css, 'index.html' => $page('Home', true), 'about/index.html' => $page('About', false));
// Input enumeration must not determine the shared navigation identity.
foreach (array($files, array_reverse($files, true)) as $orderedFiles) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $orderedFiles))->toArray()['source_reports']['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null)));
    $assert(1 === count($headers), 'One shared header part');
    $templates = ThemeTemplates::rendered($plan);
    foreach ($plan['pages'] as $route) {
        $template = $templates[$route['route_template'] ?? (!empty($route['entrypoint']) ? 'front-page' : 'page')];
        $assert(str_contains($template, '"slug":"header"'), $route['source_path'] . ' renders the shared header');
    }
    preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]+-\d+/', $headers[0]['canonical_block_markup'], $rendered);
    $emitted = implode("\n", array_column($plan['assets'], 'content'));
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $emitted, $rules, PREG_SET_ORDER);
    $backgroundRules = 0;
    $references = array();
    foreach ($rules as $rule) {
        if (!str_contains($rule[1], 'aria-current')) continue;
        preg_match_all('/blocks-engine-navigation-instance-[a-f0-9]+-\d+/', $rule[1], $instances);
        $references = array_merge($references, $instances[0]);
        $assert(array() === array_diff($instances[0], $rendered[0]), 'Every current-item instance exists in the shared header: ' . $rule[1]);
        if (!str_contains($rule[2], 'background:') || !str_contains($rule[2], '#073b83')) continue;
        ++$backgroundRules;
        $assert(!str_contains($rule[1], 'be-r-'), 'Identical current-item background is unguarded, so it also applies to About');
        $assert(str_contains($rule[1], '.wp-block-navigation-item__content[aria-current="page"]'), 'Background targets the runtime current anchor');
    }
    $assert(array() !== $references, 'Current-item rules are emitted');
    $assert(1 === $backgroundRules, 'Identical declarations collapse to one current-item background rule');
}
echo "ok\n";
