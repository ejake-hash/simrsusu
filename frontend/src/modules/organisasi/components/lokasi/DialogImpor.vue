<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { imporLokasi } from '../../apiLokasi'
import { LABEL_JENIS_LOKASI } from '../../lokasi'
import type { HasilImporLokasi, KesalahanBarisImpor } from '../../tipeLokasi'

/**
 * Impor lokasi, kamar, dan TT dari CSV (POST /lokasi/impor): unduh templat → pilih berkas → pratinjau (tidak menyimpan
 * apa pun; baris salah beserta alasannya) → terapkan (semua atau tidak sama sekali). Batas 5.000 baris / 2 MB,
 * sinkron. Templat dibuat di klien dari daftar kolom kontrak; berisi contoh sintetis yang boleh dihapus.
 */
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: HasilImporLokasi] }>()

const JUDUL = 'jenis,kode,induk,nama,fungsi,unit,bangsal,kelas_rawat,kapasitas_maksimum,ekstra'
const CONTOH = ['gedung,C,,Gedung C (contoh),,,,,,', 'lantai,1,C,Lantai 1,,,,,,', 'ruang,101,C-1,Ruang 101 (contoh),rawat_inap,,,,,', 'tempat_tidur,1,C-1-101,,,,,,,tidak']
const BATAS_BYTE = 2 * 1024 * 1024

const berkas = ref<File | null>(null)
const isianBerkas = ref<HTMLInputElement | null>(null)
const dasar = ref('')
const kesalahanIsian = ref<{ berkas?: string; dasar?: string }>({})
const hasil = ref<HasilImporLokasi | null>(null)
/** Kesalahan per baris dari 422 impor_tidak_valid saat terapkan (bentuk sama dengan data.kesalahan). */
const kesalahanTerapkan = ref<KesalahanBarisImpor[]>([])
const error = ref<string | null>(null)
const sibuk = ref<'pratinjau' | 'terapkan' | null>(null)

watch(buka, (b) => {
  if (!b) return
  berkas.value = null
  if (isianBerkas.value) isianBerkas.value.value = ''
  dasar.value = ''
  kesalahanIsian.value = {}
  hasil.value = null
  kesalahanTerapkan.value = []
  error.value = null
})

function unduhTemplat() {
  // BOM agar Excel membaca UTF-8; pemisah koma sesuai kontrak (server juga menerima ";").
  const blob = new Blob([`﻿${[JUDUL, ...CONTOH].join('\r\n')}\r\n`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'templat-impor-lokasi.csv'
  a.click()
  URL.revokeObjectURL(url)
}
function pilihBerkas(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] ?? null
  berkas.value = f
  hasil.value = null
  kesalahanTerapkan.value = []
  kesalahanIsian.value = { ...kesalahanIsian.value, berkas: f && f.size > BATAS_BYTE ? 'Berkas lebih dari 2 MB; pecah menjadi beberapa berkas.' : undefined }
}

async function jalankan(mode: 'pratinjau' | 'terapkan') {
  const e: typeof kesalahanIsian.value = {}
  if (!berkas.value) e.berkas = 'Pilih berkas CSV.'
  else if (berkas.value.size > BATAS_BYTE) e.berkas = 'Berkas lebih dari 2 MB; pecah menjadi beberapa berkas.'
  if (dasar.value.trim().length < 3) e.dasar = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahanIsian.value = e
  if (Object.keys(e).length) return
  sibuk.value = mode
  error.value = null
  kesalahanTerapkan.value = []
  try {
    const h = await imporLokasi(berkas.value!, mode, dasar.value.trim())
    if (mode === 'terapkan') {
      buka.value = false
      emit('selesai', h)
    } else hasil.value = h
  } catch (err) {
    if (err instanceof ApiError && err.kode === 'impor_tidak_valid') {
      kesalahanTerapkan.value = err.rincian as unknown as KesalahanBarisImpor[]
      error.value = `${err.message} Perbaiki berkas lalu periksa lagi.`
      hasil.value = null
    } else if (err instanceof ApiError && err.kode === 'berkas_tidak_valid') {
      kesalahanIsian.value = { berkas: [err.message, err.rincian[0]?.pesan].filter(Boolean).join(' ') }
    } else if (err instanceof ApiError && err.status === 413) {
      kesalahanIsian.value = { berkas: 'Berkas melebihi batas unggah server; pecah menjadi beberapa berkas.' }
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = null
  }
}

const daftarSalah = computed(() => (kesalahanTerapkan.value.length ? kesalahanTerapkan.value : (hasil.value?.kesalahan ?? [])))
const ringkasan = computed(() =>
  hasil.value ? (Object.entries(hasil.value.per_jenis) as [keyof HasilImporLokasi['per_jenis'], number][]).filter(([, n]) => n > 0) : [],
)
const labelJenis = (j: string) => (j === 'kamar' ? 'Kamar' : (LABEL_JENIS_LOKASI as Record<string, string>)[j] ?? j)
</script>

<template>
  <Dialog v-model:open="buka" judul="Impor lokasi dari CSV" uraian="Periksa dulu (tidak menyimpan apa pun), lalu terapkan bila tidak ada baris yang salah. Impor hanya menambah." :sibuk="sibuk !== null" lebar="lebar">
    <form id="form-impor" novalidate @submit.prevent="jalankan('pratinjau')">
      <p class="catatan catatan-info templat">
        <Ikon nama="info" :ukuran="16" />
        <span>
          Kolom: <code>{{ JUDUL }}</code>. Induk ditulis dengan kode lengkap (mis. <code>C-1</code>); TT hanya di ruang yang menjadi kamar (kolom
          <code>bangsal</code>). Paling banyak 5.000 baris / 2 MB.
          <button type="button" class="tombol-tautan" @click="unduhTemplat">Unduh templat</button>
        </span>
      </p>
      <KolomIsian v-slot="a" label="Berkas CSV" :error="kesalahanIsian.berkas" wajib>
        <input v-bind="a" ref="isianBerkas" type="file" accept=".csv,text/csv" class="isian" @change="pilihBerkas" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahanIsian.dasar" petunjuk="Nomor SK atau memo; wajib juga untuk pemeriksaan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>

      <section v-if="hasil" class="hasil" aria-live="polite">
        <p :class="['catatan', hasil.jumlah_salah ? 'catatan-peringatan' : 'catatan-info']" role="status">
          <Ikon :nama="hasil.jumlah_salah ? 'peringatan' : 'centang'" :ukuran="16" />
          <span v-if="hasil.jumlah_salah">{{ hasil.jumlah_salah }} dari {{ hasil.jumlah_baris }} baris salah. Tidak ada yang bisa diterapkan sebelum semua baris benar.</span>
          <span v-else>Semua {{ hasil.jumlah_baris }} baris benar dan siap diterapkan.</span>
        </p>
        <ul class="per-jenis" aria-label="Jumlah baris per jenis">
          <li v-for="[j, n] in ringkasan" :key="j"><b>{{ n }}</b> {{ labelJenis(j) }}</li>
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
      <Tombol type="submit" form="form-impor" :sibuk="sibuk === 'pratinjau'">{{ sibuk === 'pratinjau' ? 'Memeriksa…' : 'Periksa berkas' }}</Tombol>
      <Tombol varian="utama" :sibuk="sibuk === 'terapkan'" :disabled="!hasil || hasil.jumlah_salah > 0" @click="jalankan('terapkan')">
        {{ sibuk === 'terapkan' ? 'Menerapkan…' : 'Terapkan' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.templat {
  margin: 0 0 var(--space-4);
}
.templat code {
  font-family: var(--font-mono);
  font-size: 11.5px;
  word-break: break-all;
}
.hasil {
  margin-bottom: var(--space-3);
}
.per-jenis {
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
.tbl-salah code {
  font-family: var(--font-mono);
}
.ket {
  margin: 0;
  padding: var(--space-2);
  color: var(--muted);
  font-size: 12px;
}
</style>
