<?php

namespace App\Http\Controllers;

use App\Models\CoveragePreference;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function __construct(
        protected QuoteService $quoteService,
        protected CoveragePreference $coveragePreference,
    ) {}

    public function index(): Response
    {
        $quotes = Quote::with(['riskSnapshot', 'conversation.customer'])
            ->withCount('alternatives')
            ->latest()
            ->paginate(15);

        return Inertia::render('Quotes/Index', [
            'quotes' => $quotes->through(fn ($q): array => [
                'id' => $q->id,
                'status' => $q->status,
                'created_at' => $q->created_at->toIso8601String(),
                'marca' => $q->riskSnapshot?->marca,
                'modelo' => $q->riskSnapshot?->modelo,
                'year' => $q->riskSnapshot?->year,
                'codigo_postal' => $q->riskSnapshot?->codigo_postal,
                'customer_name' => $q->conversation?->customer?->name,
                'customer_phone' => $q->conversation?->customer?->phone,
                'customer_identifier' => $q->conversation?->customer?->phone
                    ?? $q->conversation?->customer?->email
                    ?? $q->conversation?->customer?->dni
                    ?? $q->conversation?->ext_user_id,
                'dni' => $q->riskSnapshot?->dni,
                'alternatives_count' => $q->alternatives_count,
            ]),
        ]);
    }

    public function show(Quote $quote): Response
    {
        $quote->load([
            'riskSnapshot.vehicle',
            'conversation.customer',
            'alternatives' => fn ($q) => $q->orderBy('precio'),
        ]);

        $coveragePreference = $this->coveragePreference
            ->where('conversation_id', $quote->conversation_id)
            ->where('vehicle_id', $quote->riskSnapshot?->vehicle_id)
            ->first();

        return Inertia::render('Quotes/Show', [
            'quote' => [
                'id' => $quote->id,
                'status' => $quote->status,
                'external_ref_id' => $quote->external_ref_id,
                'marca' => $quote->riskSnapshot?->marca,
                'modelo' => $quote->riskSnapshot?->modelo,
                'version' => $quote->riskSnapshot?->version,
                'year' => $quote->riskSnapshot?->year,
                'codigo_postal' => $quote->riskSnapshot?->codigo_postal,
                'combustible' => $quote->riskSnapshot?->combustible,
                'uso' => $quote->riskSnapshot?->uso,
                'edad_conductor' => $quote->riskSnapshot?->edad_conductor,
                'dni' => $quote->riskSnapshot?->dni,
                'customer_name' => $quote->conversation?->customer?->name,
                'customer_phone' => $quote->conversation?->customer?->phone,
                'customer_email' => $quote->conversation?->customer?->email,
                'customer_dni' => $quote->conversation?->customer?->dni,
                'coverage_preference' => $coveragePreference?->preference,
                'alternatives' => $quote->alternatives->map(fn ($a): array => [
                    'id' => $a->id,
                    'aseguradora' => $a->aseguradora,
                    'titulo' => $a->titulo,
                    'descripcion' => $a->descripcion,
                    'normalized_grade' => $a->normalized_grade,
                    'precio' => $a->precio,
                    'features_tags' => $a->features_tags,
                ]),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $quote = $this->quoteService->create($request->all());

        return response()->json($quote);
    }

    public function showRaw(Quote $quote)
    {
        return response()->json($this->quoteService->getRaw($quote));
    }
}
