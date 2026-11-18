<?php
declare(strict_types=1);

/**
 * functions.php registers a hook only when the site plan contains something
 * it acts on, and the plan reports each emitted hook with the feature it
 * serves (`theme.bootstrap_units`).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$compile = static function (string $body, string $bodyAttributes = ''): array {
    $page = static fn(string $title): string => '<!doctype html><html><head><title>' . $title . '</title></head><body' . $bodyAttributes . '>' . str_replace('{title}', $title, $body) . '</body></html>';
    $files = array('index.html' => $page('Home'), 'about.html' => $page('About'), 'services.html' => $page('Services'));
    if (str_contains($body, 'hero.png')) $files['hero.png'] = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    return array('plan' => $plan, 'functions' => (string) array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data']);
};
$hooks = static fn(array $plan): array => array_column($plan['theme']['bootstrap_units'] ?? array(), 'hook');
$registered = static function (string $functions): array {
    preg_match_all("/^(?:add_filter|add_action|remove_action|remove_filter)\\( '([^']+)'/m", $functions, $matches);
    return $matches[1];
};
$features = static function (array $plan): array {
    $features = array();
    foreach ($plan['theme']['bootstrap_units'] ?? array() as $unit) $features[$unit['hook']][] = $unit['feature'];
    return $features;
};

$unused = array('render_block_core/navigation-submenu', 'body_class', 'template_include', 'get_block_file_template', 'get_block_templates', 'render_block_core/template-part');

// A plain site: links but no submenus, no asset tokens, no body attributes.
$plain = $compile('<header><nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li><li><a href="/services">Services</a></li></ul></nav></header><main><h1>{title}</h1><p>Plain copy.</p></main>');
$assert(is_array($plain['plan']['theme']['bootstrap_units'] ?? null), 'The site plan reports its bootstrap units.');
$units = $plain['plan']['theme']['bootstrap_units'];
foreach ($units as $unit) $assert(is_string($unit['hook'] ?? null) && is_string($unit['feature'] ?? null) && '' !== $unit['feature'], 'Each bootstrap unit names its hook and the plan feature it serves.');
$assert($registered($plain['functions']) === $hooks($plain['plan']), 'The reported units are exactly the hooks functions.php registers.');
foreach ($unused as $hook) {
    $assert(!in_array($hook, $hooks($plain['plan']), true), "A site without the feature {$hook} serves reports no such unit.");
    $assert(!str_contains($plain['functions'], "'{$hook}'"), "functions.php does not register {$hook} for a site that never uses it.");
}
$assert(in_array('render_block_core/navigation-link', $hooks($plain['plan']), true), 'Navigation links still get their current-item filter.');
$assert(16 === count($units), 'The plain site emits 16 bootstrap units: ' . count($units) . ' ' . json_encode($units));

// The same features are emitted once the plan contains what they act on.
$rich = $compile('<header><nav><ul><li><a href="/">Home</a></li><li><a href="/services">Services</a><ul><li><a href="/about">About</a></li></ul></li></ul></nav></header><main><h1>{title}</h1><img src="hero.png" alt="Hero" width="10" height="10"></main>', ' class="site-body" data-theme="dark"');
$richFeatures = $features($rich['plan']);
$assert($registered($rich['functions']) === $hooks($rich['plan']), 'Reported units match functions.php for a site that uses the features.');
$assert(array('navigation_submenus') === ($richFeatures['render_block_core/navigation-submenu'] ?? null), 'Submenus emit the submenu filter, attributed to navigation_submenus.');
$assert(array('document_attributes') === ($richFeatures['body_class'] ?? null), 'Body classes emit body_class, attributed to document_attributes.');
$assert(array('document_attributes') === ($richFeatures['template_include'] ?? null), 'Body attributes emit the document canvas selector.');
$assert(array('template_assets') === ($richFeatures['get_block_file_template'] ?? null) && array('template_assets') === ($richFeatures['get_block_templates'] ?? null), 'Referenced asset tokens emit the template asset resolver.');

fwrite(STDOUT, "Bootstrap unit usage: {$assertions} assertions passed\n");
