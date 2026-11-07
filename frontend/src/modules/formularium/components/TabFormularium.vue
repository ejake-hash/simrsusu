<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import type { IsianButir } from '@/modules/usulan/tipe'
import { refDebounced } from '@/shared/composables/refDebounced'
import { boleh } from '@/shared/composables/usePermission'
import { ambilFormularium, daftarFormularium, daftarItem, imporItem, ubahItem } from '../api'
import { KOLOM_CSV_ITEM, KUNCI_FORMULARIUM, LABEL_JENIS_FORMULARIUM, ringkasObat, teksRentang } from '../formularium'
import type { Formularium, ItemFormularium, StatusAktif } from '../tipe'
import DialogFormularium from './DialogFormularium.vue'
import DialogImpor from './DialogImpor.vue'
import DialogItem from './DialogItem.vue'
import DialogStatus from './DialogStatus.vue'

/**
 * Formularium (Fornas, RS, penjamin) dan itemnya. Sebelum siap melayani ditulis langsung; sesudahnya server menjawab
 * 409 `harus_lewat_usulan` dan dialog yang sama mengajukan perubahannya sebagai usulan (tiket 2.12). Impor item tetap langsung saja.
 */
const qc = useQueryClient()
const kueriF = useInfiniteQuery({
  queryKey: KUNCI_FORMULARIUM.daftar,
  queryFn: ({ pageParam }) => daftarFormularium({}, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const barisF = computed(() => kueriF.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolomF = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '140px' },
  { kunci: 'nama', label: 'Formularium' },
  { kunci: 'jenis', label: 'Jenis', prioritas: 2, lebar: '170px' },
  { kunci: 'berlaku', label: 'Berlaku', prioritas: 2, lebar: '230px' },
  { kunci: 'jumlah_item_aktif', label: 'Item aktif', rata: 'kanan', lebar: '100px' },
])

const terpilihId = ref<string | null>(null)
const terpilih = computed(() => barisF.value.find((f) => f.id === terpilihId.value) ?? null)

const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<StatusAktif | ''>('aktif')
const filterItem = computed(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined }))
const kueriI = useInfiniteQuery({
  queryKey: computed(() => KUNCI_FORMULARIUM.item(terpilihId.value ?? '-', filterItem.value)),
  queryFn: ({ pageParam }) => daftarItem(terpilihId.value!, filterItem.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => terpilihId.value !== null),
})
const barisI = computed(() => kueriI.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolomI = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '140px' },
  { kunci: 'nama', label: 'Barang' },
  { kunci: 'restriksi', label: 'Restriksi', prioritas: 2 },
  { kunci: 'maks_per_resep', label: 'Maks per resep', prioritas: 3, rata: 'kanan', lebar: '130px' },
  { kunci: 'kronis', label: 'Kronis', prioritas: 3, lebar: '90px' },
  { kunci: 'status', label: 'Status', lebar: '100px' },
  ...(boleh('farmasi.formularium.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '200px' }] : []),
])

const dialogF = ref(false)
const dialogItem = ref(false)
const dialogStatus = ref(false)
const dialogImpor = ref(false)
const targetF = ref<Berversi<Formularium> | null>(null)
const targetI = ref<Berversi<ItemFormularium> | null>(null)
const memuat = ref(false)
watch(terpilihId, () => (cari.value = ''))

async function bukaFormularium(tambah: boolean) {
  if (tambah) {
    targetF.value = null
    dialogF.value = true
    return
  }
  memuat.value = true
  try {
    targetF.value = await ambilFormularium(terpilihId.value!)
    dialogF.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuat.value = false
  }
}
/** Item tak punya endpoint rincian; versi dari baris daftar (412 bila usang, dialog menawarkan muat ulang). */
function bukaItem(i: ItemFormularium | null, aksi: 'ubah' | 'status' = 'ubah') {
  targetI.value = i ? { data: i, etag: `"${i.versi}"` } : null
  if (aksi === 'ubah') dialogItem.value = true
  else dialogStatus.value = true
}
const selesai = () => void qc.invalidateQueries({ queryKey: KUNCI_FORMULARIUM.semua })
// Sesudah siap melayani hanya penonaktifan item yang bisa diusulkan (kontrak v0.24.0: formularium_item `tutup`).
const butirStatus = (s: StatusAktif, alasan: string): IsianButir | null =>
  s === 'nonaktif' && targetI.value ? { objek_jenis: 'formularium_item', aksi: 'tutup', objek_id: targetI.value.data.id, nilai_baru: { alasan } } : null
const simpanStatus = (s: StatusAktif, dasar: string) => ubahItem(terpilihId.value!, targetI.value!.data.id, targetI.value!.etag, { status: s, dasar_perubahan: dasar })
const imporKeFormularium = (berkas: File, mode: 'pratinjau' | 'terapkan', dasar: string) => imporItem(terpilihId.value!, berkas, mode, dasar)
</script>

<template>
  <section class="kartu" aria-labelledby="judul-daftar-formularium">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-daftar-formularium">Daftar formularium</h2>
        <p>Fornas diisi RS dari keputusan yang berlaku. Pilih satu baris untuk melihat dan mengelola itemnya.</p>
      </div>
      <button v-can="'farmasi.formularium.input'" type="button" class="tombol tombol-utama" @click="bukaFormularium(true)"><Ikon nama="tambah" :ukuran="16" />Tambah formularium</button>
    </div>
    <DataTable
      :kolom="kolomF"
      :baris="barisF"
      :kunci-baris="(f: Formularium) => f.id"
      label="Daftar formularium"
      dapat-dipilih
      :terpilih="terpilihId"
      :memuat="kueriF.isPending.value"
      :memuat-lagi="kueriF.isFetchingNextPage.value"
      :error="kueriF.isError.value ? pesanError(kueriF.error.value) : null"
      :ada-lagi="kueriF.hasNextPage.value"
      teks-kosong="Belum ada formularium. Tambahkan formularium bila akun Anda berhak."
      @pilih="(f: Formularium) => (terpilihId = f.id)"
      @muat-lagi="kueriF.fetchNextPage()"
      @coba-lagi="kueriF.refetch()"
    >
      <template #sel-kode="{ baris: f }"><code>{{ f.kode }}</code></template>
      <template #sel-nama="{ baris: f }">
        <b>{{ f.nama }}</b>
        <span v-if="f.penjamin" class="sekunder">{{ f.penjamin.nama }}</span>
      </template>
      <template #sel-jenis="{ baris: f }">{{ LABEL_JENIS_FORMULARIUM[f.jenis] }}</template>
      <template #sel-berlaku="{ baris: f }">{{ teksRentang(f.berlaku_dari, f.berlaku_sampai) }}</template>
    </DataTable>
  </section>

  <section v-if="terpilih" class="kartu" aria-labelledby="judul-item-formularium">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-item-formularium">Item {{ terpilih.nama }}</h2>
        <p>{{ LABEL_JENIS_FORMULARIUM[terpilih.jenis] }} · {{ teksRentang(terpilih.berlaku_dari, terpilih.berlaku_sampai) }} · {{ terpilih.jumlah_item_aktif }} item aktif</p>
      </div>
      <div class="aksi">
        <Tombol v-can="'farmasi.formularium.perbaiki'" :sibuk="memuat" @click="bukaFormularium(false)">Ubah formularium</Tombol>
        <button v-can="'farmasi.formularium.input'" type="button" class="tombol" @click="dialogImpor = true">Impor CSV item</button>
        <button v-can="'farmasi.formularium.input'" type="button" class="tombol tombol-utama" @click="bukaItem(null)"><Ikon nama="tambah" :ukuran="16" />Tambah barang</button>
      </div>
    </div>
    <div class="filter">
      <label class="sr-only" for="cari-item">Cari item formularium</label>
      <input id="cari-item" v-model="cari" type="search" class="isian" placeholder="Cari kode, nama, atau zat aktif" />
      <label class="sr-only" for="status-item">Status item</label>
      <select id="status-item" v-model="status" class="isian">
        <option value="aktif">Aktif</option>
        <option value="nonaktif">Nonaktif</option>
        <option value="">Semua status</option>
      </select>
    </div>
    <DataTable
      :kolom="kolomI"
      :baris="barisI"
      :kunci-baris="(i: ItemFormularium) => i.id"
      label="Item formularium"
      :memuat="kueriI.isPending.value"
      :memuat-lagi="kueriI.isFetchingNextPage.value"
      :error="kueriI.isError.value ? pesanError(kueriI.error.value) : null"
      :ada-lagi="kueriI.hasNextPage.value"
      teks-kosong="Belum ada item yang cocok. Ubah filter, atau tambahkan barang ke formularium ini."
      @muat-lagi="kueriI.fetchNextPage()"
      @coba-lagi="kueriI.refetch()"
    >
      <template #sel-kode="{ baris: i }"><code>{{ i.barang.kode }}</code></template>
      <template #sel-nama="{ baris: i }">
        <b>{{ i.barang.nama }}</b>
        <span v-if="ringkasObat(i.barang)" class="sekunder">{{ ringkasObat(i.barang) }}</span>
        <span v-if="i.barang.status === 'nonaktif'" class="pil pil-netral">Barang nonaktif</span>
      </template>
      <template #sel-restriksi="{ baris: i }">{{ i.restriksi ?? 'Tanpa restriksi' }}</template>
      <template #sel-maks_per_resep="{ baris: i }">{{ i.maks_per_resep === null ? 'Tidak dibatasi' : `${i.maks_per_resep} ${i.barang.satuan_terkecil}` }}</template>
      <template #sel-kronis="{ baris: i }">{{ i.kronis ? 'Ya' : 'Tidak' }}</template>
      <template #sel-status="{ baris: i }">
        <span class="pil" :class="i.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ i.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
      </template>
      <template #sel-aksi="{ baris: i }">
        <span class="aksi">
          <Tombol :aria-label="`Ubah item ${i.barang.nama}`" @click="bukaItem(i)">Ubah</Tombol>
          <Tombol :aria-label="`${i.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} item ${i.barang.nama}`" @click="bukaItem(i, 'status')">{{ i.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
        </span>
      </template>
    </DataTable>
    <DialogItem v-model:open="dialogItem" :formularium-id="terpilih.id" :item="targetI" @selesai="selesai" @muat-ulang="selesai" />
    <DialogStatus
      v-model:open="dialogStatus"
      objek="item formularium"
      :nama="targetI ? `${targetI.data.barang.kode} · ${targetI.data.barang.nama}` : ''"
      :status="targetI?.data.status ?? 'aktif'"
      dampak-nonaktif="tidak lagi dihitung sebagai item aktif formularium ini. Barangnya tetap ada di katalog."
      :simpan="simpanStatus"
      :butir-usulan="butirStatus"
      @selesai="selesai"
      @muat-ulang="selesai"
    />
    <DialogImpor
      v-model:open="dialogImpor"
      judul="Impor item formularium"
      :uraian="`Berkas CSV menambahkan barang ke ${terpilih.nama}. Barang dirujuk lewat kode barang. Satu baris salah berarti tidak ada item yang disimpan.`"
      :kolom="KOLOM_CSV_ITEM"
      nama-templat="templat-item-formularium.csv"
      :impor="imporKeFormularium"
      @selesai="selesai"
    />
  </section>
  <DialogFormularium v-model:open="dialogF" :formularium="targetF" @selesai="selesai" @muat-ulang="selesai" />
</template>

<style scoped>
.kartu + .kartu {
  margin-top: var(--space-4);
}
.filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 160px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.aksi {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.sekunder {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
@media (max-width: 768px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
