<?php

use App\Http\Controllers\ArticuloController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CatalogoProveedorController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\MovimientoController;
use App\Http\Controllers\OrdenCompraController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\TransferenciaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(base_path('routes/auth.php'));

// Sin auth:sanctum: PDF de cotización protegido por firma temporal de la URL (`signed`), no por
// sesión — exclusivo para que Twilio lo descargue al enviar el WhatsApp (ver 008-cotizaciones.md).
Route::get('cotizaciones/{cotizacion}/pdf-publico', [CotizacionController::class, 'pdfPublico'])
    ->name('cotizaciones.pdf-publico')
    ->middleware('signed');

Route::get('ordenes-compra/{ordenCompra}/pdf-publico', [OrdenCompraController::class, 'pdfPublico'])
    ->name('ordenes-compra.pdf-publico')
    ->middleware('signed');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::apiResource('clientes', ClienteController::class);
    Route::apiResource('proveedores', ProveedorController::class)
        ->parameters(['proveedores' => 'proveedor']);

    Route::get('articulos/exportar-csv', [ArticuloController::class, 'exportarCsv']);
    Route::apiResource('articulos', ArticuloController::class);

    // Prefijo "catalogos-proveedor" (no "catalogos") para no chocar con el grupo de catálogos SAT
    // de referencia registrado más abajo bajo /catalogos (ver 009-catalogos.md).
    Route::apiResource('catalogos-proveedor', CatalogoProveedorController::class)
        ->parameters(['catalogos-proveedor' => 'catalogo']);
    Route::post('catalogos-proveedor/{catalogo}/articulos/importar-csv', [ArticuloController::class, 'importarCsv']);
    Route::post('catalogos-proveedor/{catalogo}/impacto-precios', [CatalogoProveedorController::class, 'impactoPrecios']);

    Route::apiResource('facturas', FacturaController::class);

    Route::post('facturas/{factura}/timbrar', [FacturaController::class, 'timbrar']);
    Route::post('facturas/{factura}/cancelar', [FacturaController::class, 'cancelar']);
    Route::get('facturas/{factura}/xml', [FacturaController::class, 'xml']);
    Route::get('facturas/{factura}/pdf', [FacturaController::class, 'pdf']);
    Route::post('facturas/{factura}/enviar-correo', [FacturaController::class, 'enviarCorreo']);
    Route::post('facturas/{factura}/complemento-pago', [FacturaController::class, 'complementoPago']);

    Route::apiResource('cotizaciones', CotizacionController::class)
        ->parameters(['cotizaciones' => 'cotizacion']);
    Route::post('cotizaciones/{cotizacion}/enviar', [CotizacionController::class, 'enviar']);
    Route::post('cotizaciones/{cotizacion}/pagos', [CotizacionController::class, 'pagos']);
    Route::delete('cotizaciones/{cotizacion}/pagos/{pago}', [CotizacionController::class, 'eliminarPago']);
    Route::post('cotizaciones/{cotizacion}/entregar', [CotizacionController::class, 'entregar']);
    Route::post('cotizaciones/{cotizacion}/duplicar', [CotizacionController::class, 'duplicar']);
    Route::get('cotizaciones/{cotizacion}/pdf', [CotizacionController::class, 'pdf']);

    // Órdenes de compra (ver 012-ordenes-compra.md). El `->parameters()` es obligatorio: sin él,
    // Laravel singulariza "ordenes-compra" en inglés y genera un parámetro de ruta que rompe el
    // binding implícito de modelo (misma lección de 005 y 008).
    Route::apiResource('ordenes-compra', OrdenCompraController::class)
        ->parameters(['ordenes-compra' => 'ordenCompra']);
    Route::post('ordenes-compra/{ordenCompra}/enviar', [OrdenCompraController::class, 'enviar']);
    Route::post('ordenes-compra/{ordenCompra}/pagar', [OrdenCompraController::class, 'pagar']);
    Route::delete('ordenes-compra/{ordenCompra}/pago', [OrdenCompraController::class, 'cancelarPago']);
    Route::post('ordenes-compra/{ordenCompra}/recibir', [OrdenCompraController::class, 'recibir']);
    Route::post('ordenes-compra/{ordenCompra}/duplicar', [OrdenCompraController::class, 'duplicar']);
    Route::get('ordenes-compra/{ordenCompra}/pdf', [OrdenCompraController::class, 'pdf']);

    // Tesorería (ver 010-tesoreria.md). "saldos" se registra antes del apiResource para que no lo
    // capture el parámetro {cuenta}, igual que articulos/exportar-csv más arriba.
    Route::get('cuentas/saldos', [CuentaController::class, 'saldos']);
    Route::apiResource('cuentas', CuentaController::class);
    Route::apiResource('movimientos', MovimientoController::class);
    Route::post('transferencias', [TransferenciaController::class, 'store']);

    Route::prefix('catalogos')->group(function () {
        Route::get('regimenes-fiscales', [CatalogoController::class, 'regimenesFiscales']);
        Route::get('codigos-postales', [CatalogoController::class, 'codigosPostales']);
        Route::get('claves-prod-serv', [CatalogoController::class, 'clavesProdServ']);
        Route::get('claves-unidad', [CatalogoController::class, 'clavesUnidad']);
        Route::get('objetos-impuesto', [CatalogoController::class, 'objetosImpuesto']);
        Route::get('usos-cfdi', [CatalogoController::class, 'usosCfdi']);
        Route::get('formas-pago', [CatalogoController::class, 'formasPago']);
        Route::get('motivos-cancelacion', [CatalogoController::class, 'motivosCancelacion']);
        Route::get('metodos-pago', [CatalogoController::class, 'metodosPago']);
    });
});
