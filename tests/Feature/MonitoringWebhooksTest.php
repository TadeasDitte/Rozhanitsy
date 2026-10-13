<?php

use App\Models\Source;
use App\Models\SyncState;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

const DISCORD_WEBHOOK = 'https://discord.com/api/webhooks/123/token';
const GENERIC_WEBHOOK = 'https://hooks.test/rozhanitsy';

function osvSource(bool $withCursor = true): void
{
    $source = Source::factory()->create(['slug' => 'osv', 'ingest_base_url' => 'https://osv.test']);

    if ($withCursor) {
        SyncState::create(['source_id' => $source->id, 'cursor' => ['last_modified' => '2026-01-01T00:00:00Z']]);
    }
}

test('a finished run posts an embed to discord and a json payload to every other webhook', function () {
    config(['app.name' => 'Rozhanitsy', 'services.monitoring.webhook_urls' => [DISCORD_WEBHOOK, GENERIC_WEBHOOK]]);
    osvSource();
    Http::preventStrayRequests();
    Http::fake([
        'osv.test/modified_id.csv' => Http::response('2026-01-02T00:00:00Z,npm/GHSA-a'),
        'osv.test/npm/GHSA-a.json' => Http::response(['id' => 'GHSA-a']),
        DISCORD_WEBHOOK => Http::response(status: 204),
        GENERIC_WEBHOOK => Http::response(),
    ]);

    $this->artisan('ingest:osv-sync')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $embed = $request['embeds'][0] ?? null;

        return $request->url() === DISCORD_WEBHOOK
            && $embed['title'] === 'Rozhanitsy · ingest:osv-sync succeeded'
            && $embed['color'] === 0x2ECC71
            && str_contains($embed['description'], '- Done, 1 records updated, 0 skipped (not found)');
    });
    Http::assertSent(fn (Request $request) => $request->url() === GENERIC_WEBHOOK
        && $request['event'] === 'command.finished'
        && $request['command'] === 'ingest:osv-sync'
        && $request['status'] === 'succeeded'
        && $request['exit_code'] === 0
        && str_starts_with(collect($request['messages'])->last()['message'], 'Done, 1 records updated'));
});

test('a run that logs warnings is reported as warning', function () {
    config(['services.monitoring.webhook_urls' => [GENERIC_WEBHOOK]]);
    osvSource(withCursor: false);
    Http::preventStrayRequests();
    Http::fake([
        'osv.test/modified_id.csv' => Http::response(''),
        GENERIC_WEBHOOK => Http::response(),
    ]);

    $this->artisan('ingest:osv-sync')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === GENERIC_WEBHOOK
        && $request['status'] === 'warning'
        && $request['messages'][0]['level'] === 'warning');
});

test('a run that throws is reported as failed and still throws', function () {
    config(['services.monitoring.webhook_urls' => [DISCORD_WEBHOOK, GENERIC_WEBHOOK]]);
    osvSource();
    Http::preventStrayRequests();
    Http::fake([
        'osv.test/modified_id.csv' => Http::response(status: 500),
        DISCORD_WEBHOOK => Http::response(status: 204),
        GENERIC_WEBHOOK => Http::response(),
    ]);

    expect(fn () => $this->artisan('ingest:osv-sync')->run())->toThrow(RequestException::class);

    Http::assertSent(fn (Request $request) => $request->url() === GENERIC_WEBHOOK
        && $request['event'] === 'command.failed'
        && $request['status'] === 'failed'
        && $request['exit_code'] === 1
        && str_contains($request['error'], '500'));
    Http::assertSent(fn (Request $request) => $request->url() === DISCORD_WEBHOOK
        && $request['embeds'][0]['color'] === 0xE74C3C
        && $request['embeds'][0]['fields'][2]['name'] === 'Error');
});

test('a broken webhook neither fails the command nor stops the other webhooks', function () {
    config(['services.monitoring.webhook_urls' => ['https://down.test/hook', 'https://erroring.test/hook', GENERIC_WEBHOOK]]);
    osvSource();
    Http::preventStrayRequests();
    Http::fake([
        'osv.test/modified_id.csv' => Http::response(''),
        'down.test/*' => Http::failedConnection(),
        'erroring.test/*' => Http::response(status: 500),
        GENERIC_WEBHOOK => Http::response(),
    ]);

    $this->artisan('ingest:osv-sync')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === GENERIC_WEBHOOK);
});

test('no webhook is sent when none are configured', function () {
    config(['services.monitoring.webhook_urls' => []]);
    osvSource();
    Http::preventStrayRequests();
    Http::fake(['osv.test/modified_id.csv' => Http::response('')]);

    $this->artisan('ingest:osv-sync')->assertSuccessful();

    Http::assertSentCount(1);
});

test('parallel worker subprocesses do not report', function () {
    config(['services.monitoring.webhook_urls' => [GENERIC_WEBHOOK]]);
    Source::factory()->create(['slug' => 'osv']);
    Http::preventStrayRequests();
    Http::fake([GENERIC_WEBHOOK => Http::response()]);

    $this->artisan('parse:l1', ['source' => 'osv', '--partition' => '1-10'])->assertSuccessful();

    Http::assertNothingSent();
});
