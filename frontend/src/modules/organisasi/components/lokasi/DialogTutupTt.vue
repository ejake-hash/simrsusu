<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { formatTanggal } from '@/shared/utils/waktu'
import type { Berversi } from '../../api'
import { tutupTempatTidur } from '../../apiLokasi'
import { hariIniLokal } from '../../lokasi'
import type { TempatTidur } from '../../tipeLokasi'

/**
 * Tutup TT mulai sebuah tanggal (permanen; kodenya tidak dipakai ulang). Tanggal = hari pertama TT tidak dihitung;
 * sampai hari sebelumnya TT tetap dihitung dalam kapasitas (status akan ditutup). Tutup sementara/buka kembali lewat
 * usulan berjenjang (1.8c).
 */
const props = defineProps<{ tempatTidur: Berversi<TempatTidur> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<TempatTidur>]; muatUlang: [] }>()

const tanggal = ref('')
const alasan = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

const minimal = computed(() => {
  const dari = props.tempatTidur?.data.berlaku_dari ?? ''
  return dari > hariIniLokal() ? dari : hariIniLokal()
})
watch(buka, (b) => {
  if (!b) return
  tanggal.value = hariIniLokal(1)
  if (tanggal.value < minimal.value) tanggal.value = minimal.value
  alasan.value = ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})
const akibat = computed(() => {
  if (!tanggal.value) return ''
  const t = new Date(`${tanggal.value}T00:00:00`)
  t.setDate(t.getDate() - 1)
  return tanggal.value === hariIniLokal()
    ? 'Mulai hari ini TT tidak dihitung dalam kapasitas.'
    : `TT masih dihitung sampai ${formatTanggal(t.toISOString())}, lalu tidak dihitung mulai ${formatTanggal(`${tanggal.value}T00:00:00`)}.`
})

async function kirim() {
  const e: Record<string, string> = {}
  if (!tanggal.value || tanggal.value < minimal.value) e.berlaku_dari = 'Hari ini atau sesudahnya, dan tidak sebelum TT mulai berlaku.'
  if (alasan.value.trim().length < 5) e.alasan = 'Tulis alasan, minimal 5 karakter.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length || !props.tempatTidur) return
  sibuk.value = true
  error.value = null
  try {
    const t = props.tempatTidur
    const hasil = await tutupTempatTidur(t.data.id, t.etag, { berlaku_dari: tanggal.value, alasan: alasan.value.trim(), dasar_perubahan: dasar.value.trim() })
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['berlaku_dari', 'alasan', 'dasar_perubahan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'TT ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Tutup TT ${tempatTidur?.data.kode ?? ''}?`" uraian="Penutupan permanen: kode TT tidak dipakai ulang. Buka kembali lewat usulan perubahan." :sibuk="sibuk" peringatan>
    <form id="form-tutup-tt" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Tidak dihitung mulai" :error="kesalahan.berlaku_dari" :petunjuk="akibat" wajib>
        <input v-bind="a" v-model="tanggal" type="date" class="isian" :min="minimal" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Alasan penutupan" :error="kesalahan.alasan" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" />
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
      <Tombol type="submit" form="form-tutup-tt" varian="bahaya" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Tutup TT' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
