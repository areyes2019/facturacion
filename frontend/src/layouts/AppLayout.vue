<script setup lang="ts">
import { useRouter } from 'vue-router'
import { ArrowRightStartOnRectangleIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '../stores/auth'
import { Button } from '../components/ui/button'

const router = useRouter()
const auth = useAuthStore()

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
