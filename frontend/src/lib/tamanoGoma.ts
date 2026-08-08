import type { Configuracion } from '../stores/configuracion'

/**
 * Categorías de tamaño de goma (ver specs/014-costo-elaboracion-goma.md).
 *
 * Van embebidas en el frontend, sin endpoint de catálogo, igual que las cuatro opciones de
 * `objeto_imp` en 006: son tres valores fijos que el backend valida contra su propio enum.
 *
 * Las medidas son texto de ayuda. El sistema no captura ancho ni alto y no deduce la categoría de
 * ninguna medida: la elige el usuario a ojo.
 */
export interface TamanoGoma {
  /** Valor que se persiste en `articulos.tamano_goma`. */
  valor: 'chica' | 'mediana' | 'grande'
  etiqueta: string
  medida: string
  /** Clave del ajuste global que guarda su costo. */
  clave: keyof Configuracion
}

export const TAMANOS_GOMA: TamanoGoma[] = [
  {
    valor: 'chica',
    etiqueta: 'Chica',
    medida: 'Hasta 38 × 14 mm — bolsillo, fechador',
    clave: 'costo_goma_chica',
  },
  {
    valor: 'mediana',
    etiqueta: 'Mediana',
    medida: 'Hasta 58 × 22 mm — sello de datos estándar',
    clave: 'costo_goma_mediana',
  },
  {
    valor: 'grande',
    etiqueta: 'Grande',
    medida: 'Hasta 75 × 38 mm o superior',
    clave: 'costo_goma_grande',
  },
]

/** Etiqueta corta para el resumen del formulario: "Goma mediana". */
export function etiquetaGoma(valor: string | null | undefined): string | null {
  const tamano = TAMANOS_GOMA.find((candidato) => candidato.valor === valor)

  return tamano ? `Goma ${tamano.etiqueta.toLowerCase()}` : null
}
