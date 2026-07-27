<?php

use App\Http\Middleware\AppVersionHeader;
use App\Http\Middleware\EnsureMasterToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'master' => EnsureMasterToken::class,
        ]);

        // carimba a versão dos assets em toda resposta da API
        $middleware->api(append: [
            AppVersionHeader::class,
        ]);

        // Em produção o Caddy termina o TLS e conversa com o nginx em HTTP puro
        // dentro da rede do compose. Sem confiar no proxy, o Laravel enxerga o
        // request como http:// (gerando URL absoluta errada num site HTTPS) e
        // registra o IP do container do proxy no lugar do IP de quem votou.
        // O `*` é seguro aqui porque nginx e php-fpm não publicam porta: o
        // único caminho até a aplicação é passando pelo Caddy.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
