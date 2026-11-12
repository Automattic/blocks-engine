<?php
declare(strict_types=1);

/**
 * The strict site plan validator rejects a plan whose stylesheet scopes,
 * stylesheet instances, asset writes, write payloads or template part bindings
 * disagree with the code that produces them. Each case breaks one fact of a
 * compiled plan and expects that fact's rejection.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$shell = static fn (string $head, string $main): string => '<!doctype html><html><head><link rel="stylesheet" href="site.css">' . $head . '</head><body><header class="site-header"><nav><a href="index.html">Home</a> <a href="about.html">About</a></nav></header><main>' . $main . '</main><footer class="site-footer"><p>Footer text</p></footer></body></html>';
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $shell('', '<h1>Home</h1><p>Welcome home.</p><img src="photo.png" alt="A photo">'),
    'about.html' => $shell('<link rel="stylesheet" href="about.css">', '<h1 class="about-title">About</h1><p>About us.</p>'),
    'site.css' => 'body{margin:0}.site-header{color:#333}.site-footer{color:#666}',
    'about.css' => '.about-title{color:#c00}',
    'photo.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
));
$plan = ( new ArtifactCompiler() )->compile($artifact)->toArray()['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'The fixture compiles to a site plan.');
WordPressSitePlan::assertValid($plan);

$asset = static fn (array $plan, string $target): int => (int) array_search($target, array_column($plan['assets'], 'target_path'), true);
$about = $asset($plan, 'assets/about.css');
$site = $asset($plan, 'assets/site.css');
$photo = $asset($plan, 'assets/photo.png');
$assert('page' === ($plan['assets'][$about]['scopes'][0]['kind'] ?? null) && array() !== ($plan['assets'][$site]['stylesheet_instances'] ?? array()) && 'media_library' === $plan['assets'][$photo]['destination'], 'The fixture has a page-scoped stylesheet, stylesheet instances and a media library image.');
$write = static fn (array $plan, string $target): int => (int) array_search($target, array_column($plan['writes'], 'target_path'), true);
$partReference = '/<!-- wp:template-part \{"slug":"header"[^}]*\} \/-->\n?/';
$withoutHeader = static function (array $plan) use ($write, $partReference): array {
    $index = (int) array_search('index', array_column($plan['templates'], 'slug'), true);
    $plan['templates'][$index]['canonical_block_markup'] = (string) preg_replace($partReference, '', $plan['templates'][$index]['canonical_block_markup'], 1);
    $plan['templates'][$index]['content_hash'] = WordPressSitePlan::contentHash($plan['templates'][$index]['canonical_block_markup']);
    $target = $write($plan, 'templates/index.html');
    $plan['writes'][$target]['payload']['data'] = $plan['templates'][$index]['canonical_block_markup'];
    $plan['writes'][$target]['payload_hash'] = WordPressSitePlan::contentHash($plan['templates'][$index]['canonical_block_markup']);
    return $plan;
};
$assert(1 === preg_match($partReference, $plan['templates'][(int) array_search('index', array_column($plan['templates'], 'slug'), true)]['canonical_block_markup']), 'The index template places the shared header.');

$cases = array(
    'stylesheet without scopes' => array(static function (array $plan) use ($about): array { $plan['assets'][$about]['scopes'] = array(); return $plan; }, 'WordPress site plan asset scopes must be a nonempty array.'),
    'unknown scope kind' => array(static function (array $plan) use ($about): array { $plan['assets'][$about]['scopes'][0]['kind'] = 'site'; return $plan; }, 'WordPress site plan asset scope is invalid.'),
    'global scope with page fields' => array(static function (array $plan) use ($site): array { $plan['assets'][$site]['scopes'] = array(array('kind' => 'global', 'source_path' => 'index.html')); return $plan; }, 'A global asset scope cannot declare page fields.'),
    'page scope missing a field' => array(static function (array $plan) use ($about): array { unset($plan['assets'][$about]['scopes'][0]['front_page']); return $plan; }, 'A page asset scope is structurally invalid.'),
    'page scope for another route' => array(static function (array $plan) use ($about): array { $plan['assets'][$about]['scopes'][0]['route_path'] = 'elsewhere'; return $plan; }, 'An asset scope does not match its canonical document.'),
    'image with scopes' => array(static function (array $plan) use ($photo): array { $plan['assets'][$photo]['scopes'] = array(array('kind' => 'global')); return $plan; }, 'Only stylesheet assets may declare runtime scopes.'),
    'image with stylesheet instances' => array(static function (array $plan) use ($photo, $site): array { $plan['assets'][$photo]['stylesheet_instances'] = $plan['assets'][$site]['stylesheet_instances']; return $plan; }, 'Only stylesheet resources may declare ordered instances.'),
    'instance for an unknown document' => array(static function (array $plan) use ($site): array { $plan['assets'][$site]['stylesheet_instances'][0]['source_path'] = 'missing.html'; return $plan; }, 'Stylesheet instance must identify a canonical document, order and conditions.'),
    'two instances at one position' => array(static function (array $plan) use ($site): array { $plan['assets'][$site]['stylesheet_instances'][] = $plan['assets'][$site]['stylesheet_instances'][0]; return $plan; }, 'Two stylesheet instances claim one document position.'),
    'theme asset without its write' => array(static function (array $plan) use ($write): array { array_splice($plan['writes'], $write($plan, 'assets/about.css'), 1); return $plan; }, 'WordPress site plan asset lacks a write.'),
    'bound template without its part' => array($withoutHeader, 'WordPress site plan template part binding is invalid.'),
    'part referenced by an unbound template' => array(static function (array $plan): array {
        $header = (int) array_search('header', array_column($plan['template_parts'], 'slug'), true);
        $plan['template_parts'][$header]['placement']['template_slugs'] = array_values(array_diff($plan['template_parts'][$header]['placement']['template_slugs'], array('index')));
        return $plan;
    }, 'WordPress site plan has an unproven template part binding.'),
    'write whose payload no longer matches its hash' => array(static function (array $plan) use ($write): array { $plan['writes'][$write($plan, 'assets/site.css')]['payload']['data'] .= '.x{}'; return $plan; }, 'WordPress site plan write has a stale payload hash or invalid structure.'),
    'base64 write that does not decode' => array(static function (array $plan) use ($write): array {
        $target = $write($plan, 'assets/photo.png');
        $plan['writes'][$target]['payload'] = array('encoding' => 'base64', 'data' => '*not base64*');
        $plan['writes'][$target]['payload_hash'] = WordPressSitePlan::contentHash('*not base64*');
        return $plan;
    }, 'WordPress site plan write has invalid base64 payload.'),
    'reference write without a reference' => array(static function (array $plan) use ($write): array { $plan['writes'][$write($plan, 'assets/photo.png')]['payload'] = array('encoding' => 'reference'); return $plan; }, 'WordPress site plan write has an invalid payload reference.'),
);
foreach ($cases as $name => [$mutate, $expected]) {
    $error = null;
    try {
        WordPressSitePlan::assertValid($mutate($plan));
    } catch (InvalidArgumentException $caught) {
        $error = $caught->getMessage();
    }
    $assert($expected === $error, "A plan with a {$name} is rejected: expected '{$expected}', got " . var_export($error, true) . '.');
}

echo "wordpress-site-plan-rejections contract passed\n";
