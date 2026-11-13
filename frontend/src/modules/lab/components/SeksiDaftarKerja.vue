<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed, useId } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { INTERVAL_LAYAR_KERJA } from '@/modules/notifikasi/polling'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { ApiError } from '@/shared/api/klien'
import { LABEL_STATUS_ITEM, NADA_STATUS_ITEM, type PrioritasPermintaan } from '@/shared/api/permintaanPemeriksaan'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { daftarKerjaLab } from '../api'
import { bolehAdendum, bolehAmbil, bolehInput, bolehTerima, bolehValidasi, KUNCI_LAB, labelValidasi, teksAsal, teksLokasi } from '../lab'
import type { BarisDaftarKerjaLab, ButirDaftarKerjaLab, TahapDaftarKerjaLab } from '../tipe'
import TampilanHasilLab from './TampilanHasilLab.vue'

/**
 * Satu seksi daftar kerja lab per prioritas dan tahap (kueri sendiri, kursor "Muat lagi", polling 15 detik yang berhenti
 * saat tab tersembunyi karena refetchIntervalInBackground bawaan false). Aksi per butir hanya menurut `dapat` dari server.
 */
type Aksi = [baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab]
const props = withDefaults(defineProps<{ prioritas: PrioritasPermintaan; judul: string; uraian: string; teksKosong: string; tahap?: TahapDaftarKerjaLab }>(), { tahap: 'spesimen' })
const emit = defineEmits<{ ambil: Aksi; terima: Aksi; input: Aksi; validasi: Aksi; adendum: Aksi }>()
const idJudul = useId()
const adaAksi = (i: ButirDaftarKerjaLab) => bolehAmbil(i) || bolehTerima(i) || bolehInput(i) || bolehValidasi(i) || bolehAdendum(i.hasil)

const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_LAB.daftar(props.prioritas, props.tahap)),
  queryFn: ({ pageParam }) => daftarKerjaLab(props.prioritas, pageParam, 50, props.tahap),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  refetchInterval: INTERVAL_LAYAR_KERJA,
  // Tanpa izin lihat: HalamanModul menampilkan Akses ditolak; tidak ada GET atau polling yang berujung 403.
  enabled: computed(() => boleh('penunjang.laboratorium.lihat')),
})
const baris = computed<BarisDaftarKerjaLab[]>(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
// Izin unit saja (bukan cakupan RS) → 403 dari server: tampil sebagai akses ditolak, bukan galat umum.
const ditolak = computed(() => kueri.error.value instanceof ApiError && kueri.error.value.status === 403)

const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'asal', label: 'Asal dan lokasi', lebar: '200px' },
  { kunci: 'order', label: 'Order', prioritas: 2 },
  { kunci: 'butir', label: props.tahap === 'spesimen' ? 'Pemeriksaan dan spesimen' : 'Pemeriksaan dan hasil', lebar: '40%' },
])
</script>

<template>
  <section class="kartu" :aria-labelledby="idJudul">
    <div class="kartu-kepala">
      <div>
        <div class="judul-seksi">
          <h2 :id="idJudul">{{ judul }}</h2>
          <Lencana v-if="prioritas === 'cito'" jenis="bahaya">Didahulukan</Lencana>
        </div>
        <p>{{ uraian }}</p>
      </div>
    </div>
    <KeadaanKosong v-if="ditolak" jenis="error" judul="Akses ditolak" :uraian="`${pesanError(kueri.error.value)} Daftar kerja lab butuh izin laboratorium cakupan RS; minta lewat Pengaturan peran.`" />
    <DataTable
      v-else
      :kolom="kolom"
      :baris="baris"
      :kunci-baris="(b: BarisDaftarKerjaLab) => b.id"
      :label="`Daftar kerja lab ${judul}`"
      :memuat="kueri.isPending.value"
      :memuat-lagi="kueri.isFetchingNextPage.value"
      :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
      :ada-lagi="kueri.hasNextPage.value"
      :teks-kosong="teksKosong"
      @muat-lagi="kueri.fetchNextPage()"
      @coba-lagi="kueri.refetch()"
    >
      <template #sel-pasien="{ baris: b }">
        <b>{{ b.pasien.nama }}</b>
        <span class="ket">No. RM {{ formatNoRm(b.pasien.no_rm) }} · lahir {{ formatTanggal(b.pasien.tanggal_lahir) }}</span>
      </template>
      <template #sel-asal="{ baris: b }">
        <span class="label-kecil">Asal order</span>
        <span>{{ teksAsal(b) }}</span>
        <span class="label-kecil">Lokasi sekarang</span>
        <span :class="{ ket: !b.lokasi_saat_ini }">{{ teksLokasi(b) }}</span>
      </template>
      <template #sel-order="{ baris: b }">
        <span class="angka">{{ formatTanggalWaktu(b.dipesan_pada) }}</span>
        <span class="ket">{{ b.pemesan?.nama ?? 'Pemesan tidak tercatat' }}</span>
        <span class="ket">Indikasi: {{ b.diagnosis_kerja }}</span>
      </template>
      <template #sel-butir="{ baris: b }">
        <ul class="butir">
          <li v-for="i in b.item" :key="i.id">
            <div class="butir-isi">
              <span><code>{{ i.kode_item }}</code> {{ i.nama }}</span>
              <span class="tanda">
                <Lencana :jenis="NADA_STATUS_ITEM[i.status]">{{ LABEL_STATUS_ITEM[i.status] }}</Lencana>
                <span v-if="i.spesimen" class="barcode"><span class="ket-inline">Barcode</span> <code>{{ i.spesimen.barcode }}</code></span>
              </span>
              <span v-if="i.spesimen" class="ket">{{ i.spesimen.jenis }}</span>
              <TampilanHasilLab v-if="i.hasil" :hasil="i.hasil" />
            </div>
            <div v-if="adaAksi(i)" class="aksi">
              <Tombol v-if="bolehAmbil(i)" varian="utama" :aria-label="`Ambil sampel ${i.nama} ${b.pasien.nama}`" @click="emit('ambil', b, i)">Ambil sampel</Tombol>
              <Tombol v-if="bolehTerima(i)" varian="utama" :aria-label="`Terima di lab ${i.nama} ${b.pasien.nama}`" @click="emit('terima', b, i)">Terima di lab</Tombol>
              <Tombol v-if="bolehInput(i)" :varian="i.hasil ? 'kedua' : 'utama'" :aria-label="`${i.hasil ? 'Input ulang hasil' : 'Input hasil'} ${i.nama} ${b.pasien.nama}`" @click="emit('input', b, i)">{{ i.hasil ? 'Input ulang' : 'Input hasil' }}</Tombol>
              <Tombol v-if="bolehValidasi(i)" varian="utama" :aria-label="`${labelValidasi(i)} ${i.nama} ${b.pasien.nama}`" @click="emit('validasi', b, i)">{{ labelValidasi(i) }}</Tombol>
              <Tombol v-if="bolehAdendum(i.hasil)" varian="kedua" :aria-label="`Adendum ${i.nama} ${b.pasien.nama}`" @click="emit('adendum', b, i)">Adendum</Tombol>
            </div>
          </li>
        </ul>
      </template>
    </DataTable>
  </section>
</template>

<style scoped>
.judul-seksi {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.ket-inline {
  font-size: 12px;
  color: var(--muted);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.label-kecil {
  display: block;
  margin-top: var(--space-1);
  font-size: 12px;
  color: var(--muted);
}
.label-kecil:first-child {
  margin-top: 0;
}
.angka {
  display: block;
  font-variant-numeric: tabular-nums;
}
.butir {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.butir li {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-2);
}
.butir li + li {
  padding-top: var(--space-2);
  border-top: 1px solid var(--border);
}
.butir-isi {
  display: grid;
  gap: var(--space-1);
  min-width: 0;
}
.tanda {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.barcode code {
  font-family: var(--font-mono);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
</style>
