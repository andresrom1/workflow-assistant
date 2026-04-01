<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(Request $request): View
    {
        $conversations = Conversation::where('channel', 'whatsapp')
            ->orderByDesc('last_message_at')
            ->get();

        $selectedConversation = null;
        $messages = collect();

        if ($request->has('conversation_id')) {
            $selectedConversation = Conversation::find($request->input('conversation_id'));

            if ($selectedConversation) {
                $messages = $selectedConversation->messages()->orderBy('created_at')->get();
            }
        }

        return view('chat.index', compact('conversations', 'selectedConversation', 'messages'));
    }
}
