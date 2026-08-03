<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useClientesStore, type ClientePayload } from '../stores/clientes'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import RegimenFiscalSelect from '../components/RegimenFiscalSelect.vue'
import CodigoPostalCombobox from '../components/CodigoPostalCombobox.vue'
import AppLayout from '../layouts/AppLayout.vue'

const route = useRoute()
const router = useRouter()
const clientes = useClientesStore()

const clienteId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => clienteId.value !== null)

const form = reactive({
  rfc: '',
  razon_social: '',
  regimen_fiscal: null as string | null,
  codigo_postal_fiscal: null as string | null,
  nombre_comercial: '',
  correo_contacto: '',
  telefono: '',
  direccion_comercial: '',
})

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

onMounted(async () => {
  if (!clienteId.value) return

  cargando.value = true
  try {
    const cliente = await clientes.fetchOne(clienteId.value)
    form.rfc = cliente.rfc
    form.razon_social = cliente.razon_social
    form.regimen_fiscal = cliente.regimen_fiscal
    form.codigo_postal_fiscal = cliente.codigo_postal_fiscal
    form.nombre_comercial = cliente.nombre_comercial ?? ''
    form.correo_contacto = cliente.correo_contacto ?? ''
    form.telefono = cliente.telefono ?? ''
    form.direccion_comercial = cliente.direccion_comercial ?? ''
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

  const payload: ClientePayload = {
    rfc: form.rfc,
    razon_social: form.razon_social,
    regimen_fiscal: form.regimen_fiscal ?? '',
    codigo_postal_fiscal: form.codigo_postal_fiscal ?? '',
    nombre_comercial: form.nombre_comercial || null,
    correo_contacto: form.correo_contacto || null,
    telefono: form.telefono || null,
    direccion_comercial: form.direccion_comercial || null,
  }

  try {
    if (esEdicion.value && clienteId.value) {
      await clientes.update(clienteId.value, payload)
    } else {
      await clientes.create(payload)
    }
    await router.push({ name: 'clientes' })
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
        {{ esEdicion ? 'Editar cliente' : 'Nuevo cliente' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos fiscales</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label for="rfc">RFC</Label>
              <Input id="rfc" v-model="form.rfc" maxlength="13" required />
              <p v-if="erroresPorCampo.rfc" class="text-destructive text-sm">
                {{ erroresPorCampo.rfc }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="razon_social">Razón social</Label>
              <Input id="razon_social" v-model="form.razon_social" required />
              <p v-if="erroresPorCampo.razon_social" class="text-destructive text-sm">
                {{ erroresPorCampo.razon_social }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Régimen fiscal</Label>
              <RegimenFiscalSelect v-model="form.regimen_fiscal" />
              <p v-if="erroresPorCampo.regimen_fiscal" class="text-destructive text-sm">
                {{ erroresPorCampo.regimen_fiscal }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Código postal fiscal</Label>
              <CodigoPostalCombobox v-model="form.codigo_postal_fiscal" />
              <p v-if="erroresPorCampo.codigo_postal_fiscal" class="text-destructive text-sm">
                {{ erroresPorCampo.codigo_postal_fiscal }}
              </p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos comerciales (opcionales)</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label for="nombre_comercial">Nombre comercial</Label>
              <Input id="nombre_comercial" v-model="form.nombre_comercial" />
            </div>

            <div class="space-y-1.5">
              <Label for="correo_contacto">Correo de contacto</Label>
              <Input id="correo_contacto" v-model="form.correo_contacto" type="email" />
              <p v-if="erroresPorCampo.correo_contacto" class="text-destructive text-sm">
                {{ erroresPorCampo.correo_contacto }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="telefono">Teléfono</Label>
              <Input id="telefono" v-model="form.telefono" />
            </div>

            <div class="space-y-1.5">
              <Label for="direccion_comercial">Dirección comercial</Label>
              <Input id="direccion_comercial" v-model="form.direccion_comercial" />
            </div>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'clientes' })">
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
