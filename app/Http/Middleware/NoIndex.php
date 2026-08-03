<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saca la página de los índices de búsqueda.
 *
 * Para las páginas cuyo link es la única credencial de acceso: la URL viaja por WhatsApp y no
 * tiene por qué terminar en un buscador.
 *
 * Van las dos señales a propósito. El header es autoritativo y cubre cualquier content-type; el
 * meta sobrevive a un intermediario que filtre headers. Lo que NO se usa es un `Disallow` en
 * robots.txt: eso impide el crawl, y sin crawl el buscador nunca llega a leer el noindex.
 *
 * @see resources/views/app.blade.php para el meta que consume la variable compartida
 */
class NoIndex
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('noindex', true);

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
