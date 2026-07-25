<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carimba cada resposta da API com a versão dos assets compilados.
 *
 * Durante o evento cada celular segura a SPA em memória por 20 minutos. Sem
 * isto, publicar qualquer correção no meio da dinâmica deixaria as telas
 * antigas interpretando payloads novos — falhando em silêncio, sem erro
 * visível. O cliente compara este header e recarrega sozinho.
 */
class AppVersionHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-App-Version', static::current());

        return $response;
    }

    public static function current(): string
    {
        static $version = null;

        if ($version !== null) {
            return $version;
        }

        $manifest = public_path('build/manifest.json');

        return $version = File::exists($manifest)
            ? substr(md5_file($manifest), 0, 12)
            : 'dev';
    }
}
