<script setup lang="ts">
import { computed, useId } from 'vue'

/**
 * Label + kontrol + petunjuk + pesan error di dekat kolom (PRD 5.1 butir 13). Kontrol diberikan lewat slot
 * dan menerima atribut aksesibilitas yang sudah dirangkai:
 *   <KolomIsian v-slot="a" label="Dasar perubahan" :error="errors.dasar" wajib>
 *     <input v-bind="a" v-model="dasar" class="isian" />
 *   </KolomIsian>
 */
/** `id` opsional untuk id yang stabil (mis. #nama-pengguna di halaman masuk); bawaan dibangkitkan. */
const props = withDefaults(defineProps<{ label: string; error?: string; petunjuk?: string; wajib?: boolean; id?: string }>(), {
  error: undefined,
  petunjuk: undefined,
  wajib: false,
  id: undefined,
})

const idBawaan = `kolom-${useId()}`
const id = computed(() => props.id ?? idBawaan)
const atribut = computed(() => ({
  id: id.value,
  'aria-invalid': props.error ? true : undefined,
  'aria-required': props.wajib || undefined,
  'aria-describedby': [props.petunjuk ? `${id.value}-petunjuk` : '', props.error ? `${id.value}-error` : ''].filter(Boolean).join(' ') || undefined,
}))
</script>

<template>
  <div class="kolom" :class="{ 'kolom-salah': error }">
    <label :for="id" class="kolom-label">
      {{ label }}<span v-if="wajib" class="kolom-wajib" aria-hidden="true"> *</span>
    </label>
    <slot v-bind="atribut" />
    <p v-if="petunjuk" :id="`${id}-petunjuk`" class="kolom-petunjuk">{{ petunjuk }}</p>
    <p v-if="error" :id="`${id}-error`" class="kolom-error">{{ error }}</p>
  </div>
</template>

<style scoped>
.kolom {
  display: grid;
  gap: 6px;
  margin-bottom: var(--space-4);
}
.kolom-label {
  font-size: 13px;
  font-weight: var(--font-weight-medium);
}
.kolom-wajib {
  color: var(--danger);
}
.kolom-petunjuk {
  margin: 0;
  font-size: 12px;
  color: var(--muted);
}
.kolom-error {
  margin: 0;
  font-size: 12px;
  color: var(--danger);
}
</style>
