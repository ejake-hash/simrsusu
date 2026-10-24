<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { daftarGabung } from '../api'
import DialogUsulGabung from '../components/DialogUsulGabung.vue'
import DialogVerifikasiGabung from '../components/DialogVerifikasiGabung.vue'
import { formatNoRm, formatTanggal, KUNCI_GABUNG, LABEL_TAHAP_GABUNG } from '../pasien'
import type { TahapGabung, UsulanGabung } from '../tipe'

/**
 * Pendaftaran → Master pasien (tiket 2.9, kontrak v0.22.0; FR-01-02). Usulan gabung RM ganda: pendaftaran mengusulkan,
 * Rekam Medis memverifikasi (pengusul ≠ pemverifikasi). RM lama ditandai digabung dan tetap bisa ditelusuri.
 */
const qc = useQueryClient()
const tahap = ref<TahapGabung | ''>('diusulkan')

const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_GABUNG.daftar(tahap.value)),
  queryFn: ({ pageParam }) => daftarGabung(tahap.value || null, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const errorTabel = computed(() => (kueri.isError.value ? pesanError(kueri.error.value) : null))

const kolom: KolomTabel[] = [
  { kunci: 'sumber', label: 'RM digabung' },
  { kunci: 'tujuan', label: 'RM dipertahankan' },
  { kunci: 'alasan', label: 'Alasan', prioritas: 3 },
  { kunci: 'tahap', label: 'Status', lebar: '150px' },
  { kunci: 'pengusul', label: 'Diusulkan oleh', prioritas: 2, lebar: '170px' },
  { kunci: 'aksi', label: 'Aksi', rata: 'kanan', lebar: '120px' },
]
const JENIS_LENCANA = { diusulkan: 'peringatan', digabung: 'ok', ditolak: 'netral' } as const

const dialogUsul = ref(false)
const dialogVerifikasi = ref(false)
const terpilih = ref<UsulanGabung | null>(null)
function verifikasi(u: UsulanGabung) {
  terpilih.value = u
  dialogVerifikasi.value = true
}
function usulSelesai() {
  tampilkanToast('Usulan gabung terkirim ke Rekam Medis.', 'ok')
  void qc.invalidateQueries({ queryKey: KUNCI_GABUNG.semua })
}
function verifikasiSelesai(hasil: UsulanGabung) {
  tampilkanToast(hasil.tahap === 'digabung' ? 'RM digabung. Kunjungan dipindah ke RM tujuan.' : 'Usulan ditolak.', 'ok')
  void qc.invalidateQueries({ queryKey: KUNCI_GABUNG.semua })
  void qc.invalidateQueries({ queryKey: ['pasien'] })
}
</script>

<template>
  <HalamanModul
    izin="pendaftaran.master_pasien.lihat"
    judul="Master pasien"
    uraian="Gabungkan RM ganda lewat usulan yang diverifikasi Rekam Medis. RM lama tidak dihapus: ditandai digabung dan tetap bisa ditelusuri."
  >
    <section class="kartu" aria-labelledby="judul-gabung">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-gabung">Usulan gabung RM</h2>
          <p>Pengusul tidak dapat memverifikasi usulannya sendiri.</p>
        </div>
        <div class="aksi-kepala">
          <label class="saring">
            <span>Status</span>
            <select v-model="tahap" class="isian">
              <option value="">Semua</option>
              <option v-for="(label, kunci) in LABEL_TAHAP_GABUNG" :key="kunci" :value="kunci">{{ label }}</option>
            </select>
          </label>
          <button v-can="'pendaftaran.master_pasien.usul'" type="button" class="tombol tombol-utama" @click="dialogUsul = true"><Ikon nama="tambah" :ukuran="16" />Usulkan gabung</button>
        </div>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(u: UsulanGabung) => u.id"
        label="Usulan gabung RM"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="errorTabel"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Belum ada usulan gabung pada status ini."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-sumber="{ baris: u }"><code>{{ formatNoRm(u.sumber.no_rm) }}</code> {{ u.sumber.nama }}<br /><small>lahir {{ formatTanggal(u.sumber.tanggal_lahir) }}</small></template>
        <template #sel-tujuan="{ baris: u }"><code>{{ formatNoRm(u.tujuan.no_rm) }}</code> {{ u.tujuan.nama }}<br /><small>lahir {{ formatTanggal(u.tujuan.tanggal_lahir) }}</small></template>
        <template #sel-alasan="{ baris: u }">{{ u.alasan }}</template>
        <template #sel-tahap="{ baris: u }">
          <Lencana :jenis="JENIS_LENCANA[u.tahap]">{{ LABEL_TAHAP_GABUNG[u.tahap] }}</Lencana>
          <small v-if="u.catatan_verifikasi" class="catatan-baris">{{ u.catatan_verifikasi }}</small>
        </template>
        <template #sel-pengusul="{ baris: u }">{{ u.diusulkan_oleh.nama_pengguna ?? '—' }}</template>
        <template #sel-aksi="{ baris: u }">
          <Tombol v-if="u.dapat_verifikasi" :aria-label="`Verifikasi gabung ${u.sumber.no_rm}`" @click="verifikasi(u)">Verifikasi</Tombol>
        </template>
      </DataTable>
    </section>

    <DialogUsulGabung v-model:open="dialogUsul" @selesai="usulSelesai" />
    <DialogVerifikasiGabung v-if="terpilih" v-model:open="dialogVerifikasi" :usulan="terpilih" @selesai="verifikasiSelesai" @muat-ulang="kueri.refetch()" />
  </HalamanModul>
</template>

<style scoped>
.aksi-kepala {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--space-2);
}
.saring {
  display: grid;
  gap: 2px;
  font-size: 13px;
  color: var(--muted);
}
small {
  color: var(--muted);
}
.catatan-baris {
  display: block;
}
</style>
