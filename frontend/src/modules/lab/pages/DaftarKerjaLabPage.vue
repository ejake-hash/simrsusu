<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, ref } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import { INTERVAL_LAYAR_KERJA } from '@/modules/notifikasi/polling'
import { ambilSpesimen, terimaSpesimen } from '../api'
import SeksiDaftarKerja from '../components/SeksiDaftarKerja.vue'
import { identitasPasien, KUNCI_LAB, kurangJenisSpesimen, LABEL_LOKASI_PENGAMBILAN, perluMuatUlang, pesanGalatLab, teksLokasi } from '../lab'
import type { BarisDaftarKerjaLab, ButirDaftarKerjaLab, LokasiPengambilanSpesimen } from '../tipe'

/**
 * Penunjang → Laboratorium: daftar kerja lab (tiket 2.30, kontrak v0.40.0; FR-19-01..03, 7.00.5 butir 4). Order lab aktif
 * dari semua pos, cito di atas lalu rutin, terlama dulu. Ambil sampel (jenis + lokasi pengambilan) membuat barcode di server;
 * terima di lab mengunci order dari pembatalan pemesan. Kedua aksi dikonfirmasi dengan identitas dua penanda (5.1 butir 9).
 */
const qc = useQueryClient()
const segarkan = () => qc.invalidateQueries({ queryKey: KUNCI_LAB.semua })

/** Hasil aksi terakhir (barcode): tetap tampil sampai aksi berikutnya, jadi petugas bisa menyalinnya ke label. */
const hasil = ref<string | null>(null)
const elHasil = ref<HTMLElement | null>(null)
let fokusHasil = false
const fokusSesudahTutup = () => (fokusHasil ? elHasil.value : null)

// ── Ambil sampel ──
const sasaranAmbil = ref<{ baris: BarisDaftarKerjaLab; butir: ButirDaftarKerjaLab } | null>(null)
const bukaAmbil = ref(false)
const jenis = ref('')
const lokasi = ref<LokasiPengambilanSpesimen | ''>('')
const galatAmbil = ref<unknown>(null)
const mengambil = ref(false)
const sudahCoba = ref(false)
function mintaAmbil(baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab) {
  sasaranAmbil.value = { baris, butir }
  jenis.value = ''
  lokasi.value = ''
  galatAmbil.value = null
  sudahCoba.value = false
  fokusHasil = false
  bukaAmbil.value = true
}
const kurangJenis = computed(() => kurangJenisSpesimen(jenis.value))
const errorJenis = computed(() => errorKolom(galatAmbil.value, ['jenis']).jenis ?? (sudahCoba.value ? kurangJenis.value : null))
const errorLokasi = computed(() => errorKolom(galatAmbil.value, ['lokasi_pengambilan']).lokasi_pengambilan ?? (sudahCoba.value && !lokasi.value ? 'Pilih lokasi pengambilan.' : null))
const pesanAmbil = computed(() => (galatAmbil.value && !errorKolom(galatAmbil.value, ['jenis', 'lokasi_pengambilan']).jenis && !errorKolom(galatAmbil.value, ['jenis', 'lokasi_pengambilan']).lokasi_pengambilan ? pesanGalatLab(galatAmbil.value) : null))
async function ambil() {
  const s = sasaranAmbil.value
  sudahCoba.value = true
  if (!s || kurangJenis.value || !lokasi.value) return
  mengambil.value = true
  galatAmbil.value = null
  try {
    const sp = await ambilSpesimen(s.butir.id, { jenis: jenis.value.trim(), lokasi_pengambilan: lokasi.value })
    hasil.value = `Sampel ${s.butir.nama} untuk ${s.baris.pasien.nama} tercatat. Barcode ${sp.barcode}.`
    fokusHasil = true
    bukaAmbil.value = false
    tampilkanToast(`Barcode ${sp.barcode} dibuat.`, 'ok')
    await nextTick()
    await segarkan()
  } catch (e) {
    // Data basi (butir batal, sampel sudah diambil petugas lain): daftar dimuat ulang dulu, pesan server tetap di dialog.
    if (perluMuatUlang(e)) await segarkan()
    galatAmbil.value = e
  } finally {
    mengambil.value = false
  }
}

// ── Terima di lab ──
const sasaranTerima = ref<{ baris: BarisDaftarKerjaLab; butir: ButirDaftarKerjaLab } | null>(null)
const bukaTerima = ref(false)
const galatTerima = ref<unknown>(null)
const menerima = ref(false)
function mintaTerima(baris: BarisDaftarKerjaLab, butir: ButirDaftarKerjaLab) {
  sasaranTerima.value = { baris, butir }
  galatTerima.value = null
  fokusHasil = false
  bukaTerima.value = true
}
async function terima() {
  const s = sasaranTerima.value
  if (!s?.butir.spesimen) return
  menerima.value = true
  galatTerima.value = null
  try {
    const sp = await terimaSpesimen(s.butir.spesimen.id)
    hasil.value = `Spesimen ${sp.barcode} (${s.butir.nama}, ${s.baris.pasien.nama}) diterima di lab. Order ini tidak bisa lagi dibatalkan pemesan.`
    fokusHasil = true
    bukaTerima.value = false
    tampilkanToast(`Spesimen ${sp.barcode} diterima.`, 'ok')
    await nextTick()
    await segarkan()
  } catch (e) {
    if (perluMuatUlang(e)) await segarkan()
    galatTerima.value = e
  } finally {
    menerima.value = false
  }
}
</script>

<template>
  <HalamanModul
    izin="penunjang.laboratorium.lihat"
    judul="Daftar kerja laboratorium"
    :uraian="`Order lab aktif dari poliklinik, IGD, dan rawat inap. Cito di atas, lalu rutin; terlama di atas. Diperbarui otomatis tiap ${INTERVAL_LAYAR_KERJA / 1000} detik.`"
  >
    <div class="tumpukan">
      <div v-if="hasil" ref="elHasil" class="hasil" tabindex="-1">
        <Pesan jenis="ok" role="status">{{ hasil }}</Pesan>
      </div>
      <SeksiDaftarKerja
        prioritas="cito"
        judul="Cito"
        uraian="Kerjakan lebih dulu. Order keluar dari daftar sesudah semua pemeriksaannya diterima di lab."
        teks-kosong="Tidak ada order cito yang menunggu."
        @ambil="mintaAmbil"
        @terima="mintaTerima"
      />
      <SeksiDaftarKerja
        prioritas="rutin"
        judul="Rutin"
        uraian="Order rutin, terlama di atas."
        teks-kosong="Tidak ada order rutin yang menunggu. Order muncul sesudah dokter mengirim order laboratorium."
        @ambil="mintaAmbil"
        @terima="mintaTerima"
      />
    </div>

    <Dialog
      v-model:open="bukaAmbil"
      :judul="sasaranAmbil ? `Ambil sampel ${sasaranAmbil.butir.nama}` : 'Ambil sampel'"
      uraian="Cocokkan identitas pasien dengan gelang identitas sebelum mengambil sampel. Jam pengambilan dicatat server."
      lebar="lebar"
      :sibuk="mengambil"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <dl v-if="sasaranAmbil" class="ringkas">
        <div>
          <dt>Pasien</dt>
          <dd>{{ identitasPasien(sasaranAmbil.baris) }}</dd>
        </div>
        <div>
          <dt>Lokasi sekarang</dt>
          <dd>{{ teksLokasi(sasaranAmbil.baris) }}</dd>
        </div>
        <div>
          <dt>Pemeriksaan</dt>
          <dd><code>{{ sasaranAmbil.butir.kode_item }}</code> {{ sasaranAmbil.butir.nama }}</dd>
        </div>
      </dl>
      <form id="form-ambil-sampel" class="form" novalidate @submit.prevent="ambil">
        <KolomIsian v-slot="a" label="Jenis spesimen" wajib petunjuk="Mis. darah vena, urin, swab. 2 sampai 100 karakter." :error="errorJenis ?? undefined">
          <input v-bind="a" v-model="jenis" type="text" class="isian" maxlength="100" autocomplete="off" />
        </KolomIsian>
        <fieldset class="pilihan-lokasi" :aria-invalid="errorLokasi ? 'true' : undefined" :aria-describedby="errorLokasi ? 'galat-lokasi' : undefined">
          <legend class="kolom-label">Lokasi pengambilan <span class="kolom-wajib" aria-hidden="true">*</span></legend>
          <label v-for="(label, kode) in LABEL_LOKASI_PENGAMBILAN" :key="kode" class="pilihan" :class="{ dipilih: lokasi === kode }">
            <input v-model="lokasi" type="radio" name="lokasi-pengambilan" :value="kode" />
            <span>{{ label }}</span>
          </label>
          <p v-if="errorLokasi" id="galat-lokasi" class="galat">{{ errorLokasi }}</p>
        </fieldset>
      </form>
      <div role="alert">
        <div v-if="pesanAmbil" class="blok-galat">
          <Pesan jenis="error">{{ pesanAmbil }}</Pesan>
        </div>
      </div>
      <template #aksi>
        <Tombol :disabled="mengambil || undefined" @click="bukaAmbil = false">Batal</Tombol>
        <Tombol type="submit" form="form-ambil-sampel" varian="utama" :sibuk="mengambil">Catat sampel dan buat barcode</Tombol>
      </template>
    </Dialog>

    <Dialog
      v-model:open="bukaTerima"
      :judul="sasaranTerima ? `Terima spesimen ${sasaranTerima.butir.spesimen?.barcode ?? ''}`.trim() : 'Terima spesimen'"
      uraian="Cocokkan barcode dan identitas pada label spesimen. Sesudah diterima, pemesan tidak bisa membatalkan order ini."
      lebar="lebar"
      :sibuk="menerima"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <dl v-if="sasaranTerima" class="ringkas">
        <div>
          <dt>Pasien</dt>
          <dd>{{ identitasPasien(sasaranTerima.baris) }}</dd>
        </div>
        <div>
          <dt>Pemeriksaan</dt>
          <dd><code>{{ sasaranTerima.butir.kode_item }}</code> {{ sasaranTerima.butir.nama }}</dd>
        </div>
        <div v-if="sasaranTerima.butir.spesimen">
          <dt>Spesimen</dt>
          <dd><code>{{ sasaranTerima.butir.spesimen.barcode }}</code> · {{ sasaranTerima.butir.spesimen.jenis }} · {{ LABEL_LOKASI_PENGAMBILAN[sasaranTerima.butir.spesimen.lokasi_pengambilan] }}</dd>
        </div>
      </dl>
      <div role="alert">
        <div v-if="galatTerima" class="blok-galat">
          <Pesan jenis="error">{{ pesanGalatLab(galatTerima) }}</Pesan>
        </div>
      </div>
      <template #aksi>
        <Tombol :disabled="menerima || undefined" @click="bukaTerima = false">Batal</Tombol>
        <Tombol varian="utama" :sibuk="menerima" @click="terima">Terima di lab</Tombol>
      </template>
    </Dialog>
  </HalamanModul>
</template>

<style scoped>
.tumpukan {
  display: grid;
  gap: var(--space-4);
}
.hasil:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
  border-radius: var(--radius-input);
}
/* Identitas dua penanda dibaca sebelum konfirmasi (PRD 5.1 butir 9): label kecil, isi tebal. */
.ringkas {
  display: grid;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
}
.ringkas dt {
  font-size: 12px;
  color: var(--muted);
}
.ringkas dd {
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
.form {
  display: grid;
  gap: var(--space-3);
}
.pilihan-lokasi {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  border: 0;
}
.pilihan {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  box-sizing: border-box;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  cursor: pointer;
}
.pilihan:has(input:focus-visible) {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
}
/* Terpilih: tepi tebal dan teks tebal, bukan hanya warna. */
.pilihan.dipilih {
  border-width: 2px;
  border-color: var(--text);
  font-weight: var(--font-weight-semibold);
}
.galat {
  margin: 0;
  font-size: 13px;
  color: var(--danger);
}
.blok-galat {
  margin-top: var(--space-3);
}
</style>
