<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<header><div class="site-utils"><span class="search-transport"><form id="provider-search" action="/apps/search" method="get"><input type="text" name="q" placeholder="Search"></form></span><button class="search-icon"><svg width="12px" height="13px" viewBox="0 0 12 13"><path d="M1 1"></path></svg></button><button class="search-close">close</button></div></header>'
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (!str_contains($markup, '<!-- wp:search ')
    || str_contains($markup, 'search-transport')
    || !str_contains($markup, '"buttonPosition":"button-only"')
) {
    throw new RuntimeException('Native search must absorb its phrasing transport wrapper before span preservation.');
}
echo "Native search transport wrapper passed\n";
