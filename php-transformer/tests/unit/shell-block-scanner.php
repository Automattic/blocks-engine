<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$passes = 0;
$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$passes, &$failures): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}" . ('' !== $detail ? " - {$detail}" : '') . "\n");
};

$scanner = new ReflectionClass(ShellExtraction::class);
$extraction = new ShellExtraction(new Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan());
$call = static function (string $method, string $markup) use ($scanner, $extraction): array {
    $arguments = 'nestedLandmarkCandidates' === $method ? array($markup, '', 'header') : array($markup);
    return $scanner->getMethod($method)->invoke($extraction, ...$arguments);
};

// A block attribute may carry a raw '>' (a label, hand-written or older
// serialized content). Every structural walk must see the same block tree.
$header = '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
    . '<!-- wp:navigation-link {"label":"a > b","url":"/"} /-->'
    . '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
    . '</header><!-- /wp:group -->';
$page = $header . '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';

$top = $call('topLevelBlockRanges', $page);
$assert(2 === count($top) && 0 === $top[0]['offset'] && strlen($header) === $top[0]['length'], 'top-level ranges span the header and the body block', json_encode($top));

$children = $call('directChildBlockRanges', $header);
$assert(2 === count($children), 'direct children are found under an attribute carrying a raw >', json_encode($children));
$assert(str_starts_with(substr($header, $children[0]['offset'], $children[0]['length']), '<!-- wp:navigation-link'), 'the first child is the self-closing navigation link');
$assert(str_ends_with(substr($header, $children[1]['offset'], $children[1]['length']), '<!-- /wp:group -->'), 'the second child is the nested group, closed');

$tokens = $call('blockCommentTokens', $page);
$assert(9 === count($tokens), 'the token scan sees every block comment (4 openers, 4 closers, 1 self-closing)', (string) count($tokens));
$assert(1 === count(array_filter($tokens, static fn (array $token): bool => $token['self_closing'])), 'exactly one token is self-closing');

$sharedHeader = '<!-- wp:group {"tagName":"header"} --><header><!-- wp:navigation --><!-- /wp:navigation --></header><!-- /wp:group -->';
$articleHeader = '<!-- wp:group {"tagName":"article"} --><article><!-- wp:group {"tagName":"header"} --><header><!-- wp:heading --><h1>Hero</h1><!-- /wp:heading --></header><!-- /wp:group --></article><!-- /wp:group -->';
$assert(1 === count($call('nestedLandmarkCandidates', $sharedHeader)), 'root-level navigational headers are semantic shell candidates');
$assert(array() === $call('nestedLandmarkCandidates', $articleHeader), 'article-owned hero headers remain page content');

if ( 0 < $failures ) {
    fwrite(STDERR, "shell block scanner FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "shell block scanner passed: {$passes} assertions\n";
