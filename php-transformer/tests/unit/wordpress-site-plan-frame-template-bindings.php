<?php
declare(strict_types=1);

// A site with two header variants and one shared footer gets a frame template
// per page whose chrome differs from the generic page frame, and a frame
// template for heading-led content even without a main landmark.
// The producer puts the shared footer into every frame template; the plan
// validator must count the same templates as bound, including frames whose
// shell list is empty. Before, it rejected the plan with "WordPress site plan
// has an unproven template part binding." (#2822).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$headerA = '<header class="site-header"><a href="/">Acme</a><nav><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav></header>';
$headerB = '<header class="site-header alt"><a href="/">Acme Services</a><p>Call us today</p></header>';
$footer = '<footer class="site-footer"><p>Acme footer text</p><p>Copyright Acme</p></footer>';
$document = static fn(string $title, string $body): string => '<html><head><title>' . $title . '</title></head><body>' . $body . '</body></html>';
$page = static fn(string $title, string $header): string => $document($title, $header . '<main><h1>' . $title . '</h1><p>' . str_repeat($title . ' text. ', 10) . '</p></main>' . $footer);

$files = array(
    'index.html' => $page('Home', $headerA),
    'about/index.html' => $page('About', $headerA),
    'services/index.html' => $page('Services', $headerB),
    'contact/index.html' => $page('Contact', $headerB),
    // A page heading identifies content even without a main landmark.
    'legal/index.html' => $document('Legal', $headerB . '<div class="legal"><h1>Legal</h1><p>Legal notice text.</p></div>' . $footer),
);

$plan = null;
try {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
} catch (Throwable $error) {
    $assert(false, 'site plan threw ' . get_class($error) . ': ' . $error->getMessage());
}

if (is_array($plan)) {
    $parts = array_column($plan['template_parts'], null, 'slug');
    $headers = array_filter($parts, static fn(array $part): bool => 'inline_shared_shell' === ($part['placement']['kind'] ?? null) && 'header' === ($part['area'] ?? null));
    $assert(2 === count($headers), 'both header variants are inline shared shells');
    $assert('shared_shell' === ($parts['footer']['placement']['kind'] ?? null), 'the footer is one shared shell');

    $templates = array_column($plan['templates'], null, 'slug');
    $assert(array() !== ($templates['page-about']['shell_frame'] ?? array()), 'the variant A inner page gets a frame template with shells');
    $legal = current(array_filter($plan['pages'], static fn(array $page): bool => 'legal/index.html' === $page['source_path']));
    $legalTemplate = $legal['route_template'] ?? 'page';
    $assert(array() !== ($templates[$legalTemplate]['shell_frame'] ?? array()), 'A page heading exposes legal content for frame splitting even without main.');
    foreach (array('page-about', $legalTemplate) as $slug) {
        $assert(1 === substr_count((string) ($templates[$slug]['canonical_block_markup'] ?? ''), '"slug":"footer"'), $slug . ' references the shared footer once');
    }
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "wordpress-site-plan-frame-template-bindings: passed\n");
