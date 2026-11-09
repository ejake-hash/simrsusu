<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { KUNCI_TRIASE } from '@/modules/igd/triase'
import type { Berversi } from '@/modules/organisasi/api'
import { refDebounced } from '@/shared/composables/refDebounced'
import { boleh } from '@/shared/composables/usePermission'
import { ambilInstrumenTriase, daftarInstrumenTriaseDataInduk, KUNCI_INSTRUMEN_TRIASE } from '../api'
import DialogInstrumenTriase from '../components/DialogInstrumenTriase.vue'
import DialogStatusInstrumenTriase from '../components/DialogStatusInstrumenTriase.vue'
import type { InstrumenTriaseDataInduk, StatusInstrumenTriase } from '../tipe'

/**
 * Master Data → Instrumen triase (tiket 2.24, kontrak v0.32.0; FR-03-02). Instrumen dan skalanya ditetapkan RS (aturan
 * Tahap C); layar Triase IGD hanya menawarkan instrumen aktif. Pola penjamin: ubah langsung berizin dengan dasar
 * perubahan, tercatat di jejak audit; tanpa hapus.
 */
const qc = useQueryClient()
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<StatusInstrumenTriase | ''>('aktif')
const filter = computed(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_INSTRUMEN_TRIASE.daftar(filter.value)),
  queryFn: ({ pageParam }) => daftarInstrumenTriaseDataInduk(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '170px' },
  { kunci: 'nama', label: 'Instrumen' },
  { kunci: 'skala', label: 'Skala', prioritas: 2 },
  { kunci: 'status', label: 'Status', lebar: '110px' },
  ...(boleh('master_data.instrumen_triase.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '210px' }] : []),
])

const labelSkala = (i: InstrumenTriaseDataInduk) => i.skala.map((s) => s.label).join(', ')

const dialogUbah = ref(false)
const dialogStatus = ref(false)
const target = ref<Berversi<InstrumenTriaseDataInduk> | null>(null)
const memuatId = ref<string | null>(null)
/** Rincian dimuat dulu agar If-Match memakai versi terbaru, bukan versi saat daftar dibuka. */
async function buka(i: InstrumenTriaseDataInduk | null, aksi: 'ubah' | 'status' = 'ubah') {
  if (!i) {
    target.value = null
    dialogUbah.value = true
    return
  }
  memuatId.value = i.id
  try {
    target.value = await ambilInstrumenTriase(i.id)
    if (aksi === 'ubah') dialogUbah.value = true
    else dialogStatus.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuatId.value = null
  }
}
function selesai(hasil?: Berversi<InstrumenTriaseDataInduk>) {
  if (hasil) tampilkanToast(`Instrumen ${hasil.data.kode} tersimpan.`, 'ok')
  void qc.invalidateQueries({ queryKey: KUNCI_INSTRUMEN_TRIASE.semua })
  // Pilihan instrumen di layar Triase ikut diperbarui.
  void qc.invalidateQueries({ queryKey: KUNCI_TRIASE.instrumen })
}
</script>

<template>
  <HalamanModul
    izin="master_data.instrumen_triase.lihat"
    judul="Instrumen triase"
    uraian="Instrumen dan skala triase IGD yang disetujui rumah sakit. Hanya instrumen aktif yang bisa dipilih perawat saat triase. Setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <section class="kartu" aria-labelledby="judul-instrumen">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-instrumen">Daftar instrumen</h2>
          <p>Instrumen nonaktif tidak bisa dipilih untuk triase baru, tetapi triase lama tetap menampilkannya.</p>
        </div>
        <button v-can="'master_data.instrumen_triase.input'" type="button" class="tombol" @click="buka(null)"><Ikon nama="tambah" :ukuran="16" />Tambah instrumen</button>
      </div>
      <div class="filter">
        <label class="sr-only" for="cari-instrumen">Cari instrumen</label>
        <input id="cari-instrumen" v-model="cari" type="search" class="isian" placeholder="Cari kode atau nama" />
        <label class="sr-only" for="status-instrumen">Status instrumen</label>
        <select id="status-instrumen" v-model="status" class="isian">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
          <option value="">Semua status</option>
        </select>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(i: InstrumenTriaseDataInduk) => i.id"
        label="Daftar instrumen triase"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Belum ada instrumen yang cocok. Ubah filter, atau tambahkan instrumen bila akun Anda berhak."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-kode="{ baris: i }"><code>{{ i.kode }}</code></template>
        <template #sel-nama="{ baris: i }"><b>{{ i.nama }}</b></template>
        <template #sel-skala="{ baris: i }">
          <span v-if="i.skala.length">{{ labelSkala(i) }}</span>
          <span v-else class="ket">Tanpa skala</span>
        </template>
        <template #sel-status="{ baris: i }">
          <span class="pil" :class="i.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ i.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
        </template>
        <template #sel-aksi="{ baris: i }">
          <span class="aksi">
            <Tombol :sibuk="memuatId === i.id" :aria-label="`Ubah ${i.nama}`" @click="buka(i)">Ubah</Tombol>
            <Tombol :sibuk="memuatId === i.id" :aria-label="`${i.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} ${i.nama}`" @click="buka(i, 'status')">{{ i.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
          </span>
        </template>
      </DataTable>
      <DialogInstrumenTriase v-model:open="dialogUbah" :instrumen="target" @selesai="selesai" @muat-ulang="selesai()" />
      <DialogStatusInstrumenTriase v-model:open="dialogStatus" :target="target" @selesai="selesai" @muat-ulang="selesai()" />
    </section>
  </HalamanModul>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 180px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.aksi {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.ket {
  color: var(--muted);
}
@media (max-width: 768px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
