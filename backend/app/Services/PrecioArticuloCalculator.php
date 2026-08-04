<?php

namespace App\Services;

use App\Models\Articulo;
use App\Models\Catalogo;

/**
 * Cadena de cálculo de precios de un artículo (ver 011-precio-proveedor-utilidad.md).
 *
 *   precio_proveedor          (capturado)              $200.00
 *     ↓ × (1 − descuento / 100)                        descuento del catálogo
 *   costo_con_descuento       (calculado, persistido)  $180.00
 *     ↓ ÷ (1 − utilidad_efectiva / 100)                margen sobre venta
 *   precio_unitario_sin_iva   (calculado, persistido)  $240.00
 *
 * El porcentaje se interpreta como margen sobre la venta, no como recargo sobre el costo.
 */
class PrecioArticuloCalculator
{
    /**
     * Redondeo matemático estándar a 2 decimales (igual que en 009).
     */
    public static function redondeo2(float $valor): float
    {
        return round($valor, 2);
    }

    /**
     * Techo a 2 decimales que absorbe primero el error de representación en punto flotante.
     *
     * La división costo ÷ (1 − % / 100) produce error de representación: con costo $210.00 y 30%
     * de utilidad el resultado correcto es exactamente $300.00, pero 210 / 0.7 da
     * 300.00000000000006, que un techo ingenuo convertiría en $300.01. Se redondea primero a 4
     * decimales y se aplica el techo sobre ese valor (ver 011).
     */
    public static function techo2(float $valor): float
    {
        return ceil(round($valor, 4) * 100) / 100;
    }

    /**
     * Costo con descuento = redondeo2(precio_proveedor × (1 − descuento / 100)).
     */
    public static function costoConDescuento(float $precioProveedor, float $descuento): float
    {
        return self::redondeo2($precioProveedor * (1 - $descuento / 100));
    }

    /**
     * Precio de venta sin IVA = techo2(costo_con_descuento ÷ (1 − utilidad_efectiva / 100)).
     */
    public static function precioVentaSinIva(float $costoConDescuento, float $utilidadPorcentaje): float
    {
        return self::techo2($costoConDescuento / (1 - $utilidadPorcentaje / 100));
    }

    /**
     * Utilidad en pesos = precio_unitario_sin_iva − costo_con_descuento (siempre sin IVA).
     */
    public static function utilidad(float $precioVentaSinIva, float $costoConDescuento): float
    {
        return round($precioVentaSinIva - $costoConDescuento, 2);
    }

    /**
     * Porcentaje de utilidad efectivo de un artículo: el propio si lo tiene, si no el del catálogo.
     */
    public static function utilidadEfectiva(Articulo $articulo, Catalogo $catalogo): float
    {
        return (float) ($articulo->utilidad_porcentaje ?? $catalogo->utilidad_porcentaje);
    }

    /**
     * Calcula y devuelve los valores derivados de la cadena para un artículo dado su catálogo.
     *
     * @return array{costo_con_descuento: float, precio_unitario_sin_iva: float}
     */
    public static function calcularCadena(float $precioProveedor, float $descuento, float $utilidadPorcentaje): array
    {
        $costo = self::costoConDescuento($precioProveedor, $descuento);
        $precioVenta = self::precioVentaSinIva($costo, $utilidadPorcentaje);

        return [
            'costo_con_descuento' => $costo,
            'precio_unitario_sin_iva' => $precioVenta,
        ];
    }
}
