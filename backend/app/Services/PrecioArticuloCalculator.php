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
 *     ↓ × (1 + utilidad_efectiva / 100)                markup sobre el costo
 *   precio_unitario_sin_iva   (calculado, persistido)  $225.00
 *
 * El porcentaje se interpreta como markup sobre el costo: un 25% significa "quiero ganar el 25% de
 * lo que me costó", de ahí la multiplicación.
 *
 * Los casos frontera de esta cadena viven en shared/fixtures/precios-articulos.json, compartidos
 * con la implementación espejo en TypeScript (frontend/src/lib/precioArticulo.ts).
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
     * Techo a 2 decimales, redondeando DESPUÉS de escalar a centavos.
     *
     * El orden es la parte sustancial de la definición y no es intercambiable:
     *
     *   - `ceil($valor * 100) / 100` falla porque el producto costo × (1 + % / 100) arrastra error
     *     de representación en punto flotante.
     *   - `ceil(round($valor, 4) * 100) / 100` tampoco sirve: corrige el valor pero vuelve a
     *     introducir error en la multiplicación por 100 (0.07 * 100 = 7.000000000000001), y termina
     *     cobrando un centavo de más. Con costo $15.40 y 5% el resultado exacto es $16.17 y esa
     *     variante devuelve $16.18.
     *   - Redondear a 6 decimales sobre el valor ya expresado en centavos elimina ambas fuentes de
     *     error. Verificado contra aritmética entera de centavos sobre 4.2 millones de
     *     combinaciones, sin desviaciones (ver 011).
     */
    public static function techo2(float $valor): float
    {
        return ceil(round($valor * 100, 6)) / 100;
    }

    /**
     * Costo con descuento = redondeo2(precio_proveedor × (1 − descuento / 100)).
     */
    public static function costoConDescuento(float $precioProveedor, float $descuento): float
    {
        return self::redondeo2($precioProveedor * (1 - $descuento / 100));
    }

    /**
     * Precio de venta sin IVA = techo2(costo_con_descuento × (1 + utilidad_efectiva / 100)).
     */
    public static function precioVentaSinIva(float $costoConDescuento, float $utilidadPorcentaje): float
    {
        return self::techo2($costoConDescuento * (1 + $utilidadPorcentaje / 100));
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
