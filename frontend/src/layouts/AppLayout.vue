<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRightStartOnRectangleIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '../stores/auth'
import { Button } from '../components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '../components/ui/popover'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const entradasContabilidad = [
  { name: 'cuentas', etiqueta: 'Cuentas' },
  { name: 'movimientos', etiqueta: 'Movimientos' },
  { name: 'saldos', etiqueta: 'Saldos' },
]

const rutaContabilidadActiva = computed(() => route.path.startsWith('/tesoreria'))

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="bg-muted min-h-screen">
    <header class="bg-background border-border border-b">
      <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3 sm:px-6">
        <div class="flex items-center gap-6">
          <p class="text-primary font-heading text-sm font-semibold tracking-wide uppercase">
            Facturación
          </p>
          <nav class="flex gap-4 text-sm">
            <RouterLink
              :to="{ name: 'dashboard' }"
              class="text-muted-foreground hover:text-foreground"
              active-class="text-foreground font-medium"
            >
              Inicio
            </RouterLink>
            <RouterLink
              :to="{ name: 'clientes' }"
              class="text-muted-foreground hover:text-foreground"
              active-class="text-foreground font-medium"
            >
              Clientes
            </RouterLink>
            <RouterLink
              :to="{ name: 'proveedores' }"
              class="text-muted-foreground hover:text-foreground"
              active-class="text-foreground font-medium"
            >
              Proveedores
            </RouterLink>
            <RouterLink
              :to="{ name: 'catalogos' }"
              class="text-muted-foreground hover:text-foreground"
              active-class="text-foreground font-medium"
            >
              Catálogos
            </RouterLink>
            <RouterLink
              :to="{ name: 'articulos' }"
              class="text-muted-foreground hover:text-foreground"
              active-class="text-foreground font-medium"
            >
              Artículos
            </RouterLink>
            <RouterLink
              :to="{ name: 'facturas' }"
              class="text-muted-foreground hover:text-foreground"
              active-class="text-foreground font-medium"
            >
              Facturas
            </RouterLink>
            <RouterLink
              :to="{ name: 'cotizaciones' }"
              class="text-muted-foreground hover:text-foreground"
              active-class="text-foreground font-medium"
            >
              Cotizaciones
            </RouterLink>
            <RouterLink
              :to="{ name: 'ordenes-compra' }"
              class="text-muted-foreground hover:text-foreground"
              active-class="text-foreground font-medium"
            >
              Órdenes de compra
            </RouterLink>
            <!-- El nombre visible es "Contabilidad"; el módulo, sus rutas (/tesoreria/...) y sus
                 clases siguen llamándose Tesorería (ver 010-tesoreria.md). -->
            <Popover>
              <PopoverTrigger
                class="text-muted-foreground hover:text-foreground flex items-center gap-1"
                :class="rutaContabilidadActiva ? 'text-foreground font-medium' : undefined"
              >
                Contabilidad
                <ChevronDownIcon class="size-3" />
              </PopoverTrigger>
              <PopoverContent align="start" class="w-44 p-1">
                <RouterLink
                  v-for="entrada in entradasContabilidad"
                  :key="entrada.name"
                  :to="{ name: entrada.name }"
                  class="hover:bg-muted block rounded-sm px-2 py-1.5 text-sm"
                  active-class="text-foreground font-medium"
                >
                  {{ entrada.etiqueta }}
                </RouterLink>
              </PopoverContent>
            </Popover>
          </nav>
        </div>
        <Button variant="outline" size="sm" @click="onLogout">
          <ArrowRightStartOnRectangleIcon class="size-4" />
          Cerrar sesión
        </Button>
      </div>
    </header>

    <main class="mx-auto max-w-5xl p-4 sm:p-6">
      <slot />
    </main>
  </div>
</template>
