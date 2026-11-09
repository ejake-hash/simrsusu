<script setup lang="ts">
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatMenit, KUNCI_PAPAN } from '@/modules/igd/papan'
import type { Berversi } from '@/modules/organisasi/api'
import { boleh } from '@/shared/composables/usePermission'
import { ambilAturanPapanIgd, daftarAreaIgd, KUNCI_DATA_INDUK_PAPAN } from '../api'
import DialogAreaIgd from '../components/DialogAreaIgd.vue'
import DialogAturanPapanIgd from '../components/DialogAturanPapanIgd.vue'
import DialogStatusAreaIgd from '../components/DialogStatusAreaIgd.vue'
import type { AreaIgdDataInduk, AturanPapanIgd, StatusAreaIgd } from '../tipe'

/**
 * Master Data → Area & batas papan IGD (tiket 2.25, kontrak v0.34.0; FR-03-03, FR-03-04, FR-00-06). Area dan batas
 * waktu ditetapkan RS; papan IGD membaca area aktif dan batas yang berlaku. Pola instrumen triase: ubah langsung berizin
 * dengan dasar perubahan, tercatat di jejak audit; tanpa hapus (area dinonaktifkan).
 */
const qc = useQueryClient()

// ── Batas waktu ──
const aturan = useQuery({ queryKey: KUNCI_DATA_INDUK_PAPAN.aturan, queryFn: ambilAturanPapanIgd })
const dialogAturan = ref(false)

// ── Area ──
const status = ref<StatusAreaIgd | ''>('aktif')
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_DATA_INDUK_PAPAN.area(status.value || null)),
  queryFn: ({ pageParam }) => daftarAreaIgd(status.value || null, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '200px' },
  { kunci: 'nama', label: 'Nama area' },
  { kunci: 'urutan', label: 'Urutan tampil', rata: 'kanan', lebar: '130px' },
  { kunci: 'status', label: 'Status', lebar: '120px' },
  ...(boleh('master_data.papan_igd.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '230px' }] : []),
])

const dialogArea = ref(false)
const dialogStatus = ref(false)
const target = ref<AreaIgdDataInduk | null>(null)
function bukaArea(a: AreaIgdDataInduk | null, aksi: 'ubah' | 'status' = 'ubah') {
  target.value = a
  if (aksi === 'ubah') dialogArea.value = true
  else dialogStatus.value = true
}

function segarkan() {
  void qc.invalidateQueries({ queryKey: KUNCI_DATA_INDUK_PAPAN.semua })
  // Pilihan area dan tanda di papan IGD ikut diperbarui.
  void qc.invalidateQueries({ queryKey: KUNCI_PAPAN.semua })
}
function areaTersimpan(hasil?: Berversi<AreaIgdDataInduk>) {
  if (hasil) tampilkanToast(`Area ${hasil.data.kode} tersimpan.`, 'ok')
  segarkan()
}
function aturanTersimpan(hasil?: Berversi<AturanPapanIgd>) {
  if (hasil) tampilkanToast('Batas waktu papan IGD tersimpan. Berlaku untuk isi papan berikutnya.', 'ok')
  segarkan()
}
</script>

<template>
  <HalamanModul
    izin="master_data.papan_igd.lihat"
    judul="Area & batas papan IGD"
    uraian="Area penempatan pasien dan batas waktu yang dipakai Papan pasien IGD. Isinya ditetapkan rumah sakit; setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <section class="kartu" aria-labelledby="judul-batas-papan">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-batas-papan">Batas waktu</h2>
          <p>Batas 6 jam di IGD mengikuti aturan episode INA-CBG dan tidak diubah di sini.</p>
        </div>
        <Tombol v-if="boleh('master_data.papan_igd.perbaiki') && aturan.data.value" @click="dialogAturan = true">Ubah batas waktu</Tombol>
      </div>
      <KeadaanKosong v-if="aturan.isPending.value" jenis="memuat" judul="Memuat batas waktu…" />
      <KeadaanKosong v-else-if="aturan.isError.value" jenis="error" judul="Batas waktu gagal dimuat" :uraian="pesanError(aturan.error.value)">
        <template #aksi><Tombol @click="aturan.refetch()">Coba lagi</Tombol></template>
      </KeadaanKosong>
      <dl v-else-if="aturan.data.value" class="batas">
        <div>
          <dt>Batas waktu tanggap</dt>
          <dd>
            <b class="angka">{{ formatMenit(aturan.data.value.data.batas_tanggap_menit) }}</b>
            <span class="ket">Dari jam tiba sampai dokter mulai mengisi asesmen awal. Sebelum batas: Menunggu dokter; sesudahnya: Lewat batas tanggap. Tidak ada tahap peringatan.</span>
          </dd>
        </div>
        <div>
          <dt>Tanda mendekati 6 jam</dt>
          <dd>
            <template v-if="aturan.data.value.data.peringatan_lama_igd_menit !== null">
              <b class="angka">{{ formatMenit(aturan.data.value.data.peringatan_lama_igd_menit) }} sebelum 6 jam</b>
              <span class="ket">Papan menandai Mendekati 6 jam sejak sisa waktu sampai 6 jam sama dengan atau kurang dari angka ini.</span>
            </template>
            <template v-else>
              <Lencana jenis="netral">Tidak dipakai</Lencana>
              <span class="ket">Ambang belum diisi rumah sakit, jadi tanda ini mati. Tanda Lebih dari 6 jam tetap tampil.</span>
            </template>
          </dd>
        </div>
      </dl>
      <DialogAturanPapanIgd v-model:open="dialogAturan" :aturan="aturan.data.value ?? null" @selesai="aturanTersimpan" @muat-ulang="aturanTersimpan()" />
    </section>

    <section class="kartu" aria-labelledby="judul-area-igd">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-area-igd">Area IGD</h2>
          <p>Hanya area aktif yang bisa dipilih saat menempatkan pasien. Area nonaktif tetap tampil pada pasien yang sudah ditempatkan.</p>
        </div>
        <button v-can="'master_data.papan_igd.input'" type="button" class="tombol" @click="bukaArea(null)"><Ikon nama="tambah" :ukuran="16" />Tambah area</button>
      </div>
      <div class="filter">
        <label class="sr-only" for="status-area-igd">Status area</label>
        <select id="status-area-igd" v-model="status" class="isian">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
          <option value="">Semua status</option>
        </select>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(a: AreaIgdDataInduk) => a.id"
        label="Daftar area IGD"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Belum ada area dengan status ini. Ubah filter, atau tambahkan area bila akun Anda berhak."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-kode="{ baris: a }"><code>{{ a.kode }}</code></template>
        <template #sel-nama="{ baris: a }"><b>{{ a.nama }}</b></template>
        <template #sel-urutan="{ baris: a }"><span class="angka">{{ a.urutan }}</span></template>
        <template #sel-status="{ baris: a }">
          <Lencana :jenis="a.status === 'aktif' ? 'ok' : 'netral'">{{ a.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</Lencana>
        </template>
        <template #sel-aksi="{ baris: a }">
          <span class="aksi">
            <Tombol :aria-label="`Ubah ${a.nama}`" @click="bukaArea(a)">Ubah</Tombol>
            <Tombol :aria-label="`${a.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} ${a.nama}`" @click="bukaArea(a, 'status')">{{ a.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
          </span>
        </template>
      </DataTable>
      <DialogAreaIgd v-model:open="dialogArea" :area="target" @selesai="areaTersimpan" @muat-ulang="areaTersimpan()" />
      <DialogStatusAreaIgd v-model:open="dialogStatus" :area="target" @selesai="areaTersimpan" @muat-ulang="areaTersimpan()" />
    </section>
  </HalamanModul>
</template>

<style scoped>
.batas {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: var(--space-4);
  margin: 0;
  padding: var(--space-3) var(--space-4) var(--space-4);
}
.batas dt {
  margin-bottom: var(--space-1);
  font-size: 13px;
  color: var(--muted);
}
.batas dd {
  margin: 0;
}
.ket {
  display: block;
  margin-top: var(--space-1);
  font-size: 13px;
  color: var(--muted);
}
.angka {
  font-variant-numeric: tabular-nums;
}
.filter {
  display: grid;
  grid-template-columns: 200px;
  padding: var(--space-3) var(--space-4);
}
.aksi {
  display: inline-flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>
