<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowDownTrayIcon,
  EnvelopeIcon,
  BanknotesIcon,
  TruckIcon,
  DocumentDuplicateIcon,
  DocumentTextIcon,
} from '@heroicons/vue/24/outline'
import {
  useCotizacionesStore,
  type Cotizacion,
  type EstadoCotizacion,
  type TipoPagoCotizacion,
} from '../stores/cotizaciones'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Badge } from '../components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '../components/ui/dialog'
import FormaPagoSelect from '../components/FormaPagoSelect.vue'

const route = useRoute()
const router = useRouter()
const cotizacionesStore = useCotizacionesStore()

const cotizacion = ref<Cotizacion | null>(null)
const cargando = ref(true)
const errorGeneral = ref<string | null>(null)

const cotizacionId = computed(() => Number(route.params.id))

function estadoVariant(estado: EstadoCotizacion) {
  return {
    borrador: 'secondary',
    enviada: 'warning',
    pagada: 'success',
    producto_entregado: 'default',
  }[estado] as 'secondary' | 'warning' | 'success' | 'default'
}

async function cargar() {
  cargando.value = true
  try {
    cotizacion.value = await cotizacionesStore.fetchOne(cotizacionId.value)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

// Una cotización admite como máximo un anticipo (ver 008-cotizaciones.md): mientras no exista,
// se puede registrar "anticipo" o "pago total"; una vez que existe, solo queda "saldo" (y solo
// mientras haya saldo pendiente).
const tieneAnticipo = computed(
  () => cotizacion.value?.pagos.some((pago) => pago.tipo === 'anticipo') ?? false,
)

// Botón "Facturar": comportamiento según el estado de la factura asociada (ver
// 008-cotizaciones.md, "Conversión a factura").
const facturarEstado = computed<'sin-factura' | 'pendiente' | 'bloqueado'>(() => {
  if (!cotizacion.value?.factura_id) return 'sin-factura'
  if (cotizacion.value.factura_estado === 'pendiente') return 'pendiente'
  return 'bloqueado'
})

function onFacturar() {
  if (!cotizacion.value) return

  if (facturarEstado.value === 'sin-factura') {
    router.push({ name: 'facturas-crear', query: { cotizacion_id: String(cotizacion.value.id) } })
  } else if (facturarEstado.value === 'pendiente') {
    router.push({ name: 'facturas-editar', params: { id: cotizacion.value.factura_id! } })
  }
}

// Enviar por correo/WhatsApp.
const mostrarEnviar = ref(false)
const canalEnvio = ref<'correo' | 'whatsapp'>('correo')
const destinatarios = ref('')
const telefono = ref('')
const enviando = ref(false)
const errorEnviar = ref<string | null>(null)
const enviadoOk = ref(false)

function abrirEnviar() {
  canalEnvio.value = 'correo'
  destinatarios.value = cotizacion.value?.cliente_correo ?? ''
  telefono.value = cotizacion.value?.cliente_telefono ?? ''
  errorEnviar.value = null
  enviadoOk.value = false
  mostrarEnviar.value = true
}

async function confirmarEnviar() {
  if (!cotizacion.value) return

  enviando.value = true
  errorEnviar.value = null
  try {
    if (canalEnvio.value === 'correo') {
      const lista = destinatarios.value
        .split(',')
        .map((d) => d.trim())
        .filter((d) => d.length > 0)
      await cotizacionesStore.enviar(cotizacion.value.id, { canal: 'correo', destinatarios: lista })
    } else {
      await cotizacionesStore.enviar(cotizacion.value.id, {
        canal: 'whatsapp',
        telefono: telefono.value,
      })
    }
    enviadoOk.value = true
    await cargar()
  } catch (err) {
    errorEnviar.value = extractErrorMessage(err)
  } finally {
    enviando.value = false
  }
}

// Descarga de PDF.
const descargandoPdf = ref(false)
const errorDescarga = ref<string | null>(null)

async function onDescargarPdf() {
  if (!cotizacion.value) return
  descargandoPdf.value = true
  errorDescarga.value = null
  try {
    await cotizacionesStore.descargarPdf(cotizacion.value)
  } catch (err) {
    errorDescarga.value = extractErrorMessage(err)
  } finally {
    descargandoPdf.value = false
  }
}

// Registro de pagos.
const mostrarPago = ref(false)
const tipoPago = ref<TipoPagoCotizacion>('anticipo')
const fechaPago = ref('')
const montoPago = ref<number | null>(null)
const formaPagoPago = ref<string | null>(null)
const registrandoPago = ref(false)
const errorPago = ref<string | null>(null)

function abrirPago(tipo: TipoPagoCotizacion) {
  tipoPago.value = tipo
  fechaPago.value = new Date().toISOString().slice(0, 10)
  montoPago.value = null
  formaPagoPago.value = null
  errorPago.value = null
  mostrarPago.value = true
}

async function confirmarPago() {
  if (!cotizacion.value || !formaPagoPago.value) return
  if (tipoPago.value === 'anticipo' && !montoPago.value) return

  registrandoPago.value = true
  errorPago.value = null
  try {
    cotizacion.value = await cotizacionesStore.registrarPago(cotizacion.value.id, {
      tipo: tipoPago.value,
      fecha_pago: fechaPago.value,
      // "saldo"/"pago_total" siempre los autocalcula el backend como el saldo pendiente; solo
      // "anticipo" manda un monto libre (ver 008-cotizaciones.md).
      monto: tipoPago.value === 'anticipo' ? montoPago.value : null,
      forma_pago: formaPagoPago.value,
    })
    mostrarPago.value = false
  } catch (err) {
    errorPago.value = extractErrorMessage(err)
  } finally {
    registrandoPago.value = false
  }
}

// Marcar como entregado.
const entregando = ref(false)
async function onEntregar() {
  if (!cotizacion.value) return
  entregando.value = true
  try {
    cotizacion.value = await cotizacionesStore.entregar(cotizacion.value.id)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    entregando.value = false
  }
}

// Duplicar.
const duplicando = ref(false)
async function onDuplicar() {
  if (!cotizacion.value) return
  duplicando.value = true
  try {
    const copia = await cotizacionesStore.duplicar(cotizacion.value.id)
    await router.push({ name: 'cotizaciones-detalle', params: { id: copia.id } })
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    duplicando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-4xl space-y-4">
      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <template v-if="!cargando && cotizacion">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 class="font-heading text-foreground text-xl font-semibold">
              Cotización {{ cotizacion.folio }}
            </h1>
            <p class="text-muted-foreground text-sm">{{ cotizacion.cliente_razon_social }}</p>
          </div>
          <Badge :variant="estadoVariant(cotizacion.estado)">
            {{ cotizacion.estado }}
          </Badge>
        </div>

        <div class="flex flex-wrap gap-2">
          <Button variant="outline" @click="abrirEnviar">
            <EnvelopeIcon class="size-4" />
            Enviar
          </Button>
          <Button variant="outline" :disabled="descargandoPdf" @click="onDescargarPdf">
            <ArrowDownTrayIcon class="size-4" />
            {{ descargandoPdf ? 'Descargando...' : 'Descargar PDF' }}
          </Button>
          <template v-if="cotizacion.estado === 'enviada'">
            <template v-if="!tieneAnticipo">
              <Button variant="outline" @click="abrirPago('anticipo')">
                <BanknotesIcon class="size-4" />
                Registrar anticipo
              </Button>
              <Button variant="outline" @click="abrirPago('pago_total')">
                <BanknotesIcon class="size-4" />
                Pago total
              </Button>
            </template>
            <Button
              v-if="tieneAnticipo && cotizacion.saldo_pendiente > 0"
              variant="outline"
              @click="abrirPago('saldo')"
            >
              <BanknotesIcon class="size-4" />
              Registrar saldo
            </Button>
          </template>
          <Button
            v-if="cotizacion.estado === 'pagada'"
            variant="outline"
            :disabled="entregando"
            @click="onEntregar"
          >
            <TruckIcon class="size-4" />
            Marcar como entregado
          </Button>
          <Button variant="outline" :disabled="duplicando" @click="onDuplicar">
            <DocumentDuplicateIcon class="size-4" />
            Duplicar
          </Button>
          <Button variant="outline" :disabled="facturarEstado === 'bloqueado'" @click="onFacturar">
            <DocumentTextIcon class="size-4" />
            {{
              facturarEstado === 'sin-factura'
                ? 'Facturar'
                : facturarEstado === 'pendiente'
                  ? 'Reintentar factura'
                  : 'Facturada'
            }}
          </Button>
        </div>

        <Alert v-if="errorDescarga" variant="destructive">
          <AlertDescription>{{ errorDescarga }}</AlertDescription>
        </Alert>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Pagos</CardTitle>
          </CardHeader>
          <CardContent class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span>Total pagado</span><span>${{ cotizacion.total_pagado.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Saldo pendiente</span><span>${{ cotizacion.saldo_pendiente.toFixed(2) }}</span>
            </div>
            <Table v-if="cotizacion.pagos.length > 0">
              <TableHeader>
                <TableRow>
                  <TableHead>Tipo</TableHead>
                  <TableHead>Fecha</TableHead>
                  <TableHead class="text-right">Monto</TableHead>
                  <TableHead>Forma de pago</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="pago in cotizacion.pagos" :key="pago.id">
                  <TableCell>{{ pago.tipo }}</TableCell>
                  <TableCell>{{ pago.fecha_pago }}</TableCell>
                  <TableCell class="text-right">${{ pago.monto.toFixed(2) }}</TableCell>
                  <TableCell>{{ pago.forma_pago }}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Artículos</CardTitle>
          </CardHeader>
          <CardContent class="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Cantidad</TableHead>
                  <TableHead>Descripción</TableHead>
                  <TableHead>Modelo</TableHead>
                  <TableHead class="text-right">P. unitario</TableHead>
                  <TableHead>IVA</TableHead>
                  <TableHead class="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="linea in cotizacion.lineas" :key="linea.id">
                  <TableCell>{{ linea.cantidad }}</TableCell>
                  <TableCell>{{ linea.descripcion }}</TableCell>
                  <TableCell>{{ linea.modelo }}</TableCell>
                  <TableCell class="text-right">${{ linea.precio_unitario.toFixed(2) }}</TableCell>
                  <TableCell>{{
                    linea.tasa_iva === 'exento' ? 'Exento' : linea.tasa_iva + '%'
                  }}</TableCell>
                  <TableCell class="text-right">${{ linea.importe.toFixed(2) }}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="ml-auto max-w-xs space-y-1 pt-6 text-sm">
            <div class="flex justify-between">
              <span>Subtotal</span><span>${{ cotizacion.subtotal.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Descuento</span><span>${{ cotizacion.total_descuento.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>IVA 16%</span><span>${{ cotizacion.total_iva_16.toFixed(2) }}</span>
            </div>
            <div class="text-foreground flex justify-between border-t pt-1 text-base font-semibold">
              <span>Total</span><span>${{ cotizacion.total.toFixed(2) }}</span>
            </div>
          </CardContent>
        </Card>

        <div>
          <Button variant="outline" @click="router.push({ name: 'cotizaciones' })"
            >Volver al listado</Button
          >
        </div>
      </template>

      <Dialog :open="mostrarEnviar" @update:open="(v) => (mostrarEnviar = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Enviar cotización</DialogTitle>
            <DialogDescription>Elige el canal de envío.</DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <div class="space-y-1.5">
              <Label>Canal</Label>
              <select
                v-model="canalEnvio"
                class="border-input h-9 w-full rounded-md border bg-transparent px-2 text-sm"
              >
                <option value="correo">Correo</option>
                <option value="whatsapp">WhatsApp</option>
              </select>
            </div>
            <div v-if="canalEnvio === 'correo'" class="space-y-1.5">
              <Label for="destinatarios">Destinatarios</Label>
              <Input
                id="destinatarios"
                v-model="destinatarios"
                placeholder="correo1@ejemplo.com, correo2@ejemplo.com"
              />
            </div>
            <div v-else class="space-y-1.5">
              <Label for="telefono">Teléfono</Label>
              <Input id="telefono" v-model="telefono" placeholder="5512345678" />
            </div>
          </div>
          <Alert v-if="errorEnviar" variant="destructive">
            <AlertDescription>{{ errorEnviar }}</AlertDescription>
          </Alert>
          <Alert v-if="enviadoOk" variant="success">
            <AlertDescription>Cotización enviada correctamente.</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="enviando" @click="mostrarEnviar = false"
              >Cerrar</Button
            >
            <Button :disabled="enviando" @click="confirmarEnviar">
              {{ enviando ? 'Enviando...' : 'Enviar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarPago" @update:open="(v) => (mostrarPago = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Registrar pago</DialogTitle>
            <DialogDescription>
              {{
                tipoPago === 'anticipo' ? 'Anticipo' : tipoPago === 'saldo' ? 'Saldo' : 'Pago total'
              }}
            </DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <div class="space-y-1.5">
              <Label for="fecha_pago">Fecha de pago</Label>
              <Input id="fecha_pago" v-model="fechaPago" type="date" />
            </div>
            <div v-if="tipoPago === 'anticipo'" class="space-y-1.5">
              <Label for="monto">Monto</Label>
              <Input
                id="monto"
                :model-value="montoPago ?? undefined"
                type="number"
                min="0.01"
                step="0.01"
                @update:model-value="(v) => (montoPago = v === '' ? null : Number(v))"
              />
            </div>
            <p v-else class="text-sm">
              Se registrará un pago de
              <strong>${{ (cotizacion?.saldo_pendiente ?? 0).toFixed(2) }}</strong>
              (saldo pendiente).
            </p>
            <div class="space-y-1.5">
              <Label>Forma de pago</Label>
              <FormaPagoSelect v-model="formaPagoPago" />
            </div>
          </div>
          <Alert v-if="errorPago" variant="destructive">
            <AlertDescription>{{ errorPago }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="registrandoPago" @click="mostrarPago = false">
              Cerrar
            </Button>
            <Button :disabled="registrandoPago" @click="confirmarPago">
              {{ registrandoPago ? 'Registrando...' : 'Registrar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
