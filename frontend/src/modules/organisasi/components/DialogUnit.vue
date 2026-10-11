<script setup lang="ts">
import { useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { buatUnit, ubahUnit, type Berversi } from '../api'
import { DAFTAR_JENIS, labelUnit } from '../organisasi'
import type { JenisUnit, UbahUnitOrganisasi, UnitOrganisasi } from '../tipe'
import PemilihUnit from './PemilihUnit.vue'

/**
 * Tambah unit (di bawah induk terpilih atau sebagai unit teratas) dan ubah unit: kode, nama, jenis, induk (pindah
 * cabang), tanggal berlaku. Ubah hanya mengirim kolom yang berubah. Dasar perubahan wajib (PRD 9.3). Menonaktifkan
 * lewat DialogStatus agar alasan 409 unit_masih_dipakai tampil jelas.
 */
const props = defineProps<{ mode: 'buat' | 'ubah'; unit: Berversi<UnitOrganisasi> | null; induk: UnitOrganisasi | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<UnitOrganisasi>]; muatUlang: [] }>()

const sibuk = ref(false)
const error = ref<string | null>(null)
const versiBerubah = ref(false)

const skema = z.object({
  induk_id: z.string().nullable(),
  kode: z
    .string()
    .trim()
    .regex(/^[A-Z0-9][A-Z0-9._-]{0,29}$/, 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'),
  nama: z.string().trim().min(2, 'Nama minimal 2 karakter.').max(150),
  jenis: z.string().min(1, 'Pilih jenis unit.'),
  berlaku_dari: z.string().optional(),
  dasar_perubahan: z.string().trim().min(3, 'Isi dasar perubahan, minimal 3 karakter.').max(200),
})
const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({ validationSchema: skemaZod(skema) })
const [indukId] = defineField<'induk_id', string | null>('induk_id')
const [kode] = defineField('kode')
const [nama] = defineField('nama')
const [jenis] = defineField('jenis')
const [berlaku] = defineField('berlaku_dari')
const [dasar] = defineField('dasar_perubahan')

const labelInduk = ref<string | null>(null)
watch(buka, (b) => {
  if (!b) return
  error.value = null
  versiBerubah.value = false
  const u = props.unit?.data
  if (props.mode === 'ubah' && u) {
    const jalurInduk = u.jalur[u.jalur.length - 1]
    labelInduk.value = jalurInduk ? labelUnit(jalurInduk) : null
    resetForm({ values: { induk_id: u.induk_id, kode: u.kode, nama: u.nama, jenis: u.jenis, berlaku_dari: u.berlaku_dari, dasar_perubahan: '' } })
  } else {
    labelInduk.value = props.induk ? labelUnit(props.induk) : null
    resetForm({ values: { induk_id: props.induk?.id ?? null, kode: '', nama: '', jenis: '', berlaku_dari: '', dasar_perubahan: '' } })
  }
})

const judul = computed(() => (props.mode === 'ubah' ? `Ubah unit ${props.unit?.data.kode ?? ''}` : props.induk ? `Tambah sub-unit di ${props.induk.kode}` : 'Tambah unit teratas'))

function muatUlang() {
  buka.value = false
  emit('muatUlang')
}

const kirim = handleSubmit(async (v) => {
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<UnitOrganisasi>
    if (props.mode === 'ubah' && props.unit) {
      const u = props.unit.data
      const ubah: UbahUnitOrganisasi = { dasar_perubahan: v.dasar_perubahan }
      if (v.induk_id !== u.induk_id) ubah.induk_id = v.induk_id
      if (v.kode !== u.kode) ubah.kode = v.kode
      if (v.nama !== u.nama) ubah.nama = v.nama
      if (v.jenis !== u.jenis) ubah.jenis = v.jenis as JenisUnit
      if (v.berlaku_dari && v.berlaku_dari !== u.berlaku_dari) ubah.berlaku_dari = v.berlaku_dari
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahUnit(u.id, props.unit.etag, ubah)
    } else {
      hasil = await buatUnit({
        induk_id: v.induk_id,
        kode: v.kode,
        nama: v.nama,
        jenis: v.jenis as JenisUnit,
        berlaku_dari: v.berlaku_dari || null,
        dasar_perubahan: v.dasar_perubahan,
      })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    const kolom = errorKolom(e, ['induk_id', 'kode', 'nama', 'jenis', 'berlaku_dari', 'dasar_perubahan'])
    if (Object.keys(kolom).length) setErrors(kolom)
    else if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Unit ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi perubahan.'
    } else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk">
    <form id="form-unit" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Unit induk" :error="errors.induk_id" :petunjuk="mode === 'ubah' ? 'Ganti induk untuk memindahkan unit beserta turunannya.' : undefined">
        <PemilihUnit v-bind="a" v-model="indukId" :label-terpilih="labelInduk" :kecuali="mode === 'ubah' ? (unit?.data.id ?? null) : null" opsi-kosong="Tanpa induk (unit teratas)" />
      </KolomIsian>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Kode" :error="errors.kode" petunjuk="Unik di seluruh RS, mis. RUANG-MELATI." wajib>
          <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" @input="kode = String(kode ?? '').toUpperCase()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Jenis" :error="errors.jenis" wajib>
          <select v-bind="a" v-model="jenis" class="isian">
            <option value="" disabled>Pilih jenis</option>
            <option v-for="[nilai, label] in DAFTAR_JENIS" :key="nilai" :value="nilai">{{ label }}</option>
          </select>
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Nama unit" :error="errors.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Berlaku dari" :error="errors.berlaku_dari" :petunjuk="mode === 'buat' ? 'Kosong = hari ini.' : undefined">
        <input v-bind="a" v-model="berlaku" type="date" class="isian" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errors.dasar_perubahan" petunjuk="Nomor SK struktur organisasi, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="muatUlang">Muat ulang unit</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-unit" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : mode === 'ubah' ? 'Simpan' : 'Tambah unit' }}</Tombol>
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
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
