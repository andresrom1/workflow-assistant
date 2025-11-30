<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cliente: {{ $customer->name }} - Sistema de Cotización</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <div class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="{{ route('customers.index') }}" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                        <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">{{ $customer->name }}</h1>
                            <p class="text-sm text-gray-500">DNI: {{ $customer->dni }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Customer Info -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Información del Cliente</h2>
                    
                    <div class="space-y-4">
                        <div class="flex items-start space-x-3">
                            <svg class="w-5 h-5 text-gray-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-500">DNI</p>
                                <p class="text-sm text-gray-900 font-mono">{{ $customer->dni }}</p>
                            </div>
                        </div>

                        <div class="flex items-start space-x-3">
                            <svg class="w-5 h-5 text-gray-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Email</p>
                                <p class="text-sm text-gray-900">{{ $customer->email ?? 'No registrado' }}</p>
                            </div>
                        </div>

                        <div class="flex items-start space-x-3">
                            <svg class="w-5 h-5 text-gray-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Teléfono</p>
                                <p class="text-sm text-gray-900">{{ $customer->phone ?? 'No registrado' }}</p>
                            </div>
                        </div>

                        <div class="flex items-start space-x-3">
                            <svg class="w-5 h-5 text-gray-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Fecha de Registro</p>
                                <p class="text-sm text-gray-900">{{ $customer->created_at->format('d/m/Y H:i') }}</p>
                            </div>
                        </div>

                        <div class="flex items-start space-x-3">
                            <svg class="w-5 h-5 text-gray-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Última Actualización</p>
                                <p class="text-sm text-gray-900">{{ $customer->updated_at->format('d/m/Y H:i') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="text-center">
                                <div class="flex items-center justify-center mb-1">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                                    </svg>
                                </div>
                                <p class="text-2xl font-bold text-gray-900">{{ $customer->vehicles->count() }}</p>
                                <p class="text-xs text-gray-500">Vehículos</p>
                            </div>
                            <div class="text-center">
                                <div class="flex items-center justify-center mb-1">
                                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                    </svg>
                                </div>
                                <p class="text-2xl font-bold text-gray-900">{{ $customer->conversations->count() }}</p>
                                <p class="text-xs text-gray-500">Conversaciones</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vehicles and Conversations -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Vehicles -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                            </svg>
                            Vehículos
                        </h2>
                        <span class="text-sm text-gray-500">{{ $customer->vehicles->count() }} registrado(s)</span>
                    </div>

                    @if($customer->vehicles->count() > 0)
                        <div class="space-y-3">
                            @foreach($customer->vehicles as $vehicle)
                                <div class="border border-gray-200 rounded-lg p-4 hover:border-blue-300 transition-colors">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-2">
                                                <span class="text-lg font-semibold text-gray-900 font-mono">
                                                    {{ $vehicle->plate }}
                                                </span>
                                                <span class="px-2 py-1 text-xs font-medium rounded-full {{ $vehicle->is_complete ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                                    {{ $vehicle->is_complete ? 'Completo' : 'Incompleto' }}
                                                </span>
                                                @if($vehicle->usage)
                                                    <span class="px-2 py-1 text-xs font-medium rounded-full
                                                        @if($vehicle->usage === 'particular') bg-green-100 text-green-800
                                                        @elseif($vehicle->usage === 'comercial') bg-blue-100 text-blue-800
                                                        @elseif($vehicle->usage === 'taxi') bg-yellow-100 text-yellow-800
                                                        @else bg-gray-100 text-gray-800
                                                        @endif">
                                                        {{ ucfirst($vehicle->usage) }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="grid grid-cols-3 gap-4 text-sm">
                                                <div>
                                                    <p class="text-gray-500">Marca</p>
                                                    <p class="text-gray-900 font-medium">{{ $vehicle->brand ?? 'N/A' }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-gray-500">Modelo</p>
                                                    <p class="text-gray-900 font-medium">{{ $vehicle->model ?? 'N/A' }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-gray-500">Año</p>
                                                    <p class="text-gray-900 font-medium">{{ $vehicle->year ?? 'N/A' }}</p>
                                                </div>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-2">
                                                Registrado: {{ $vehicle->created_at->format('d/m/Y H:i') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8 text-gray-500">
                            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                            </svg>
                            <p>No hay vehículos registrados</p>
                        </div>
                    @endif
                </div>

                <!-- Conversations -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            Conversaciones
                        </h2>
                        <span class="text-sm text-gray-500">{{ $customer->conversations->count() }} conversación(es)</span>
                    </div>

                    @if($customer->conversations->count() > 0)
                        <div class="space-y-3">
                            @foreach($customer->conversations as $conversation)
                                <div class="border border-gray-200 rounded-lg p-4 hover:border-green-300 transition-colors">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Thread ID <span class="font-normal text-blue-600">Última actividad: {{ $conversation->last_message_at->format('d/m/Y H:i') }} </p>
                                            <p class="text-xs text-gray-500 font-mono mt-1">{{ $conversation->external_conversation_id }}</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-xs text-gray-500">Iniciada</p>
                                            <p class="text-sm text-gray-900">{{ $conversation->created_at->format('d/m/Y H:i') }}</p>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-right">
                                        <p class="text-xs text-gray-500">
                                            Última actualización: {{ $conversation->updated_at->format('d/m/Y H:i') }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8 text-gray-500">
                            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <p>No hay conversaciones registradas</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</body>
</html>