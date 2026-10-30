<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/image-caption-margins.html');
$margin = $argv[1] ?? '';
if ( '' !== $margin ) {
    $source = str_replace('</style>', 'figcaption{' . $margin . '}</style>', $source);
}
$result = (new HtmlTransformer())->transform($source, array())->toArray();
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( 'css' === ($asset['kind'] ?? '') ) {
        $css .= "\n" . ($asset['content'] ?? '');
    }
}
echo json_encode(array('source' => $source, 'candidate' => $result['serialized_blocks'], 'css' => $css), JSON_THROW_ON_ERROR);
