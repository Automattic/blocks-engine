<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callable, string $message, string $expected) use ($assert): void {
    try {
        $callable();
    } catch (\InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), $expected), $message . ' (' . $error->getMessage() . ')');
        return;
    }
    throw new RuntimeException($message);
};

$html = '<!doctype html><html><body><main><img src="media/hero.jpg" alt="Hero"><video src="media/tour.mp4"></video></main></body></html>';
$reference = static fn(string $id, int $bytes): array => array(
    'schema' => 'blocks-engine/payload-reference/v1',
    'id'     => $id,
    'bytes'  => $bytes,
    'sha256' => hash('sha256', $id),
);
$textReference = array(
    'schema' => 'blocks-engine/payload-reference/v1',
    'id'     => 'index',
    'bytes'  => strlen($html),
    'sha256' => hash('sha256', $html),
);
$reader = new class($html) implements PayloadReader {
    public array $reads = array();
    public function __construct(private string $html) {}
    public function read(array $reference): string
    {
        $this->reads[] = (string) $reference['id'];
        if ('index' !== $reference['id']) throw new RuntimeException('A reference-backed media payload must never be read.');
        return $this->html;
    }
};

// A capture whose source text fits the read budget many times over, but whose
// referenced media dwarfs it: madalenatavares.net projects 9.9 MiB of text
// beside 336.5 MiB of srcset candidates. The per-file case is the same bug on
// the other budget: one captured source video costs the compiler a digest.
$mediaFiles = array(
    array('path' => 'index.html', 'payload_reference' => $textReference),
    array('path' => 'media/tour.mp4', 'mime_type' => 'video/mp4', 'payload_reference' => $reference('tour', 49 * 1024 * 1024)),
);
// 40 srcset candidates of 8 MiB each: 320 MiB of images, the aggregate that
// used to be charged against the 320 MiB source-read ceiling.
for ($index = 0; $index < 40; ++$index) {
    $mediaFiles[] = array('path' => 'media/hero-' . $index . '.jpg', 'mime_type' => 'image/jpeg', 'payload_reference' => $reference('hero-' . $index, 8 * 1024 * 1024));
}
$mediaArtifact = array(
    'entrypoint'      => 'index.html',
    // The contract a request bundle declares: an unchanged source-read budget,
    // plus a media budget sized for real captures.
    'compiler_limits' => array(
        'max_file_bytes'        => 10485760,
        'max_total_bytes'       => 335544320,
        'max_media_file_bytes'  => 67108864,
        'max_media_total_bytes' => 1073741824,
    ),
    'files' => $mediaFiles,
);

$normalized = (new ArtifactNormalizer())->normalize($mediaArtifact);
$paths = array_column($normalized['files'], 'path');
$assert(in_array('media/tour.mp4', $paths, true) && in_array('media/hero-0.jpg', $paths, true), 'Reference-backed media larger than the source-read budget is admitted.');
$assert(0 === $normalized['rejected_count'], 'Reference-backed media is not rejected by the budgets that bound parsed source bytes.');

$shared = (new ArtifactCompiler())->prepareShared($mediaArtifact, $reader);
$assert(array('index') === $reader->reads, 'Staged preparation reads only the text payload it parses.');
$page = (new ArtifactCompiler())->preparePage($mediaArtifact, $shared, 'index.html', $reader);
$receipt = (new ArtifactCompiler())->compilePreparedPage($shared, $page, $reader);
$composed = (new ArtifactCompiler())->compose($shared, array($receipt), $reader)->toArray();
$assert(in_array(($composed['status'] ?? ''), array('success', 'success_with_warnings'), true), 'A capture of mostly referenced media compiles end to end.');

// The protection stays intact: growth past the media budget is still refused,
// with its own distinct message rather than the source-read budget's.
$overMediaTotal = $mediaArtifact;
$overMediaTotal['compiler_limits']['max_media_total_bytes'] = 256 * 1024 * 1024;
$throws(
    static fn() => (new ArtifactCompiler())->prepareShared($overMediaTotal, $reader),
    'Referenced media beyond the aggregate media budget is refused.',
    'aggregate media byte limit'
);

$overMediaFile = $mediaArtifact;
$overMediaFile['compiler_limits']['max_media_file_bytes'] = 32 * 1024 * 1024;
$throws(
    static fn() => (new ArtifactCompiler())->prepareShared($overMediaFile, $reader),
    'A single referenced media file beyond the per-file media budget is refused.',
    'per-file media byte limit'
);

$normalizedOverMedia = (new ArtifactNormalizer())->normalize($overMediaFile);
$mediaCodes = array_column($normalizedOverMedia['diagnostics'], 'code');
$assert(in_array('artifact_media_file_too_large', $mediaCodes, true), 'Normalization reports oversized referenced media under its own diagnostic code.');
$assert(!in_array('artifact_file_too_large', $mediaCodes, true), 'Oversized referenced media is not misreported as an oversized source file.');

// Text payload references keep the unchanged source-read budget.
$oversizedText = array(
    'entrypoint' => 'index.html',
    'files'      => array(array('path' => 'index.html', 'payload_reference' => $reference('index', ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES + 1))),
);
$throws(
    static fn() => (new ArtifactCompiler())->prepareShared($oversizedText, $reader),
    'An oversized text payload reference is still refused by the per-file read budget.',
    'per-file byte limit'
);

$assert(ArtifactNormalizer::isReferenceBackedBinary(array('path' => 'media/hero.jpg', 'payload_reference' => $textReference)), 'The shared rule classifies a referenced image as media.');
$assert(!ArtifactNormalizer::isReferenceBackedBinary(array('path' => 'assets/logo.svg', 'payload_reference' => $textReference)), 'The shared rule keeps SVG on the parsed-source side.');
$assert(!ArtifactNormalizer::isReferenceBackedBinary(array('path' => 'media/hero.jpg')), 'The shared rule only classifies payload references.');

echo "artifact media budget: ok\n";
