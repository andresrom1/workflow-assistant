<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando un Firebase ID Token no se puede verificar (inválido,
 * expirado, firma incorrecta, o credenciales del backend mal configuradas).
 */
class InvalidFirebaseTokenException extends RuntimeException {}
