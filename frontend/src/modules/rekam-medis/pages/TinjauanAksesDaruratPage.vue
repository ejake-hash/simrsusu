<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { type AksesDarurat, daftarAksesDarurat, type StatusTinjauan } from '@/shared/api/aksesDarurat'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import DialogTinjauAksesDarurat from '../components/DialogTinjauAksesDarurat.vue'
import { LABEL_KATEGORI_ALASAN } from '../aksesDarurat'

/**
 * Rekam Medis → Tinjauan akses darurat (tiket 2.21; FR-26-12, UC-33): akses darurat RM seluruh RS, yang menunggu tinjauan dulu.
 * Tautan tugas kotak kerja memakai ?akses=<id> dan menyorot barisnya bila ada di halaman pertama.
 */
const qc = useQueryClient()
const route = useRoute()
const status = ref<StatusTinjauan>('menunggu')
const sorot = computed(() => (typeof route.query.akses === 'string' ? route.query.akses : null))

const kueri = useInfiniteQuery({
  queryKey: computed(() => ['rekam-medis', 'akses-darurat', status.value]),
  queryFn: ({ pageParam }) => daftarAksesDarurat(status.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed<AksesDarurat[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])

const ditinjau = ref<AksesDarurat | null>(null)
const dialogTinjau = ref(false)
function tinjau(a: AksesDarurat) {
  ditinjau.value = a
  dialogTinjau.value = true
}
function selesaiTinjau() {
  tampilkanToast('Tinjauan disimpan.', 'ok')
  void qc.invalidateQueries({ queryKey: ['rekam-medis', 'akses-darurat'] })
}

const kolom: KolomTabel[] = [
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'pengguna', label: 'Dibuka oleh', prioritas: 2 },
  { kunci: 'alasan', label: 'Alasan' },
  { kunci: 'masa', label: 'Masa berlaku', lebar: '190px' },
  { kunci: 'jumlah', label: 'Jumlah akses', lebar: '110px', prioritas: 2 },
  { kunci: 'tinjauan', label: 'Tinjauan', lebar: '220px' },
]
</script>

<template>
  <HalamanModul izin="rekam_medis.akses_darurat.lihat" judul="Tinjauan akses darurat" uraian="Akses darurat rekam medis oleh tenaga klinis di luar cakupan biasa. Setiap akses perlu ditinjau wajar atau tidaknya.">
    <section class="kartu" aria-labelledby="judul-akses-darurat">
      <div class="kartu-kepala">
        <h2 id="judul-akses-darurat">Daftar akses darurat</h2>
      </div>
      <div class="baris-filter">
        <KolomIsian v-slot="a" label="Status tinjauan">
          <select v-bind="a" v-model="status" class="isian">
            <option value="menunggu">Menunggu tinjauan</option>
            <option value="ditinjau">Sudah ditinjau</option>
          </select>
        </KolomIsian>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(a: AksesDarurat) => a.id"
        label="Akses darurat"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        :teks-kosong="status === 'menunggu' ? 'Tidak ada akses darurat yang menunggu tinjauan.' : 'Belum ada akses darurat yang ditinjau.'"
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-pasien="{ baris: a }">
          <b>{{ a.pasien.nama }}</b>
          <span class="ket">No. RM {{ formatNoRm(a.pasien.no_rm) }} · {{ formatTanggal(a.pasien.tanggal_lahir) }}</span>
          <Lencana v-if="a.id === sorot" jenis="info">Dari tugas Anda</Lencana>
        </template>
        <template #sel-pengguna="{ baris: a }">{{ a.pengguna.nama }}</template>
        <template #sel-alasan="{ baris: a }">
          <b>{{ LABEL_KATEGORI_ALASAN[a.kategori_alasan] }}</b>
          <span class="ket alasan">{{ a.alasan }}</span>
        </template>
        <template #sel-masa="{ baris: a }">
          {{ formatTanggalWaktu(a.mulai_pada) }}
          <span class="ket">sampai {{ formatTanggalWaktu(a.berakhir_pada) }}</span>
          <Lencana :jenis="a.aktif ? 'peringatan' : 'netral'">{{ a.aktif ? 'Aktif' : 'Berakhir' }}</Lencana>
        </template>
        <template #sel-jumlah="{ baris: a }">{{ a.jumlah_akses }}</template>
        <template #sel-tinjauan="{ baris: a }">
          <template v-if="a.tinjauan">
            <Lencana :jenis="a.tinjauan.hasil === 'wajar' ? 'ok' : 'bahaya'">{{ a.tinjauan.hasil === 'wajar' ? 'Wajar' : 'Tidak wajar' }}</Lencana>
            <span class="ket">{{ a.tinjauan.oleh.nama }}, {{ formatTanggalWaktu(a.tinjauan.pada) }}</span>
            <span class="ket alasan">{{ a.tinjauan.catatan }}</span>
          </template>
          <Tombol v-else-if="a.dapat.tinjau" :aria-label="`Tinjau akses darurat ${a.pasien.nama}`" @click="tinjau(a)">Tinjau</Tombol>
          <span v-else class="ket">Menunggu peninjau</span>
        </template>
      </DataTable>
    </section>
    <DialogTinjauAksesDarurat v-model:open="dialogTinjau" :akses="ditinjau" @selesai="selesaiTinjau" />
  </HalamanModul>
</template>

<style scoped>
.baris-filter {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.alasan {
  overflow-wrap: anywhere;
}
</style>
