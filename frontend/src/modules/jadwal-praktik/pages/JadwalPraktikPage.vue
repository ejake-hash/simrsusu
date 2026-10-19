<script setup lang="ts">
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { daftarPoliklinik } from '@/modules/organisasi/apiLokasi'
import { boleh } from '@/shared/composables/usePermission'
import { daftarJadwal } from '../api'
import DialogJadwal from '../components/DialogJadwal.vue'
import DialogPengecualian from '../components/DialogPengecualian.vue'
import { KUNCI_JADWAL, LABEL_ALASAN, hariIniLokal, labelHari, nadaSisa } from '../jadwal'
import type { JadwalPengecualian, JadwalPraktik } from '../tipe'

/**
 * Master Data → Jadwal praktik & kuota poli (tiket 2.4, kontrak v0.17.0). Dengan tanggal: slot hari itu beserta
 * sisa kuota JKN/non-JKN dan alasan bila tertutup (cuti/libur). Tanpa tanggal: definisi jadwal untuk pengelolaan.
 */
const qc = useQueryClient()
const tanggal = ref(hariIniLokal())
const poliId = ref('')
const status = ref<'aktif' | 'nonaktif'>('aktif')
const filter = computed(() => ({ tanggal: tanggal.value || undefined, poli_id: poliId.value || undefined, status: status.value }))
const pakaiTanggal = computed(() => !!tanggal.value)

const poli = useQuery({ queryKey: ['jadwal-praktik', 'poli'], queryFn: async () => (await daftarPoliklinik({ status: 'aktif' }, null, 200)).data, staleTime: 60_000 })
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_JADWAL.daftar(filter.value)),
  queryFn: ({ pageParam }) => daftarJadwal(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const bolehUbah = computed(() => boleh('master_data.jadwal_praktik.perbaiki'))
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'waktu', label: 'Hari dan jam', lebar: '150px' },
  { kunci: 'dokter', label: 'Dokter' },
  { kunci: 'poli', label: 'Poliklinik dan ruang', prioritas: 2 },
  { kunci: 'jkn', label: pakaiTanggal.value ? 'Sisa JKN' : 'Kuota JKN', rata: 'kanan', lebar: '110px' },
  { kunci: 'non', label: pakaiTanggal.value ? 'Sisa non-JKN' : 'Kuota non-JKN', rata: 'kanan', lebar: '120px' },
  { kunci: 'keadaan', label: 'Keadaan', prioritas: 2, lebar: '150px' },
  ...(bolehUbah.value ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '220px' }] : []),
])

const dialogJadwal = ref(false)
const dialogPengecualian = ref(false)
const target = ref<{ data: JadwalPraktik; etag: string } | null>(null)
const ubahStatus = ref<'aktif' | 'nonaktif' | null>(null)
const batal = ref<JadwalPengecualian | null>(null)
function bukaJadwal(j: JadwalPraktik | null, st: 'aktif' | 'nonaktif' | null = null) {
  target.value = j ? { data: j, etag: `"${j.versi}"` } : null
  ubahStatus.value = st
  dialogJadwal.value = true
}
function bukaPengecualian(x: JadwalPengecualian | null) {
  batal.value = x
  dialogPengecualian.value = true
}
function selesai() {
  void qc.invalidateQueries({ queryKey: KUNCI_JADWAL.semua })
}
const kuota = (sisa: number, jumlah: number) => `${sisa} / ${jumlah}`
</script>

<template>
  <HalamanModul
    izin="master_data.jadwal_praktik.lihat"
    judul="Jadwal praktik dan kuota poli"
    uraian="Dokter, jam, ruang, dan sisa kuota per poli. Pendaftaran memakai daftar ini untuk memilih slot. Setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <section class="kartu" aria-labelledby="judul-jadwal">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-jadwal">Jadwal praktik</h2>
          <p>Kosongkan tanggal untuk melihat semua definisi jadwal.</p>
        </div>
        <span class="aksi-kepala">
          <button v-can="'master_data.jadwal_praktik.input'" type="button" class="tombol" @click="bukaPengecualian(null)">Catat cuti atau libur</button>
          <button v-can="'master_data.jadwal_praktik.input'" type="button" class="tombol" @click="bukaJadwal(null)"><Ikon nama="tambah" :ukuran="16" />Tambah jadwal</button>
        </span>
      </div>
      <div class="filter">
        <label class="sr-only" for="tanggal-jadwal">Tanggal</label>
        <input id="tanggal-jadwal" v-model="tanggal" type="date" class="isian" />
        <label class="sr-only" for="poli-jadwal">Poliklinik</label>
        <select id="poli-jadwal" v-model="poliId" class="isian">
          <option value="">Semua poliklinik</option>
          <option v-for="p in poli.data.value ?? []" :key="p.id" :value="p.id">{{ p.nama }}</option>
        </select>
        <label class="sr-only" for="status-jadwal">Status jadwal</label>
        <select id="status-jadwal" v-model="status" class="isian">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
        </select>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(j: JadwalPraktik) => j.id"
        label="Daftar jadwal praktik"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Tidak ada jadwal praktik yang cocok."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-waktu="{ baris: j }">
          <b>{{ labelHari(j.hari) }}</b>
          <span class="ket">{{ j.jam_mulai }}–{{ j.jam_selesai }}</span>
        </template>
        <template #sel-dokter="{ baris: j }">{{ j.pegawai.nama }}</template>
        <template #sel-poli="{ baris: j }">
          {{ j.poliklinik.nama }}
          <span class="ket">{{ j.ruang ? `${j.ruang.kode_lengkap} · ${j.ruang.nama}` : 'Ruang belum dipetakan' }}</span>
        </template>
        <template #sel-jkn="{ baris: j }">
          <span v-if="j.pada_tanggal" class="pil" :class="j.pada_tanggal.tersedia ? `pil-${nadaSisa(j.pada_tanggal.sisa_jkn, j.kuota_jkn)}` : 'pil-netral'">{{ kuota(j.pada_tanggal.sisa_jkn, j.kuota_jkn) }}</span>
          <template v-else>{{ j.kuota_jkn }}</template>
        </template>
        <template #sel-non="{ baris: j }">
          <span v-if="j.pada_tanggal" class="pil" :class="j.pada_tanggal.tersedia ? `pil-${nadaSisa(j.pada_tanggal.sisa_non_jkn, j.kuota_non_jkn)}` : 'pil-netral'">{{ kuota(j.pada_tanggal.sisa_non_jkn, j.kuota_non_jkn) }}</span>
          <template v-else>{{ j.kuota_non_jkn }}</template>
        </template>
        <template #sel-keadaan="{ baris: j }">
          <span v-if="j.pada_tanggal && !j.pada_tanggal.tersedia" class="pil pil-bahaya">{{ LABEL_ALASAN[j.pada_tanggal.alasan_tidak_tersedia!] }}</span>
          <span v-else class="pil" :class="j.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ j.status === 'aktif' ? 'Tersedia' : 'Nonaktif' }}</span>
          <span v-for="x in j.pengecualian" :key="x.id" class="ket">
            {{ x.jenis === 'cuti' ? 'Cuti' : 'Libur' }} {{ x.dari_tanggal }}–{{ x.sampai_tanggal }}
            <button v-can="'master_data.jadwal_praktik.perbaiki'" type="button" class="tautan" :aria-label="`Batalkan ${x.jenis === 'cuti' ? 'cuti' : 'libur'} ${x.dari_tanggal}`" @click="bukaPengecualian(x)">Batalkan</button>
          </span>
        </template>
        <template #sel-aksi="{ baris: j }">
          <span class="aksi">
            <Tombol :aria-label="`Ubah jadwal ${j.pegawai.nama} ${labelHari(j.hari)}`" @click="bukaJadwal(j)">Ubah</Tombol>
            <Tombol :aria-label="`${j.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} jadwal ${j.pegawai.nama} ${labelHari(j.hari)}`" @click="bukaJadwal(j, j.status === 'aktif' ? 'nonaktif' : 'aktif')">{{ j.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
          </span>
        </template>
      </DataTable>
      <DialogJadwal v-model:open="dialogJadwal" :jadwal="target" :poli="poli.data.value ?? []" :ubah-status="ubahStatus" @selesai="selesai" @muat-ulang="selesai" />
      <DialogPengecualian v-model:open="dialogPengecualian" :batal="batal" @selesai="selesai" @muat-ulang="selesai" />
    </section>
  </HalamanModul>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: 180px minmax(0, 1fr) 160px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.aksi-kepala,
.aksi {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
.tautan {
  margin-left: var(--space-1);
  padding: 0;
  border: 0;
  background: none;
  color: var(--primary);
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
}
@media (max-width: 599.98px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
