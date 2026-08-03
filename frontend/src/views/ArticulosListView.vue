<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  PlusIcon,
  PencilIcon,
  TrashIcon,
  ArrowUpTrayIcon,
  ArrowDownTrayIcon,
} from '@heroicons/vue/24/outline'
import { useArticulosStore, type Articulo, type ImportarCsvReporte } from '../stores/articulos'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import CatalogoSelect from '../components/CatalogoSelect.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'
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
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '../components/ui/dialog'

const articulos = useArticulosStore()

const articuloAEliminar = ref<Articulo | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

const exportando = ref(false)
const errorExportar = ref<string | null>(null)

const mostrarImportar = ref(false)
const catalogoImportar = ref<number | null>(null)
const archivoImportar = ref<File | null>(null)
const importando = ref(false)
const errorImportar = ref<string | null>(null)
const reporteImportar = ref<ImportarCsvReporte | null>(null)

onMounted(() => articulos.fetchList())

let buscarTimeout: ReturnType<typeof setTimeout>
function onBuscar() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => articulos.fetchList(1), 300)
}

function irAPagina(pagina: number) {
  articulos.fetchList(pagina)
}

function abrirEliminar(articulo: Articulo) {
  articuloAEliminar.value = articulo
  errorEliminar.value = null
}

function cerrarEliminar() {
  articuloAEliminar.value = null
  errorEliminar.value = null
}

async function confirmarEliminar() {
  if (!articuloAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await articulos.remove(articuloAEliminar.value.id)
    articuloAEliminar.value = null
  } catch (err) {
    errorEliminar.value = extractErrorMessage(err)
  } finally {
    eliminando.value = false
  }
}

async function onExportar() {
  exportando.value = true
  errorExportar.value = null
  try {
    await articulos.exportarCsv()
  } catch (err) {
    errorExportar.value = extractErrorMessage(err)
  } finally {
    exportando.value = false
  }
}

function abrirImportar() {
  mostrarImportar.value = true
  catalogoImportar.value = null
  archivoImportar.value = null
  errorImportar.value = null
  reporteImportar.value = null
}

function cerrarImportar() {
  mostrarImportar.value = false
}

function onArchivoSeleccionado(event: Event) {
  const input = event.target as HTMLInputElement
  archivoImportar.value = input.files?.[0] ?? null
}

async function confirmarImportar() {
  if (!catalogoImportar.value || !archivoImportar.value) return

  importando.value = true
  errorImportar.value = null
  reporteImportar.value = null
  try {
    reporteImportar.value = await articulos.importarCsv(
      catalogoImportar.value,
      archivoImportar.value,
    )
    await articulos.fetchList(1)
  } catch (err) {
    errorImportar.value = extractErrorMessage(err)
  } finally {
    importando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Artículos</h1>
        <div class="flex flex-wrap gap-2">
          <Button variant="outline" :disabled="exportando" @click="onExportar">
            <ArrowDownTrayIcon class="size-4" />
            {{ exportando ? 'Exportando...' : 'Exportar CSV' }}
          </Button>
          <Button variant="outline" @click="abrirImportar">
            <ArrowUpTrayIcon class="size-4" />
            Importar CSV
          </Button>
          <Button as-child>
            <RouterLink :to="{ name: 'articulos-crear' }">
              <PlusIcon class="size-4" />
              Nuevo artículo
            </RouterLink>
          </Button>
        </div>
      </div>

      <Input
        v-model="articulos.search"
        placeholder="Buscar por nombre, modelo o proveedor..."
        class="max-w-sm"
        @update:model-value="onBuscar"
      />

      <Alert v-if="articulos.error" variant="destructive">
        <AlertDescription>{{ articulos.error }}</AlertDescription>
      </Alert>
      <Alert v-if="errorExportar" variant="destructive">
        <AlertDescription>{{ errorExportar }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nombre</TableHead>
                <TableHead>Modelo</TableHead>
                <TableHead>Proveedor</TableHead>
                <TableHead>Precio con IVA</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!articulos.loading && articulos.items.length === 0">
                <TableCell colspan="5" class="text-muted-foreground py-10 text-center">
                  No hay artículos registrados todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="articulo in articulos.items" :key="articulo.id">
                <TableCell>{{ articulo.nombre }}</TableCell>
                <TableCell>{{ articulo.modelo }}</TableCell>
                <TableCell truncate :title="articulo.proveedor_nombre_comercial ?? undefined">
                  {{ articulo.proveedor_nombre_comercial ?? '—' }}
                </TableCell>
                <TableCell>${{ articulo.precio_unitario_con_iva.toFixed(2) }}</TableCell>
                <TableCell class="flex justify-end gap-2 text-right">
                  <Button as-child variant="outline" size="icon-sm">
                    <RouterLink :to="{ name: 'articulos-editar', params: { id: articulo.id } }">
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Editar</span>
                    </RouterLink>
                  </Button>
                  <Button variant="outline" size="icon-sm" @click="abrirEliminar(articulo)">
                    <TrashIcon class="size-4" />
                    <span class="sr-only">Eliminar</span>
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div v-if="articulos.meta && articulos.meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="articulos.meta.current_page <= 1"
          @click="irAPagina(articulos.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ articulos.meta.current_page }} de {{ articulos.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="articulos.meta.current_page >= articulos.meta.last_page"
          @click="irAPagina(articulos.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="articuloAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar artículo</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar "{{ articuloAEliminar?.nombre }}"? Podrás recuperarlo
              solo por soporte técnico.
            </DialogDescription>
          </DialogHeader>
          <Alert v-if="errorEliminar" variant="destructive">
            <AlertDescription>{{ errorEliminar }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="eliminando" @click="cerrarEliminar">
              Cancelar
            </Button>
            <Button variant="destructive" :disabled="eliminando" @click="confirmarEliminar">
              Eliminar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarImportar" @update:open="(v) => !v && cerrarImportar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Importar artículos desde CSV</DialogTitle>
            <DialogDescription>
              Todas las filas del archivo se importarán asociadas al catálogo seleccionado (y por lo
              tanto a su proveedor). El CSV debe tener las columnas:
            </DialogDescription>
          </DialogHeader>

          <div class="min-w-0 space-y-4">
            <code
              class="bg-muted block w-full min-w-0 overflow-x-auto rounded-md px-3 py-2 text-xs whitespace-nowrap"
            >
              nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva
            </code>

            <div class="space-y-1.5">
              <Label>Catálogo</Label>
              <CatalogoSelect v-model="catalogoImportar" />
            </div>
            <div class="min-w-0 space-y-1.5">
              <Label for="archivo_csv">Archivo CSV</Label>
              <input
                id="archivo_csv"
                type="file"
                accept=".csv,text/csv"
                class="border-input text-sm w-full min-w-0 rounded-md border px-3 py-1.5"
                @change="onArchivoSeleccionado"
              />
            </div>

            <Alert v-if="errorImportar" variant="destructive">
              <AlertDescription>{{ errorImportar }}</AlertDescription>
            </Alert>

            <Alert v-if="reporteImportar">
              <AlertDescription>
                {{ reporteImportar.importados }} artículo(s) importado(s).
                <template v-if="reporteImportar.errores.length > 0">
                  {{ reporteImportar.errores.length }} fila(s) con errores:
                  <ul class="mt-1 list-disc pl-5">
                    <li v-for="error in reporteImportar.errores" :key="error.fila">
                      Fila {{ error.fila }}: {{ error.motivo }}
                    </li>
                  </ul>
                </template>
              </AlertDescription>
            </Alert>
          </div>

          <DialogFooter>
            <Button variant="outline" :disabled="importando" @click="cerrarImportar">
              Cerrar
            </Button>
            <Button
              :disabled="importando || !catalogoImportar || !archivoImportar"
              @click="confirmarImportar"
            >
              {{ importando ? 'Importando...' : 'Importar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
