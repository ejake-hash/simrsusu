<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { refDebounced } from '@/shared/composables/refDebounced'
import { boleh } from '@/shared/composables/usePermission'
import { ambilBarang, daftarBarang, imporBarang, ubahBarang } from '../api'
import { JENIS_BARANG, KOLOM_CSV_BARANG, KUNCI_FORMULARIUM, LABEL_GENERIK_PATEN, LABEL_JENIS_BARANG, ringkasObat } from '../formularium'
import type { Barang, JenisBarang, StatusAktif } from '../tipe'
import DialogBarang from './DialogBarang.vue'
import DialogImpor from './DialogImpor.vue'
import DialogStatus from './DialogStatus.vue'

/** Katalog obat dan BMHP (FR-22-06). Barang tidak dihapus; generik dan paten dari zat yang sama = dua kode. */
const qc = useQueryClient()
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<StatusAktif | ''>('aktif')
const jenis = ref<JenisBarang | ''>('')
const tanpaKfa = ref(false)
const filter = computed(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined, jenis: jenis.value || undefined, tanpa_kfa: tanpaKfa.value || undefined }))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_FORMULARIUM.barang(filter.value)),
  queryFn: ({ pageParam }) => daftarBarang(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '140px' },
  { kunci: 'nama', label: 'Barang' },
  { kunci: 'jenis', label: 'Jenis', prioritas: 2, lebar: '90px' },
  { kunci: 'satuan_terkecil', label: 'Satuan', prioritas: 3, lebar: '100px' },
  { kunci: 'kode_kfa', label: 'Kode KFA', prioritas: 3, lebar: '130px' },
  { kunci: 'tanda', label: 'Tanda', prioritas: 2, lebar: '170px' },
  { kunci: 'status', label: 'Status', lebar: '100px' },
  ...(boleh('farmasi.formularium.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '200px' }] : []),
])

const dialogBarang = ref(false)
const dialogStatus = ref(false)
const dialogImpor = ref(false)
const target = ref<Berversi<Barang> | null>(null)
const memuatId = ref<string | null>(null)
/** Rincian dimuat dulu agar If-Match memakai versi terbaru, bukan versi saat daftar dibuka. */
async function buka(b: Barang | null, aksi: 'ubah' | 'status' = 'ubah') {
  if (!b) {
    target.value = null
    dialogBarang.value = true
    return
  }
  memuatId.value = b.id
  try {
    target.value = await ambilBarang(b.id)
    if (aksi === 'ubah') dialogBarang.value = true
    else dialogStatus.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuatId.value = null
  }
}
const selesai = () => void qc.invalidateQueries({ queryKey: KUNCI_FORMULARIUM.semua })
const simpanStatus = (s: StatusAktif, dasar: string) => ubahBarang(target.value!.data.id, target.value!.etag, { status: s, dasar_perubahan: dasar })
</script>

<template>
  <section class="kartu" aria-labelledby="judul-katalog">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-katalog">Katalog barang</h2>
        <p>Obat dan BMHP yang bisa masuk formularium. Barang nonaktif tidak muncul di pilihan resep, tetapi riwayat lama tetap menampilkannya.</p>
      </div>
      <div class="aksi">
        <button v-can="'farmasi.formularium.input'" type="button" class="tombol" @click="dialogImpor = true">Impor CSV</button>
        <button v-can="'farmasi.formularium.input'" type="button" class="tombol tombol-utama" @click="buka(null)"><Ikon nama="tambah" :ukuran="16" />Tambah barang</button>
      </div>
    </div>
    <div class="filter">
      <label class="sr-only" for="cari-barang">Cari barang</label>
      <input id="cari-barang" v-model="cari" type="search" class="isian" placeholder="Cari kode, nama, atau zat aktif" />
      <label class="sr-only" for="jenis-barang">Jenis barang</label>
      <select id="jenis-barang" v-model="jenis" class="isian">
        <option value="">Semua jenis</option>
        <option v-for="j in JENIS_BARANG" :key="j" :value="j">{{ LABEL_JENIS_BARANG[j] }}</option>
      </select>
      <label class="sr-only" for="status-barang">Status barang</label>
      <select id="status-barang" v-model="status" class="isian">
        <option value="aktif">Aktif</option>
        <option value="nonaktif">Nonaktif</option>
        <option value="">Semua status</option>
      </select>
      <label class="centang"><input v-model="tanpaKfa" type="checkbox" /> Tanpa kode KFA</label>
    </div>
    <DataTable
      :kolom="kolom"
      :baris="baris"
      :kunci-baris="(b: Barang) => b.id"
      label="Daftar barang"
      :memuat="kueri.isPending.value"
      :memuat-lagi="kueri.isFetchingNextPage.value"
      :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
      :ada-lagi="kueri.hasNextPage.value"
      teks-kosong="Belum ada barang yang cocok. Ubah filter, atau tambahkan barang bila akun Anda berhak."
      @muat-lagi="kueri.fetchNextPage()"
      @coba-lagi="kueri.refetch()"
    >
      <template #sel-kode="{ baris: b }"><code>{{ b.kode }}</code></template>
      <template #sel-nama="{ baris: b }">
        <b>{{ b.nama }}</b>
        <span v-if="ringkasObat(b) || b.generik_paten" class="sekunder">{{ [b.generik_paten ? LABEL_GENERIK_PATEN[b.generik_paten] : '', ringkasObat(b)].filter(Boolean).join(' · ') }}</span>
      </template>
      <template #sel-jenis="{ baris: b }">{{ LABEL_JENIS_BARANG[b.jenis] }}</template>
      <template #sel-kode_kfa="{ baris: b }">{{ b.kode_kfa ?? 'Belum diisi' }}</template>
      <template #sel-tanda="{ baris: b }">
        <span class="tanda">
          <span v-if="b.kewaspadaan_tinggi" class="pil pil-peringatan">Waspada tinggi</span>
          <span v-if="b.narkotika" class="pil pil-peringatan">Narkotika</span>
          <span v-if="b.lasa" class="pil pil-netral">LASA</span>
        </span>
      </template>
      <template #sel-status="{ baris: b }">
        <span class="pil" :class="b.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ b.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
      </template>
      <template #sel-aksi="{ baris: b }">
        <span class="aksi">
          <Tombol :sibuk="memuatId === b.id" :aria-label="`Ubah ${b.nama}`" @click="buka(b)">Ubah</Tombol>
          <Tombol :sibuk="memuatId === b.id" :aria-label="`${b.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} ${b.nama}`" @click="buka(b, 'status')">{{ b.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
        </span>
      </template>
    </DataTable>
    <DialogBarang v-model:open="dialogBarang" :barang="target" @selesai="selesai" @muat-ulang="selesai" />
    <DialogStatus
      v-model:open="dialogStatus"
      objek="barang"
      :nama="target ? `${target.data.kode} · ${target.data.nama}` : ''"
      :status="target?.data.status ?? 'aktif'"
      dampak-nonaktif="tidak bisa ditambahkan ke formularium dan tidak muncul di pilihan resep. Item formularium yang memuatnya tetap tampil, bertanda nonaktif."
      :simpan="simpanStatus"
      @selesai="selesai"
      @muat-ulang="selesai"
    />
    <DialogImpor
      v-model:open="dialogImpor"
      judul="Impor katalog barang"
      uraian="Berkas CSV hanya menambah barang baru. Satu baris salah berarti tidak ada barang yang disimpan."
      :kolom="KOLOM_CSV_BARANG"
      nama-templat="templat-barang.csv"
      :impor="imporBarang"
      @selesai="selesai"
    />
  </section>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 160px 160px auto;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.centang {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  white-space: nowrap;
}
.aksi,
.tanda {
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
