<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Tabs from '@/design-system/components/Tabs.vue'
import DaftarNotifikasi from '../components/DaftarNotifikasi.vue'
import PreferensiNotifikasi from '../components/PreferensiNotifikasi.vue'

/**
 * Notifikasi akun sendiri (tiket 1.7 fe 1 dan 3): belum dibaca, semua, dan preferensi kanal. Tanpa izin khusus
 * (/saya/*), jadi tidak lewat HalamanModul. Tab di ?tab=. Berfungsi di ponsel (PRD 5.5).
 */
const route = useRoute()
const router = useRouter()
const DAFTAR = [
  { nilai: 'belum', label: 'Belum dibaca' },
  { nilai: 'semua', label: 'Semua' },
  { nilai: 'preferensi', label: 'Preferensi' },
]
const tab = computed({
  get: () => (DAFTAR.some((t) => t.nilai === route.query.tab) ? (route.query.tab as string) : 'belum'),
  set: (t: string) => void router.replace({ query: { tab: t === 'belum' ? undefined : t } }),
})
</script>

<template>
  <div class="halaman">
    <header class="kepala">
      <h1>Notifikasi</h1>
      <p>Pengingat, teguran, eskalasi tugas, dan pemberitahuan keamanan akun Anda.</p>
    </header>
    <section class="kartu">
      <div class="kartu-isi">
        <Tabs v-model="tab" label="Notifikasi" :daftar="DAFTAR">
          <template #belum><DaftarNotifikasi status="belum_dibaca" /></template>
          <template #semua><DaftarNotifikasi status="semua" /></template>
          <template #preferensi><PreferensiNotifikasi /></template>
        </Tabs>
      </div>
    </section>
  </div>
</template>

<style scoped>
.kepala {
  margin-bottom: var(--space-4);
}
.kepala h1 {
  margin: 0 0 var(--space-1);
  font-size: 22px;
  letter-spacing: -0.01em;
}
.kepala p {
  margin: 0;
  color: var(--muted);
}
</style>
