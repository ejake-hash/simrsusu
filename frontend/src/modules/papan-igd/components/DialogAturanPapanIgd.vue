<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { ApiError } from '@/shared/api/klien'
import { ubahAturanPapanIgd } from '../api'
import type { AturanPapanIgd, UbahAturanPapanIgd } from '../tipe'

/**
 * Ubah batas waktu papan IGD (tiket 2.25; FR-03-04, FR-00-06). Tanpa lebar peringatan tanggap (keputusan pemilik 9 Nov
 * 2026). Ambang mendekati 6 jam boleh dikosongkan: kosong = tanda mati. Batas 6 jam sendiri aturan INA-CBG, bukan isian.
 */
const props = defineProps<{ aturan: Berversi<AturanPapanIgd> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<AturanPapanIgd>]; muatUlang: [] }>()

// v-model input number memberi angka, atau '' saat kosong.
const batas = ref<string | number>('')
const ambang = ref<string | number>('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  batas.value = String(props.aturan?.data.batas_tanggap_menit ?? '')
  ambang.value = props.aturan?.data.peringatan_lama_igd_menit == null ? '' : String(props.aturan.data.peringatan_lama_igd_menit)
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

const teks = (v: string | number) => String(v ?? '').trim()
const bulat = (v: string | number, min: number, maks: number) => {
  const n = Number(teks(v))
  return teks(v) !== '' && Number.isInteger(n) && n >= min && n <= maks ? n : null
}

async function kirim() {
  if (!props.aturan) return
  const e: Record<string, string> = {}
  const b = bulat(batas.value, 1, 1440)
  const kosong = teks(ambang.value) === ''
  const am = kosong ? null : bulat(ambang.value, 1, 359)
  if (b === null) e.batas_tanggap_menit = 'Isi bilangan bulat 1 sampai 1.440 menit.'
  if (!kosong && am === null) e.peringatan_lama_igd_menit = 'Isi bilangan bulat 1 sampai 359 menit, atau kosongkan.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  const lama = props.aturan.data
  const ubah: UbahAturanPapanIgd = { dasar_perubahan: dasar.value.trim() }
  if (b !== lama.batas_tanggap_menit) ubah.batas_tanggap_menit = b!
  if (am !== lama.peringatan_lama_igd_menit) ubah.peringatan_lama_igd_menit = am
  if (Object.keys(ubah).length < 2) {
    error.value = 'Belum ada perubahan untuk disimpan.'
    return
  }
  sibuk.value = true
  error.value = null
  try {
    const hasil = await ubahAturanPapanIgd(props.aturan.etag, ubah)
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['batas_tanggap_menit', 'peringatan_lama_igd_menit', 'dasar_perubahan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Batas waktu ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" judul="Ubah batas waktu papan IGD" :sibuk="sibuk">
    <form id="form-aturan-papan-igd" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Batas waktu tanggap (menit)" :error="kesalahan.batas_tanggap_menit" petunjuk="Dihitung dari jam tiba sampai dokter mulai mengisi asesmen awal. Lewat dari batas ini, papan menandai Lewat batas tanggap." wajib>
        <input v-bind="a" v-model="batas" class="isian angka" type="number" inputmode="numeric" min="1" max="1440" step="1" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Tanda mendekati 6 jam (menit sebelum 6 jam)" :error="kesalahan.peringatan_lama_igd_menit" petunjuk="Kosongkan untuk mematikan tanda ini. Tanda Lebih dari 6 jam tetap tampil.">
        <input v-bind="a" v-model="ambang" class="isian angka" type="number" inputmode="numeric" min="1" max="359" step="1" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, notulen komite, atau persetujuan." wajib>
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
      <Tombol type="submit" form="form-aturan-papan-igd" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.angka {
  max-width: 160px;
  font-variant-numeric: tabular-nums;
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
