<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

/** Ordered, exclusive wall-clock timings; repeated steps accumulate calls. */
final class StepTimer
{
    /** @var array<string,array{step:string,ms:float,calls:int}> */
    private array $steps = array();
    private string $active;
    private int $since;
    private int $startedAt;

    public function __construct(string $step)
    {
        $this->startedAt = $this->since = hrtime(true);
        $this->active = $step;
        $this->enter($step);
    }

    private function enter(string $step): void
    {
        $this->steps[$step] ??= array('step' => $step, 'ms' => 0.0, 'calls' => 0);
        ++$this->steps[$step]['calls'];
    }

    public function start(string $step): void
    {
        $now = hrtime(true);
        $this->steps[$this->active]['ms'] += ($now - $this->since) / 1000000;
        $this->since = $now;
        $this->active = $step;
        $this->enter($step);
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function measure(string $step, callable $work): mixed
    {
        $previous = $this->active;
        $this->start($step);
        try {
            return $work();
        } finally {
            $this->start($previous);
            // Resuming a parent is not another invocation.
            --$this->steps[$previous]['calls'];
        }
    }

    /** Attach observations only after canonical reports and plans are built. */
    public function result(TransformerResult $result): TransformerResult
    {
        $now = hrtime(true);
        $this->steps[$this->active]['ms'] += ($now - $this->since) / 1000000;
        $this->since = $now;
        return new TransformerResult(
            status: $result->status,
            components: $result->components,
            blockTypes: $result->blockTypes,
            sourceReports: $result->sourceReports,
            blocks: $result->blocks,
            serializedBlocks: $result->serializedBlocks,
            documents: $result->documents,
            assets: $result->assets,
            diagnostics: $result->diagnostics,
            fallbacks: $result->fallbacks,
            provenance: $result->provenance,
            coverage: $result->coverage,
            context: $result->context,
            metrics: array_merge($result->metrics, array(
                'transform_duration_ms' => ($now - $this->startedAt) / 1000000,
                'step_timings' => array_values($this->steps),
            )),
            blockCompilationOutput: $result->blockCompilationOutput
        );
    }
}
