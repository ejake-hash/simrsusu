<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { KesalahanBarisImpor } from '@/modules/organisasi/tipeLokasi'
import { ApiError } from '@/shared/api/klien'
import { muatPaket } from '../api'
import { KOLOM_SISTEM, LABEL_SISTEM } from '../penyiapan'
import type { HasilMuatReferensi, SistemReferensi } from '../tipe'

/**
 * Muat satu versi paket referensi dari CSV (POST /paket-referensi/{sistem}/muat, kontrak v0.11.0; wizard langkah 4):
 * pratinjau (tidak menyimpan; ringkasan baru/diubah/dinonaktifkan/diaktifkan lagi dan baris salah) lalu terapkan (semua
 * atau tidak sama sekali). Versi yang sama dengan isi berbeda, atau versi lama yang sudah digantikan, ditolak 409.
 * LOINC wajib catatan lisensi (atribusi Regenstrief). Berkas paling besar 10 MB / 120.000 baris.
 */
const props = defineProps<{ sistem: SistemReferensi }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: HasilMuatReferensi] }>()

const BATAS_BYTE = 10 * 1024 * 1024
const PESAN_BESAR = 'Berkas lebih dari 10 MB; paket ini tidak bisa dipecah, hubungi pengelola server.'

const berkas = ref<File | null>(null)
const isianBerkas = ref<HTMLInputElement | null>(null)
const versi = ref('')
const sumber = ref('')
const lisensi = ref('')
const kesalahanIsian = ref<{ berkas?: string; versi?: string; sumber?: string; catatan_lisensi?: string }>({})
const hasil = ref<HasilMuatReferensi | null>(null)
const kesalahanTerapkan = ref<KesalahanBarisImpor[]>([])
const error = ref<string | null>(null)
const sibuk = ref<'pratinjau' | 'terapkan' | null>(null)
const wajibLisensi = computed(() => props.sistem === 'loinc')

watch(buka, (b) => {
  if (!b) return
  berkas.value = null
  if (isianBerkas.value) isianBerkas.value.value = ''
  versi.value = ''
  sumber.value = ''
  lisensi.value = ''
  kesalahanIsian.value = {}
  hasil.value = null
  kesalahanTerapkan.value = []
  error.value = null
})

function pilihBerkas(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] ?? null
  berkas.value = f
  hasil.value = null
  kesalahanTerapkan.value = []
  kesalahanIsian.value = { ...kesalahanIsian.value, berkas: f && f.size > BATAS_BYTE ? PESAN_BESAR : undefined }
}
/** Isian berubah sesudah pratinjau: pratinjau harus diulang sebelum terapkan. */
watch([versi, sumber, lisensi], () => (hasil.value = null))

async function jalankan(mode: 'pratinjau' | 'terapkan') {
  const e: typeof kesalahanIsian.value = {}
  if (!berkas.value) e.berkas = 'Pilih berkas CSV.'
  else if (berkas.value.size > BATAS_BYTE) e.berkas = PESAN_BESAR
  if (!versi.value.trim()) e.versi = 'Isi versi paket.'
  if (sumber.value.trim().length < 3) e.sumber = 'Isi asal berkas, minimal 3 karakter.'
  if (wajibLisensi.value && !lisensi.value.trim()) e.catatan_lisensi = 'Atribusi lisensi LOINC wajib diisi.'
  kesalahanIsian.value = e
  if (Object.keys(e).length) return
  sibuk.value = mode
  error.value = null
  kesalahanTerapkan.value = []
  try {
    const h = await muatPaket(props.sistem, {
      berkas: berkas.value!, mode, versi: versi.value.trim(), sumber: sumber.value.trim(), catatan_lisensi: lisensi.value.trim() || null,
    })
    if (mode === 'terapkan') {
      buka.value = false
      emit('selesai', h)
    } else hasil.value = h
  } catch (err) {
    if (err instanceof ApiError && err.kode === 'muat_tidak_valid') {
      kesalahanTerapkan.value = err.rincian as unknown as KesalahanBarisImpor[]
      error.value = `${err.message} Perbaiki berkas lalu periksa lagi.`
      hasil.value = null
    } else if (err instanceof ApiError && err.kode === 'versi_sudah_dimuat') {
      kesalahanIsian.value = { versi: 'Versi ini sudah pernah dimuat. Pakai nomor versi lain.' }
      hasil.value = null
    } else if (err instanceof ApiError && err.kode === 'berkas_tidak_valid') {
      kesalahanIsian.value = { berkas: [err.message, err.rincian[0]?.pesan].filter(Boolean).join(' ') }
    } else if (err instanceof ApiError && err.kode === 'validasi_gagal' && err.rincian.length) {
      kesalahanIsian.value = Object.fromEntries(err.rincian.map((r) => [String(r.kolom), String(r.pesan)]))
    } else if (err instanceof ApiError && err.status === 413) {
      kesalahanIsian.value = { berkas: 'Berkas melebihi batas unggah server.' }
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = null
  }
}

const daftarSalah = computed(() => (kesalahanTerapkan.value.length ? kesalahanTerapkan.value : (hasil.value?.kesalahan ?? [])))
const tanpaPerubahan = computed(
  () => !!hasil.value && !hasil.value.jumlah_salah && hasil.value.jumlah_baru + hasil.value.jumlah_diubah + hasil.value.jumlah_dinonaktifkan + hasil.value.jumlah_diaktifkan_lagi === 0,
)
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Muat paket ${LABEL_SISTEM[sistem]}`" uraian="Periksa dulu (tidak menyimpan apa pun), lalu terapkan. Kode yang tidak ada di versi ini ditandai tidak aktif, tidak dihapus." :sibuk="sibuk !== null" lebar="lebar">
    <form id="form-muat-paket" novalidate @submit.prevent="jalankan('pratinjau')">
      <p class="catatan catatan-info kolom">
        <Ikon nama="info" :ukuran="16" />
        <span>Kolom: <code>{{ KOLOM_SISTEM[sistem] }}</code>. CSV UTF-8, paling besar 10 MB / 120.000 baris. Satu berkas = satu versi lengkap.</span>
      </p>
      <KolomIsian v-slot="a" label="Berkas CSV" :error="kesalahanIsian.berkas" wajib>
        <input v-bind="a" ref="isianBerkas" type="file" accept=".csv,text/csv" class="isian" @change="pilihBerkas" />
      </KolomIsian>
      <div class="kisi">
        <KolomIsian v-slot="a" label="Versi" :error="kesalahanIsian.versi" :petunjuk="sistem === 'referensi' ? 'Satu versi per berkas, mis. agama-2025.1.' : 'Mis. 2025.1.'" wajib>
          <input v-bind="a" v-model="versi" class="isian" maxlength="50" autocomplete="off" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Sumber" :error="kesalahanIsian.sumber" petunjuk="Instansi, URL, atau nomor dokumen." wajib>
          <input v-bind="a" v-model="sumber" class="isian" maxlength="500" autocomplete="off" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Catatan lisensi" :error="kesalahanIsian.catatan_lisensi" :petunjuk="wajibLisensi ? 'Atribusi Regenstrief wajib untuk LOINC.' : undefined" :wajib="wajibLisensi">
        <textarea v-bind="a" v-model="lisensi" class="isian" rows="2" maxlength="1000" />
      </KolomIsian>

      <section v-if="hasil" class="hasil" aria-live="polite">
        <p :class="['catatan', hasil.jumlah_salah ? 'catatan-peringatan' : 'catatan-info']" role="status">
          <Ikon :nama="hasil.jumlah_salah ? 'peringatan' : 'centang'" :ukuran="16" />
          <span v-if="hasil.jumlah_salah">{{ hasil.jumlah_salah }} dari {{ hasil.jumlah_baris }} baris salah. Tidak ada yang bisa diterapkan sebelum semua baris benar.</span>
          <span v-else-if="tanpaPerubahan">Tidak ada perubahan; versi ini sudah termuat.</span>
          <span v-else>Semua {{ hasil.jumlah_baris }} baris benar dan siap diterapkan.</span>
        </p>
        <ul v-if="!hasil.jumlah_salah" class="ringkasan" aria-label="Ringkasan perubahan">
          <li><b>{{ hasil.jumlah_baru }}</b> baru</li>
          <li><b>{{ hasil.jumlah_diubah }}</b> diubah</li>
          <li><b>{{ hasil.jumlah_dinonaktifkan }}</b> dinonaktifkan</li>
          <li><b>{{ hasil.jumlah_diaktifkan_lagi }}</b> diaktifkan lagi</li>
        </ul>
      </section>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <div v-if="daftarSalah.length" class="tbl-salah" role="region" aria-label="Baris yang salah" tabindex="0">
        <table>
          <thead>
            <tr>
              <th scope="col">Baris</th>
              <th scope="col">Kolom</th>
              <th scope="col">Alasan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="k in daftarSalah" :key="`${k.baris}-${k.kolom}-${k.kode}`">
              <td>{{ k.baris }}</td>
              <td><code>{{ k.kolom ?? '—' }}</code></td>
              <td>{{ k.pesan }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="hasil?.kesalahan_terpotong || daftarSalah.length >= 500" class="ket">Ditampilkan 500 baris salah pertama.</p>
      </div>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk !== null" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-muat-paket" :sibuk="sibuk === 'pratinjau'">{{ sibuk === 'pratinjau' ? 'Memeriksa…' : 'Periksa berkas' }}</Tombol>
      <Tombol varian="utama" :sibuk="sibuk === 'terapkan'" :disabled="!hasil || hasil.jumlah_salah > 0" @click="jalankan('terapkan')">
        {{ sibuk === 'terapkan' ? 'Menerapkan…' : 'Terapkan' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.kolom {
  margin: 0 0 var(--space-4);
}
.kolom code,
.tbl-salah code {
  font-family: var(--font-mono);
  font-size: 11.5px;
}
.kisi {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.hasil {
  margin-bottom: var(--space-3);
}
.ringkasan {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2) var(--space-4);
  margin: var(--space-2) 0 0;
  padding: 0;
  list-style: none;
  font-size: 13px;
}
.tbl-salah {
  max-height: 280px;
  margin-top: var(--space-3);
  overflow: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.tbl-salah table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}
.tbl-salah th,
.tbl-salah td {
  padding: 6px var(--space-2);
  border-bottom: 1px solid var(--border);
  text-align: left;
  vertical-align: top;
}
.tbl-salah th {
  position: sticky;
  top: 0;
  background: var(--bg);
  font-weight: var(--font-weight-semibold);
}
.ket {
  margin: 0;
  padding: var(--space-2);
  color: var(--muted);
  font-size: 12px;
}
</style>
