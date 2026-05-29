<?php

use App\Models\AcpProcesado;
use App\Services\Smn\FcmTopicPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const RSS_URL = 'https://ssl.smn.gob.ar/feeds/CAP/avisocortoplazo/rss_acpCAP.xml';
const CAP_URL = 'https://ssl.smn.gob.ar/feeds/CAP/avisocortoplazo/2026_05_29_1407_cap_es.xml';

beforeEach(function () {
    $this->fixtures = __DIR__.'/../../../Fixtures/Smn';
    // El aviso de la fixture expira 16:07; congelo el now para que no venza.
    Carbon::setTestNow('2026-05-29T15:00:00-03:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Helper: fakea el RSS índice + el CAP individual con el XML provisto. */
function fakeSmnFeed(string $rss, string $cap): void
{
    Http::fake([
        RSS_URL => Http::response($rss, 200),
        CAP_URL => Http::response($cap, 200),
    ]);
}

it('publica un ACP Severe al topic y persiste el dedup', function () {
    $rss = file_get_contents($this->fixtures.'/rss_acpCAP_2026_05_29.xml');
    $cap = file_get_contents($this->fixtures.'/cap_2026_05_29_1407.xml');
    fakeSmnFeed($rss, $cap);

    $publisher = $this->mock(FcmTopicPublisher::class);
    $publisher->shouldReceive('publish')->once()
        ->with(Mockery::on(fn ($acp) => $acp['severity'] === 'Severe'));

    $this->artisan('smn:poll-acp', ['--force' => true])->assertSuccessful();

    expect(AcpProcesado::query()->count())->toBe(1)
        ->and(AcpProcesado::query()->first()->id)
        ->toBe('urn:oid:2.49.0.1.32.0.2026.05.29.14.07.00');
});

it('no republica un ACP ya procesado (dedup)', function () {
    $rss = file_get_contents($this->fixtures.'/rss_acpCAP_2026_05_29.xml');
    $cap = file_get_contents($this->fixtures.'/cap_2026_05_29_1407.xml');
    fakeSmnFeed($rss, $cap);

    AcpProcesado::query()->create([
        'id' => 'urn:oid:2.49.0.1.32.0.2026.05.29.14.07.00',
        'expires_at' => Carbon::parse('2026-05-29T16:07:00-03:00'),
        'processed_at' => Carbon::now(),
    ]);

    $publisher = $this->mock(FcmTopicPublisher::class);
    $publisher->shouldNotReceive('publish');

    $this->artisan('smn:poll-acp', ['--force' => true])->assertSuccessful();

    expect(AcpProcesado::query()->count())->toBe(1);
});

it('omite avisos con severidad menor a Severe', function () {
    $rss = file_get_contents($this->fixtures.'/rss_acpCAP_2026_05_29.xml');
    $cap = file_get_contents($this->fixtures.'/cap_2026_05_29_1407.xml');
    $moderate = str_replace(
        '<cap:severity>Severe</cap:severity>',
        '<cap:severity>Moderate</cap:severity>',
        $cap
    );
    fakeSmnFeed($rss, $moderate);

    $publisher = $this->mock(FcmTopicPublisher::class);
    $publisher->shouldNotReceive('publish');

    $this->artisan('smn:poll-acp', ['--force' => true])->assertSuccessful();

    expect(AcpProcesado::query()->count())->toBe(0);
});

it('borra filas vencidas hace más de 7 días en el cleanup', function () {
    Http::fake([RSS_URL => Http::response('', 500)]); // feed caído → solo corre cleanup

    AcpProcesado::query()->create([
        'id' => 'urn:oid:viejo',
        'expires_at' => Carbon::now()->subDays(10),
        'processed_at' => Carbon::now()->subDays(10),
    ]);
    AcpProcesado::query()->create([
        'id' => 'urn:oid:reciente',
        'expires_at' => Carbon::now()->subDays(2),
        'processed_at' => Carbon::now()->subDays(2),
    ]);

    $this->mock(FcmTopicPublisher::class)->shouldNotReceive('publish');

    $this->artisan('smn:poll-acp', ['--force' => true])->assertSuccessful();

    expect(AcpProcesado::query()->pluck('id')->all())->toBe(['urn:oid:reciente']);
});

it('no hace fetch fuera de su tick estacional', function () {
    // Mayo → off-season, intervalo 30 min. Minuto 17 no es múltiplo de 30.
    Carbon::setTestNow('2026-05-29T15:17:00-03:00');
    Http::fake(); // cualquier request fallaría el assertNothingSent

    $this->mock(FcmTopicPublisher::class)->shouldNotReceive('publish');

    $this->artisan('smn:poll-acp')->assertSuccessful();

    Http::assertNothingSent();
});
