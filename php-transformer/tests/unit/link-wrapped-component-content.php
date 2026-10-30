<?php
declare(strict_types=1);

/**
 * A block-level link around content that is frozen into generated component
 * content (a repeated card body with an interactive role) must stay
 * navigable: the link is restored around that content, layout-transparent,
 * and is not reported as dropped.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    if (!$ok) $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
};

$body = '<div class="card-body"><div class="row"><p class="date">May 1</p></div><div class="row"><h4>First post</h4></div><div class="row"><span role="button">Continue Reading</span></div></div>';
$nest = static fn (string $inner): string => '<main>' . str_repeat('<div class="w">', 16) . $inner . str_repeat('</div>', 16) . '</main>';
$result = (new HtmlTransformer())->transform($nest('<a class="card" href="/posts/one/" target="_blank" rel="noopener">' . $body . '</a>'), array())->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$json = json_encode($result);

$assert(str_contains($markup, '<a class="card" href="/posts/one/" rel="noopener" target="_blank">'), 'container-keeps-enclosing-link', $markup);
$assert(1 === substr_count($markup, '<a '), 'one-saved-link', $markup);
$assert(str_contains($markup, 'First post'), 'inner-content-is-retained');
$assert(!str_contains($json, 'no longer navigable'), 'restored-link-not-reported-dropped');

$unlinked = (string) ((new HtmlTransformer())->transform($nest($body), array())->toArray()['serialized_blocks'] ?? '');
$assert(!str_contains($unlinked, 'blocks-engine-link-contents') && !str_contains($unlinked, 'href='), 'component-outside-a-link-gains-no-link', $unlinked);

$unsafe = (string) ((new HtmlTransformer())->transform($nest('<a href="javascript:alert(1)">' . $body . '</a>'), array())->toArray()['serialized_blocks'] ?? '');
$assert(!str_contains($unsafe, 'javascript:') && !str_contains($unsafe, 'blocks-engine-link-contents'), 'unsafe-href-is-not-restored', $unsafe);

// A viewport-fixed layer keeps its source box out of frontend flow.
$fixed = (new HtmlTransformer())->transform('<main><p>Body</p><div class="badge" style="width:256px;height:60px;position:fixed;bottom:14px;right:-186px"><textarea style="display:none"></textarea></div></main>', array())->toArray();
$fixedJson = json_encode($fixed);
$assert(str_contains((string) ($fixed['serialized_blocks'] ?? ''), 'badge') && preg_match('/be-inline-geometry-[a-f0-9-]+\{[^}]*width:256px[^}]*height:60px[^}]*position:fixed[^}]*bottom:14px[^}]*right:-186px/', $fixedJson), 'fixed-layer-retains-out-of-flow-box', $fixedJson);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Link-wrapped component content tests: 7 passed\n";
