<?php
declare(strict_types=1);

/**
 * A page's <style> elements and <link> stylesheets form one cascade sequence.
 * The generated theme must enqueue page-owned inline styles between the same
 * linked stylesheets on every page, not after all of a page's links.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$document = static fn(string $head, string $body): string => '<!doctype html><html><head>' . $head . '</head><body><main><div class="box">' . $body . '</div></main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document('<link rel="stylesheet" href="base.css"><style>.box{padding:56px}</style><link rel="stylesheet" href="responsive.css">', 'Home'),
        'post.html' => $document('<link rel="stylesheet" href="base.css"><style>.box{padding:56px}.post{color:red}</style><link rel="stylesheet" href="responsive.css">', 'Post'),
        'split.html' => $document('<style>.box{margin:8px}</style><link rel="stylesheet" href="responsive.css"><style>.box{margin:4px}</style>', 'Split'),
        'base.css' => '.base{color:blue}',
        'responsive.css' => '@media (max-width:767px){.box{padding:40px;margin:0}}',
    ),
))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);

$bootstrap = '';
foreach ($plan['writes'] as $write) {
    if ('functions.php' === ($write['target_path'] ?? null)) {
        $bootstrap = (string) ($write['payload']['data'] ?? '');
        break;
    }
}
$contents = array();
foreach ($plan['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null)) $contents[(string) $asset['target_path']] = (string) ($asset['content'] ?? '');
}

// Enqueue targets for one route, in bootstrap order.
$routeEnqueues = static function (string $condition) use ($bootstrap): array {
    $targets = array();
    $inRoute = false;
    foreach (explode("\n", $bootstrap) as $line) {
        if (str_contains($line, 'if ( ' . $condition . ' ) {')) { $inRoute = true; continue; }
        if ($inRoute && '    }' === $line) { $inRoute = false; continue; }
        if (($inRoute || str_contains($line, 'if ( ' . $condition . ' ) wp_enqueue_style(')) && preg_match("/wp_enqueue_style\\( '[^']+', get_theme_file_uri\\( '([^']+)' \\)/", $line, $match)) $targets[] = $match[1];
    }
    return $targets;
};
$position = static function (array $targets, callable $matches): int {
    foreach ($targets as $index => $target) if ($matches($target)) return $index;
    return -1;
};
$containing = static fn(string $needle): callable => static fn(string $target): bool => str_contains($contents[$target] ?? '', $needle);
$named = static fn(string $name): callable => static fn(string $target): bool => str_ends_with($target, '/' . $name);

foreach (array('home' => 'is_front_page()', 'post' => "is_page() && 'post' === trim( get_page_uri( get_queried_object_id() ), '/' )") as $route => $condition) {
    $targets = $routeEnqueues($condition);
    $base = $position($targets, $named('base.css'));
    $inline = $position($targets, $containing('padding:56px'));
    $responsive = $position($targets, $named('responsive.css'));
    $assert($base >= 0 && $inline >= 0 && $responsive >= 0, "{$route}: route enqueues base, inline and responsive stylesheets", implode(' -> ', $targets));
    $assert($base < $inline && $inline < $responsive, "{$route}: inline <style> is enqueued between the links that surround it", implode(' -> ', $targets));
}

$split = $routeEnqueues("is_page() && 'split' === trim( get_page_uri( get_queried_object_id() ), '/' )");
$before = $position($split, $containing('margin:8px'));
$responsive = $position($split, $named('responsive.css'));
$after = $position($split, $containing('margin:4px'));
$assert($before >= 0 && $responsive >= 0 && $after >= 0 && $before !== $after, 'split: styles on either side of a link stay separate stylesheets', implode(' -> ', $split));
$assert($before < $responsive && $responsive < $after, 'split: enqueue order matches <style>, <link>, <style> source order', implode(' -> ', $split));

if ($failures > 0) {
    fwrite(STDERR, "WordPress site-plan inline stylesheet order: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "WordPress site-plan inline stylesheet order passed: {$passes} assertions\n");
