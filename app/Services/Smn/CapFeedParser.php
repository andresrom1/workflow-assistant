<?php

namespace App\Services\Smn;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Parser puro de XML del SMN — sin HTTP.
 *
 * Maneja dos formatos:
 *   1. RSS índice (`rss_acpCAP.xml`): devuelve la lista de URLs a XMLs CAP individuales.
 *   2. CAP XML individual: devuelve un array tipado con los campos del aviso,
 *      o null si debe descartarse (status≠Actual, msgType inválido, expirado, sin polígono).
 *
 * Spec v2 §4.4. Fase 4 backend.
 *
 * Decisión: se usa el feed `rss_acpCAP.xml` y no `avisocorto_GeoRSS.xml` porque el
 * primero apunta a los XMLs CAP individuales que tienen `cap:identifier`, `cap:severity`,
 * `cap:expires` y `cap:msgType` — campos requeridos por la spec para dedup, filtro
 * por severidad y descarte de avisos vencidos. El GeoRSS no los expone.
 */
class CapFeedParser
{
    /**
     * msgTypes que el comando procesa. Otros (Ack, Error, Cancel-en-XML-malformado) se ignoran.
     *
     * Cancel se procesa porque la app cliente puede querer notificar que un aviso fue
     * cancelado antes de su expiración (UX futura). Hoy el handler de la app lo trata
     * igual que un Alert — la diferencia queda lista para cuando haga falta.
     */
    private const ALLOWED_MSG_TYPES = ['Alert', 'Update', 'Cancel'];

    /**
     * Extrae las URLs de XMLs CAP individuales del RSS índice del SMN.
     *
     * @return list<string>
     */
    public function parseRssIndex(string $rssXml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $sxml = simplexml_load_string($rssXml);
        } catch (Throwable) {
            $sxml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($sxml === false || ! isset($sxml->channel)) {
            return [];
        }

        $urls = [];

        foreach ($sxml->channel->item ?? [] as $item) {
            $link = trim((string) $item->link);

            // Solo URLs CAP individuales del SMN. Filtra basura defensiva.
            if ($link !== '' && str_starts_with($link, 'https://ssl.smn.gob.ar/feeds/CAP/')) {
                $urls[] = $link;
            }
        }

        return $urls;
    }

    /**
     * Parsea un XML CAP individual y devuelve el array tipado, o null si se descarta.
     *
     * Razones de descarte (todas silenciosas, no excepción):
     *   - XML malformado
     *   - status != "Actual" (Test, Exercise, Draft, System)
     *   - msgType ∉ {Alert, Update, Cancel}
     *   - Sin bloque cap:info o cap:area
     *   - Sin polígono parseable (≥3 puntos)
     *   - Sin cap:identifier
     *   - cap:expires ya en el pasado (`$now` permite testing determinístico)
     *
     * @return array{
     *     id: string,
     *     msg_type: string,
     *     event: string,
     *     severity: string,
     *     expires_at: Carbon,
     *     area_desc: string,
     *     polygon: list<array{0: float, 1: float}>,
     *     instruction: string
     * }|null
     */
    public function parseCapAlert(string $capXml, ?Carbon $now = null): ?array
    {
        $now ??= Carbon::now();

        $previous = libxml_use_internal_errors(true);

        try {
            $sxml = simplexml_load_string($capXml);
        } catch (Throwable) {
            $sxml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($sxml === false) {
            return null;
        }

        // El XML del SMN usa namespace cap:* en todos los nodos.
        $cap = $sxml->children('cap', true);

        $identifier = trim((string) $cap->identifier);
        $status = trim((string) $cap->status);
        $msgType = trim((string) $cap->msgType);

        if ($identifier === '' || $status !== 'Actual' || ! in_array($msgType, self::ALLOWED_MSG_TYPES, true)) {
            return null;
        }

        $info = $cap->info;
        if ($info === null || $info->count() === 0) {
            return null;
        }

        $expiresRaw = trim((string) $info->expires);
        try {
            $expiresAt = Carbon::parse($expiresRaw);
        } catch (Throwable) {
            return null;
        }

        if ($expiresAt->lessThanOrEqualTo($now)) {
            return null;
        }

        $area = $info->area;
        if ($area === null || $area->count() === 0) {
            return null;
        }

        $polygon = $this->parsePolygon(trim((string) $area->polygon));
        if (count($polygon) < 3) {
            return null;
        }

        return [
            'id' => $identifier,
            'msg_type' => $msgType,
            'event' => trim((string) $info->event),
            'severity' => trim((string) $info->severity),
            'expires_at' => $expiresAt,
            'area_desc' => trim((string) $area->areaDesc),
            'polygon' => $polygon,
            'instruction' => trim((string) $info->instruction),
        ];
    }

    /**
     * Convierte "lat1,lon1 lat2,lon2 lat3,lon3" → [[lat1,lon1], [lat2,lon2], ...].
     *
     * El SMN usa "lat,lon" (con coma) separados por espacio. Tolera vértices malformados
     * salteándolos individualmente — un polígono con 1 vértice basura aún sirve si
     * los demás son válidos.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function parsePolygon(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $points = [];

        foreach (preg_split('/\s+/', $raw) ?: [] as $pair) {
            $coords = explode(',', $pair);
            if (count($coords) !== 2) {
                continue;
            }

            $lat = filter_var(trim($coords[0]), FILTER_VALIDATE_FLOAT);
            $lon = filter_var(trim($coords[1]), FILTER_VALIDATE_FLOAT);

            if ($lat === false || $lon === false) {
                continue;
            }

            $points[] = [(float) $lat, (float) $lon];
        }

        return $points;
    }
}
