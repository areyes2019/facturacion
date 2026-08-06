<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCotizacionesStore, type CotizacionPayload } from '../stores/cotizaciones'
import type { TipoDescuento } from '../stores/facturas'
import { calcularTotales } from '../lib/totalesDocumento'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import AppLayout from '../layouts/AppLayout.vue'
import ClienteCombobox from '../components/ClienteCombobox.vue'
import DocumentoLineas, { type LineaEditable } from '../components/DocumentoLineas.vue'

const route = useRoute()
const router = useRouter()
const cotizaciones = useCotizacionesStore()

const cotizacionId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => cotizacionId.value !== null)

const form = reactive({
  cliente_id: null as number | null,
  descuento_global_tipo: null as TipoDescuento | null,
  descuento_global_valor: null as number | null,
})

const lineas = ref<LineaEditable[]>([])

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

// Mismo módulo de cálculo que usa el componente de líneas para su desglose y que replica al
// backend, atado a él por el fixture compartido (ver 012-ordenes-compra.md, adición técnica 42).
const totales = computed(() =>
  calcularTotales(lineas.value, form.descuento_global_tipo, form.descuento_global_valor),
)

onMounted(async () => {
  if (!cotizacionId.value) return

  cargando.value = true
  try {
    const cotizacion = await cotizaciones.fetchOne(cotizacionId.value)
    form.cliente_id = cotizacion.cliente_id
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
})

async function onSubmit() {
  guardando.value = true
  errorGeneral.value = null
  erroresPorCampo.value = {}

  const payload: CotizacionPayload = {
    cliente_id: form.cliente_id,
    descuento_global_tipo: form.descuento_global_tipo,
    descuento_global_valor: form.descuento_global_valor,
    lineas: lineas.value,
    total: totales.value.total,
  }

  try {
    const cotizacion =
      esEdicion.value && cotizacionId.value
        ? await cotizaciones.update(cotizacionId.value, payload)
        : await cotizaciones.create(payload)

    await router.push({ name: 'cotizaciones-detalle', params: { id: cotizacion.id } })
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
        {{ esEdicion ? 'Editar cotización' : 'Nueva cotización' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Cliente</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>Cliente</Label>
              <ClienteCombobox v-model="form.cliente_id" />
              <p v-if="erroresPorCampo.cliente_id" class="text-destructive text-sm">
                {{ erroresPorCampo.cliente_id }}
              </p>
            </div>
          </CardContent>
        </Card>

        <DocumentoLineas
          v-model:lineas="lineas"
          v-model:descuento-global-tipo="form.descuento_global_tipo"
          v-model:descuento-global-valor="form.descuento_global_valor"
          :error-lineas="erroresPorCampo.lineas"
        />

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'cotizaciones' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando || lineas.length === 0">
            {{ guardando ? 'Guardando...' : 'Guardar cotización' }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
