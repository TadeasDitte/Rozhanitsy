<?php

namespace App\Services\Monitoring;

use Carbon\CarbonInterface;

final readonly class CommandRunReport
{
    /**
     * @param  list<array{level: string, message: string, context: array<string, mixed>}>  $messages
     */
    public function __construct(
        public string $command,
        public RunStatus $status,
        public int $exitCode,
        public float $durationSeconds,
        public array $messages,
        public CarbonInterface $finishedAt,
        public ?string $error = null,
    ) {}
}
