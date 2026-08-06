<script setup lang="ts">
import { computed } from 'vue'
import { TrashIcon } from '@heroicons/vue/24/outline'
import type { TasaIva, TipoDescuento } from '../stores/facturas'
import { calcularTotales, importeNetoLinea } from '../lib/totalesDocumento'
import { Button } from './ui/button'
import { Card, CardContent, CardHeader, CardTitle } from './ui/card'
import { Input } from './ui/input'
import { Label } from './ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './ui/table'
import TasaIvaSelect from './TasaIvaSelect.vue'
import ArticuloBuscador, { type ArticuloResultado } from './ArticuloBuscador.vue'

export interface LineaEditable {
  articulo_id: number
  cantidad: number
  descripcion: string
  modelo: string
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
}

/**
 * Tabla de captura de líneas compartida por Factura (007), Cotización (008) y Orden de compra
 * (012). Antes existía una copia por vista; la tercera fue la que justificó extraerla (ver
 * 012-ordenes-compra.md, adición técnica 40).
 *
 * Lo único que difiere entre documentos se parametriza: de dónde se precarga el precio del
 * artículo, qué artículos ofrece el buscador y las etiquetas de la columna de precio.
 */
const props = withDefaults(
  defineProps<{
    /** `venta` precarga el precio de venta del artículo; `costo`, lo que le pagas al proveedor. */
    origenPrecio?: 'venta' | 'costo'
    /** Limita el buscador a los artículos de catálogos de este proveedor. */
    proveedorId?: number | null
    /** Bloquea toda la captura (documento ya no editable). */
    soloLectura?: boolean
    errorLineas?: string | null
  }>(),
  {
    origenPrecio: 'venta',
    proveedorId: null,
    soloLectura: false,
    errorLineas: null,
  },
)

const lineas = defineModel<LineaEditable[]>('lineas', { required: true })
const descuentoGlobalTipo = defineModel<TipoDescuento | null>('descuentoGlobalTipo', {
  default: null,
})
const descuentoGlobalValor = defineModel<number | null>('descuentoGlobalValor', { default: null })

const etiquetaPrecio = computed(() =>
  props.origenPrecio === 'costo' ? 'Costo unit.' : 'P. unitario',
)

const totales = computed(() =>
  calcularTotales(lineas.value, descuentoGlobalTipo.value, descuentoGlobalValor.value),
)

function onArticuloSeleccionado(articulo: ArticuloResultado) {
  lineas.value.push({
    articulo_id: articulo.id,
    cantidad: 1,
    descripcion: articulo.nombre,
    modelo: articulo.modelo,
    // El precio precargado es editable; capturar otro no modifica el catálogo (ver 012,
    // supuestos #8 y #10).
    precio_unitario:
      props.origenPrecio === 'costo'
        ? articulo.costo_con_descuento
        : articulo.precio_unitario_sin_iva,
    descuento_tipo: null,
    descuento_valor: null,
    tasa_iva: '16',
  })
}

function quitarLinea(indice: number) {
  lineas.value.splice(indice, 1)
}
</script>

<template>
  <div class="space-y-6">
    <Card>
      <CardHeader>
        <CardTitle class="text-base">Artículos</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <ArticuloBuscador
          v-if="!soloLectura"
          :proveedor-id="proveedorId"
          :origen-precio="origenPrecio"
          @seleccionar="onArticuloSeleccionado"
        />
        <p v-if="errorLineas" class="text-destructive text-sm">{{ errorLineas }}</p>

        <div class="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead class="w-20">Cantidad</TableHead>
                <TableHead>Descripción</TableHead>
                <TableHead>Modelo</TableHead>
                <TableHead class="w-28">{{ etiquetaPrecio }}</TableHead>
                <TableHead class="w-32">Descuento</TableHead>
                <TableHead class="w-24">IVA</TableHead>
                <TableHead class="w-24 text-right">Total</TableHead>
                <TableHead class="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="lineas.length === 0">
                <TableCell colspan="8" class="text-muted-foreground py-6 text-center">
                  Agrega al menos un artículo.
                </TableCell>
              </TableRow>
              <TableRow v-for="(linea, i) in lineas" :key="i">
                <TableCell>
                  <Input
                    v-model.number="linea.cantidad"
                    type="number"
                    min="1"
                    step="1"
                    :disabled="soloLectura"
                  />
                </TableCell>
                <TableCell>
                  <Input v-model="linea.descripcion" :disabled="soloLectura" />
                </TableCell>
                <TableCell>
                  <Input v-model="linea.modelo" :disabled="soloLectura" />
                </TableCell>
                <TableCell>
                  <Input
                    v-model.number="linea.precio_unitario"
                    type="number"
                    min="0.01"
                    step="0.01"
                    :disabled="soloLectura"
                  />
                </TableCell>
                <TableCell class="flex gap-1">
                  <select
                    v-model="linea.descuento_tipo"
                    class="border-input h-9 rounded-md border bg-transparent px-1 text-xs"
                    :disabled="soloLectura"
                  >
                    <option :value="null">—</option>
                    <option value="porcentaje">%</option>
                    <option value="monto">$</option>
                  </select>
                  <Input
                    :model-value="linea.descuento_valor ?? undefined"
                    type="number"
                    min="0"
                    step="0.01"
                    class="w-16"
                    :disabled="soloLectura"
                    @update:model-value="
                      (v) => (linea.descuento_valor = v === '' ? null : Number(v))
                    "
                  />
                </TableCell>
                <TableCell>
                  <TasaIvaSelect v-model="linea.tasa_iva" :disabled="soloLectura" />
                </TableCell>
                <TableCell class="text-right">${{ importeNetoLinea(linea).toFixed(2) }}</TableCell>
                <TableCell>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon-sm"
                    :disabled="soloLectura"
                    @click="quitarLinea(i)"
                  >
                    <TrashIcon class="size-4" />
                    <span class="sr-only">Quitar</span>
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle class="text-base">Descuento global y totales</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="flex gap-2">
          <div class="space-y-1.5">
            <Label>Tipo de descuento global</Label>
            <select
              v-model="descuentoGlobalTipo"
              class="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
              :disabled="soloLectura"
            >
              <option :value="null">Sin descuento</option>
              <option value="porcentaje">Porcentaje</option>
              <option value="monto">Monto fijo</option>
            </select>
          </div>
          <div class="space-y-1.5">
            <Label>Valor</Label>
            <Input
              :model-value="descuentoGlobalValor ?? undefined"
              type="number"
              min="0"
              step="0.01"
              :disabled="soloLectura"
              @update:model-value="(v) => (descuentoGlobalValor = v === '' ? null : Number(v))"
            />
          </div>
        </div>

        <div class="ml-auto max-w-xs space-y-1 text-sm">
          <div class="flex justify-between">
            <span>Subtotal</span><span>${{ totales.subtotal.toFixed(2) }}</span>
          </div>
          <div class="flex justify-between">
            <span>Descuento global</span><span>${{ totales.descuento_global.toFixed(2) }}</span>
          </div>
          <div class="flex justify-between">
            <span>IVA 16%</span><span>${{ totales.total_iva_16.toFixed(2) }}</span>
          </div>
          <div class="text-foreground flex justify-between border-t pt-1 text-base font-semibold">
            <span>Total</span><span>${{ totales.total.toFixed(2) }}</span>
          </div>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
