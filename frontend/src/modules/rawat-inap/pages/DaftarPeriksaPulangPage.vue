<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { daftarDaftarPeriksaPulang } from '../api'
import DialogDaftarPeriksaPulang from '../components/DialogDaftarPeriksaPulang.vue'
import { KUNCI_PEMULANGAN } from '../pemulangan'
import type { DaftarPeriksaPulangDataInduk } from '../tipe'

/**
 * Master Data → Daftar periksa pulang (tiket 2.40, kontrak v0.39.0; FR-05-03). Butir yang disalin ke rencana pulang saat
 * rencana dibuat. Pola area IGD: ubah langsung berizin dengan dasar perubahan, tercatat di jejak audit; tanpa hapus (butir
 * dinonaktifkan). Menambah butir belum ada di kontrak.
 */
const qc = useQueryClient()
const status = ref<'aktif' | 'nonaktif' | ''>('')
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_PEMULANGAN.dataInduk(status.value || null)),
  queryFn: ({ pageParam }) => daftarDaftarPeriksaPulang(status.value || null, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const bisaUbah = computed(() => boleh('master_data.daftar_periksa_pulang.perbaiki'))
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '200px' },
  { kunci: 'nama', label: 'Nama butir' },
  { kunci: 'sumber', label: 'Diselesaikan oleh', lebar: '200px' },
  { kunci: 'wajib', label: 'Wajib', lebar: '110px' },
  { kunci: 'urutan', label: 'Urutan', rata: 'kanan', lebar: '100px' },
  { kunci: 'status', label: 'Status', lebar: '120px' },
  ...(bisaUbah.value ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '110px' }] : []),
])

const dialog = ref(false)
const target = ref<DaftarPeriksaPulangDataInduk | null>(null)
function ubah(b: DaftarPeriksaPulangDataInduk) {
  target.value = b
  dialog.value = true
}
function segarkan() {
  void qc.invalidateQueries({ queryKey: ['data-induk-daftar-periksa-pulang'] })
}
function tersimpan(hasil?: DaftarPeriksaPulangDataInduk) {
  if (hasil) tampilkanToast(`Butir ${hasil.kode} tersimpan. Berlaku untuk rencana pulang yang dibuat sesudahnya.`, 'ok')
  segarkan()
}
</script>

<template>
  <HalamanModul
    izin="master_data.daftar_periksa_pulang.lihat"
    judul="Daftar periksa pulang"
    uraian="Butir yang harus diselesaikan sebelum pasien rawat inap pulang. Butir aktif disalin ke rencana pulang saat rencana dibuat; setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <section class="kartu" aria-labelledby="judul-daftar-periksa">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-daftar-periksa">Butir daftar periksa</h2>
          <p>Butir yang diselesaikan kasir (tagihan atau jaminan) menunggu modul kasir Tahap D dan tidak menahan pulang. Menambah butir baru belum tersedia.</p>
        </div>
      </div>
      <div class="filter">
        <label class="sr-only" for="status-daftar-periksa">Status butir</label>
        <select id="status-daftar-periksa" v-model="status" class="isian">
          <option value="">Semua status</option>
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
        </select>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(b: DaftarPeriksaPulangDataInduk) => b.id"
        label="Daftar butir periksa pulang"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Tidak ada butir dengan status ini. Ubah filter status."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-kode="{ baris: b }"><code>{{ b.kode }}</code></template>
        <template #sel-nama="{ baris: b }"><b>{{ b.nama }}</b></template>
        <template #sel-sumber="{ baris: b }">{{ b.sumber === 'kejadian' ? 'Kasir (Tahap D)' : 'Petugas bangsal' }}</template>
        <template #sel-wajib="{ baris: b }">{{ b.wajib ? 'Wajib' : 'Tidak wajib' }}</template>
        <template #sel-urutan="{ baris: b }"><span class="angka">{{ b.urutan }}</span></template>
        <template #sel-status="{ baris: b }">
          <Lencana :jenis="b.status === 'aktif' ? 'ok' : 'netral'">{{ b.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</Lencana>
        </template>
        <template #sel-aksi="{ baris: b }">
          <Tombol :aria-label="`Ubah ${b.nama}`" @click="ubah(b)">Ubah</Tombol>
        </template>
      </DataTable>
      <DialogDaftarPeriksaPulang v-model:open="dialog" :butir="target" @selesai="tersimpan" @muat-ulang="tersimpan()" />
    </section>
  </HalamanModul>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: 200px;
  padding: var(--space-3) var(--space-4);
}
.angka {
  font-variant-numeric: tabular-nums;
}
code {
  font-family: var(--font-mono);
}
</style>
