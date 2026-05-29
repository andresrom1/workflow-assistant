<?php

use App\Services\Smn\CapFeedParser;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->parser = new CapFeedParser;
    $this->fixtures = __DIR__.'/../../../Fixtures/Smn';
});

// ───────────────────────────────────────────────────────────────────
// parseRssIndex
// ───────────────────────────────────────────────────────────────────

it('extrae URLs de XMLs CAP del RSS índice', function () {
    $rss = file_get_contents($this->fixtures.'/rss_acpCAP_2026_05_29.xml');

    $urls = $this->parser->parseRssIndex($rss);

    expect($urls)->toBe([
        'https://ssl.smn.gob.ar/feeds/CAP/avisocortoplazo/2026_05_29_1407_cap_es.xml',
    ]);
});

it('devuelve array vacío cuando el RSS no tiene items', function () {
    $rss = file_get_contents($this->fixtures.'/rss_acpCAP_vacio.xml');

    expect($this->parser->parseRssIndex($rss))->toBe([]);
});

it('devuelve array vacío con XML malformado', function () {
    expect($this->parser->parseRssIndex('<not valid xml'))->toBe([]);
    expect($this->parser->parseRssIndex(''))->toBe([]);
});

it('filtra links que no apunten al dominio CAP del SMN', function () {
    $rss = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
          <item><link>https://attacker.example.com/fake.xml</link></item>
          <item><link>https://ssl.smn.gob.ar/feeds/CAP/avisocortoplazo/real.xml</link></item>
          <item><link></link></item>
        </channel></rss>
        XML;

    expect($this->parser->parseRssIndex($rss))->toBe([
        'https://ssl.smn.gob.ar/feeds/CAP/avisocortoplazo/real.xml',
    ]);
});

// ───────────────────────────────────────────────────────────────────
// parseCapAlert — fixture real del SMN
// ───────────────────────────────────────────────────────────────────

it('parsea el CAP XML real del SMN del 2026-05-29', function () {
    $xml = file_get_contents($this->fixtures.'/cap_2026_05_29_1407.xml');

    // El aviso expira a las 16:07 del 2026-05-29; congelo el "now" para que el
    // test no pase a verde y luego rojo después de esa hora.
    $now = Carbon::parse('2026-05-29T15:00:00-03:00');

    $result = $this->parser->parseCapAlert($xml, $now);

    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe('urn:oid:2.49.0.1.32.0.2026.05.29.14.07.00')
        ->and($result['msg_type'])->toBe('Alert')
        ->and($result['severity'])->toBe('Severe')
        ->and($result['event'])->toContain('TORMENTAS FUERTES')
        ->and($result['area_desc'])->toContain('FORMOSA')
        ->and($result['expires_at']->toIso8601String())->toBe('2026-05-29T16:07:00-03:00')
        ->and($result['polygon'])->toHaveCount(9)
        ->and($result['polygon'][0])->toBe([-24.63, -59.11])
        ->and($result['polygon'][8])->toBe([-24.63, -59.11]); // cierra el polígono
});

// ───────────────────────────────────────────────────────────────────
// parseCapAlert — descartes
// ───────────────────────────────────────────────────────────────────

it('descarta avisos con status != Actual', function () {
    $xml = file_get_contents($this->fixtures.'/cap_status_test.xml');

    expect($this->parser->parseCapAlert($xml))->toBeNull();
});

it('descarta avisos vencidos', function () {
    $xml = file_get_contents($this->fixtures.'/cap_expired.xml');

    expect($this->parser->parseCapAlert($xml))->toBeNull();
});

it('descarta XML malformado sin lanzar excepción', function () {
    expect($this->parser->parseCapAlert('<not xml'))->toBeNull();
    expect($this->parser->parseCapAlert(''))->toBeNull();
});

it('descarta msgType no soportados', function () {
    $base = file_get_contents($this->fixtures.'/cap_2026_05_29_1407.xml');
    $now = Carbon::parse('2026-05-29T15:00:00-03:00');

    // Ack y Error son msgTypes legítimos de CAP que no nos interesan
    $ack = str_replace('<cap:msgType>Alert</cap:msgType>', '<cap:msgType>Ack</cap:msgType>', $base);
    $error = str_replace('<cap:msgType>Alert</cap:msgType>', '<cap:msgType>Error</cap:msgType>', $base);

    expect($this->parser->parseCapAlert($ack, $now))->toBeNull();
    expect($this->parser->parseCapAlert($error, $now))->toBeNull();
});

it('acepta msgType Update y Cancel', function () {
    $base = file_get_contents($this->fixtures.'/cap_2026_05_29_1407.xml');
    $now = Carbon::parse('2026-05-29T15:00:00-03:00');

    $update = str_replace('<cap:msgType>Alert</cap:msgType>', '<cap:msgType>Update</cap:msgType>', $base);
    $cancel = str_replace('<cap:msgType>Alert</cap:msgType>', '<cap:msgType>Cancel</cap:msgType>', $base);

    expect($this->parser->parseCapAlert($update, $now)['msg_type'])->toBe('Update');
    expect($this->parser->parseCapAlert($cancel, $now)['msg_type'])->toBe('Cancel');
});

it('descarta polígonos con menos de 3 vértices', function () {
    $base = file_get_contents($this->fixtures.'/cap_2026_05_29_1407.xml');
    $now = Carbon::parse('2026-05-29T15:00:00-03:00');

    $stripped = preg_replace(
        '#<cap:polygon>.*?</cap:polygon>#',
        '<cap:polygon>-24.63,-59.11 -25.33,-59.23</cap:polygon>',
        $base
    );

    expect($this->parser->parseCapAlert($stripped, $now))->toBeNull();
});

it('tolera vértices basura dentro de un polígono mayormente válido', function () {
    $base = file_get_contents($this->fixtures.'/cap_2026_05_29_1407.xml');
    $now = Carbon::parse('2026-05-29T15:00:00-03:00');

    $messy = preg_replace(
        '#<cap:polygon>.*?</cap:polygon>#',
        '<cap:polygon>-24.63,-59.11 basura,nada -25.33,-59.23 1,2,3 -25.58,-58.73 -24.63,-59.11</cap:polygon>',
        $base
    );

    $result = $this->parser->parseCapAlert($messy, $now);

    expect($result)->not->toBeNull()
        ->and($result['polygon'])->toHaveCount(4);
});
