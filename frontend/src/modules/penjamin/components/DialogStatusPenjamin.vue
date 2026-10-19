<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { ApiError } from '@/shared/api/klien'
import { ubahPenjamin } from '../api'
import type { Penjamin } from '../tipe'

/** Nonaktifkan atau aktifkan kembali penjamin (tanpa hapus, PRD 11.1). Penjamin nonaktif tetap terbaca di riwayat. */
const props = defineProps<{ target: Berversi<Penjamin> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Penjamin>]; muatUlang: [] }>()

const dasar = ref('')
const errorDasar = ref<string | undefined>()
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

const statusBaru = computed(() => (props.target?.data.status === 'aktif' ? 'nonaktif' : 'aktif'))
const nama = computed(() => (props.target ? `${props.target.data.kode} · ${props.target.data.nama}` : ''))
const judul = computed(() => `${statusBaru.value === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan kembali'} penjamin?`)
const uraian = computed(() =>
  statusBaru.value === 'nonaktif'
    ? `${nama.value} tidak muncul lagi di pilihan pendaftaran. Kunjungan dan tagihan lama tetap menampilkan penjamin ini.`
    : `${nama.value} muncul lagi di pilihan pendaftaran.`,
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
  if (!props.target) return
  sibuk.value = true
  error.value = null
  try {
    const hasil = await ubahPenjamin(props.target.data.id, props.target.etag, { status: statusBaru.value, dasar_perubahan: d })
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Penjamin ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :uraian="uraian" :sibuk="sibuk" :peringatan="statusBaru === 'nonaktif'">
    <form id="form-status-penjamin" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errorDasar" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
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
      <Tombol type="submit" form="form-status-penjamin" :varian="statusBaru === 'nonaktif' ? 'bahaya' : 'utama'" :sibuk="sibuk">{{ statusBaru === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
