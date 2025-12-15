<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotización #{{ $quote->id }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-7xl mx-auto space-y-6">
        
        <!-- Header -->
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Cotización #{{ $quote->id }}</h1>
                <p class="text-gray-500">Task ID Externo: {{ $quote->external_ref_id ?? 'N/A' }}</p>
            </div>
            <a href="{{ route('quotes.index') }}" class="text-gray-600 hover:text-gray-800 bg-white px-4 py-2 rounded shadow">
                ← Volver al listado
            </a>
        </div>

        <!-- Panel de Riesgo (SNAPSHOT INMUTABLE) -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <h2 class="text-xl font-bold mb-4 text-gray-800 flex items-center">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                Snapshot de Riesgo (Datos Congelados)
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Vehículo -->
                <div>
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Vehículo</h3>
                    <p class="text-lg font-medium text-gray-900">{{ $quote->riskSnapshot->marca }} {{ $quote->riskSnapshot->modelo }}</p>
                    <p class="text-gray-600">{{ $quote->riskSnapshot->version }}</p>
                    <div class="mt-2 flex gap-2">
                        <span class="px-2 py-1 bg-gray-100 rounded text-xs">Año: {{ $quote->riskSnapshot->year }}</span>
                        <span class="px-2 py-1 bg-gray-100 rounded text-xs">CP: {{ $quote->riskSnapshot->codigo_postal }}</span>
                    </div>
                </div>
                
                <!-- Factores -->
                <div>
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Factores Críticos</h3>
                    <ul class="mt-1 space-y-1">
                        <li class="flex items-center">
                            <span class="w-24 text-gray-500 text-sm">Combustible:</span>
                            <span class="font-medium {{ strtolower($quote->riskSnapshot->combustible) == 'gnc' ? 'text-red-600' : 'text-green-600' }}">
                                {{ strtoupper($quote->riskSnapshot->combustible) }}
                            </span>
                        </li>
                        <li class="flex items-center">
                            <span class="w-24 text-gray-500 text-sm">Uso:</span>
                            <span class="font-medium">{{ ucfirst($quote->riskSnapshot->uso) }}</span>
                        </li>
                        <li class="flex items-center">
                            <span class="w-24 text-gray-500 text-sm">Edad Cond.:</span>
                            <span class="font-medium">{{ $quote->riskSnapshot->edad_conductor ? \Carbon\Carbon::parse($quote->riskSnapshot->edad_conductor)->age . ' años' : 'N/D' }}</span>
                        </li>
                    </ul>
                </div>

                 <!-- Cliente Snapshot -->
                 <div>
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider">Cliente al Cotizar</h3>
                    <p class="text-gray-900">DNI: {{ $quote->riskSnapshot->dni ?? 'No especificado' }}</p>
                </div>
            </div>
        </div>

        <!-- Estado del Proceso -->
        @if($quote->status == 'failed')
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded relative" role="alert">
                <strong class="font-bold">Error en la cotización:</strong>
                <span class="block sm:inline">{{ $quote->metadata['error'] ?? 'Error desconocido del proveedor.' }}</span>
            </div>
        @endif

        <!-- Listado de Alternativas -->
        <h2 class="text-2xl font-bold text-gray-800 mt-8">Alternativas de Cobertura ({{ $quote->alternatives->count() }})</h2>
        
        @if($quote->alternatives->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($quote->alternatives as $alt)
                    <div class="bg-white rounded-lg shadow-sm hover:shadow-md transition duration-200 border border-gray-200 overflow-hidden flex flex-col">
                        <!-- Header Card -->
                        <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex justify-between items-start">
                            <div>
                                <span class="text-xs font-bold text-blue-600 uppercase tracking-wide">{{ $alt->aseguradora }}</span>
                                <h3 class="font-bold text-gray-800 leading-tight">{{ $alt->titulo }}</h3>
                            </div>
                            <!-- Badge de Grado -->
                            @php
                                $gradeColors = [
                                    'liability' => 'bg-gray-100 text-gray-600',
                                    'basic' => 'bg-orange-100 text-orange-700',
                                    'third_party_complete' => 'bg-blue-100 text-blue-700',
                                    'all_risk' => 'bg-purple-100 text-purple-700',
                                ];
                                $gradeLabel = [
                                    'liability' => 'Resp. Civil',
                                    'basic' => 'Básico',
                                    'third_party_complete' => 'Terceros Comp.',
                                    'all_risk' => 'Todo Riesgo',
                                ];
                            @endphp
                            <span class="px-2 py-1 rounded text-xs font-bold {{ $gradeColors[$alt->normalized_grade] ?? 'bg-gray-100' }}">
                                {{ $gradeLabel[$alt->normalized_grade] ?? $alt->normalized_grade }}
                            </span>
                        </div>
                        
                        <!-- Body Card -->
                        <div class="p-4 flex-grow">
                            <p class="text-sm text-gray-600 mb-3 line-clamp-2">{{ $alt->descripcion }}</p>
                            
                            <!-- Features Chips -->
                            @if($alt->features_tags)
                                <div class="flex flex-wrap gap-1 mb-3">
                                    @foreach(array_slice($alt->features_tags, 0, 3) as $tag)
                                        <span class="px-2 py-0.5 bg-gray-100 text-gray-500 text-xs rounded-full border border-gray-200">{{ $tag }}</span>
                                    @endforeach
                                    @if(count($alt->features_tags) > 3)
                                        <span class="px-2 py-0.5 text-gray-400 text-xs">+{{ count($alt->features_tags) - 3 }}</span>
                                    @endif
                                </div>
                            @endif
                            
                            <div class="text-xs text-gray-400 mb-1">Suma Asegurada: {{ $alt->sum_insured_text ?? 'N/D' }}</div>
                        </div>

                        <!-- Footer Card -->
                        <div class="bg-gray-50 px-4 py-3 border-t border-gray-200 flex justify-between items-center">
                            <div class="text-2xl font-bold text-gray-900">
                                <span class="text-sm font-normal text-gray-500">$</span> 
                                {{ number_format($alt->precio, 2, ',', '.') }}
                            </div>
                            <button class="text-blue-600 text-sm font-semibold hover:underline">Ver JSON</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-10 bg-white rounded-lg border border-dashed border-gray-300">
                <p class="text-gray-500">No hay alternativas disponibles para esta cotización aún.</p>
                @if($quote->status == 'pending')
                    <p class="text-blue-500 text-sm mt-2 animate-pulse">Consultando proveedores...</p>
                @endif
            </div>
        @endif

        <!-- Auditoría JSON (Collapsible) -->
        <div class="mt-8 border-t pt-6">
            <details>
                <summary class="cursor-pointer text-gray-500 hover:text-gray-700 text-sm font-medium">Ver JSON Crudo del Proveedor</summary>
                <pre class="mt-4 bg-gray-800 text-green-400 p-4 rounded text-xs overflow-x-auto">
                    {{ json_encode($quote->raw_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}
                </pre>
            </details>
        </div>

    </div>
</body>
</html>