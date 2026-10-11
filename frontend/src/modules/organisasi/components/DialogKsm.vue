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
import PemilihPegawai from '@/modules/sdm/components/PemilihPegawai.vue'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { buatKsm, ubahKsm, type Berversi } from '../api'
import type { Ksm } from '../tipe'

/**
 * Tambah dan ubah KSM (kode, nama; ketua KSM dari pegawai saat ubah, butuh sdm.pegawai.lihat). Status lewat
 * DialogStatus. Dasar perubahan wajib.
 */
const props = defineProps<{ ksm: Berversi<Ksm> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Ksm>]; muatUlang: [] }>()

const sibuk = ref(false)
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const ketuaId = ref<string | null>(null)
const labelKetua = ref<string | null>(null)
const kesalahanKetua = ref<string | undefined>()
const bolehPilihKetua = computed(() => boleh('sdm.pegawai.lihat'))

const skema = z.object({
  kode: z
    .string()
    .trim()
    .regex(/^[A-Z0-9][A-Z0-9._-]{0,29}$/, 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'),
  nama: z.string().trim().min(2, 'Nama minimal 2 karakter.').max(150),
  dasar_perubahan: z.string().trim().min(3, 'Isi dasar perubahan, minimal 3 karakter.').max(200),
})
const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({ validationSchema: skemaZod(skema) })
const [kode] = defineField('kode')
const [nama] = defineField('nama')
const [dasar] = defineField('dasar_perubahan')

watch(buka, (b) => {
  if (!b) return
  error.value = null
  versiBerubah.value = false
  kesalahanKetua.value = undefined
  ketuaId.value = props.ksm?.data.ketua?.id ?? null
  labelKetua.value = props.ksm?.data.ketua?.nama ?? null
  resetForm({ values: { kode: props.ksm?.data.kode ?? '', nama: props.ksm?.data.nama ?? '', dasar_perubahan: '' } })
})

const kirim = handleSubmit(async (v) => {
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<Ksm>
    if (props.ksm) {
      const k = props.ksm.data
      const ubah: { kode?: string; nama?: string; ketua_pegawai_id?: string | null; dasar_perubahan: string } = { dasar_perubahan: v.dasar_perubahan }
      if (v.kode !== k.kode) ubah.kode = v.kode
      if (v.nama !== k.nama) ubah.nama = v.nama
      if (bolehPilihKetua.value && ketuaId.value !== (k.ketua?.id ?? null)) ubah.ketua_pegawai_id = ketuaId.value
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahKsm(k.id, props.ksm.etag, ubah)
    } else {
      hasil = await buatKsm(v)
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    const kolom = errorKolom(e, ['kode', 'nama', 'ketua_pegawai_id', 'dasar_perubahan'])
    if (Object.keys(kolom).length) {
      const { ketua_pegawai_id: ketua, ...lain } = kolom
      kesalahanKetua.value = ketua
      setErrors(lain)
    }
    else if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'KSM ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi perubahan.'
    } else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="ksm ? `Ubah KSM ${ksm.data.kode}` : 'Tambah KSM'" :sibuk="sibuk">
    <form id="form-ksm" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Kode" :error="errors.kode" petunjuk="Unik, mis. KSM-IPD." wajib>
        <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" @input="kode = String(kode ?? '').toUpperCase()" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Nama KSM" :error="errors.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-if="ksm && bolehPilihKetua" v-slot="a" label="Ketua KSM" :error="kesalahanKetua">
        <PemilihPegawai v-bind="a" v-model="ketuaId" :label-terpilih="labelKetua" opsi-kosong="Belum ditetapkan" />
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
      <Tombol type="submit" form="form-ksm" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : ksm ? 'Simpan' : 'Tambah KSM' }}</Tombol>
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
