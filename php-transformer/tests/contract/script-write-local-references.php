<?php
/**
 * A captured script is published verbatim. Links and paths it writes into
 * markup at runtime (`'<a href="' + slug + '.html">'`) cannot be rewritten for
 * WordPress, so each is a warning naming the file and value, and the site still
 * imports. The same unresolved reference in page markup still fails validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$failures = 0; $passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) { ++$passed; return; }
    ++$failures; fwrite(STDERR, "FAIL: {$message}\n");
};

$script = <<<'JS'
const results = document.getElementById('results');
const pages = [{ page: 'setup.html', title: 'Setup' }];
results.innerHTML = pages.map(r => `<a class="hit" href="${r.page}">${r.title}</a>`).join('');
document.querySelector('.cart').innerHTML = '<a href="cart.html">View cart</a><img src="img/badge.png" alt="">';
JS;
$files = array(
    'index.html' => '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><div id="results"></div><div class="cart"></div><img src="img/badge.png" alt="Badge"></main><script src="js/app.js"></script></body></html>',
    'setup.html' => '<!doctype html><html><head><title>Setup</title></head><body><main><h1>Setup</h1></main></body></html>',
    'cart.html' => '<!doctype html><html><head><title>Cart</title></head><body><main><h1>Cart</h1></main></body></html>',
    'img/badge.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
    'js/app.js' => $script,
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'A site whose script writes local links compiles to a WordPress site plan.');
$plan = is_array($plan) ? $plan : array('diagnostics' => array(), 'writes' => array());

$resolved = null;
try { $resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/shop', 'require_proven_dynamic_client_assets' => true)); } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); }
$assert(is_array($resolved), 'The plan resolves for materialization instead of failing on links that exist only inside the script.');

$findings = array();
foreach ($plan['diagnostics'] as $diagnostic) if ('wordpress_site_plan_unresolved_script_reference' === ($diagnostic['code'] ?? null)) $findings[(string) ($diagnostic['value'] ?? '')] = $diagnostic;
ksort($findings);
$assert(array('${r.page}', 'cart.html') === array_keys($findings), 'Each runtime-only link the script writes is reported once by value.');
$assert(array('warning') === array_values(array_unique(array_column($findings, 'severity'))) && array('js/app.js') === array_values(array_unique(array_column($findings, 'source_path'))), 'Each finding is a warning naming the script that writes it.');

$scriptWrite = null;
foreach ($plan['writes'] as $write) if (str_ends_with((string) ($write['target_path'] ?? ''), 'js/app.js')) $scriptWrite = (string) ($write['payload']['data'] ?? '');
$assert(is_string($scriptWrite) && str_contains($scriptWrite, 'href="cart.html"') && str_contains($scriptWrite, '${r.page}') && !str_contains($scriptWrite, 'src="img/badge.png"'), 'The script is published verbatim apart from the declared asset it names.');

$markup = $plan;
$markup['pages'][0]['canonical_block_markup'] .= '<img src="img/missing.png">';
$markup['pages'][0]['content_hash'] = WordPressSitePlan::contentHash($markup['pages'][0]['canonical_block_markup']);
$rejected = false;
try { WordPressSitePlan::assertValid($markup); } catch (InvalidArgumentException $error) { $rejected = str_contains($error->getMessage(), 'unresolved local browser reference img/missing.png'); }
$assert($rejected, 'An unresolved reference in page markup still fails validation.');

if (0 !== $failures) { fwrite(STDERR, "Script write local references: {$failures} failed\n"); exit(1); }
echo "Script write local references: {$passed} passed\n";
