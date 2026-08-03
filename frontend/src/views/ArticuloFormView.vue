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
  precio_unitario_sin_iva: '' as string,
})

const precioConIva = computed(() => {
  const precio = parseFloat(form.precio_unitario_sin_iva)
  return Number.isFinite(precio) ? (precio * 1.16).toFixed(2) : '0.00'
})

// Descuento del catálogo seleccionado, para mostrar el precio con descuento en vivo (ver
// 009-catalogos.md); se consulta cada vez que cambia el catálogo porque el combobox solo expone
// id/nombre, no el descuento.
const descuentoCatalogo = ref(0)
watch(
  () => form.catalogo_id,
  async (catalogoId) => {
    if (!catalogoId) {
      descuentoCatalogo.value = 0
      return
    }
    try {
      const catalogo = await catalogos.fetchOne(catalogoId)
      descuentoCatalogo.value = catalogo.descuento
    } catch {
      descuentoCatalogo.value = 0
    }
  },
)

const precioConDescuento = computed(() => {
  const precio = parseFloat(form.precio_unitario_sin_iva)
  if (!Number.isFinite(precio)) return '0.00'
  return (precio * (1 - descuentoCatalogo.value / 100)).toFixed(2)
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
    form.precio_unitario_sin_iva = articulo.precio_unitario_sin_iva.toString()
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
    precio_unitario_sin_iva: form.precio_unitario_sin_iva
      ? parseFloat(form.precio_unitario_sin_iva)
      : null,
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
              <Label for="precio_unitario_sin_iva">Precio unitario sin IVA (MXN)</Label>
              <Input
                id="precio_unitario_sin_iva"
                v-model="form.precio_unitario_sin_iva"
                type="number"
                min="0.01"
                step="0.01"
                required
              />
              <p class="text-muted-foreground text-sm">
                Precio con IVA (16%, solo referencia): ${{ precioConIva }}
              </p>
              <p class="text-muted-foreground text-sm">
                Precio con descuento del catálogo ({{ descuentoCatalogo }}%, solo referencia): ${{
                  precioConDescuento
                }}
              </p>
              <p v-if="erroresPorCampo.precio_unitario_sin_iva" class="text-destructive text-sm">
                {{ erroresPorCampo.precio_unitario_sin_iva }}
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
