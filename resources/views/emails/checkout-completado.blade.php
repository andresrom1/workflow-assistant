<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Checkout completado</title></head>
<body style="font-family: sans-serif; color: #222; max-width: 600px; margin: 0 auto; padding: 20px;">

    <h2 style="color: #1d4ed8;">✅ Nuevo checkout completado</h2>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr><td style="padding: 6px; font-weight: bold; width: 160px;">Tomador</td>
            <td>{{ $session->nombre }} (DNI {{ $session->dni }})</td></tr>
        <tr><td style="padding: 6px; font-weight: bold;">Email</td>
            <td>{{ $session->email }}</td></tr>
        <tr><td style="padding: 6px; font-weight: bold;">Teléfono</td>
            <td>{{ $session->telefono }}</td></tr>
        <tr><td style="padding: 6px; font-weight: bold;">Vehículo</td>
            <td>{{ $snap->marca }} {{ $snap->modelo }} {{ $snap->year }}
                @if($snap->vehicle?->patente) — {{ $snap->vehicle->patente }} @endif</td></tr>
        <tr><td style="padding: 6px; font-weight: bold;">Cobertura</td>
            <td>{{ $alternative?->aseguradora }} — {{ $alternative?->titulo }}</td></tr>
        <tr><td style="padding: 6px; font-weight: bold;">Prima mensual</td>
            <td>${{ number_format($alternative?->precio, 0, ',', '.') }} {{ $alternative?->moneda }}</td></tr>
        <tr><td style="padding: 6px; font-weight: bold;">Enviado</td>
            <td>{{ $session->submitted_at?->format('d/m/Y H:i') }}</td></tr>
    </table>

    <p style="color: #6b7280; font-size: 13px;">
        Quote ID: {{ $quote->id }} — 
        <a href="{{ url('/admin/checkout/' . $quote->id) }}">Ver en el sistema</a>
    </p>

</body>
</html>