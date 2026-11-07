<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import type { IsianButir } from '@/modules/usulan/tipe'
import type { StatusAktif } from '../tipe'
import { harusLewatUsulan } from '../formularium'
import { galatUsulan, useModeUsulan } from '../usulanFormularium'
import GalatForm from './GalatForm.vue'
import KolomUsulan from './KolomUsulan.vue'

/**
 * Nonaktifkan atau aktifkan kembali satu barang atau item formularium (tanpa hapus, PRD 11.1). Pemanggil memberi `nama`,
 * keterangan dampak, dan fungsi `simpan` yang membawa If-Match; galat (412, 409) tampil di sini. Bila `butirUsulan` diberi
 * dan server menjawab 409 `harus_lewat_usulan`, perubahan diajukan sebagai usulan (2.12); null = tidak tersedia lewat usulan.
 */
const props = defineProps<{
  objek: string
  nama: string
  status: StatusAktif
  dampakNonaktif: string
  simpan: (statusBaru: StatusAktif, dasar: string) => Promise<unknown>
  butirUsulan?: (statusBaru: StatusAktif, alasan: string) => IsianButir | null
}>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: []; muatUlang: [] }>()

const dasar = ref('')
const errorDasar = ref<string | undefined>()
const galat = ref<unknown>(null)
const sibuk = ref(false)
const kesalahan = ref<Record<string, string>>({})
const { aktif: modeUsulan, berlaku, alasan, reset: resetUsulan, tangkap, periksa: periksaUsulan, ajukan } = useModeUsulan()
const baru = computed<StatusAktif>(() => (props.status === 'aktif' ? 'nonaktif' : 'aktif'))
const judul = computed(() => `${baru.value === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan kembali'} ${props.objek}?`)
const uraian = computed(() => (baru.value === 'nonaktif' ? `${props.nama}: ${props.dampakNonaktif}` : `${props.nama} bisa dipakai lagi.`))

watch(buka, (b) => {
  if (!b) return
  dasar.value = ''
  errorDasar.value = undefined
  galat.value = null
  kesalahan.value = {}
  resetUsulan()
})

async function kirim() {
  const d = dasar.value.trim()
  if (d.length < 3) {
    errorDasar.value = 'Isi dasar perubahan, minimal 3 karakter.'
    return
  }
  errorDasar.value = undefined
  kesalahan.value = modeUsulan.value ? periksaUsulan() : {}
  if (Object.keys(kesalahan.value).length) return
  sibuk.value = true
  galat.value = null
  try {
    if (modeUsulan.value) {
      const butir = props.butirUsulan?.(baru.value, alasan.value.trim())
      if (!butir) {
        galat.value = 'Perubahan status ini belum bisa diajukan lewat usulan.'
        return
      }
      await ajukan(butir, d)
    } else await props.simpan(baru.value, d)
    buka.value = false
    emit('selesai')
  } catch (e) {
    // Hanya perubahan yang punya butir usulan yang dipindah ke mode usulan; selainnya galat 409 tampil apa adanya.
    if (props.butirUsulan && harusLewatUsulan(e) && props.butirUsulan(baru.value, '') !== null) {
      tangkap(e)
      return
    }
    if (modeUsulan.value) {
      const [kolom, sisa] = galatUsulan(e, [])
      kesalahan.value = kolom
      galat.value = sisa ?? (Object.keys(kolom).length ? null : e)
    } else galat.value = e
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :uraian="uraian" :sibuk="sibuk" :peringatan="baru === 'nonaktif'">
    <form id="form-status-formularium" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errorDasar" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomUsulan v-if="modeUsulan" v-model:berlaku="berlaku" v-model:alasan="alasan" :kesalahan="kesalahan" />
      <GalatForm :galat="galat" @muat-ulang="((buka = false), emit('muatUlang'))" />
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-status-formularium" :varian="baru === 'nonaktif' ? 'bahaya' : 'utama'" :sibuk="sibuk">{{ modeUsulan ? 'Ajukan usulan' : baru === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
    </template>
  </Dialog>
</template>
