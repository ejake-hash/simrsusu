<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { boleh } from '@/shared/composables/usePermission'
import { ambilJenisTugas, daftarJenisTugas } from '../api'
import DialogJenisTugas from '../components/DialogJenisTugas.vue'
import { KUNCI_NOTIF, teksMenit } from '../notifikasi'
import type { JenisTugas } from '../tipe'

/**
 * Master Data → Jenis Tugas, Tenggat & Eskalasi (tiket 1.7 fe 3, FR-53-04, PRD 9.1). Jenis dipasang modul pembuatnya
 * (tanpa tambah); RS mengubah tenggat, waktu eskalasi, unit teguran/eskalasi, kritis, dan aktif. Angka awal adalah data
 * yang perlu diverifikasi kebijakan RS.
 */
const qc = useQueryClient()
const status = ref<'aktif' | 'nonaktif' | ''>('')
const filterAktif = computed(() => (status.value === '' ? undefined : status.value === 'aktif'))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_NOTIF.daftarJenisTugas(filterAktif.value)),
  queryFn: ({ pageParam }) => daftarJenisTugas({ aktif: filterAktif.value }, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'nama', label: 'Jenis tugas' },
  { kunci: 'tenggat', label: 'Tenggat', prioritas: 2, lebar: '170px' },
  { kunci: 'eskalasi', label: 'Pengingat → teguran → eskalasi', prioritas: 2 },
  { kunci: 'unit', label: 'Unit teguran / eskalasi', prioritas: 3 },
  { kunci: 'status', label: 'Status', lebar: '120px' },
  ...(boleh('master_data.jenis_tugas.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '90px' }] : []),
])

const dialog = ref(false)
const target = ref<Berversi<JenisTugas> | null>(null)
const memuatId = ref<string | null>(null)
async function ubah(j: JenisTugas) {
  memuatId.value = j.id
  try {
    target.value = await ambilJenisTugas(j.id)
    dialog.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuatId.value = null
  }
}
function selesai(h: Berversi<JenisTugas>) {
  tampilkanToast(`${h.data.kode} disimpan; berlaku untuk tugas yang dibuat sesudahnya.`)
  void qc.invalidateQueries({ queryKey: KUNCI_NOTIF.jenisTugas })
}
</script>

<template>
  <HalamanModul
    izin="master_data.jenis_tugas.lihat"
    judul="Jenis Tugas, Tenggat & Eskalasi"
    uraian="Tenggat dan waktu pengingat, teguran, serta eskalasi per jenis tugas. Jenis dipasang modul pembuatnya; angka awal perlu disesuaikan dengan kebijakan RS."
  >
    <section class="kartu" aria-labelledby="judul-jenis-tugas">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-jenis-tugas">Jenis tugas</h2>
          <p>Jenis berpelaksana peran (mis. persetujuan akses) tidak punya penerima teguran sampai unit teguran diisi.</p>
        </div>
        <label>
          <span class="sr-only">Status jenis tugas</span>
          <select v-model="status" class="isian">
            <option value="">Semua status</option>
            <option value="aktif">Aktif</option>
            <option value="nonaktif">Nonaktif</option>
          </select>
        </label>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(j: JenisTugas) => j.id"
        label="Daftar jenis tugas"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Tidak ada jenis tugas yang cocok."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-nama="{ baris: j }">
          <b>{{ j.nama }}</b>
          <span class="ket"><code>{{ j.kode }}</code> · modul {{ j.modul }}</span>
          <span v-if="j.uraian" class="ket">{{ j.uraian }}</span>
        </template>
        <template #sel-tenggat="{ baris: j }">
          {{ j.tenggat_dari === 'objek' ? `Dibuat ${teksMenit(j.batas_menit)} sebelum tanggal objek` : `${teksMenit(j.batas_menit)} sesudah dibuat` }}
        </template>
        <template #sel-eskalasi="{ baris: j }">
          {{ teksMenit(j.eskalasi.pengingat_menit) }} sebelum → {{ teksMenit(j.eskalasi.teguran_menit) }} sesudah → {{ teksMenit(j.eskalasi.eskalasi_menit) }} sesudah
        </template>
        <template #sel-unit="{ baris: j }">
          <span class="ket">Teguran: {{ j.unit_teguran?.nama ?? 'unit tugas' }}</span>
          <span class="ket">Eskalasi: {{ j.unit_eskalasi?.nama ?? 'unit induk' }}</span>
        </template>
        <template #sel-status="{ baris: j }">
          <span class="pil" :class="j.aktif ? 'pil-ok' : 'pil-netral'">{{ j.aktif ? 'Aktif' : 'Nonaktif' }}</span>
          <span v-if="j.kritis" class="pil pil-bahaya">Kritis</span>
        </template>
        <template #sel-aksi="{ baris: j }">
          <Tombol :sibuk="memuatId === j.id" :aria-label="`Ubah ${j.nama}`" @click="ubah(j)">Ubah</Tombol>
        </template>
      </DataTable>
    </section>
    <DialogJenisTugas v-model:open="dialog" :jenis="target" @selesai="selesai" @muat-ulang="() => qc.invalidateQueries({ queryKey: KUNCI_NOTIF.jenisTugas })" />
  </HalamanModul>
</template>

<style scoped>
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
</style>
