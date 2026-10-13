<?php

namespace App\Concerns;

use App\Services\Monitoring\CommandRunReport;
use App\Services\Monitoring\RunStatus;
use App\Services\Monitoring\WebhookNotifier;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Sends one report per command run to the configured monitoring webhooks, built from the
 * messages logged during the run. Parallel worker subprocesses (--partition) stay silent,
 * the parent run reports their failures.
 *
 * @mixin Command
 */
trait ReportsRunToWebhooks
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private array $runMessages = [];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $notifier = app(WebhookNotifier::class);

        if (! $notifier->isEnabled() || $this->isWorkerRun($input)) {
            return parent::execute($input, $output);
        }

        $startedAt = now();

        try {
            $exitCode = parent::execute($input, $output);
        } catch (Throwable $e) {
            $notifier->send($this->runReport(RunStatus::Failed, self::FAILURE, $startedAt, $e->getMessage()));

            throw $e;
        }

        $notifier->send($this->runReport($this->runStatus($exitCode), $exitCode, $startedAt));

        return $exitCode;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function bufferRunMessage(string $level, string $message, array $context = []): void
    {
        $this->runMessages[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    private function isWorkerRun(InputInterface $input): bool
    {
        return $input->hasOption('partition') && $input->getOption('partition') !== null;
    }

    private function runStatus(int $exitCode): RunStatus
    {
        if ($exitCode !== self::SUCCESS) {
            return RunStatus::Failed;
        }

        $hasWarnings = array_any($this->runMessages, fn (array $message) => $message['level'] !== 'info');

        return $hasWarnings ? RunStatus::Warning : RunStatus::Succeeded;
    }

    private function runReport(RunStatus $status, int $exitCode, CarbonInterface $startedAt, ?string $error = null): CommandRunReport
    {
        return new CommandRunReport(
            command: $this->getName(),
            status: $status,
            exitCode: $exitCode,
            durationSeconds: round($startedAt->diffInSeconds(now()), 1),
            messages: $this->runMessages,
            finishedAt: now(),
            error: $error,
        );
    }
}
