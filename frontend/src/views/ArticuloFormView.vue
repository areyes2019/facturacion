<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useArticulosStore, type ArticuloPayload } from '../stores/articulos'
import { useCatalogosStore } from '../stores/catalogos'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import CatalogoSelect from '../components/CatalogoSelect.vue'
import ClaveProdServCombobox from '../components/ClaveProdServCombobox.vue'
import ClaveUnidadCombobox from '../components/ClaveUnidadCombobox.vue'
import ObjetoImpuestoSelect from '../components/ObjetoImpuestoSelect.vue'
import AppLayout from '../layouts/AppLayout.vue'

const route = useRoute()
const router = useRouter()
const articulos = useArticulosStore()
const catalogos = useCatalogosStore()

const articuloId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => articuloId.value !== null)

const form = reactive({
  catalogo_id: null as number | null,
  nombre: '',
  modelo: '',
  clave_prod_serv: null as string | null,
  clave_unidad: null as string | null,
  objeto_imp: null as string | null,
  precio_proveedor: '' as string,
  utilidad_porcentaje: '' as string,
})

// Descuento y utilidad del catálogo seleccionado, para mostrar los precios en vivo (ver
// 009-catalogos.md y 011-precio-proveedor-utilidad.md); se consultan cada vez que cambia el
// catálogo porque el combobox solo expone id/nombre, no el descuento ni la utilidad.
const descuentoCatalogo = ref(0)
const utilidadCatalogo = ref(0)
watch(
  () => form.catalogo_id,
  async (catalogoId) => {
    if (!catalogoId) {
      descuentoCatalogo.value = 0
      utilidadCatalogo.value = 0
      return
    }
    try {
      const catalogo = await catalogos.fetchOne(catalogoId)
      descuentoCatalogo.value = catalogo.descuento
      utilidadCatalogo.value = catalogo.utilidad_porcentaje
    } catch {
      descuentoCatalogo.value = 0
      utilidadCatalogo.value = 0
    }
  },
)

// Utilidad efectiva: la del artículo si se capturó, si no la del catálogo.
const utilidadEfectiva = computed(() => {
  const utilidad = parseFloat(form.utilidad_porcentaje)
  if (Number.isFinite(utilidad)) return utilidad
  return utilidadCatalogo.value
})

// Costo con descuento del catálogo aplicado al precio de proveedor.
const costoConDescuento = computed(() => {
  const precio = parseFloat(form.precio_proveedor)
  if (!Number.isFinite(precio)) return '0.00'
  return (precio * (1 - descuentoCatalogo.value / 100)).toFixed(2)
})

// Precio unitario sin IVA = costo con descuento + utilidad.
const precioUnitarioSinIva = computed(() => {
  const costo = parseFloat(costoConDescuento.value)
  if (!Number.isFinite(costo)) return '0.00'
  return (costo * (1 + utilidadEfectiva.value / 100)).toFixed(2)
})

const precioConIva = computed(() => {
  const precio = parseFloat(precioUnitarioSinIva.value)
  return Number.isFinite(precio) ? (precio * 1.16).toFixed(2) : '0.00'
})

const utilidadMonto = computed(() => {
  const costo = parseFloat(costoConDescuento.value)
  const precio = parseFloat(precioUnitarioSinIva.value)
  if (!Number.isFinite(costo) || !Number.isFinite(precio)) return '0.00'
  return (precio - costo).toFixed(2)
})

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

onMounted(async () => {
  if (!articuloId.value) return

  cargando.value = true
  try {
    const articulo = await articulos.fetchOne(articuloId.value)
    form.catalogo_id = articulo.catalogo_id
    form.nombre = articulo.nombre
    form.modelo = articulo.modelo
    form.clave_prod_serv = articulo.clave_prod_serv
    form.clave_unidad = articulo.clave_unidad
    form.objeto_imp = articulo.objeto_imp
    form.precio_proveedor = articulo.precio_proveedor.toString()
    form.utilidad_porcentaje =
      articulo.utilidad_porcentaje !== null ? articulo.utilidad_porcentaje.toString() : ''
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

  const payload: ArticuloPayload = {
    catalogo_id: form.catalogo_id,
    nombre: form.nombre,
    modelo: form.modelo,
    clave_prod_serv: form.clave_prod_serv,
    clave_unidad: form.clave_unidad,
    objeto_imp: form.objeto_imp,
    precio_proveedor: form.precio_proveedor ? parseFloat(form.precio_proveedor) : null,
    utilidad_porcentaje: form.utilidad_porcentaje ? parseFloat(form.utilidad_porcentaje) : null,
  }

  try {
    if (esEdicion.value && articuloId.value) {
      await articulos.update(articuloId.value, payload)
    } else {
      await articulos.create(payload)
    }

    await router.push({ name: 'articulos' })
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
    <div class="mx-auto max-w-2xl space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">
        {{ esEdicion ? 'Editar artículo' : 'Nuevo artículo' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos del artículo</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>Catálogo</Label>
              <CatalogoSelect v-model="form.catalogo_id" />
              <p v-if="erroresPorCampo.catalogo_id" class="text-destructive text-sm">
                {{ erroresPorCampo.catalogo_id }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="nombre">Nombre</Label>
              <Input id="nombre" v-model="form.nombre" required />
              <p v-if="erroresPorCampo.nombre" class="text-destructive text-sm">
                {{ erroresPorCampo.nombre }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="modelo">Modelo</Label>
              <Input id="modelo" v-model="form.modelo" required />
              <p v-if="erroresPorCampo.modelo" class="text-destructive text-sm">
                {{ erroresPorCampo.modelo }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Clave de producto o servicio (SAT)</Label>
              <ClaveProdServCombobox v-model="form.clave_prod_serv" />
              <p v-if="erroresPorCampo.clave_prod_serv" class="text-destructive text-sm">
                {{ erroresPorCampo.clave_prod_serv }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Clave de unidad (SAT)</Label>
              <ClaveUnidadCombobox v-model="form.clave_unidad" />
              <p v-if="erroresPorCampo.clave_unidad" class="text-destructive text-sm">
                {{ erroresPorCampo.clave_unidad }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Objeto de impuesto (SAT)</Label>
              <ObjetoImpuestoSelect v-model="form.objeto_imp" />
              <p v-if="erroresPorCampo.objeto_imp" class="text-destructive text-sm">
                {{ erroresPorCampo.objeto_imp }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="precio_proveedor">Precio de proveedor (MXN)</Label>
              <Input
                id="precio_proveedor"
                v-model="form.precio_proveedor"
                type="number"
                min="0.01"
                step="0.01"
                required
              />
              <p class="text-muted-foreground text-sm">
                Costo con descuento del catálogo ({{ descuentoCatalogo }}%): ${{
                  costoConDescuento
                }}
              </p>
              <p v-if="erroresPorCampo.precio_proveedor" class="text-destructive text-sm">
                {{ erroresPorCampo.precio_proveedor }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="utilidad_porcentaje">Utilidad (%)</Label>
              <Input
                id="utilidad_porcentaje"
                v-model="form.utilidad_porcentaje"
                type="number"
                min="0"
                step="0.01"
                placeholder="Usa la utilidad del catálogo"
              />
              <p class="text-muted-foreground text-sm">
                Si se deja vacío se usa la utilidad del catálogo ({{ utilidadCatalogo }}%).
              </p>
              <p v-if="erroresPorCampo.utilidad_porcentaje" class="text-destructive text-sm">
                {{ erroresPorCampo.utilidad_porcentaje }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Precio unitario sin IVA (MXN)</Label>
              <Input :model-value="precioUnitarioSinIva" disabled />
              <p class="text-muted-foreground text-sm">
                Utilidad: ${{ utilidadMonto }} ({{ utilidadEfectiva }}%)
              </p>
              <p class="text-muted-foreground text-sm">
                Precio con IVA (16%, solo referencia): ${{ precioConIva }}
              </p>
            </div>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'articulos' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando">
            {{ guardando ? 'Guardando...' : 'Guardar' }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
