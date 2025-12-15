<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    /**
     * Muestra el listado de todas las cotizaciones ordenadas por fecha.
     */
    public function index()
    {
        // Cargamos la relación riskSnapshot para mostrar qué auto se cotizó
        // y conversation.customer para saber quién lo pidió.
        $quotes = Quote::with(['riskSnapshot', 'conversation.customer'])
            ->latest()
            ->paginate(15);

        return view('quotes.index', compact('quotes'));
    }

    /**
     * Muestra el detalle profundo de una cotización:
     * 1. El Snapshot (Condiciones inmutables de riesgo).
     * 2. Las Alternativas (Precios obtenidos).
     * 3. El JSON Crudo (Auditoría).
     */
    public function show(Quote $quote)
    {
        // Cargamos las alternativas ordenadas por precio
        $quote->load(['riskSnapshot', 'alternatives' => function ($query) {
            $query->orderBy('precio', 'asc');
        }]);

        return view('quotes.show', compact('quote'));
    }
}