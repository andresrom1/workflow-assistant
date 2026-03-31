<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando WhatsApp devuelve el código 131048 (SPAM rate limit).
 * Indica que el Quality Rating está comprometido — los broadcasts deben
 * detenerse inmediatamente y NO reintentarse.
 */
class WhatsAppSpamLimitException extends RuntimeException {}
