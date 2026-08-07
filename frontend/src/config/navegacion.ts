import type { FunctionalComponent } from 'vue'
import {
  ArrowsRightLeftIcon,
  BanknotesIcon,
  ChartBarIcon,
  ClipboardDocumentListIcon,
  CubeIcon,
  DocumentDuplicateIcon,
  DocumentTextIcon,
  RectangleStackIcon,
  ScaleIcon,
  ShoppingCartIcon,
  TagIcon,
  TruckIcon,
  UsersIcon,
  WalletIcon,
} from '@heroicons/vue/24/outline'

/**
 * Definición declarativa de la navegación principal (spec 013).
 *
 * Esta es la única fuente de verdad del menú: `AppLayout.vue` solo la recorre.
 * Toda funcionalidad nueva que necesite entrada de menú se agrega acá, dentro
 * del grupo que le corresponda — no como un enlace suelto en el header.
 */

export interface OpcionNavegacion {
  /** `name` de la ruta a la que navega la opción. */
  name: string
  etiqueta: string
  icono: FunctionalComponent
  /**
   * `name` de las rutas hermanas (crear / editar / detalle) que no aparecen en
   * el menú pero mantienen resaltados la opción y su grupo.
   */
  rutasRelacionadas?: string[]
}

export interface GrupoNavegacion {
  /** Identificador estable del grupo; es el `value` del NavigationMenuItem. */
  id: string
  etiqueta: string
  icono: FunctionalComponent
  opciones: OpcionNavegacion[]
}

export const gruposNavegacion: GrupoNavegacion[] = [
  {
    id: 'ventas',
    etiqueta: 'Ventas',
    icono: BanknotesIcon,
    opciones: [
      {
        name: 'facturas',
        etiqueta: 'Facturas',
        icono: DocumentTextIcon,
        rutasRelacionadas: ['facturas-crear', 'facturas-editar', 'facturas-detalle'],
      },
      {
        name: 'cotizaciones',
        etiqueta: 'Cotizaciones',
        icono: DocumentDuplicateIcon,
        rutasRelacionadas: ['cotizaciones-crear', 'cotizaciones-editar', 'cotizaciones-detalle'],
      },
      {
        name: 'clientes',
        etiqueta: 'Clientes',
        icono: UsersIcon,
        rutasRelacionadas: ['clientes-crear', 'clientes-editar'],
      },
    ],
  },
  {
    id: 'compras',
    etiqueta: 'Compras',
    icono: ShoppingCartIcon,
    opciones: [
      {
        name: 'ordenes-compra',
        etiqueta: 'Órdenes de compra',
        icono: ClipboardDocumentListIcon,
        rutasRelacionadas: [
          'ordenes-compra-crear',
          'ordenes-compra-editar',
          'ordenes-compra-detalle',
        ],
      },
      {
        name: 'proveedores',
        etiqueta: 'Proveedores',
        icono: TruckIcon,
        rutasRelacionadas: ['proveedores-crear', 'proveedores-editar'],
      },
    ],
  },
  {
    id: 'inventario',
    etiqueta: 'Inventario',
    icono: CubeIcon,
    opciones: [
      {
        name: 'articulos',
        etiqueta: 'Artículos',
        icono: TagIcon,
        rutasRelacionadas: ['articulos-crear', 'articulos-editar'],
      },
      {
        name: 'catalogos',
        etiqueta: 'Catálogos',
        icono: RectangleStackIcon,
        rutasRelacionadas: ['catalogos-crear', 'catalogos-editar'],
      },
    ],
  },
  {
    // El nombre visible es "Contabilidad"; el módulo, sus rutas (/tesoreria/...)
    // y sus clases siguen llamándose Tesorería (ver 010-tesoreria.md).
    id: 'contabilidad',
    etiqueta: 'Contabilidad',
    icono: ChartBarIcon,
    opciones: [
      {
        name: 'cuentas',
        etiqueta: 'Cuentas',
        icono: WalletIcon,
        rutasRelacionadas: ['cuentas-crear', 'cuentas-editar'],
      },
      { name: 'movimientos', etiqueta: 'Movimientos', icono: ArrowsRightLeftIcon },
      { name: 'saldos', etiqueta: 'Saldos', icono: ScaleIcon },
    ],
  },
]

/** Todos los `name` de ruta que mantienen resaltada a una opción. */
export function nombresDeRutaDeOpcion(opcion: OpcionNavegacion): string[] {
  return [opcion.name, ...(opcion.rutasRelacionadas ?? [])]
}

/** Todos los `name` de ruta que mantienen resaltado a un grupo. */
export function nombresDeRutaDeGrupo(grupo: GrupoNavegacion): string[] {
  return grupo.opciones.flatMap(nombresDeRutaDeOpcion)
}
