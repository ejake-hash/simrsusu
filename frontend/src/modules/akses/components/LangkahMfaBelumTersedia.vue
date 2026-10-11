<script setup lang="ts">
import { onMounted, useTemplateRef } from 'vue'
import { profilRsDariEnv } from '@/app/profilRs'
import type { Langkah, LangkahTampil } from '../alurMasuk'

// Pengganti sementara langkah perlu_mfa dan perlu_daftar_mfa sampai tiket 1.1b. Sesi di server
// belum masuk pada status ini (PRD 10.3.2), jadi kembali ke langkah sandi aman.
defineProps<{
  langkah: LangkahTampil
  lanjut: (langkah: Langkah) => Promise<void>
}>()
const emit = defineEmits<{ kembali: [] }>()
const { unitAdmin } = profilRsDariEnv()

const judul = useTemplateRef<HTMLHeadingElement>('judul')
onMounted(() => judul.value?.focus())
</script>

<template>
  <section aria-labelledby="judul-mfa">
    <h1 id="judul-mfa" ref="judul" tabindex="-1">Verifikasi dua langkah diperlukan</h1>
    <p class="lead">
      Akun ini wajib memakai verifikasi dua langkah, tetapi versi aplikasi ini belum mendukungnya. Hubungi
      {{ unitAdmin }} untuk masuk.
    </p>
    <button type="button" class="tombol-kedua" @click="emit('kembali')">Kembali ke halaman masuk</button>
  </section>
</template>

<style scoped>
h1 {
  margin: 0 0 var(--space-2);
  font-size: 26px;
  font-weight: var(--font-weight-bold);
  line-height: 1.2;
  letter-spacing: -0.02em;
  text-wrap: balance;
}
h1:focus {
  outline: none;
}
.lead {
  margin: 0 0 var(--space-6);
  color: var(--muted);
}
.tombol-kedua {
  width: 100%;
  height: 44px;
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-weight: var(--font-weight-semibold);
  cursor: pointer;
}
.tombol-kedua:hover {
  background: var(--bg);
}
.tombol-kedua:focus-visible {
  outline: none;
  border-color: var(--brand-600);
  box-shadow: var(--ring);
}
</style>
