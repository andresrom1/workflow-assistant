    <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotizaciones - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-7xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Cotizaciones</h1>
            <a href="/" class="text-blue-600 hover:underline">← Volver al inicio</a>
        </div>

        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <table class="min-w-full leading-normal">
                <thead>
                    <tr>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            ID / Fecha
                        </th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Estado
                        </th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Vehículo (Snapshot)
                        </th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Cliente
                        </th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Opciones
                        </th>
                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Acción
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($quotes as $quote)
                    <tr>
                        <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                            <p class="text-gray-900 whitespace-no-wrap font-bold">#{{ $quote->id }}</p>
                            <p class="text-gray-500 text-xs">{{ $quote->created_at->format('d/m/Y H:i') }}</p>
                        </td>
                        <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                            @php
                                $statusClasses = [
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'processed' => 'bg-green-100 text-green-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                ];
                                $class = $statusClasses[$quote->status] ?? 'bg-gray-100 text-gray-800';
                            @endphp
                            <span class="relative inline-block px-3 py-1 font-semibold leading-tight rounded-full {{ $class }}">
                                <span class="relative">{{ strtoupper($quote->status) }}</span>
                            </span>
                        </td>
                        <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                            <div class="flex items-center">
                                <div class="ml-3">
                                    <p class="text-gray-900 whitespace-no-wrap font-semibold">
                                        {{ $quote->riskSnapshot->marca }} {{ $quote->riskSnapshot->modelo }}
                                    </p>
                                    <p class="text-gray-500 text-xs">
                                        {{ $quote->riskSnapshot->year }} | CP: {{ $quote->riskSnapshot->codigo_postal }}
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                             <p class="text-gray-900 whitespace-no-wrap">
                                {{ optional($quote->conversation->customer)->name ?? 'Anónimo' }}
                            </p>
                            <p class="text-gray-500 text-xs">
                                DNI: {{ optional($quote->riskSnapshot)->dni ?? '-' }}
                            </p>
                        </td>
                        <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                            {{ $quote->alternatives_count }}
                        </td>
                        <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm">
                            <a href="{{ route('quotes.show', $quote) }}" class="text-indigo-600 hover:text-indigo-900 font-bold">Ver Detalle</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-5 py-5 bg-white border-t flex flex-col xs:flex-row items-center xs:justify-between">
                {{ $quotes->links() }}
            </div>
        </div>
    </div>
</body>
</html>