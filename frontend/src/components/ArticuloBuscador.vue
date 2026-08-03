<script setup lang="ts">
import { ref } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import http from '../lib/http'
import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
  ComboboxViewport,
} from './ui/combobox'

export interface ArticuloResultado {
  id: number
  nombre: string
  modelo: string
  precio_unitario_sin_iva: number
}

const emit = defineEmits<{ seleccionar: [articulo: ArticuloResultado] }>()

const resultados = ref<ArticuloResultado[]>([])
const buscando = ref(false)

const buscar = useDebounceFn(async (texto: string) => {
  if (texto.trim().length < 2) {
    resultados.value = []
    return
  }

  buscando.value = true
  try {
    const { data } = await http.get('/articulos', { params: { search: texto } })
    resultados.value = data.data
  } finally {
    buscando.value = false
  }
}, 300)

function onSeleccionar(id: string | null) {
  const articulo = resultados.value.find((a) => a.id.toString() === id)
  if (articulo) {
    emit('seleccionar', articulo)
  }
  resultados.value = []
}
</script>

<template>
  <Combobox
    :model-value="null"
    ignore-filter
    class="w-full"
    @update:model-value="onSeleccionar($event as string | null)"
  >
    <ComboboxAnchor class="w-full">
      <ComboboxInput
        class="w-full"
        placeholder="Buscar artículo por nombre, modelo o proveedor para agregarlo..."
        :display-value="() => ''"
        @update:model-value="buscar($event as string)"
      />
    </ComboboxAnchor>
    <ComboboxList class="w-full">
      <ComboboxViewport>
        <ComboboxEmpty v-if="!buscando">
          {{ resultados.length === 0 ? 'Escribe al menos 2 caracteres para buscar.' : '' }}
        </ComboboxEmpty>
        <ComboboxItem v-for="item in resultados" :key="item.id" :value="item.id.toString()">
          {{ item.nombre }} ({{ item.modelo }}) — ${{ item.precio_unitario_sin_iva.toFixed(2) }}
        </ComboboxItem>
      </ComboboxViewport>
    </ComboboxList>
  </Combobox>
</template>
