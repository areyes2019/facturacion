<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  useFacturasStore,
  type FacturaPayload,
  type TasaIva,
  type TipoDescuento,
} from '../stores/facturas'
import { useCotizacionesStore } from '../stores/cotizaciones'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../components/ui/table'
import { TrashIcon } from '@heroicons/vue/24/outline'
import AppLayout from '../layouts/AppLayout.vue'
import ClienteCombobox from '../components/ClienteCombobox.vue'
import UsoCfdiCombobox from '../components/UsoCfdiCombobox.vue'
import FormaPagoSelect from '../components/FormaPagoSelect.vue'
import MetodoPagoSelect from '../components/MetodoPagoSelect.vue'
import TasaIvaSelect from '../components/TasaIvaSelect.vue'
import ArticuloBuscador, { type ArticuloResultado } from '../components/ArticuloBuscador.vue'

interface LineaEditable {
  articulo_id: number
  cantidad: number
  descripcion: string
  modelo: string
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
}

const route = useRoute()
const router = useRouter()
const facturas = useFacturasStore()
const cotizacionesStore = useCotizacionesStore()

const facturaId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => facturaId.value !== null)

// Al facturar desde una cotización (ver 008-cotizaciones.md, "Conversión a factura"), el
// formulario se precarga con el cliente (fijo, no editable) y las líneas de la cotización.
const cotizacionId = computed(() => {
  const id = route.query.cotizacion_id
  return typeof id === 'string' ? Number(id) : null
})
const clienteFijoNombre = ref<string | null>(null)

const form = reactive({
  cliente_id: null as number | null,
  uso_cfdi: null as string | null,
  forma_pago: null as string | null,
  metodo_pago: null as string | null,
  descuento_global_tipo: null as TipoDescuento | null,
  descuento_global_valor: null as number | null,
})

const lineas = ref<LineaEditable[]>([])

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

function tasaIvaFactor(tasa: TasaIva): number {
  return tasa === '16' ? 0.16 : 0
}

function importeNetoLinea(linea: LineaEditable): number {
  const bruto = linea.cantidad * linea.precio_unitario
  const descuento =
    linea.descuento_tipo === 'porcentaje'
      ? bruto * ((linea.descuento_valor ?? 0) / 100)
      : (linea.descuento_valor ?? 0)
  return Math.round((bruto - descuento) * 100) / 100
}

const subtotal = computed(
  () => Math.round(lineas.value.reduce((suma, l) => suma + importeNetoLinea(l), 0) * 100) / 100,
)

const descuentoGlobalMonto = computed(() => {
  if (!form.descuento_global_tipo || !form.descuento_global_valor) return 0
  return form.descuento_global_tipo === 'porcentaje'
    ? Math.round(subtotal.value * (form.descuento_global_valor / 100) * 100) / 100
    : form.descuento_global_valor
})

/**
 * Reparte el descuento global entre las líneas, proporcional a su importe neto, con la última
 * línea absorbiendo el residuo de redondeo. Debe replicar exactamente
 * FacturaTotalesCalculator::prorratear() del backend (ver 007-facturacion.md, "Cálculo de
 * totales e IVA con descuento global") — si se toca uno, hay que tocar el otro.
 */
const prorrateoDescuentoGlobal = computed(() => {
  const importes = lineas.value.map((l) => importeNetoLinea(l))
  const descuentoGlobal = descuentoGlobalMonto.value
  const total = importes.length

  if (descuentoGlobal <= 0 || subtotal.value <= 0 || total === 0) {
    return importes.map(() => 0)
  }

  const partes: number[] = []
  let acumulado = 0
  const ultimo = total - 1

  importes.forEach((importe, i) => {
    if (i === ultimo) {
      partes.push(Math.round((descuentoGlobal - acumulado) * 100) / 100)
      return
    }

    const parte = Math.round(((descuentoGlobal * importe) / subtotal.value) * 100) / 100
    partes.push(parte)
    acumulado += parte
  })

  return partes
})

function ivaLinea(linea: LineaEditable, parteDescuentoGlobal: number): number {
  const importeNetoFinal = importeNetoLinea(linea) - parteDescuentoGlobal
  return Math.round(importeNetoFinal * tasaIvaFactor(linea.tasa_iva) * 100) / 100
}

const totalIva16 = computed(
  () =>
    Math.round(
      lineas.value.reduce((suma, l, i) => {
        if (l.tasa_iva !== '16') return suma
        return suma + ivaLinea(l, prorrateoDescuentoGlobal.value[i])
      }, 0) * 100,
    ) / 100,
)

const total = computed(
  () => Math.round((subtotal.value - descuentoGlobalMonto.value + totalIva16.value) * 100) / 100,
)

onMounted(async () => {
  if (facturaId.value) {
    cargando.value = true
    try {
      const factura = await facturas.fetchOne(facturaId.value)
      form.cliente_id = factura.cliente_id
      form.uso_cfdi = factura.uso_cfdi
      form.forma_pago = factura.forma_pago
      form.metodo_pago = factura.metodo_pago
      form.descuento_global_tipo = factura.descuento_global_tipo
      form.descuento_global_valor = factura.descuento_global_valor
      lineas.value = factura.lineas.map((l) => ({
        articulo_id: l.articulo_id,
        cantidad: l.cantidad,
        descripcion: l.descripcion,
        modelo: l.modelo,
        precio_unitario: l.precio_unitario,
        descuento_tipo: l.descuento_tipo,
        descuento_valor: l.descuento_valor,
        tasa_iva: l.tasa_iva,
      }))
      if (factura.error_timbrado) {
        errorGeneral.value = factura.error_timbrado
      }
    } catch (err) {
      errorGeneral.value = extractErrorMessage(err)
    } finally {
      cargando.value = false
    }
    return
  }

  if (cotizacionId.value) {
    cargando.value = true
    try {
      const cotizacion = await cotizacionesStore.fetchOne(cotizacionId.value)
      form.cliente_id = cotizacion.cliente_id
      clienteFijoNombre.value = cotizacion.cliente_razon_social
      form.descuento_global_tipo = cotizacion.descuento_global_tipo
      form.descuento_global_valor = cotizacion.descuento_global_valor
      lineas.value = cotizacion.lineas.map((l) => ({
        articulo_id: l.articulo_id,
        cantidad: l.cantidad,
        descripcion: l.descripcion,
        modelo: l.modelo,
        precio_unitario: l.precio_unitario,
        descuento_tipo: l.descuento_tipo,
        descuento_valor: l.descuento_valor,
        tasa_iva: l.tasa_iva,
      }))
    } catch (err) {
      errorGeneral.value = extractErrorMessage(err)
    } finally {
      cargando.value = false
    }
  }
})

function onArticuloSeleccionado(articulo: ArticuloResultado) {
  lineas.value.push({
    articulo_id: articulo.id,
    cantidad: 1,
    descripcion: articulo.nombre,
    modelo: articulo.modelo,
    precio_unitario: articulo.precio_unitario_sin_iva,
    descuento_tipo: null,
    descuento_valor: null,
    tasa_iva: '16',
  })
}

function quitarLinea(indice: number) {
  lineas.value.splice(indice, 1)
}

async function onSubmit() {
  guardando.value = true
  errorGeneral.value = null
  erroresPorCampo.value = {}

  const payload: FacturaPayload = {
    cliente_id: form.cliente_id,
    uso_cfdi: form.uso_cfdi,
    forma_pago: form.forma_pago,
    metodo_pago: form.metodo_pago as FacturaPayload['metodo_pago'],
    descuento_global_tipo: form.descuento_global_tipo,
    descuento_global_valor: form.descuento_global_valor,
    lineas: lineas.value,
    total: total.value,
    cotizacion_id: !esEdicion.value ? cotizacionId.value : undefined,
  }

  try {
    const factura =
      esEdicion.value && facturaId.value
        ? await facturas.update(facturaId.value, payload)
        : await facturas.create(payload)

    if (factura.estado === 'timbrada') {
      await router.push({ name: 'facturas-detalle', params: { id: factura.id } })
    } else {
      errorGeneral.value = factura.error_timbrado ?? 'No se pudo timbrar la factura.'
      if (!esEdicion.value) {
        await router.push({ name: 'facturas-editar', params: { id: factura.id } })
      }
    }
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-4xl space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">
        {{ esEdicion ? 'Reintentar factura' : 'Nueva factura' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Cliente y datos fiscales</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>Cliente</Label>
              <ClienteCombobox v-if="!clienteFijoNombre" v-model="form.cliente_id" />
              <p
                v-else
                class="border-input bg-muted text-muted-foreground rounded-md border px-3 py-2 text-sm"
              >
                {{ clienteFijoNombre }} (fijo, viene de la cotización)
              </p>
              <p v-if="erroresPorCampo.cliente_id" class="text-destructive text-sm">
                {{ erroresPorCampo.cliente_id }}
              </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
              <div class="space-y-1.5">
                <Label>Uso de CFDI</Label>
                <UsoCfdiCombobox v-model="form.uso_cfdi" />
                <p v-if="erroresPorCampo.uso_cfdi" class="text-destructive text-sm">
                  {{ erroresPorCampo.uso_cfdi }}
                </p>
              </div>
              <div class="space-y-1.5">
                <Label>Forma de pago</Label>
                <FormaPagoSelect v-model="form.forma_pago" />
                <p v-if="erroresPorCampo.forma_pago" class="text-destructive text-sm">
                  {{ erroresPorCampo.forma_pago }}
                </p>
              </div>
              <div class="space-y-1.5">
                <Label>Método de pago</Label>
                <MetodoPagoSelect v-model="form.metodo_pago" />
                <p v-if="erroresPorCampo.metodo_pago" class="text-destructive text-sm">
                  {{ erroresPorCampo.metodo_pago }}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Artículos</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <ArticuloBuscador @seleccionar="onArticuloSeleccionado" />
            <p v-if="erroresPorCampo.lineas" class="text-destructive text-sm">
              {{ erroresPorCampo.lineas }}
            </p>

            <div class="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead class="w-20">Cantidad</TableHead>
                    <TableHead>Descripción</TableHead>
                    <TableHead>Modelo</TableHead>
                    <TableHead class="w-28">P. unitario</TableHead>
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
                      <Input v-model.number="linea.cantidad" type="number" min="1" step="1" />
                    </TableCell>
                    <TableCell>
                      <Input v-model="linea.descripcion" />
                    </TableCell>
                    <TableCell>
                      <Input v-model="linea.modelo" />
                    </TableCell>
                    <TableCell>
                      <Input
                        v-model.number="linea.precio_unitario"
                        type="number"
                        min="0.01"
                        step="0.01"
                      />
                    </TableCell>
                    <TableCell class="flex gap-1">
                      <select
                        v-model="linea.descuento_tipo"
                        class="border-input h-9 rounded-md border bg-transparent px-1 text-xs"
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
                        @update:model-value="
                          (v) => (linea.descuento_valor = v === '' ? null : Number(v))
                        "
                      />
                    </TableCell>
                    <TableCell>
                      <TasaIvaSelect v-model="linea.tasa_iva" />
                    </TableCell>
                    <TableCell class="text-right"
                      >${{ importeNetoLinea(linea).toFixed(2) }}</TableCell
                    >
                    <TableCell>
                      <Button
                        type="button"
                        variant="outline"
                        size="icon-sm"
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
                  v-model="form.descuento_global_tipo"
                  class="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                >
                  <option :value="null">Sin descuento</option>
                  <option value="porcentaje">Porcentaje</option>
                  <option value="monto">Monto fijo</option>
                </select>
              </div>
              <div class="space-y-1.5">
                <Label>Valor</Label>
                <Input
                  :model-value="form.descuento_global_valor ?? undefined"
                  type="number"
                  min="0"
                  step="0.01"
                  @update:model-value="
                    (v) => (form.descuento_global_valor = v === '' ? null : Number(v))
                  "
                />
              </div>
            </div>

            <div class="ml-auto max-w-xs space-y-1 text-sm">
              <div class="flex justify-between">
                <span>Subtotal</span><span>${{ subtotal.toFixed(2) }}</span>
              </div>
              <div class="flex justify-between">
                <span>Descuento global</span><span>${{ descuentoGlobalMonto.toFixed(2) }}</span>
              </div>
              <div class="flex justify-between">
                <span>IVA 16%</span><span>${{ totalIva16.toFixed(2) }}</span>
              </div>
              <div
                class="text-foreground flex justify-between border-t pt-1 text-base font-semibold"
              >
                <span>Total</span><span>${{ total.toFixed(2) }}</span>
              </div>
            </div>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'facturas' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando || lineas.length === 0">
            {{ guardando ? 'Generando...' : 'Generar y timbrar' }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
