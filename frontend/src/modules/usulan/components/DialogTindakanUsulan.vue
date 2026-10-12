<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { formatTanggal } from '@/shared/utils/waktu'
import { tindakUsulan } from '../api'
import type { UsulanDataInduk } from '../tipe'

/**
 * Verifikasi, setujui (catatan opsional), atau kembalikan (catatan wajib ≥ 5) satu usulan. 403 (`pengusul_sendiri`,
 * `sudah_memverifikasi`, `bukan_*`) dan 409 `tahap_tidak_sesuai` (diubah orang lain) tampil sebagai pesan; 409 juga
 * menawarkan muat ulang. Setujui dengan berlaku hari ini langsung diterapkan.
 */
const props = defineProps<{ usulan: UsulanDataInduk; tindakan: 'verifikasi' | 'setujui' | 'kembalikan' }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: UsulanDataInduk]; muatUlang: [] }>()

const catatan = ref('')
const kesalahan = ref<string | undefined>()
const error = ref<string | null>(null)
const basi = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  catatan.value = ''
  kesalahan.value = undefined
  error.value = null
  basi.value = false
})

const judul = computed(() => ({ verifikasi: 'Verifikasi', setujui: 'Setujui', kembalikan: 'Kembalikan' })[props.tindakan] + ` ${props.usulan.nomor}?`)
const akibat = computed(() => {
  const tgl = formatTanggal(`${props.usulan.berlaku_dari}T00:00:00`)
  if (props.tindakan === 'verifikasi') return 'Usulan diteruskan ke penyetuju. Anda tidak bisa menyetujui usulan yang Anda verifikasi.'
  if (props.tindakan === 'setujui') return `Versi baru berlaku otomatis ${tgl} pukul 00.00 (langsung bila tanggalnya hari ini). Semua perubahan diterapkan dalam satu transaksi.`
  return 'Usulan kembali ke pengusul untuk diperbaiki dan dikirim ulang. Catatan Anda dibaca pengusul.'
})
const adaBerubah = computed(() => props.usulan.perubahan.some((b) => b.objek_berubah))

async function kirim() {
  const t = catatan.value.trim()
  if (props.tindakan === 'kembalikan' && t.length < 5) {
    kesalahan.value = 'Tulis catatan pengembalian, minimal 5 karakter.'
    return
  }
  kesalahan.value = undefined
  sibuk.value = true
  error.value = null
  try {
    const hasil = await tindakUsulan(props.usulan.id, props.tindakan, t || null)
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['catatan'])
    if (kolom.catatan) kesalahan.value = kolom.catatan
    else {
      error.value = pesanError(err)
      basi.value = err instanceof ApiError && err.status === 409
    }
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" :peringatan="tindakan === 'kembalikan'">
    <form id="form-tindakan-usulan" novalidate @submit.prevent="kirim">
      <p class="catatan" :class="tindakan === 'kembalikan' ? 'catatan-peringatan' : 'catatan-info'">
        <Ikon :nama="tindakan === 'kembalikan' ? 'peringatan' : 'info'" :ukuran="16" />
        <span>{{ akibat }}</span>
      </p>
      <p v-if="adaBerubah && tindakan !== 'kembalikan'" class="catatan catatan-peringatan">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>Ada objek yang berubah sejak diusulkan; penerapan akan gagal. Pertimbangkan mengembalikan usulan ini.</span>
      </p>
      <KolomIsian v-slot="a" :label="tindakan === 'kembalikan' ? 'Catatan pengembalian' : 'Catatan (opsional)'" :error="kesalahan" :wajib="tindakan === 'kembalikan'">
        <textarea v-bind="a" v-model="catatan" class="isian" rows="3" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="basi" type="button" class="tombol" @click="((buka = false), emit('muatUlang'))">Muat ulang usulan</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-tindakan-usulan" :varian="tindakan === 'kembalikan' ? 'bahaya' : 'utama'" :sibuk="sibuk">
        {{ sibuk ? 'Memproses…' : { verifikasi: 'Verifikasi', setujui: 'Setujui', kembalikan: 'Kembalikan' }[tindakan] }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.catatan {
  margin: 0 0 var(--space-3);
}
</style>
