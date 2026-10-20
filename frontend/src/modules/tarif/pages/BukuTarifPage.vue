<script setup lang="ts">
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { semuaKelasRawatAktif } from '@/modules/organisasi/apiLokasi'
import { KUNCI_LOKASI } from '@/modules/organisasi/lokasi'
import type { UsulanDataInduk } from '@/modules/usulan/tipe'
import { formatTanggal } from '@/shared/utils/waktu'
import { daftarTarif } from '../api'
import DialogUsulanTarif from '../components/DialogUsulanTarif.vue'
import RincianTarif from '../components/RincianTarif.vue'
import { KUNCI_TARIF, formatRupiah } from '../tarif'
import type { Tarif } from '../tipe'

/**
 * Master Data → Buku tarif (tiket 2.2, kontrak v0.18.0). Hanya baca: tarif tidak punya jalur ubah langsung, bahkan
 * untuk kasir. Menambah, mengubah, atau menutup tarif = usulan berjenjang (diusulkan, diverifikasi, disetujui) dengan
 * tanggal berlaku; harga tampil per tanggal dan per kelas rawat.
 */
const qc = useQueryClient()
const router = useRouter()
const cari = ref('')
const status = ref<'' | 'aktif' | 'nonaktif'>('aktif')
const kelasId = ref('')
const tanggal = ref('')
const filter = computed(() => ({ cari: cari.value.trim() || undefined, status: status.value || undefined, kelas_rawat_id: kelasId.value || undefined, tanggal: tanggal.value || undefined }))

const kelas = useQuery({ queryKey: KUNCI_LOKASI.kelasAktif, queryFn: semuaKelasRawatAktif, staleTime: 60_000 })
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_TARIF.daftar(filter.value)),
  queryFn: ({ pageParam }) => daftarTarif(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Kode', lebar: '120px' },
  { kunci: 'nama', label: 'Tarif' },
  { kunci: 'harga', label: kelasId.value ? 'Total harga' : 'Total per kelas', rata: 'kanan' },
  { kunci: 'berlaku', label: 'Berlaku sejak', prioritas: 2, lebar: '140px' },
  { kunci: 'keadaan', label: 'Keadaan', prioritas: 2, lebar: '190px' },
  { kunci: 'aksi', label: 'Aksi', rata: 'kanan', lebar: '110px' },
])

const rincian = ref<Tarif | null>(null)
const dialogRincian = ref(false)
const dialogUsulan = ref(false)
const tarifUsul = ref<Tarif | null>(null)
function lihat(t: Tarif) {
  rincian.value = t
  dialogRincian.value = true
}
function usulkan(t: Tarif | null) {
  tarifUsul.value = t
  dialogRincian.value = false
  dialogUsulan.value = true
}
function diajukan(u: UsulanDataInduk) {
  void qc.invalidateQueries({ queryKey: KUNCI_TARIF.semua })
  void qc.invalidateQueries({ queryKey: ['usulan'] })
  tampilkanToast(`${u.nomor} diajukan; menunggu verifikasi.`)
  void router.push({ path: '/master-data/usulan', query: { usulan: u.id } })
}
const tgl = (d: string) => formatTanggal(`${d}T00:00:00`)
const ringkasHarga = (t: Tarif) => (t.harga.length ? t.harga.map((h) => `${h.kelas_rawat_label} ${formatRupiah(h.total)}`) : [])
</script>

<template>
  <HalamanModul
    izin="master_data.tarif.lihat"
    judul="Buku tarif"
    uraian="Harga layanan per kelas rawat: jasa sarana, jasa pelayanan, dan BHP. Kasir dan tagihan hanya membaca. Perubahan harga lewat usulan, berlaku pada tanggal yang disahkan."
  >
    <section class="kartu" aria-labelledby="judul-tarif">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-tarif">Daftar tarif</h2>
          <p>Kosongkan tanggal untuk harga yang berlaku hari ini.</p>
        </div>
        <span class="aksi-kepala">
          <RouterLink v-can="'master_data.usulan.lihat'" class="tombol" to="/master-data/usulan">Lihat usulan</RouterLink>
          <button v-can="'master_data.usulan.input'" type="button" class="tombol" @click="usulkan(null)"><Ikon nama="tambah" :ukuran="16" />Usulkan perubahan tarif</button>
        </span>
      </div>
      <div class="filter">
        <label class="sr-only" for="cari-tarif">Cari kode atau nama tarif</label>
        <input id="cari-tarif" v-model="cari" type="search" class="isian" placeholder="Cari kode atau nama" autocomplete="off" />
        <label class="sr-only" for="kelas-tarif">Kelas rawat</label>
        <select id="kelas-tarif" v-model="kelasId" class="isian">
          <option value="">Semua kelas</option>
          <option v-for="k in kelas.data.value ?? []" :key="k.id" :value="k.id">{{ k.nama }}</option>
        </select>
        <label class="sr-only" for="status-tarif">Status tarif</label>
        <select id="status-tarif" v-model="status" class="isian">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
          <option value="">Semua status</option>
        </select>
        <label class="sr-only" for="tanggal-tarif">Harga pada tanggal</label>
        <input id="tanggal-tarif" v-model="tanggal" type="date" class="isian" />
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(t: Tarif) => t.id"
        label="Daftar tarif"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Tidak ada tarif yang cocok."
        @muat-ulang="kueri.refetch()"
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-nama="{ baris: t }">
          {{ t.nama }}
          <span class="ket">{{ t.kelompok || 'Tanpa kelompok' }}</span>
        </template>
        <template #sel-harga="{ baris: t }">
          <span v-for="h in ringkasHarga(t)" :key="h" class="harga-baris">{{ h }}</span>
        </template>
        <template #sel-berlaku="{ baris: t }">{{ tgl(t.berlaku_dari) }}</template>
        <template #sel-keadaan="{ baris: t }">
          <span class="pil" :class="t.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ t.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
          <span v-if="t.akan_berlaku" class="pil pil-info">Harga baru {{ tgl(t.akan_berlaku.berlaku_dari) }}</span>
          <RouterLink v-if="t.usulan_terbuka" class="ket" :to="{ path: '/master-data/usulan', query: { usulan: t.usulan_terbuka.id } }">Usulan {{ t.usulan_terbuka.nomor }}</RouterLink>
        </template>
        <template #sel-aksi="{ baris: t }">
          <Tombol :aria-label="`Rincian tarif ${t.kode}`" @click="lihat(t)">Rincian</Tombol>
        </template>
      </DataTable>
    </section>
    <RincianTarif v-model:open="dialogRincian" :tarif="rincian" @usulkan="usulkan" />
    <DialogUsulanTarif v-model:open="dialogUsulan" :tarif="tarifUsul" @selesai="diajukan" />
  </HalamanModul>
</template>

<style scoped>
.filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 180px 150px 170px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.aksi-kepala {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
.harga-baris {
  display: block;
  font-variant-numeric: tabular-nums;
}
@media (max-width: 1023.98px) {
  .filter {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 599.98px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
