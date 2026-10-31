<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$cases = array(
    'unmarked' => array('', '', true),
    'marked entrypoint' => array('wordpress', '', false),
    'marked second page' => array('', 'wordpress', false),
    'other runtime' => array('', 'native', true),
);
foreach ($cases as $name => [$homeRuntime, $secondRuntime, $removesEmoji]) {
    $document = static fn(string $runtime, string $text): string => '<!doctype html><html'
        . ('' === $runtime ? '' : ' ' . CaptureEvidence::RUNTIME_EMOJI . '="' . $runtime . '"')
        . '><head><title>' . $text . '</title></head><body><main><p>' . $text . ' 🇦🇷</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => $document($homeRuntime, 'Home'),
            'second.html' => $document($secondRuntime, 'Second'),
        ),
    ))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    $writes = array_column($plan['writes'], null, 'target_path');
    $bootstrap = (string) ($writes['functions.php']['payload']['data'] ?? '');
    if (!str_contains($bootstrap, "add_filter( 'run_wptexturize', '__return_false' );")
        || $removesEmoji !== str_contains($bootstrap, "remove_action( 'wp_head', 'print_emoji_detection_script', 7 );")
        || $removesEmoji !== str_contains($bootstrap, WordPressSitePlan::SOURCE_NATIVE_EMOJI)
        || (!$removesEmoji && (str_contains($bootstrap, 'print_emoji_detection_script') || str_contains($bootstrap, 'tiny_mce_plugins')))) {
        fwrite(STDERR, "FAIL: {$name}: captured emoji runtime controls removal without enabling texturization\n");
        exit(1);
    }
}
fwrite(STDOUT, "Capture runtime emoji passed\n");
