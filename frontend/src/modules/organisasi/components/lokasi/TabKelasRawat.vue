<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal } from '@/shared/utils/waktu'
import type { Berversi } from '../../api'
import { ambilKelasRawat, daftarKelasRawat } from '../../apiLokasi'
import { KUNCI_LOKASI } from '../../lokasi'
import type { KelasRawat } from '../../tipeLokasi'
import DialogStatus from '../DialogStatus.vue'
import DialogKelasRawat from './DialogKelasRawat.vue'

/** Kelas rawat (GET /kelas-rawat berkursor). Diisi RS; dipakai per TT dan dasar kapasitas per kelas. */
const qc = useQueryClient()
const status = ref<'aktif' | 'nonaktif' | ''>('aktif')
const filter = computed(() => ({ status: status.value || undefined }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_LOKASI.daftarKelas(filter.value)),
  queryFn: ({ pageParam }) => daftarKelasRawat(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '140px' },
  { kunci: 'nama', label: 'Nama kelas' },
  { kunci: 'kode_aplicares', label: 'Kode Aplicares', prioritas: 2, lebar: '150px' },
  { kunci: 'berlaku_dari', label: 'Berlaku dari', prioritas: 3, lebar: '150px' },
  { kunci: 'status', label: 'Status', lebar: '100px' },
  ...(boleh('master_data.organisasi.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '200px' }] : []),
])

const dialogKelas = ref(false)
const dialogStatus = ref(false)
const target = ref<Berversi<KelasRawat> | null>(null)
const memuatId = ref<string | null>(null)
async function buka(k: KelasRawat | null, aksi: 'ubah' | 'status' = 'ubah') {
  if (!k) {
    target.value = null
    dialogKelas.value = true
    return
  }
  memuatId.value = k.id
  try {
    target.value = await ambilKelasRawat(k.id)
    if (aksi === 'ubah') dialogKelas.value = true
    else dialogStatus.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuatId.value = null
  }
}
function selesai() {
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.kelas })
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.bangsal })
}
</script>

<template>
  <section class="kartu" aria-labelledby="judul-kelas-rawat">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-kelas-rawat">Kelas rawat</h2>
        <p>Kelas lama dan KRIS boleh berdampingan dengan tanggal berlaku. Isi dan kode Aplicares diisi RS dari dokumen resmi.</p>
      </div>
      <span class="aksi">
        <label class="sr-only" for="status-kelas">Status kelas rawat</label>
        <select id="status-kelas" v-model="status" class="isian">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
          <option value="">Semua status</option>
        </select>
        <button v-can="'master_data.organisasi.input'" type="button" class="tombol" @click="buka(null)"><Ikon nama="tambah" :ukuran="16" />Tambah kelas</button>
      </span>
    </div>
    <DataTable
      :kolom="kolom"
      :baris="baris"
      :kunci-baris="(k: KelasRawat) => k.id"
      label="Daftar kelas rawat"
      :memuat="kueri.isPending.value"
      :memuat-lagi="kueri.isFetchingNextPage.value"
      :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
      :ada-lagi="kueri.hasNextPage.value"
      teks-kosong="Belum ada kelas rawat. Tambahkan sesuai ketentuan RS sebelum membuat tempat tidur."
      @muat-lagi="kueri.fetchNextPage()"
      @coba-lagi="kueri.refetch()"
    >
      <template #sel-kode="{ baris: k }"><code>{{ k.kode }}</code></template>
      <template #sel-kode_aplicares="{ baris: k }">{{ k.kode_aplicares ?? '—' }}</template>
      <template #sel-berlaku_dari="{ baris: k }">{{ formatTanggal(`${k.berlaku_dari}T00:00:00`) }}</template>
      <template #sel-status="{ baris: k }">
        <span class="pil" :class="k.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ k.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
      </template>
      <template #sel-aksi="{ baris: k }">
        <span class="aksi">
          <Tombol :sibuk="memuatId === k.id" :aria-label="`Ubah ${k.nama}`" @click="buka(k)">Ubah</Tombol>
          <Tombol :sibuk="memuatId === k.id" :aria-label="`${k.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} ${k.nama}`" @click="buka(k, 'status')">{{ k.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
        </span>
      </template>
    </DataTable>
    <DialogKelasRawat v-model:open="dialogKelas" :kelas="target" @selesai="selesai" @muat-ulang="selesai" />
    <DialogStatus v-model:open="dialogStatus" jenis="kelas" :target="target" @selesai="selesai" @muat-ulang="selesai" />
  </section>
</template>

<style scoped>
.aksi {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
</style>
