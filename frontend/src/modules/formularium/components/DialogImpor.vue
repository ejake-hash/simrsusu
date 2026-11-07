<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ApiError } from '@/shared/api/klien'
import { templatCsv } from '../formularium'
import type { HasilImpor, KesalahanBarisImpor } from '../tipe'
import GalatForm from './GalatForm.vue'

/**
 * Impor CSV dua langkah: pratinjau (hanya memeriksa, menampilkan galat per nomor baris), lalu terapkan. Satu baris salah =
 * tidak ada yang disimpan. Berkas atau dasar yang diubah sesudah pratinjau mengharuskan pratinjau ulang.
 */
const props = defineProps<{
  judul: string
  uraian: string
  kolom: readonly string[]
  namaTemplat: string
  impor: (berkas: File, mode: 'pratinjau' | 'terapkan', dasar: string) => Promise<HasilImpor>
}>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: []; muatUlang: [] }>()

const berkas = ref<File | null>(null)
const dasar = ref('')
const errorKolom = ref<{ berkas?: string; dasar?: string }>({})
const hasil = ref<HasilImpor | null>(null)
const galat = ref<unknown>(null)
const sibuk = ref(false)
const siapTerapkan = computed(() => hasil.value?.mode === 'pratinjau' && hasil.value.jumlah_salah === 0 && hasil.value.jumlah_baris > 0)

watch(buka, (b) => {
  if (!b) return
  berkas.value = null
  dasar.value = ''
  errorKolom.value = {}
  hasil.value = null
  galat.value = null
})

function pilihBerkas(e: Event) {
  berkas.value = (e.target as HTMLInputElement).files?.[0] ?? null
  hasil.value = null
}
function unduhTemplat() {
  const url = URL.createObjectURL(new Blob([templatCsv(props.kolom)], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = props.namaTemplat
  a.click()
  URL.revokeObjectURL(url)
}

async function jalankan(mode: 'pratinjau' | 'terapkan') {
  const e: { berkas?: string; dasar?: string } = {}
  if (!berkas.value) e.berkas = 'Pilih berkas CSV.'
  if (dasar.value.trim().length < 3) e.dasar = 'Isi dasar perubahan, minimal 3 karakter.'
  errorKolom.value = e
  if (e.berkas || e.dasar) return
  sibuk.value = true
  galat.value = null
  try {
    hasil.value = await props.impor(berkas.value!, mode, dasar.value.trim())
    if (hasil.value.diterapkan) emit('selesai')
  } catch (err) {
    hasil.value = null
    // 422 impor_tidak_valid (mode terapkan): rincian = daftar kesalahan per baris, sama dengan pratinjau.
    if (err instanceof ApiError && err.kode === 'impor_tidak_valid') {
      const kesalahan = err.rincian as unknown as KesalahanBarisImpor[]
      hasil.value = { mode: 'terapkan', diterapkan: false, jumlah_baris: 0, jumlah_salah: kesalahan.length, kesalahan, kesalahan_terpotong: false }
    } else galat.value = err
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :uraian="uraian" lebar="lebar" :sibuk="sibuk">
    <form id="form-impor-csv" novalidate @submit.prevent="jalankan('pratinjau')">
      <p><button type="button" class="tombol" @click="unduhTemplat">Unduh templat CSV</button></p>
      <KolomIsian v-slot="a" label="Berkas CSV" :error="errorKolom.berkas" petunjuk="Pemisah titik koma, maksimal 2 MB dan 5.000 baris." wajib>
        <input v-bind="a" type="file" accept=".csv,text/csv" class="isian" @change="pilihBerkas" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errorKolom.dasar" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" @input="hasil = null" />
      </KolomIsian>
      <GalatForm :galat="galat" @muat-ulang="((buka = false), emit('muatUlang'))" />
      <section v-if="hasil" class="hasil" aria-live="polite" aria-label="Hasil impor">
        <p v-if="hasil.diterapkan" class="catatan catatan-info" role="status">{{ hasil.jumlah_baris }} baris disimpan.</p>
        <p v-else-if="hasil.jumlah_salah === 0" class="catatan catatan-info" role="status">{{ hasil.jumlah_baris }} baris lolos pemeriksaan. Belum disimpan; pilih Terapkan untuk menyimpan.</p>
        <template v-else>
          <p class="catatan catatan-bahaya" role="alert">
            <Ikon nama="peringatan" :ukuran="16" />
            <span>{{ hasil.jumlah_baris ? `${hasil.jumlah_salah} dari ${hasil.jumlah_baris} baris salah.` : `${hasil.jumlah_salah} baris salah.` }} Tidak ada yang disimpan; perbaiki berkas lalu periksa ulang.</span>
          </p>
          <table class="tabel-galat">
            <caption class="sr-only">Kesalahan per baris berkas</caption>
            <thead>
              <tr><th scope="col">Baris</th><th scope="col">Kolom</th><th scope="col">Masalah</th></tr>
            </thead>
            <tbody>
              <tr v-for="(k, i) in hasil.kesalahan" :key="i">
                <td>{{ k.baris }}</td>
                <td><code v-if="k.kolom">{{ k.kolom }}</code></td>
                <td>{{ k.pesan }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="hasil.kesalahan_terpotong">Daftar dipotong pada 500 kesalahan pertama.</p>
        </template>
      </section>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">{{ hasil?.diterapkan ? 'Tutup' : 'Batal' }}</button>
      <template v-if="!hasil?.diterapkan">
        <Tombol type="submit" form="form-impor-csv" :varian="siapTerapkan ? undefined : 'utama'" :sibuk="sibuk && !siapTerapkan">Periksa berkas</Tombol>
        <Tombol v-if="siapTerapkan" type="button" varian="utama" :sibuk="sibuk" @click="jalankan('terapkan')">Terapkan {{ hasil?.jumlah_baris }} baris</Tombol>
      </template>
    </template>
  </Dialog>
</template>

<style scoped>
.hasil {
  margin-top: var(--space-3);
}
.tabel-galat {
  display: block;
  max-height: 240px;
  overflow: auto;
  border-collapse: collapse;
  font-size: var(--text-sm, 13px);
}
.tabel-galat th,
.tabel-galat td {
  padding: var(--space-1) var(--space-2);
  border-bottom: 1px solid var(--border);
  text-align: left;
}
</style>
