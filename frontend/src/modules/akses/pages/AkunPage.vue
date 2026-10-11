<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { useKelasLayar } from '@/shared/composables/useKelasLayar'
import DaftarAkun from '../components/pengaturan/DaftarAkun.vue'
import RincianAkun from '../components/pengaturan/RincianAkun.vue'
import { usePohonMenu } from '../pengaturan'

/**
 * Pengaturan → Akun & akses (PRD 9.3, paket 1.2c): daftar akun + rincian (Penugasan, Per akun, Lihat sebagai,
 * MFA). Akun terpilih di ?akun=. Di bawah 1280 px rincian tampil di bawah daftar dan digulir ke tampilan.
 */
const route = useRoute()
const router = useRouter()
const pohon = usePohonMenu()
const kelas = useKelasLayar()

const terpilih = computed(() => (typeof route.query.akun === 'string' ? route.query.akun : null))
const panel = ref<HTMLElement | null>(null)

async function pilih(id: string | null) {
  await router.replace({ query: { ...route.query, akun: id ?? undefined } })
  if (kelas.value !== 'desktop') {
    await nextTick()
    panel.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }
}
</script>

<template>
  <HalamanModul
    izin="pengaturan.akun.lihat"
    judul="Akun & akses"
    uraian="Penugasan peran, pengecualian per akun (cabut selalu menang), pratinjau Lihat sebagai, dan MFA."
    kelas-terbatas
  >
    <div class="tata">
      <DaftarAkun :model-value="terpilih" @update:model-value="pilih" />
      <div ref="panel" class="panel">
        <RincianAkun v-if="terpilih" :key="terpilih" :akun-id="terpilih" :indeks="pohon.indeks.value" />
        <section v-else class="kartu kosong">
          <h2>Pilih akun</h2>
          <p>Pilih akun di daftar untuk melihat penugasan, pengecualian, menu, dan MFA-nya.</p>
        </section>
      </div>
    </div>
  </HalamanModul>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(360px, 440px);
  align-items: start;
  gap: var(--space-4);
}
.panel {
  min-width: 0;
  scroll-margin-top: calc(var(--tinggi-atas-lekat, 60px) + var(--space-4));
}
/* Desktop: rincian tetap terlihat saat daftar akun panjang digulir. */
@media (min-width: 1280px) {
  .panel {
    position: sticky;
    top: calc(var(--tinggi-atas-lekat, 60px) + var(--space-4));
    max-height: calc(100vh - var(--tinggi-atas-lekat, 60px) - var(--space-8));
    overflow-y: auto;
    border-radius: var(--radius-card);
  }
}
.kosong {
  padding: var(--space-8) var(--space-6);
  text-align: center;
  color: var(--muted);
}
.kosong h2 {
  margin: 0 0 var(--space-1);
  font-size: 15px;
  color: var(--text);
}
.kosong p {
  margin: 0;
}
@media (max-width: 1279.98px) {
  .tata {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
