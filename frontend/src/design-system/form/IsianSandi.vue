<script setup lang="ts">
import { computed, ref, useAttrs, useId } from 'vue'

/**
 * Isian kata sandi: tombol tampilkan/sembunyikan (aria-pressed) dan penanda Caps Lock yang dibacakan sopan.
 * Dipakai di dalam KolomIsian: atribut dari slot KolomIsian (id, aria-invalid, aria-describedby) diteruskan ke input.
 */
const props = withDefaults(defineProps<{ autocomplete?: string; describedby?: string }>(), {
  autocomplete: 'current-password',
  describedby: undefined,
})
defineOptions({ inheritAttrs: false })
const nilai = defineModel<string>({ required: true })

const tampil = ref(false)
const capsLock = ref(false)
const input = ref<HTMLInputElement | null>(null)
const attrs = useAttrs()
const idBawaan = `caps-${useId()}`
/** Mengikuti id isian bila ada (mis. #sandi → #sandi-caps). */
const idCaps = computed(() => (typeof attrs.id === 'string' ? `${attrs.id}-caps` : idBawaan))

function periksaCapsLock(e: KeyboardEvent) {
  capsLock.value = e.getModifierState?.('CapsLock') ?? false
}

function ubahTampilan() {
  tampil.value = !tampil.value
  input.value?.focus()
}

defineExpose({ fokus: () => input.value?.focus() })
const gabung = (...id: (string | undefined | false)[]) => id.filter(Boolean).join(' ') || undefined
</script>

<template>
  <!-- Satu elemen akar: di dalam grid KolomIsian, wadah Caps Lock yang kosong tidak menambah jarak. -->
  <div>
    <div class="isian-sandi">
      <input
        v-bind="$attrs"
        ref="input"
        v-model="nilai"
        class="isian isian-besar"
        :type="tampil ? 'text' : 'password'"
        :autocomplete="props.autocomplete"
        autocapitalize="none"
        autocorrect="off"
        spellcheck="false"
        :aria-describedby="gabung(($attrs['aria-describedby'] as string | undefined) ?? props.describedby, capsLock && idCaps)"
        @keydown="periksaCapsLock"
        @keyup="periksaCapsLock"
        @blur="capsLock = false"
      />
      <button
        type="button"
        class="tombol-lihat"
        :aria-controls="$attrs.id as string | undefined"
        aria-label="Tampilkan kata sandi"
        :aria-pressed="tampil"
        @click="ubahTampilan"
      >
        <svg v-if="!tampil" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
          <circle cx="12" cy="12" r="3" />
        </svg>
        <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M3 3l18 18" />
          <path d="M10.6 10.6a3 3 0 0 0 4.2 4.2" />
          <path d="M9.4 5.2A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.1 6.6C3.6 8.3 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.3-1" />
        </svg>
      </button>
    </div>
    <div aria-live="polite">
      <p v-if="capsLock" :id="idCaps" class="caps">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M8 2 2 8h3v4h6V8h3L8 2z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" />
        </svg>
        Caps Lock aktif
      </p>
    </div>
  </div>
</template>

<style scoped>
.isian-sandi {
  position: relative;
}
.isian-sandi .isian {
  padding-right: 48px;
}
.tombol-lihat {
  position: absolute;
  top: 2px;
  right: 2px;
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--muted);
  cursor: pointer;
}
.tombol-lihat:hover {
  color: var(--text);
}
.tombol-lihat:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.caps {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: var(--space-2) 0 0;
  padding: 4px var(--space-2);
  border-radius: 6px;
  background: var(--warn-bg);
  color: var(--warn);
  font-size: 12px;
}
</style>
