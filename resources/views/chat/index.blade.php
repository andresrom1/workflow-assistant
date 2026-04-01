<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="15">
    <title>Chat - {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 h-screen flex flex-col">
    {{-- Header --}}
    <header class="bg-green-600 text-white px-6 py-3 flex items-center justify-between shrink-0">
        <h1 class="text-lg font-semibold">WhatsApp Chat Viewer</h1>
        <a href="{{ route('chat.index', request()->query()) }}" class="text-sm bg-green-700 hover:bg-green-800 px-3 py-1 rounded">
            Refresh
        </a>
    </header>

    <div class="flex flex-1 overflow-hidden">
        {{-- Sidebar --}}
        <aside class="w-80 bg-white border-r border-gray-200 overflow-y-auto shrink-0">
            @forelse($conversations as $conversation)
                <a href="{{ route('chat.index', ['conversation_id' => $conversation->id]) }}"
                   class="block px-4 py-3 border-b border-gray-100 hover:bg-gray-50 transition
                          {{ $selectedConversation?->id === $conversation->id ? 'bg-green-50 border-l-4 border-l-green-500' : '' }}">
                    <div class="flex justify-between items-center">
                        <span class="font-medium text-gray-800 text-sm">
                            {{ $conversation->external_conversation_id }}
                        </span>
                        @if($conversation->last_message_at)
                            <span class="text-xs text-gray-400">
                                {{ \Carbon\Carbon::parse($conversation->last_message_at)->diffForHumans(short: true) }}
                            </span>
                        @endif
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        {{ ucfirst($conversation->status ?? 'active') }}
                        @if($conversation->customer)
                            &middot; {{ $conversation->customer->name ?? '' }}
                        @endif
                    </div>
                </a>
            @empty
                <div class="px-4 py-8 text-center text-gray-400 text-sm">
                    No hay conversaciones
                </div>
            @endforelse
        </aside>

        {{-- Chat Panel --}}
        <main class="flex-1 flex flex-col bg-gray-50">
            @if($selectedConversation)
                {{-- Chat Header --}}
                <div class="bg-white px-6 py-3 border-b border-gray-200 shrink-0">
                    <h2 class="font-semibold text-gray-800">{{ $selectedConversation->external_conversation_id }}</h2>
                    <p class="text-xs text-gray-500">
                        Conversation #{{ $selectedConversation->id }}
                        &middot; {{ $messages->count() }} mensajes
                    </p>
                </div>

                {{-- Messages --}}
                <div class="flex-1 overflow-y-auto px-6 py-4 space-y-3" id="messages-container">
                    @forelse($messages as $message)
                        <div class="flex {{ $message->direction === 'outbound' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-md px-4 py-2 rounded-lg text-sm
                                        {{ $message->direction === 'outbound'
                                            ? 'bg-green-500 text-white rounded-br-none'
                                            : 'bg-white text-gray-800 shadow-sm rounded-bl-none' }}">
                                @if($message->direction === 'inbound' && $message->sender_name)
                                    <div class="text-xs font-semibold text-green-600 mb-1">{{ $message->sender_name }}</div>
                                @endif
                                <p class="whitespace-pre-wrap">{{ $message->content }}</p>
                                <div class="text-xs mt-1 {{ $message->direction === 'outbound' ? 'text-green-100' : 'text-gray-400' }} text-right">
                                    {{ $message->created_at->format('H:i') }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-gray-400 text-sm py-8">
                            No hay mensajes en esta conversación
                        </div>
                    @endforelse
                </div>
            @else
                {{-- Empty State --}}
                <div class="flex-1 flex items-center justify-center text-gray-400">
                    <div class="text-center">
                        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <p>Selecciona una conversación</p>
                    </div>
                </div>
            @endif
        </main>
    </div>

    <script>
        // Auto-scroll to bottom on load
        const container = document.getElementById('messages-container');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    </script>
</body>
</html>
