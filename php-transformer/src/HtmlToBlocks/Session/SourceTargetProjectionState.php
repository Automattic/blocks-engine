<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

/** Maps stable source identity to CSS on a native replacement. */
final class SourceTargetProjectionState
{
    private const MAX_CORRESPONDENCES = 100;

    /** @var array<string, array{source_selector:string,target_selector:string,declarations:string}> */
    private array $correspondences = array();

    /** @var array<string, string> */
    private array $rules = array();

    public function record(string $sourceSelector, string $targetSelector, string $declarations): void
    {
        $sourceSelector = trim($sourceSelector);
        $targetSelector = trim($targetSelector);
        $declarations = trim($declarations);
        if ('' === $sourceSelector || '' === $targetSelector || '' === $declarations) {
            return;
        }

        $rule = $targetSelector . '{' . $declarations . '}';
        $this->rules[$rule] = $rule;

        $key = $sourceSelector . "\0" . $targetSelector . "\0" . $declarations;
        if ( isset($this->correspondences[$key]) || self::MAX_CORRESPONDENCES <= count($this->correspondences) ) {
            return;
        }

        $this->correspondences[$key] = array(
            'source_selector' => $sourceSelector,
            'target_selector' => $targetSelector,
            'declarations' => $declarations,
        );
    }

    /** @return list<string> */
    public function rules(): array
    {
        return array_values($this->rules);
    }

    /** @return list<array{source_selector:string,target_selector:string,declarations:string}> */
    public function correspondences(): array
    {
        return array_values($this->correspondences);
    }
}
