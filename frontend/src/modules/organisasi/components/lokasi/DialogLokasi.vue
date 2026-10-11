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
import type { Berversi } from '../../api'
import { buatLokasi, ubahLokasi } from '../../apiLokasi'
import { DAFTAR_FUNGSI, JENIS_ANAK, LABEL_JENIS_LOKASI } from '../../lokasi'
import type { FungsiRuang, IsianLokasi, JenisLokasi, Lokasi, UbahLokasi } from '../../tipeLokasi'
import PemilihLokasi from '../PemilihLokasi.vue'
import PemilihUnit from '../PemilihUnit.vue'

/**
 * Tambah lokasi di bawah induk terpilih (jenis anak mengikuti aturan kontrak) atau ubah lokasi: kode segmen, nama,
 * fungsi (ruang), unit pengelola, dan pindah induk. Ubah hanya mengirim kolom yang berubah; dasar perubahan wajib.
 * Tempat tidur tidak dikelola di sini (panel kamar). Status lewat DialogStatusLokasi.
 */
const props = defineProps<{ mode: 'buat' | 'ubah'; lokasi: Berversi<Lokasi> | null; induk: Lokasi | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Lokasi>]; muatUlang: [] }>()

const sibuk = ref(false)
const error = ref<string | null>(null)
const versiBerubah = ref(false)

const jenisBoleh = computed(() => JENIS_ANAK[props.induk?.jenis ?? 'akar'])
/** Jenis induk yang sah untuk lokasi yang diubah (pindah induk). */
const jenisIndukBoleh = computed<JenisLokasi[]>(() => {
  const j = props.lokasi?.data.jenis
  return (Object.entries(JENIS_ANAK) as [JenisLokasi | 'akar', JenisLokasi[]][]).filter(([induk, anak]) => induk !== 'akar' && j && anak.includes(j as never)).map(([induk]) => induk as JenisLokasi)
})
const bolehAkar = computed(() => !!props.lokasi && JENIS_ANAK.akar.includes(props.lokasi.data.jenis as never))

const skema = z
  .object({
    jenis: z.string().min(1, 'Pilih jenis lokasi.'),
    induk_id: z.string().nullable(),
    kode: z.string().trim().regex(/^[A-Z0-9]{1,10}$/, 'Huruf besar atau angka, 1–10 karakter, tanpa tanda hubung.'),
    nama: z.string().trim().min(2, 'Nama minimal 2 karakter.').max(150),
    fungsi: z.string().optional(),
    unit_organisasi_id: z.string().nullable(),
    dasar_perubahan: z.string().trim().min(3, 'Isi dasar perubahan, minimal 3 karakter.').max(200),
  })
  .refine((v) => v.jenis !== 'ruang' || !!v.fungsi, { path: ['fungsi'], message: 'Pilih fungsi ruang.' })
const { defineField, handleSubmit, errors, resetForm, setErrors, values } = useForm({ validationSchema: skemaZod(skema) })
const [jenis] = defineField('jenis')
const [indukId] = defineField<'induk_id', string | null>('induk_id')
const [kode] = defineField('kode')
const [nama] = defineField('nama')
const [fungsi] = defineField('fungsi')
const [unitId] = defineField<'unit_organisasi_id', string | null>('unit_organisasi_id')
const [dasar] = defineField('dasar_perubahan')

const labelInduk = ref<string | null>(null)
const labelUnit = ref<string | null>(null)
watch(buka, (b) => {
  if (!b) return
  error.value = null
  versiBerubah.value = false
  const l = props.lokasi?.data
  if (props.mode === 'ubah' && l) {
    const induk = l.jalur[l.jalur.length - 1]
    labelInduk.value = induk ? `${induk.kode_lengkap} · ${induk.nama}` : null
    labelUnit.value = l.unit ? `${l.unit.kode} · ${l.unit.nama}` : null
    resetForm({ values: { jenis: l.jenis, induk_id: l.induk_id, kode: l.kode, nama: l.nama, fungsi: l.fungsi ?? '', unit_organisasi_id: l.unit?.id ?? null, dasar_perubahan: '' } })
  } else {
    labelUnit.value = null
    resetForm({ values: { jenis: jenisBoleh.value.length === 1 ? jenisBoleh.value[0] : '', induk_id: props.induk?.id ?? null, kode: '', nama: '', fungsi: '', unit_organisasi_id: null, dasar_perubahan: '' } })
  }
})

const judul = computed(() =>
  props.mode === 'ubah' ? `Ubah ${props.lokasi?.data.kode_lengkap ?? ''}` : props.induk ? `Tambah di bawah ${props.induk.kode_lengkap}` : 'Tambah lokasi teratas',
)
const pratinjauKode = computed(() => {
  const k = String(values.kode ?? '').trim()
  if (!k) return null
  const induk = props.mode === 'buat' ? props.induk?.kode_lengkap : props.lokasi?.data.jalur[props.lokasi.data.jalur.length - 1]?.kode_lengkap
  return induk ? `${induk}-${k}` : k
})

const kirim = handleSubmit(async (v) => {
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<Lokasi>
    const fungsiNilai = v.jenis === 'ruang' ? ((v.fungsi || null) as FungsiRuang | null) : null
    if (props.mode === 'ubah' && props.lokasi) {
      const l = props.lokasi.data
      const ubah: UbahLokasi = { dasar_perubahan: v.dasar_perubahan }
      if (v.induk_id !== l.induk_id) ubah.induk_id = v.induk_id
      if (v.kode !== l.kode) ubah.kode = v.kode
      if (v.nama !== l.nama) ubah.nama = v.nama
      if (l.jenis === 'ruang' && fungsiNilai !== l.fungsi) ubah.fungsi = fungsiNilai
      if (v.unit_organisasi_id !== (l.unit?.id ?? null)) ubah.unit_organisasi_id = v.unit_organisasi_id
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahLokasi(l.id, props.lokasi.etag, ubah)
    } else {
      const isian: IsianLokasi = {
        induk_id: v.induk_id,
        jenis: v.jenis as IsianLokasi['jenis'],
        kode: v.kode,
        nama: v.nama,
        fungsi: fungsiNilai,
        unit_organisasi_id: v.unit_organisasi_id,
        dasar_perubahan: v.dasar_perubahan,
      }
      hasil = await buatLokasi(isian)
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    const kolom = errorKolom(e, ['jenis', 'induk_id', 'kode', 'nama', 'fungsi', 'unit_organisasi_id', 'dasar_perubahan'])
    if (Object.keys(kolom).length) setErrors(kolom)
    else if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Lokasi ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi perubahan.'
    } else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk">
    <form id="form-lokasi" novalidate @submit.prevent="kirim">
      <KolomIsian v-if="mode === 'buat'" v-slot="a" label="Jenis" :error="errors.jenis" wajib>
        <select v-bind="a" v-model="jenis" class="isian">
          <option value="" disabled>Pilih jenis</option>
          <option v-for="j in jenisBoleh" :key="j" :value="j">{{ LABEL_JENIS_LOKASI[j] }}</option>
        </select>
      </KolomIsian>
      <KolomIsian v-else v-slot="a" label="Induk" :error="errors.induk_id" petunjuk="Ganti induk untuk memindahkan lokasi beserta turunannya; kode lengkap dihitung ulang, kode TT tetap.">
        <PemilihLokasi
          v-bind="a"
          v-model="indukId"
          :jenis="jenisIndukBoleh"
          :label-terpilih="labelInduk"
          :kecuali="lokasi?.data.id ?? null"
          :opsi-kosong="bolehAkar ? 'Tanpa induk (lokasi teratas)' : null"
        />
      </KolomIsian>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Kode" :error="errors.kode" :petunjuk="pratinjauKode ? `Kode lengkap: ${pratinjauKode}` : 'Segmen pendek, mis. 3 atau 304.'" wajib>
          <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" maxlength="10" @input="kode = String(kode ?? '').toUpperCase()" />
        </KolomIsian>
        <KolomIsian v-if="jenis === 'ruang'" v-slot="a" label="Fungsi ruang" :error="errors.fungsi" wajib>
          <select v-bind="a" v-model="fungsi" class="isian">
            <option value="" disabled>Pilih fungsi</option>
            <option v-for="[nilai, label] in DAFTAR_FUNGSI" :key="nilai" :value="nilai">{{ label }}</option>
          </select>
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Nama" :error="errors.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Unit pengelola" :error="errors.unit_organisasi_id" :petunjuk="mode === 'buat' ? 'Kosong = disalin dari pengelola induk saat dibuat.' : undefined">
        <PemilihUnit v-bind="a" v-model="unitId" :label-terpilih="labelUnit" opsi-kosong="Ikuti pengelola induk" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errors.dasar_perubahan" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-lokasi" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : mode === 'ubah' ? 'Simpan' : 'Tambah' }}</Tombol>
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
