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

const modelValue = defineModel<number | null>({ default: null })

interface ClienteResultado {
  id: number
  razon_social: string
  rfc: string
}

const resultados = ref<ClienteResultado[]>([])
const buscando = ref(false)

const buscar = useDebounceFn(async (texto: string) => {
  if (texto.trim().length < 2) {
    resultados.value = []
    return
  }

  buscando.value = true
  try {
    const { data } = await http.get('/clientes', { params: { search: texto } })
    resultados.value = data.data
  } finally {
    buscando.value = false
  }
}, 300)
</script>

<template>
  <Combobox
    :model-value="modelValue?.toString() ?? null"
    ignore-filter
    class="w-full"
    @update:model-value="(v) => (modelValue = v ? Number(v) : null)"
  >
    <ComboboxAnchor class="w-full">
      <ComboboxInput
        class="w-full"
        placeholder="Buscar cliente por razón social o RFC..."
        :display-value="
          (v: unknown) =>
            resultados.find((c) => c.id.toString() === v)?.razon_social ??
            (typeof v === 'string' ? v : '')
        "
        @update:model-value="buscar($event as string)"
      />
    </ComboboxAnchor>
    <ComboboxList class="w-full">
      <ComboboxViewport>
        <ComboboxEmpty v-if="!buscando">
          {{ resultados.length === 0 ? 'Escribe al menos 2 caracteres para buscar.' : '' }}
        </ComboboxEmpty>
        <ComboboxItem v-for="item in resultados" :key="item.id" :value="item.id.toString()">
          {{ item.razon_social }} ({{ item.rfc }})
        </ComboboxItem>
      </ComboboxViewport>
    </ComboboxList>
  </Combobox>
</template>
