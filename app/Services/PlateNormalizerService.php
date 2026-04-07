<?php

namespace App\Services;

use App\Traits\ConditionalLogger;

class PlateNormalizerService
{
    use ConditionalLogger;

    public function normalize($plate): string
    {
        return strtoupper(str_replace([' ', '-', '.', '(', ')', '_'], '', $plate));
    }
}
