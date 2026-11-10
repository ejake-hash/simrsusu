<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { useKueriBerPolling } from '@/modules/notifikasi/polling'
import { formatNoRm, formatTanggal } from '@/modules/pendaftaran/pasien'
import { boleh } from '@/shared/composables/usePermission'
import { bacaPapanIgd, tempatkanAreaIgd } from '../apiPapan'
import LencanaTriase from '../components/LencanaTriase.vue'
import { formatMenit, INTERVAL_PAPAN, KUNCI_PAPAN, pesanGalatTempatkan, pilihanArea, tandaLamaIgd, tandaPermintaanKamar, tandaTanggap, teksDisposisiMenunggu, uraianAturan, waktuTiba } from '../papan'
import type { BarisPapanIgd, PapanIgd, RingkasAreaIgd } from '../tipePapan'

/**
 * IGD → Papan pasien IGD (tiket 2.25, kontrak v0.34.0; FR-03-03/04/05, FR-00-06). Satu layar untuk dokter dan perawat
 * IGD di layar besar atau tablet. Urutan prioritas (belum ditriase, merah, kuning, hijau, hitam, lalu jam tiba) dan semua
 * menit/tanda dari server, jadi layar tidak mengurutkan atau menghitung ulang. Polling K1 tiap 15 detik dengan
 * If-None-Match lewat sesi petugas: 304 = data lama tetap; berhenti saat tab tidak terlihat.
 */
const areaFilter = ref('')
const kunci = computed(() => KUNCI_PAPAN.papan(areaFilter.value || null))
const { kueri, segarkan } = useKueriBerPolling<PapanIgd>(kunci, (p) => bacaPapanIgd(areaFilter.value || null, p), { interval: INTERVAL_PAPAN })

const baris = computed<BarisPapanIgd[]>(() => kueri.data.value?.data ?? [])
const meta = computed(() => kueri.data.value?.meta ?? null)
// Pilihan filter tetap ada selama papan area lain dimuat.
const areaTerakhir = ref<RingkasAreaIgd[]>([])
watch(meta, (m) => {
  if (m) areaTerakhir.value = m.area
})
const areaAktif = computed(() => meta.value?.area ?? areaTerakhir.value)
/** Data induk belum berisi area aktif: pilihan area disembunyikan, diganti petunjuk. */
const tanpaArea = computed(() => meta.value !== null && meta.value.area.length === 0)
const namaAreaFilter = computed(() => areaAktif.value.find((a) => a.id === areaFilter.value)?.nama ?? null)

const bolehTempatkan = computed(() => boleh('igd.papan.input'))
// Lebar kolom sengaja minimal (lencana boleh turun baris) agar tabel muat di tablet 1024 px tanpa gulir samping.
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'pasien', label: 'Pasien' },
  { kunci: 'triase', label: 'Triase', lebar: '112px' },
  { kunci: 'keluhan', label: 'Keluhan', prioritas: 3 },
  { kunci: 'area', label: 'Area', ...(bolehTempatkan.value && !tanpaArea.value ? { lebar: '170px' } : {}) },
  { kunci: 'tiba', label: 'Tiba dan lama' },
  { kunci: 'tanggap', label: 'Waktu tanggap' },
  { kunci: 'disposisi', label: 'Disposisi dan kamar' },
])

// ── Tempatkan ke area: pilih di baris, lalu simpan (pilihan lewat papan ketik tidak langsung mengirim) ──
const pilihan = reactive<Record<string, string>>({})
const menyimpan = ref<string | null>(null)
const nilaiPilihan = (b: BarisPapanIgd) => pilihan[b.kunjungan_id] ?? b.area?.id ?? ''
const berubah = (b: BarisPapanIgd) => nilaiPilihan(b) !== '' && nilaiPilihan(b) !== (b.area?.id ?? '')
async function tempatkan(b: BarisPapanIgd) {
  const areaId = nilaiPilihan(b)
  menyimpan.value = b.kunjungan_id
  let pesan: [string, 'ok' | 'error']
  try {
    const hasil = await tempatkanAreaIgd(b.kunjungan_id, areaId)
    pesan = [`${b.pasien.nama} ditempatkan di ${hasil.area.nama}.`, 'ok']
  } catch (e) {
    pesan = [pesanGalatTempatkan(e), 'error']
  }
  // Toast sesudah papan termuat ulang, agar pesan sesuai dengan isi papan yang tampil.
  try {
    await segarkan()
  } finally {
    delete pilihan[b.kunjungan_id]
    menyimpan.value = null
  }
  tampilkanToast(...pesan)
  // Tombol Tempatkan hilang sesudah tersimpan: fokus kembali ke pilihan area baris itu (bila pasien masih di papan).
  await nextTick()
  const pilih = document.getElementById(`area-${b.kunjungan_id}`)
  if (pilih) pilih.focus()
  else document.getElementById('judul-papan-igd')?.focus()
}
</script>

<template>
  <HalamanModul
    izin="igd.papan.lihat"
    judul="Papan pasien IGD"
    uraian="Semua pasien IGD yang masih terdaftar atau dilayani, urut prioritas: belum ditriase, merah, kuning, hijau, hitam, lalu jam tiba terlama. Diperbarui otomatis tiap 15 detik."
  >
    <section class="kartu" aria-labelledby="judul-papan-igd">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-papan-igd" tabindex="-1">{{ namaAreaFilter ? `Pasien di ${namaAreaFilter}` : 'Semua pasien IGD' }}</h2>
          <p v-if="meta">{{ uraianAturan(meta.aturan) }}</p>
        </div>
        <div v-if="!tanpaArea" class="filter">
          <label for="filter-area-papan">Area</label>
          <select id="filter-area-papan" v-model="areaFilter" class="isian">
            <option value="">Semua area</option>
            <option v-for="a in areaAktif" :key="a.id" :value="a.id">{{ a.nama }}</option>
          </select>
        </div>
      </div>

      <div v-if="tanpaArea" class="blok">
        <Pesan jenis="info">Belum ada area aktif; atur di Master Data → Area & batas papan IGD.</Pesan>
      </div>
      <div v-if="meta?.terpotong" class="blok">
        <Pesan jenis="peringatan">Papan hanya memuat 200 pasien pertama menurut prioritas. Sebagian pasien tidak tampil; pilih area untuk mempersempit papan.</Pesan>
      </div>

      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(b: BarisPapanIgd) => b.kunjungan_id"
        label="Tabel papan pasien IGD"
        :memuat="kueri.isPending.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :teks-kosong="areaFilter ? 'Tidak ada pasien di area ini.' : 'Belum ada pasien di IGD.'"
        @coba-lagi="segarkan()"
      >
        <template #sel-pasien="{ baris: b }">
          <b>{{ b.pasien.nama }}</b>
          <span class="ket">No. RM {{ formatNoRm(b.pasien.no_rm) }} · {{ formatTanggal(b.pasien.tanggal_lahir) }}</span>
          <code class="ket">{{ b.nomor }}</code>
        </template>
        <template #sel-triase="{ baris: b }"><LencanaTriase :kategori="b.triase?.kategori ?? null" /></template>
        <template #sel-keluhan="{ baris: b }">
          <span v-if="b.keluhan" class="keluhan" :title="b.keluhan">{{ b.keluhan }}</span>
          <span v-else class="ket">Belum diisi</span>
        </template>
        <template #sel-area="{ baris: b }">
          <span v-if="bolehTempatkan && !tanpaArea" class="area">
            <label class="sr-only" :for="`area-${b.kunjungan_id}`">Area {{ b.pasien.nama }}</label>
            <select
              :id="`area-${b.kunjungan_id}`"
              class="isian"
              :value="nilaiPilihan(b)"
              :disabled="menyimpan === b.kunjungan_id || undefined"
              @change="pilihan[b.kunjungan_id] = ($event.target as HTMLSelectElement).value"
            >
              <option value="" disabled>Belum ditempatkan</option>
              <option v-for="p in pilihanArea(b, areaAktif)" :key="p.area.id" :value="p.area.id" :disabled="p.nonaktif || undefined">
                {{ p.nonaktif ? `${p.area.nama} (nonaktif)` : p.area.nama }}
              </option>
            </select>
            <Tombol v-if="berubah(b)" :sibuk="menyimpan === b.kunjungan_id" :aria-label="`Tempatkan ${b.pasien.nama}`" @click="tempatkan(b)">Tempatkan</Tombol>
          </span>
          <template v-else>
            <span v-if="b.area">{{ b.area.nama }}</span>
            <span v-else class="ket">Belum ditempatkan</span>
          </template>
        </template>
        <template #sel-tiba="{ baris: b }">
          <span class="angka">{{ waktuTiba(b, new Date()) }}</span>
          <span class="ket angka">Lama {{ formatMenit(b.lama_di_igd_menit) }}</span>
          <Lencana v-if="tandaLamaIgd(b)" :jenis="tandaLamaIgd(b)!.jenis" tanpa-ikon class="lencana lencana-lama"><Ikon :nama="tandaLamaIgd(b)!.ikon" :ukuran="12" />{{ tandaLamaIgd(b)!.label }}</Lencana>
        </template>
        <template #sel-tanggap="{ baris: b }">
          <Lencana :jenis="tandaTanggap(b).jenis" tanpa-ikon class="lencana"><Ikon :nama="tandaTanggap(b).ikon" :ukuran="12" />{{ tandaTanggap(b).label }}</Lencana>
        </template>
        <template #sel-disposisi="{ baris: b }">
          <span v-if="b.disposisi_menunggu" class="disposisi">{{ teksDisposisiMenunggu(b, new Date()) }}</span>
          <span v-else-if="!tandaPermintaanKamar(b)" class="ket">Tidak ada</span>
          <template v-if="tandaPermintaanKamar(b)">
            <Lencana :jenis="tandaPermintaanKamar(b)!.jenis" tanpa-ikon class="lencana lencana-lama"><Ikon :nama="tandaPermintaanKamar(b)!.ikon" :ukuran="12" />{{ tandaPermintaanKamar(b)!.label }}</Lencana>
            <span v-if="tandaPermintaanKamar(b)!.rincian" class="ket-kuat">{{ tandaPermintaanKamar(b)!.rincian }}</span>
          </template>
        </template>
      </DataTable>
    </section>
  </HalamanModul>
</template>

<style scoped>
.filter {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.filter label {
  font-size: 13px;
  color: var(--muted);
}
.filter select {
  min-width: 200px;
}
.blok {
  padding: 0 var(--space-4) var(--space-3);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.angka {
  font-variant-numeric: tabular-nums;
}
.keluhan {
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}
.area {
  display: grid;
  gap: var(--space-1);
}
.area select {
  width: 100%;
  min-width: 0;
}
/* Di tablet lencana boleh turun baris agar tabel muat tanpa gulir samping; ikon tetap di depan teks. */
@media (max-width: 1279.98px) {
  .lencana {
    align-items: flex-start;
    white-space: normal;
  }
}
.lencana-lama {
  margin-top: var(--space-1);
}
.ket-kuat {
  display: block;
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.disposisi {
  font-size: 13px;
}
@media (max-width: 1023.98px) {
  .filter {
    width: 100%;
  }
  .filter select {
    flex: 1;
    min-width: 0;
  }
}
</style>
