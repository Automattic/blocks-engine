<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$document = new DOMDocument();
$document->loadHTML('<body><div><span>Original</span></div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$old = $document->getElementsByTagName('span')->item(0);
$cache = new CssSelectorMatchCache();
$parsed = CssSelectorMatcher::parse('.spaced');
$assert(array('') === $cache->classTokens($old) && !$cache->matches($old, '.spaced', $parsed)['matches'], 'original node caches its absent class');
$path = $old->getNodePath();
$replacement = $document->createElement('span', 'Replacement');
$replacement->setAttribute('class', 'spaced');
$old->parentNode->replaceChild($replacement, $old);
$assert($path === $replacement->getNodePath(), 'lowering replacement reuses the document path');
$assert(array('spaced') === $cache->classTokens($replacement) && $cache->matches($replacement, '.spaced', $parsed)['matches'], 'replacement gets its own selector identity instead of stale path inputs');
$fetched = $document->getElementsByTagName('span')->item(0);
$assert(array('spaced') === $cache->classTokens($fetched), 'another wrapper for the same live node reuses correct inputs');
$cache->clear();
$assert(array('spaced') === $cache->classTokens($fetched), 'new cache revision retains correct source identity');
fwrite(STDOUT, "Selector cache native node identity passed\n");
