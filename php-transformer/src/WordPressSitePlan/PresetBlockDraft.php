<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * One block's preset binding in progress. Each `PresetBinding` phase reads the
 * block's facts and moves literals out of its inline declarations into
 * preset attributes, classes or variables, counting every binding it makes.
 *
 * @internal
 */
final class PresetBlockDraft
{
    /** @var list<string> Classes the bound block gains. */
    public array $addClasses = array();
    /** @var list<string> Classes the bound block loses. */
    public array $dropClasses = array();
    /** Bindings made so far; a block with none is left untouched. */
    public int $bound = 0;

    /**
     * @param list<array{0:string,1:string}> $declarations The tag's inline declarations.
     * @param array<string,true> $tokens Selector tokens of the bound tag.
     * @param array<string,string> $observed Author-class style observations.
     * @param list<string> $supports Preset classes the block type supports.
     * @param array<string,true> $deviceOverridden Properties a device-inline runtime rewrites.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $html,
        public readonly string $classes,
        public readonly \stdClass $attributes,
        public readonly \stdClass $style,
        public array $declarations,
        public readonly array $tokens,
        public readonly array $observed,
        public readonly array $supports,
        public readonly bool $isLink,
        public readonly array $deviceOverridden,
        public readonly bool $buttonLook,
    ) {
    }
}
