<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Ubicación en tiempo real · MANGO</title>

    {{-- Leaflet desde CDN (OSM, sin API key). --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <style>
        /* Tokens NOIR de MANGO (dark-first). */
        :root {
            --ink: #0A0908;
            --ink-soft: #161412;
            --paper: #F2EFE9;
            --paper-dim: #9C978D;
            --mango: #FF6B00;
            --hairline: rgba(242, 239, 233, 0.10);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: var(--ink);
            color: var(--paper);
            font-family: -apple-system, "Segoe UI", Roboto, system-ui, sans-serif;
            display: flex;
            flex-direction: column;
        }
        header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--hairline);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand { font-weight: 700; letter-spacing: 2px; font-size: 14px; }
        .brand .dot { color: var(--mango); }
        .status {
            margin-left: auto;
            font-size: 12px;
            color: var(--paper-dim);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pulse {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--mango);
            box-shadow: 0 0 0 0 rgba(255, 107, 0, 0.6);
            animation: pulse 1.6s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 107, 0, 0.6); }
            70% { box-shadow: 0 0 0 10px rgba(255, 107, 0, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 107, 0, 0); }
        }
        #map { flex: 1; background: var(--ink-soft); }
        footer {
            padding: 10px 20px;
            font-size: 11px;
            color: var(--paper-dim);
            border-top: 1px solid var(--hairline);
            text-align: center;
        }
        /* Overlay para el estado "ya no disponible". */
        #gone {
            position: fixed; inset: 0;
            background: var(--ink);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 32px;
            text-align: center;
        }
        #gone.show { display: flex; }
        #gone h1 { font-size: 22px; font-weight: 700; }
        #gone p { color: var(--paper-dim); font-size: 14px; max-width: 320px; }
    </style>
</head>
<body>
    <header>
        <span class="brand">MANGO<span class="dot">.</span></span>
        <span class="status"><span class="pulse"></span><span id="statusText">Conectando…</span></span>
    </header>

    <div id="map"></div>

    <footer id="footer">Ubicación compartida en una situación de emergencia.</footer>

    <div id="gone">
        <h1>Esta ubicación ya no está disponible.</h1>
        <p>La persona dejó de compartir su ubicación o el enlace expiró.</p>
    </div>

    <script>
        const TRACK_URL = @json(url('/track/'.$token));
        const INITIAL = {
            active: @json($active),
            lat: @json($lastLat),
            lon: @json($lastLon),
            lastUpdatedAt: @json($lastUpdatedAt),
        };

        let map, marker;

        function initMap(lat, lon) {
            map = L.map('map', { zoomControl: true }).setView([lat, lon], 16);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(map);
            marker = L.circleMarker([lat, lon], {
                radius: 10, color: '#FF6B00', fillColor: '#FF6B00', fillOpacity: 0.9, weight: 3,
            }).addTo(map);
        }

        function setStatus(text) {
            document.getElementById('statusText').textContent = text;
        }

        function showGone() {
            document.getElementById('gone').classList.add('show');
        }

        function relativeTime(iso) {
            if (!iso) return '';
            const diff = Math.max(0, Date.now() - new Date(iso).getTime());
            const min = Math.floor(diff / 60000);
            if (min < 1) return 'hace instantes';
            if (min === 1) return 'hace 1 minuto';
            if (min < 60) return `hace ${min} minutos`;
            const h = Math.floor(min / 60);
            return h === 1 ? 'hace 1 hora' : `hace ${h} horas`;
        }

        // Animación del marcador: en vez de saltar, se desliza linealmente desde
        // la posición actual hasta la nueva durante ~9,5s (un poco menos que el
        // intervalo de polling), para que el movimiento se vea fluido.
        let animFrom = null, animTo = null, animStart = 0;
        const ANIM_MS = 9500;

        function glideMarkerTo(lat, lon) {
            const target = L.latLng(lat, lon);
            if (!map) { initMap(lat, lon); return; }
            animFrom = marker.getLatLng();
            animTo = target;
            animStart = performance.now();
            requestAnimationFrame(stepMarker);
        }

        function stepMarker(now) {
            if (!animFrom || !animTo) return;
            const t = Math.min(1, (now - animStart) / ANIM_MS);
            const lat = animFrom.lat + (animTo.lat - animFrom.lat) * t;
            const lon = animFrom.lng + (animTo.lng - animFrom.lng) * t;
            marker.setLatLng([lat, lon]);
            if (t < 1) {
                requestAnimationFrame(stepMarker);
            } else {
                map.panTo(animTo, { animate: true, duration: 0.5 });
                animFrom = animTo = null;
            }
        }

        function update(data) {
            if (!data.active || data.last_lat == null || data.last_lon == null) {
                showGone();
                return;
            }
            const lat = parseFloat(data.last_lat);
            const lon = parseFloat(data.last_lon);
            glideMarkerTo(lat, lon);
            setStatus('Actualizado ' + relativeTime(data.last_updated_at || data.lastUpdatedAt));
        }

        async function poll() {
            try {
                const res = await fetch(TRACK_URL, { headers: { 'Accept': 'application/json' } });
                if (res.status === 404) { showGone(); return; }
                update(await res.json());
            } catch (e) {
                setStatus('Sin conexión, reintentando…');
            }
        }

        // Render inicial con los datos que vinieron del servidor.
        if (INITIAL.active && INITIAL.lat != null && INITIAL.lon != null) {
            update({
                active: true,
                last_lat: INITIAL.lat,
                last_lon: INITIAL.lon,
                last_updated_at: INITIAL.lastUpdatedAt,
            });
        } else if (!INITIAL.active) {
            showGone();
        } else {
            // Activo pero sin posición todavía: esperamos al primer poll.
            setStatus('Esperando ubicación…');
        }

        // Polling cada 10s, alineado al ritmo del replay del backend (una posición
        // nueva cada ~10s). El marcador se desliza entre cada update.
        setInterval(poll, 10000);
    </script>
</body>
</html>
