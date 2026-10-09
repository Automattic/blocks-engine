<?php
declare(strict_types=1);

// Separate process: baseline and candidate must load their own source classes.
require $argv[1];
$source = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
echo json_encode((new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($source['html'])->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
