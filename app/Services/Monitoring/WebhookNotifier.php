<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts command run reports to every configured monitoring webhook. Discord webhooks
 * receive an embed, every other URL receives a plain JSON payload.
 */
final class WebhookNotifier
{
    private const DISCORD_HOSTS = ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'];

    private const DISCORD_DESCRIPTION_LIMIT = 4096;

    private const DISCORD_FIELD_LIMIT = 1024;

    private const TIMEOUT_SECONDS = 5;

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return config('services.monitoring.webhook_urls', []);
    }

    public function isEnabled(): bool
    {
        return $this->urls() !== [];
    }

    /**
     * Failures are logged and swallowed, so a broken webhook never fails the monitored command.
     */
    public function send(CommandRunReport $report): void
    {
        foreach ($this->urls() as $url) {
            $payload = $this->isDiscord($url) ? $this->discordPayload($report) : $this->genericPayload($report);

            try {
                Http::timeout(self::TIMEOUT_SECONDS)->post($url, $payload)->throw();
            } catch (Throwable $e) {
                Log::warning('Failed to send monitoring webhook', [
                    'host' => parse_url($url, PHP_URL_HOST),
                    'command' => $report->command,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function isDiscord(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array($host, self::DISCORD_HOSTS, true) && str_starts_with($path, '/api/webhooks/');
    }

    /**
     * @return array{embeds: list<array<string, mixed>>}
     */
    private function discordPayload(CommandRunReport $report): array
    {
        $fields = [
            ['name' => 'Duration', 'value' => "{$report->durationSeconds}s", 'inline' => true],
            ['name' => 'Exit code', 'value' => (string) $report->exitCode, 'inline' => true],
        ];

        if ($report->error !== null) {
            $fields[] = ['name' => 'Error', 'value' => Str::limit($report->error, self::DISCORD_FIELD_LIMIT - 3), 'inline' => false];
        }

        $description = implode("\n", array_map(
            fn (array $message) => $message['level'] === 'info' ? "- {$message['message']}" : "- **{$message['level']}:** {$message['message']}",
            $report->messages,
        ));

        return [
            'embeds' => [[
                'title' => config('app.name')." · {$report->command} {$report->status->value}",
                'description' => Str::limit($description, self::DISCORD_DESCRIPTION_LIMIT - 3),
                'color' => $report->status->color(),
                'fields' => $fields,
                'timestamp' => $report->finishedAt->toIso8601String(),
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function genericPayload(CommandRunReport $report): array
    {
        return [
            'event' => $report->status === RunStatus::Failed ? 'command.failed' : 'command.finished',
            'app' => config('app.name'),
            'command' => $report->command,
            'status' => $report->status->value,
            'exit_code' => $report->exitCode,
            'duration_seconds' => $report->durationSeconds,
            'messages' => $report->messages,
            'error' => $report->error,
            'occurred_at' => $report->finishedAt->toIso8601ZuluString(),
        ];
    }
}
