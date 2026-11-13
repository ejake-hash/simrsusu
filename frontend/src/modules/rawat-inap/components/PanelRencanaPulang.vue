<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, useId, watch } from 'vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { hariIniRs } from '../admisi'
import { bacaRencanaPulang, simpanRencanaPulang } from '../api'
import { KUNCI_PETA } from '../peta'
import {
  IZIN_PEMULANGAN,
  isianAwalRencana,
  isianPutRencana,
  KUNCI_PEMULANGAN,
  kurangRencana,
  pesanGalatRencana,
  rencanaBerubah,
  tandaButir,
  teksLamaRawat,
  type IsianRencanaLayar,
} from '../pemulangan'

/**
 * Rencana pulang dan daftar periksa (FR-05-01, FR-05-03; non-klinis, `rawat_inap.rencana_pulang.*`). Membuat butuh izin
 * input, mengubah butuh izin perbaiki dengan If-Match; 412/428 memuat ulang isian dulu, baru pesan. Butir sumber kejadian
 * (tagihan/jaminan) tidak bisa dicentang: menunggu kasir (Tahap D). Sesudah pulang, rencana hanya dibaca.
 */
const props = defineProps<{ kunjunganId: string }>()
const qc = useQueryClient()
const idJudul = useId()

const kueri = useQuery({ queryKey: computed(() => KUNCI_PEMULANGAN.rencana(props.kunjunganId)), queryFn: () => bacaRencanaPulang(props.kunjunganId) })
const rencana = computed(() => kueri.data.value ?? null)
const berjalan = computed(() => !!rencana.value && ['terdaftar', 'dilayani'].includes(rencana.value.kunjungan_status) && rencana.value.status !== 'pulang')
const bisaUbah = computed(() => berjalan.value && boleh(rencana.value?.id === null ? IZIN_PEMULANGAN.rencanaInput : IZIN_PEMULANGAN.rencanaPerbaiki))

const isian = ref<IsianRencanaLayar>({ tanggal: '', lamaRawat: '', selesai: {} })
// Isian mengikuti rencana terbaru (sesudah simpan, 412, atau muat ulang), bukan sisa isian lama.
watch(
  () => [rencana.value?.id, rencana.value?.versi, rencana.value?.kunjungan_id] as const,
  () => {
    if (rencana.value) isian.value = isianAwalRencana(rencana.value, hariIniRs())
  },
  { immediate: true },
)
const berubah = computed(() => !!rencana.value && rencanaBerubah(rencana.value, isian.value))

const galatKolom = ref<Record<string, string>>({})
const pesan = ref<string | null>(null)
const menyimpan = ref(false)
async function simpan() {
  const r = rencana.value
  if (!r || !bisaUbah.value) return
  galatKolom.value = kurangRencana(isian.value)
  pesan.value = null
  if (Object.keys(galatKolom.value).length) return
  menyimpan.value = true
  try {
    const hasil = await simpanRencanaPulang(r.kunjungan_id, r.versi, isianPutRencana(r, isian.value))
    qc.setQueryData(KUNCI_PEMULANGAN.rencana(props.kunjunganId), hasil)
    // Status TT "Rencana pulang" di peta dihitung dari rencana ini.
    void qc.invalidateQueries({ queryKey: KUNCI_PETA.semua })
    tampilkanToast(`Rencana pulang ${hasil.pasien.nama} tersimpan: ${formatTanggal(hasil.rencana_pulang_pada)}.`, 'ok')
  } catch (e) {
    galatKolom.value = errorKolom(e, ['rencana_pulang_pada', 'rencana_lama_rawat'])
    // Data basi: muat ulang dulu, baru pesan tampil.
    if (e instanceof ApiError && (e.status === 412 || e.status === 428 || e.status === 409 || e.status === 404)) await kueri.refetch()
    pesan.value = pesanGalatRencana(e) ?? (Object.keys(galatKolom.value).length ? null : pesanError(e))
  } finally {
    menyimpan.value = false
  }
}
</script>

<template>
  <section class="kartu" :aria-labelledby="idJudul">
    <div class="kartu-kepala">
      <div>
        <h2 :id="idJudul">Rencana pulang dan daftar periksa</h2>
        <p>Rencana pulang hari ini tampil sebagai status Rencana pulang di peta TT. Butir wajib yang belum selesai saat pulang harus diberi alasan.</p>
      </div>
    </div>
    <KeadaanKosong v-if="kueri.isPending.value" jenis="memuat" judul="Memuat rencana pulang" />
    <KeadaanKosong v-else-if="kueri.isError.value" jenis="error" judul="Rencana pulang gagal dimuat" :uraian="pesanGalatRencana(kueri.error.value) ?? pesanError(kueri.error.value)">
      <template #aksi><Tombol @click="kueri.refetch()">Coba lagi</Tombol></template>
    </KeadaanKosong>
    <form v-else-if="rencana" class="kartu-isi isi" novalidate @submit.prevent="simpan">
      <Pesan v-if="rencana.id === null" jenis="info">
        Belum ada rencana pulang. {{ bisaUbah ? 'Daftar periksa di bawah diambil dari data induk dan disalin saat rencana disimpan.' : 'Rencana dibuat oleh petugas dengan izin rencana pulang.' }}
      </Pesan>
      <Pesan v-else-if="rencana.status === 'pulang'" jenis="ok">Pasien sudah pulang. Rencana dan daftar periksa ini tidak bisa diubah lagi.</Pesan>

      <div v-if="bisaUbah" class="baris-isian">
        <KolomIsian v-slot="a" label="Tanggal rencana pulang" wajib :error="galatKolom.rencana_pulang_pada">
          <input v-bind="a" v-model="isian.tanggal" type="date" class="isian" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Rencana lama rawat (hari)" petunjuk="1 sampai 365. Kosongkan bila belum ditetapkan." :error="galatKolom.rencana_lama_rawat">
          <input v-bind="a" v-model="isian.lamaRawat" type="number" inputmode="numeric" min="1" max="365" step="1" class="isian angka" />
        </KolomIsian>
      </div>
      <dl v-else class="ringkas">
        <div>
          <dt>Tanggal rencana pulang</dt>
          <dd>{{ formatTanggal(rencana.rencana_pulang_pada) ?? 'Belum ditetapkan' }}</dd>
        </div>
        <div>
          <dt>Rencana lama rawat</dt>
          <dd>{{ teksLamaRawat(rencana.rencana_lama_rawat) }}</dd>
        </div>
      </dl>

      <fieldset class="daftar-periksa">
        <legend class="kolom-label">Daftar periksa pulang</legend>
        <p v-if="!rencana.daftar_periksa.length" class="ket">Data induk daftar periksa pulang belum berisi butir aktif.</p>
        <ul v-else>
          <li v-for="b in rencana.daftar_periksa" :key="b.kode" class="butir">
            <label v-if="bisaUbah && b.sumber === 'manual'" class="centang">
              <input v-model="isian.selesai[b.kode]" type="checkbox" />
              <span class="nama">{{ b.nama }}<template v-if="b.wajib"> <span class="wajib">(wajib)</span></template></span>
            </label>
            <span v-else class="nama">{{ b.nama }}<template v-if="b.wajib"> <span class="wajib">(wajib)</span></template></span>
            <Lencana :jenis="tandaButir(b).jenis" tanpa-ikon><Ikon :nama="tandaButir(b).ikon" :ukuran="12" />{{ tandaButir(b).label }}</Lencana>
            <span v-if="b.status === 'selesai' && b.selesai_pada" class="ket">Dicentang {{ b.selesai_oleh?.nama ?? 'sistem' }}, {{ formatTanggalWaktu(b.selesai_pada) }}</span>
            <span v-if="b.sumber === 'kejadian'" class="ket">Diselesaikan kasir, tidak menahan pulang.</span>
            <span v-if="b.alasan_tidak_selesai" class="ket">Alasan belum selesai saat pulang: {{ b.alasan_tidak_selesai }}</span>
          </li>
        </ul>
      </fieldset>

      <div v-if="pesan" role="alert">
        <Pesan jenis="error">{{ pesan }}</Pesan>
      </div>
      <div v-if="bisaUbah" class="aksi">
        <span v-if="berubah && rencana.id !== null" class="ket">Ada perubahan yang belum disimpan.</span>
        <Tombol type="submit" varian="utama" :sibuk="menyimpan" :disabled="!berubah || undefined">{{ rencana.id === null ? 'Buat rencana pulang' : 'Simpan rencana pulang' }}</Tombol>
      </div>
    </form>
  </section>
</template>

<style scoped>
.isi {
  display: grid;
  gap: var(--space-4);
}
.baris-isian {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 280px));
  gap: var(--space-3) var(--space-4);
}
.angka {
  max-width: 160px;
  font-variant-numeric: tabular-nums;
}
.ringkas {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3) var(--space-6);
  margin: 0;
}
.ringkas dt {
  font-size: 12px;
  color: var(--muted);
}
.ringkas dd {
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
.daftar-periksa {
  margin: 0;
  padding: 0;
  border: 0;
}
.daftar-periksa ul {
  display: grid;
  margin: var(--space-2) 0 0;
  padding: 0;
  list-style: none;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.butir {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-1) var(--space-3);
  min-height: 44px;
  box-sizing: border-box;
  padding: var(--space-2) var(--space-3);
}
.butir + .butir {
  border-top: 1px solid var(--border);
}
.centang {
  display: flex;
  flex: 1 1 220px;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  cursor: pointer;
}
.centang input:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
}
.nama {
  flex: 1 1 220px;
  font-weight: var(--font-weight-semibold);
}
.centang .nama {
  flex: none;
}
.wajib {
  font-weight: normal;
  color: var(--muted);
}
.ket {
  flex-basis: 100%;
  font-size: 12.5px;
  color: var(--muted);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-3);
}
.aksi .ket {
  flex-basis: auto;
}
</style>
