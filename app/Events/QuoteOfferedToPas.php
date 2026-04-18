<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuoteOfferedToPas
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Quote $quote
    ) {}
}
