<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { buatIzinPraktik } from '../api'
import { LABEL_JENIS_IZIN, ukuranBerkas } from '../sdm'
import type { IzinPraktik, JenisIzinPraktik } from '../tipe'

/**
 * Catat izin praktik (STR/SIP/SIK/SPK) beserta dokumennya (opsional, PDF/JPEG/PNG ≤ 5 MB; jenis sebenarnya diperiksa
 * server dari isi berkas). Tidak bisa diubah sesudah dicatat: perbaikan = batalkan lalu catat ulang. Berlaku sampai
 * kosong hanya untuk STR seumur hidup.
 */
const props = defineProps<{ pegawaiId: string }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: IzinPraktik] }>()

const MIME = ['application/pdf', 'image/jpeg', 'image/png']
const BATAS = 5 * 1024 * 1024

const jenis = ref<JenisIzinPraktik | ''>('')
const nomor = ref('')
const terbit = ref('')
const sampai = ref('')
const seumurHidup = ref(false)
const berkas = ref<File | null>(null)
const isianBerkas = ref<HTMLInputElement | null>(null)
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

const bolehSeumurHidup = computed(() => jenis.value === 'str')
watch(jenis, () => {
  if (!bolehSeumurHidup.value) seumurHidup.value = false
})
watch(buka, (b) => {
  if (!b) return
  jenis.value = ''
  nomor.value = ''
  terbit.value = ''
  sampai.value = ''
  seumurHidup.value = false
  berkas.value = null
  if (isianBerkas.value) isianBerkas.value.value = ''
  kesalahan.value = {}
  error.value = null
})

function pilihBerkas(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] ?? null
  berkas.value = f
  const { berkas: _, ...lain } = kesalahan.value
  kesalahan.value = lain
  if (f && !MIME.includes(f.type)) kesalahan.value = { ...lain, berkas: 'Berkas harus PDF, JPEG, atau PNG.' }
  else if (f && f.size > BATAS) kesalahan.value = { ...lain, berkas: `Berkas ${ukuranBerkas(f.size)}; paling besar 5 MB.` }
}

async function kirim() {
  const e: Record<string, string> = {}
  if (!jenis.value) e.jenis = 'Pilih jenis izin.'
  if (nomor.value.trim().length < 3) e.nomor = 'Nomor minimal 3 karakter.'
  if (!terbit.value) e.terbit = 'Isi tanggal terbit.'
  if (!seumurHidup.value && !sampai.value) e.berlaku_sampai = bolehSeumurHidup.value ? 'Isi tanggal, atau centang seumur hidup.' : 'Isi tanggal berlaku sampai.'
  if (!seumurHidup.value && sampai.value && terbit.value && sampai.value < terbit.value) e.berlaku_sampai = 'Tidak boleh sebelum tanggal terbit.'
  if (kesalahan.value.berkas) e.berkas = kesalahan.value.berkas
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    const hasil = await buatIzinPraktik(props.pegawaiId, {
      jenis: jenis.value as JenisIzinPraktik,
      nomor: nomor.value.trim(),
      terbit: terbit.value,
      berlaku_sampai: seumurHidup.value ? null : sampai.value,
      berkas: berkas.value,
    })
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['jenis', 'nomor', 'terbit', 'berlaku_sampai', 'berkas'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 413) kesalahan.value = { berkas: 'Berkas melebihi batas unggah server.' }
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" judul="Catat izin praktik" :sibuk="sibuk">
    <form id="form-izin-praktik" novalidate @submit.prevent="kirim">
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Jenis izin" :error="kesalahan.jenis" wajib>
          <select v-bind="a" v-model="jenis" class="isian">
            <option value="" disabled>Pilih jenis</option>
            <option v-for="(label, nilai) in LABEL_JENIS_IZIN" :key="nilai" :value="nilai">{{ label }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Nomor" :error="kesalahan.nomor" wajib>
          <input v-bind="a" v-model="nomor" class="isian kode" autocomplete="off" spellcheck="false" maxlength="100" />
        </KolomIsian>
      </div>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Tanggal terbit" :error="kesalahan.terbit" petunjuk="Di masa depan = belum berlaku." wajib>
          <input v-bind="a" v-model="terbit" type="date" class="isian" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Berlaku sampai" :error="kesalahan.berlaku_sampai" :wajib="!seumurHidup">
          <input v-bind="a" v-model="sampai" type="date" class="isian" :disabled="seumurHidup" />
        </KolomIsian>
      </div>
      <label v-if="bolehSeumurHidup" class="centang">
        <input v-model="seumurHidup" type="checkbox" />
        <span>STR seumur hidup (tanpa tanggal berakhir)</span>
      </label>
      <KolomIsian v-slot="a" label="Dokumen (opsional)" :error="kesalahan.berkas" petunjuk="PDF, JPEG, atau PNG; paling besar 5 MB. Tidak bisa ditambahkan sesudah dicatat.">
        <input v-bind="a" ref="isianBerkas" type="file" class="isian" accept="application/pdf,image/jpeg,image/png" @change="pilihBerkas" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-izin-praktik" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Catat izin' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.kode {
  font-family: var(--font-mono);
}
.centang {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 32px;
  margin: calc(-1 * var(--space-2)) 0 var(--space-3);
  font-size: 13px;
  cursor: pointer;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .centang {
    min-height: 44px;
  }
}
</style>
