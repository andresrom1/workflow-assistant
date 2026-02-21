<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Traits\ConditionalLogger;

class PlateNormalizerService
{
    use ConditionalLogger;

    public function normalize($plate)
    {
        $normalizedPlate = strtoupper(str_replace([' ', '-', '.', '(', ')', '_'], '', $plate));
        return $normalizedPlate;
    }
}