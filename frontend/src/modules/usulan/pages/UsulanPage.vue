<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { tampilkanToast } from '@/design-system/components/toast'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { useKelasLayar } from '@/shared/composables/useKelasLayar'
import DaftarUsulan from '../components/DaftarUsulan.vue'
import DialogUsulan from '../components/DialogUsulan.vue'
import RincianUsulan from '../components/RincianUsulan.vue'
import { KUNCI_USULAN } from '../usulan'
import type { UsulanDataInduk } from '../tipe'

/**
 * Master Data → Usulan perubahan (tiket 1.8c, FR-54-01/02, FR-08-16): daftar + rincian. Usulan terpilih di ?usulan=
 * (rute tautan tugas/notifikasi 1.7). Di bawah 1280 px rincian tampil di bawah daftar dan digulir ke tampilan.
 */
const route = useRoute()
const router = useRouter()
const qc = useQueryClient()
const kelas = useKelasLayar()
const terpilih = computed(() => (typeof route.query.usulan === 'string' ? route.query.usulan : null))
const panel = ref<HTMLElement | null>(null)
const dialogAjukan = ref(false)

async function pilih(id: string | null) {
  await router.replace({ query: { ...route.query, usulan: id ?? undefined } })
  if (kelas.value !== 'desktop' && id) {
    await nextTick()
    panel.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }
}
function diajukan(u: UsulanDataInduk) {
  qc.setQueryData(KUNCI_USULAN.rinci(u.id), u)
  void qc.invalidateQueries({ queryKey: KUNCI_USULAN.semua, predicate: (q) => q.queryKey[1] === 'daftar' })
  tampilkanToast(`${u.nomor} diajukan; menunggu verifikasi.`)
  void pilih(u.id)
}
</script>

<template>
  <HalamanModul
    izin="master_data.usulan.lihat"
    judul="Usulan perubahan"
    uraian="Perubahan data induk penting sesudah instalasi melayani: diusulkan, diverifikasi, disetujui, lalu berlaku otomatis pada tanggalnya dengan versi dan jejak."
  >
    <div class="tata">
      <DaftarUsulan :model-value="terpilih" @update:model-value="pilih" @ajukan="dialogAjukan = true" />
      <div ref="panel" class="panel">
        <RincianUsulan v-if="terpilih" :key="terpilih" :id="terpilih" @tutup="pilih(null)" />
        <section v-else class="kartu kosong">
          <h2>Pilih usulan</h2>
          <p>Pilih usulan di daftar untuk melihat perbedaan lama → baru, alur, dan riwayatnya.</p>
        </section>
      </div>
    </div>
    <DialogUsulan v-model:open="dialogAjukan" @selesai="diajukan" />
  </HalamanModul>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(380px, 480px);
  align-items: start;
  gap: var(--space-4);
}
.panel {
  min-width: 0;
  scroll-margin-top: calc(var(--tinggi-atas-lekat, 60px) + var(--space-4));
}
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
