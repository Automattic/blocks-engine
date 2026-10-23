<?php
/**
 * Hand-written scripts wrap DOM lookups in one-line helpers such as
 * `function $(sel, root) { return (root || document).querySelector(sel); }`.
 * A literal selector passed to a helper the script defines is the same runtime
 * target as the lookup it forwards to, so the element the script needs keeps
 * the attribute it is found by. An undefined `$` (jQuery) proves nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeScriptEvidenceAnalyzer;

$failures = 0; $passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) { ++$passed; return; }
    ++$failures; fwrite(STDERR, "FAIL: {$message}\n");
};

$helpers = <<<'JS'
(function () {
  function $(sel, root) { return (root || document).querySelector(sel); }
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  var qsa = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  const byId = id => document.getElementById(id);
  var grid = $('[data-shop-grid]');
  $$('.book-card', grid).forEach(function (card) { card.hidden = false; });
  qsa("[data-filter]").forEach(function (f) { f.addEventListener('change', render); });
  byId('cart-total').textContent = '$0.00';
  var url = $(window.location.href);
})();
JS;
$selectors = (new RuntimeScriptEvidenceAnalyzer())->analyze($helpers)['selectors'];
foreach (array('[data-shop-grid]', '.book-card', '[data-filter]', '#cart-total') as $selector) $assert(in_array($selector, $selectors, true), "A literal selector passed to a lookup helper is a runtime target: {$selector}.");
$assert(4 === count($selectors), 'Only literal selectors passed to the defined helpers are targets.');

$jquery = (new RuntimeScriptEvidenceAnalyzer())->analyze('$("[data-gallery]").slick(); jQuery(".hero").fadeIn();')['selectors'];
$assert(array() === $jquery, 'An undefined $ helper (jQuery) is not read as a DOM lookup.');

$script = "(function () {\n  function \$(sel, root) { return (root || document).querySelector(sel); }\n  var grid = \$('[data-shop-grid]');\n  grid.innerHTML = '<article class=\"book\">Book</article>';\n})();";
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><title>Shop</title></head><body><main><h1>Shop</h1><div class="book-grid" data-shop-grid></div></main><script src="js/app.js"></script></body></html>',
    'js/app.js' => $script,
)))->toArray();
$markup = '';
foreach ($compiled['source_reports']['wordpress_site_plan']['pages'] ?? array() as $page) $markup .= (string) ($page['canonical_block_markup'] ?? '');
$assert(1 === preg_match('/<div[^>]*\sdata-shop-grid(?:[\s=>])/', $markup), 'The element the script finds through its helper keeps the data attribute it is found by.');

if (0 !== $failures) { fwrite(STDERR, "Script selector helper targets: {$failures} failed\n"); exit(1); }
echo "Script selector helper targets: {$passed} passed\n";
