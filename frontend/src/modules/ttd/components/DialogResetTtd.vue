<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ajukanResetTtd } from '../api'

/**
 * Ajukan reset TTE (PIN lupa) untuk akun seorang pegawai (tiket 1.6; PRD 7.57.2 b "oleh atasan + SDM"): petugas SDM
 * mengajukan sesudah memverifikasi identitas pemiliknya (dicatat di dasar), atasan langsung pemilik menyetujui lewat
 * Persetujuan akses. Disetujui → TTE lama dicabut (tanda tangan lama tetap sah) dan pemilik mengaktifkan ulang sendiri.
 * 409/422 (`ttd_belum_aktif`, `persetujuan_ganda`, `atasan_belum_ditetapkan`) dan 403 `diri_sendiri` tampil sebagai pesan.
 */
const props = defineProps<{ akun: { id: string; nama_pengguna: string } | null; namaPegawai: string }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [] }>()

const alasan = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  alasan.value = ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
})

async function kirim() {
  if (!props.akun) return
  const e: Record<string, string> = {}
  if (alasan.value.trim().length < 5) e.alasan = 'Isi alasan, minimal 5 karakter.'
  if (dasar.value.trim().length < 3) e.dasar = 'Catat verifikasi identitas pemilik (mis. tatap muka + kartu pegawai).'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    const p = await ajukanResetTtd(props.akun.id, { alasan: alasan.value.trim(), dasar: dasar.value.trim() })
    buka.value = false
    tampilkanToast(`Reset TTE ${p.nomor} diajukan; menunggu persetujuan ${p.penyetuju_pengguna?.nama ?? 'atasan langsung'}.`)
    emit('selesai')
  } catch (err) {
    const kolom = errorKolom(err, ['alasan', 'dasar'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Ajukan reset TTE ${akun?.nama_pengguna ?? ''}`" :sibuk="sibuk" peringatan>
    <form id="form-reset-ttd" novalidate @submit.prevent="kirim">
      <p class="catatan catatan-peringatan">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>
          Disetujui atasan langsung {{ namaPegawai }} → TTE lamanya dicabut (tanda tangan lama tetap sah) dan ia mengaktifkan ulang sendiri dengan PIN baru.
          Tidak ada yang menetapkan PIN untuk orang lain.
        </span>
      </p>
      <KolomIsian v-slot="a" label="Alasan" :error="kesalahan.alasan" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" placeholder="Mis. lupa PIN" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar (verifikasi identitas)" :error="kesalahan.dasar" petunjuk="Cara Anda memastikan pemohon adalah pemilik akun." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" maxlength="200" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-reset-ttd" varian="bahaya" :sibuk="sibuk">{{ sibuk ? 'Mengajukan…' : 'Ajukan reset' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.catatan {
  margin: 0 0 var(--space-3);
}
</style>
