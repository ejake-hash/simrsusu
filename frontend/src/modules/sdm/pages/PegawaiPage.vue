<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { tampilkanToast } from '@/design-system/components/toast'
import HalamanModul from '@/layouts/HalamanModul.vue'
import type { Berversi } from '@/modules/organisasi/api'
import { useKelasLayar } from '@/shared/composables/useKelasLayar'
import DaftarPegawai from '../components/DaftarPegawai.vue'
import DialogPegawai from '../components/DialogPegawai.vue'
import RincianPegawai from '../components/RincianPegawai.vue'
import { KUNCI_SDM } from '../sdm'
import type { PegawaiRinci } from '../tipe'

/**
 * SDM → Data pegawai (tiket 1.8d, FR-54-08 langkah 5): daftar + rincian (izin praktik, RKK, akun). Pegawai terpilih di
 * ?pegawai= (id, bukan data pribadi). Di bawah 1280 px rincian tampil di bawah daftar dan digulir ke tampilan.
 */
const route = useRoute()
const router = useRouter()
const qc = useQueryClient()
const kelas = useKelasLayar()

const terpilih = computed(() => (typeof route.query.pegawai === 'string' ? route.query.pegawai : null))
const panel = ref<HTMLElement | null>(null)
const dialogTambah = ref(false)

async function pilih(id: string | null) {
  await router.replace({ query: { ...route.query, pegawai: id ?? undefined } })
  if (kelas.value !== 'desktop' && id) {
    await nextTick()
    panel.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }
}
function ditambah(hasil: Berversi<PegawaiRinci>) {
  qc.setQueryData(KUNCI_SDM.rinciPegawai(hasil.data.id), hasil)
  void qc.invalidateQueries({ queryKey: KUNCI_SDM.pegawai })
  tampilkanToast(`${hasil.data.nama} ditambahkan.`)
  void pilih(hasil.data.id)
}
</script>

<template>
  <HalamanModul
    izin="sdm.pegawai.lihat"
    judul="Data pegawai"
    uraian="Data kepegawaian, izin praktik (STR/SIP/SIK/SPK), kewenangan klinis, dan akun aplikasi. Satu akun untuk satu pegawai; setiap perubahan tercatat di jejak audit."
  >
    <div class="tata">
      <DaftarPegawai :model-value="terpilih" @update:model-value="pilih" @tambah="dialogTambah = true" />
      <div ref="panel" class="panel">
        <RincianPegawai v-if="terpilih" :key="terpilih" :pegawai-id="terpilih" @tutup="pilih(null)" />
        <section v-else class="kartu kosong">
          <h2>Pilih pegawai</h2>
          <p>Pilih pegawai di daftar untuk melihat data kepegawaian, izin praktik, kewenangan klinis, dan akunnya.</p>
        </section>
      </div>
    </div>
    <DialogPegawai v-model:open="dialogTambah" :pegawai="null" @selesai="ditambah" />
  </HalamanModul>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(360px, 460px);
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
