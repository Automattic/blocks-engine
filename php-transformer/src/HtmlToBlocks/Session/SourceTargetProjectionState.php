<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

/** Maps stable source identity to CSS on a native replacement. */
final class SourceTargetProjectionState
{
    private const MAX_CORRESPONDENCES = 100;

    /** @var array<string, array{source_selector:string,target_selector:string,declarations:string,conditions?:list<string>}> */
    private array $correspondences = array();

    /** @var array<string, string> */
    private array $rules = array();

    /** @param list<string> $conditions Ordered authored conditional groups. */
    public function record(string $sourceSelector, string $targetSelector, string $declarations, array $conditions = array()): void
    {
        $sourceSelector = trim($sourceSelector);
        $targetSelector = trim($targetSelector);
        $declarations = trim($declarations);
        if ('' === $sourceSelector || '' === $targetSelector || '' === $declarations) {
            return;
        }

        $rule = $targetSelector . '{' . $declarations . '}';
        foreach (array_reverse($conditions) as $condition) {
            $rule = $condition . '{' . $rule . '}';
        }
        $this->rules[$rule] = $rule;

        $key = $sourceSelector . "\0" . $targetSelector . "\0" . $declarations . "\0" . implode("\n", $conditions);
        if ( isset($this->correspondences[$key]) || self::MAX_CORRESPONDENCES <= count($this->correspondences) ) {
            return;
        }

        $this->correspondences[$key] = array(
            'source_selector' => $sourceSelector,
            'target_selector' => $targetSelector,
            'declarations' => $declarations,
        );
        if (array() !== $conditions) $this->correspondences[$key]['conditions'] = $conditions;
    }

    /** @return list<string> */
    public function rules(): array
    {
        return array_values($this->rules);
    }

    /** @return list<array{source_selector:string,target_selector:string,declarations:string,conditions?:list<string>}> */
    public function correspondences(): array
    {
        return array_values($this->correspondences);
    }
}
