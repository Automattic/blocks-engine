<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
$g = 'background-image:linear-gradient(100deg,#ffffff 0%,#34d6c4 100%);-webkit-background-clip:text;background-clip:text;color:transparent';
$result = (new HtmlTransformer())->transform("<h1>A first impression, <span style=\"$g\">rebuilt.</span></h1><h3 style=\"$g\">Bespoke joinery</h3>")->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert = static function (bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } };
$assert(!str_contains($markup, 'style.color.gradient'), 'text-clipped gradient is not mapped to the background support');
$assert(substr_count($markup, 'background-image:linear-gradient(100deg,#ffffff 0%,#34d6c4 100%)') >= 2, 'both inline and block text retain gradient paint');
$assert(substr_count($markup, '-webkit-background-clip:text') >= 2 && substr_count($markup, 'background-clip:text') >= 2, 'both clip declarations survive on both shapes');
$assert(str_contains($markup, 'color:transparent'), 'transparent text color survives');
echo "gradient text background clip ok\n";
