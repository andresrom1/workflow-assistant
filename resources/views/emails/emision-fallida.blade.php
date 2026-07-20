<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Emisión fallida</title></head>
<body style="font-family: sans-serif; color: #222; max-width: 600px; margin: 0 auto; padding: 20px;">

    <h2 style="color: #b91c1c;">⚠️ La emisión de una póliza falló</h2>

    <p style="color: #6b7280;">El cliente ya completó el checkout, pero Visred rechazó la emisión. Requiere revisión manual.</p>

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
        <tr><td style="padding: 6px; font-weight: bold;">Enviado</td>
            <td>{{ $session->submitted_at?->format('d/m/Y H:i') }}</td></tr>
    </table>

    <h3 style="color: #b91c1c; font-size: 15px;">Detalle del rechazo</h3>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr><td style="padding: 6px; font-weight: bold; width: 160px;">Mensaje</td>
            <td>{{ $error['message'] }}</td></tr>
        @if($error['status'])
        <tr><td style="padding: 6px; font-weight: bold;">HTTP status</td>
            <td>{{ $error['status'] }}</td></tr>
        @endif
        @if($error['error_code'])
        <tr><td style="padding: 6px; font-weight: bold;">Código</td>
            <td>{{ $error['error_code'] }}</td></tr>
        @endif
        @if(!empty($error['field_errors']))
        <tr><td style="padding: 6px; font-weight: bold; vertical-align: top;">Campos</td>
            <td>
                <ul style="margin: 0; padding-left: 18px;">
                    @foreach($error['field_errors'] as $field => $messages)
                        <li><strong>{{ $field }}</strong>: {{ implode(', ', $messages) }}</li>
                    @endforeach
                </ul>
            </td></tr>
        @endif
    </table>

    <p style="color: #6b7280; font-size: 13px;">
        Quote ID: {{ $quote->id }} —
        <a href="{{ route('admin.checkout-sessions.show', $session->id) }}">Ver en el sistema</a>
    </p>

</body>
</html>
