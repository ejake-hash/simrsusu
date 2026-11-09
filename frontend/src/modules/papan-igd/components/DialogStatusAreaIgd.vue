<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { ApiError } from '@/shared/api/klien'
import { etagArea, ubahAreaIgd } from '../api'
import type { AreaIgdDataInduk } from '../tipe'

/** Nonaktifkan atau aktifkan kembali area IGD (tanpa hapus, PRD 11.1). Pasien yang sudah ditempatkan tetap tampil di area itu. */
const props = defineProps<{ area: AreaIgdDataInduk | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<AreaIgdDataInduk>]; muatUlang: [] }>()

const dasar = ref('')
const errorDasar = ref<string | undefined>()
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

const statusBaru = computed(() => (props.area?.status === 'aktif' ? 'nonaktif' : 'aktif'))
const nama = computed(() => (props.area ? `${props.area.kode} · ${props.area.nama}` : ''))
const judul = computed(() => `${statusBaru.value === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan kembali'} area IGD?`)
const uraian = computed(() =>
  statusBaru.value === 'nonaktif'
    ? `${nama.value} tidak bisa dipilih lagi untuk penempatan baru. Pasien yang sudah ditempatkan di area ini tetap tampil dengan area ini.`
    : `${nama.value} bisa dipilih lagi untuk penempatan pasien.`,
)

watch(buka, (b) => {
  if (!b) return
  dasar.value = ''
  errorDasar.value = undefined
  error.value = null
  versiBerubah.value = false
})

async function kirim() {
  const d = dasar.value.trim()
  if (d.length < 3) {
    errorDasar.value = 'Isi dasar perubahan, minimal 3 karakter.'
    return
  }
  errorDasar.value = undefined
  if (!props.area) return
  sibuk.value = true
  error.value = null
  try {
    const hasil = await ubahAreaIgd(props.area.id, etagArea(props.area), { status: statusBaru.value, dasar_perubahan: d })
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Area ini diubah orang lain sejak daftar dimuat. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :uraian="uraian" :sibuk="sibuk" :peringatan="statusBaru === 'nonaktif'">
    <form id="form-status-area-igd" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errorDasar" petunjuk="Nomor SK, notulen komite, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" maxlength="200" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-status-area-igd" :varian="statusBaru === 'nonaktif' ? 'bahaya' : 'utama'" :sibuk="sibuk">{{ statusBaru === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
