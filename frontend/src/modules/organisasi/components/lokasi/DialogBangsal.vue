<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import type { Berversi } from '../../api'
import { buatBangsal, ubahBangsal } from '../../apiLokasi'
import type { Bangsal } from '../../tipeLokasi'
import PemilihUnit from '../PemilihUnit.vue'

/** Tambah atau ubah bangsal (kode, nama, unit pengelola). Status lewat DialogStatus. Dasar perubahan wajib. */
const props = defineProps<{ bangsal: Berversi<Bangsal> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Bangsal>]; muatUlang: [] }>()

const kode = ref('')
const nama = ref('')
const unitId = ref<string | null>(null)
const labelUnit = ref<string | null>(null)
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const x = props.bangsal?.data
  kode.value = x?.kode ?? ''
  nama.value = x?.nama ?? ''
  unitId.value = x?.unit.id ?? null
  labelUnit.value = x ? `${x.unit.kode} · ${x.unit.nama}` : null
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

async function kirim() {
  const e: Record<string, string> = {}
  if (!/^[A-Z0-9][A-Z0-9._-]{0,29}$/.test(kode.value.trim())) e.kode = 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'
  if (nama.value.trim().length < 2) e.nama = 'Nama minimal 2 karakter.'
  if (!unitId.value) e.unit_organisasi_id = 'Pilih unit pengelola.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<Bangsal>
    if (props.bangsal) {
      const x = props.bangsal.data
      const ubah: Parameters<typeof ubahBangsal>[2] = { dasar_perubahan: dasar.value.trim() }
      if (kode.value.trim() !== x.kode) ubah.kode = kode.value.trim()
      if (nama.value.trim() !== x.nama) ubah.nama = nama.value.trim()
      if (unitId.value !== x.unit.id) ubah.unit_organisasi_id = unitId.value!
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahBangsal(x.id, props.bangsal.etag, ubah)
    } else {
      hasil = await buatBangsal({ kode: kode.value.trim(), nama: nama.value.trim(), unit_organisasi_id: unitId.value!, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['kode', 'nama', 'unit_organisasi_id', 'dasar_perubahan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Bangsal ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="bangsal ? `Ubah bangsal ${bangsal.data.kode}` : 'Tambah bangsal'" :sibuk="sibuk">
    <form id="form-bangsal" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Kode" :error="kesalahan.kode" petunjuk="Unik, mis. MELATI." wajib>
        <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" @input="kode = kode.toUpperCase()" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Nama bangsal" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Unit pengelola" :error="kesalahan.unit_organisasi_id" wajib>
        <PemilihUnit v-bind="a" v-model="unitId" :label-terpilih="labelUnit" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
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
      <Tombol type="submit" form="form-bangsal" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : bangsal ? 'Simpan' : 'Tambah bangsal' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.kode {
  font-family: var(--font-mono);
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
