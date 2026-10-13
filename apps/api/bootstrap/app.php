<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\StockInsufficientException;
use App\Modules\Sales\Application\SaleException;
use App\Shared\Lifecycle\RecordInUseException;
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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (RecordInUseException $e) {
            return response()->json([
                'code' => 'record_in_use',
                'message' => 'Este registro ya tiene movimientos y no puede eliminarse.',
                'blockers' => $e->blockers,
                'can_deactivate' => $e->canDeactivate,
            ], 409);
        });
        $exceptions->render(function (StockInsufficientException $e) {
            return response()->json([
                'code' => 'stock_insufficient',
                'message' => "Stock insuficiente: disponible {$e->available}, solicitado {$e->requested}.",
                'variant_id' => $e->variantId,
            ], 422);
        });
        $exceptions->render(function (SaleException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->userMessage], $e->httpStatus);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
