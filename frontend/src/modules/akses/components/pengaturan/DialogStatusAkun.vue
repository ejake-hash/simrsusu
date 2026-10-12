<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ubahStatusAkun } from '../../apiPengaturan'
import { errorKolom, pesanError } from '../../pengaturan'
import type { AkunRinci } from '../../tipePengaturan'

/**
 * Nonaktifkan atau aktifkan akun dengan alasan wajib (tiket 1.8d fe 3). Nonaktif memutus sesi berjalan akun itu.
 * 409 (sudah_nonaktif, sudah_aktif, superadmin_aktif, pegawai_keluar) berisi `rincian` kosong: pesan server tampil
 * sebagai banner di dialog (kontrak: tombol tetap dirender, 409 ditampilkan sebagai pesan).
 */
const props = defineProps<{ akun: AkunRinci }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: AkunRinci] }>()

const alasan = ref('')
const kesalahan = ref<string | null>(null)
const error = ref<string | null>(null)
const sibuk = ref(false)
const nonaktifkan = computed(() => props.akun.status === 'aktif')

watch(buka, (b) => {
  if (!b) return
  alasan.value = ''
  kesalahan.value = null
  error.value = null
})

async function kirim() {
  if (alasan.value.trim().length < 5) {
    kesalahan.value = 'Isi alasan, minimal 5 karakter.'
    return
  }
  kesalahan.value = null
  sibuk.value = true
  error.value = null
  try {
    const hasil = await ubahStatusAkun(props.akun.id, nonaktifkan.value ? 'nonaktifkan' : 'aktifkan', alasan.value.trim())
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['alasan'])
    if (kolom.alasan) kesalahan.value = kolom.alasan
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="nonaktifkan ? `Nonaktifkan ${akun.nama_pengguna}?` : `Aktifkan ${akun.nama_pengguna}?`" :sibuk="sibuk" :peringatan="nonaktifkan">
    <form id="form-status-akun" novalidate @submit.prevent="kirim">
      <p class="uraian">
        {{
          nonaktifkan
            ? `${akun.nama} tidak bisa masuk lagi dan sesi yang sedang berjalan diakhiri. Penugasan dan pengecualian tetap tersimpan.`
            : `${akun.nama} bisa masuk lagi dengan sandinya. Penugasan yang masih berlaku kembali berlaku.`
        }}
      </p>
      <KolomIsian v-slot="a" label="Alasan" :error="kesalahan ?? undefined" petunjuk="Tercatat di jejak audit." wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-status-akun" :varian="nonaktifkan ? 'bahaya' : 'utama'" :sibuk="sibuk">
        {{ sibuk ? 'Menyimpan…' : nonaktifkan ? 'Nonaktifkan' : 'Aktifkan' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.uraian {
  margin: 0 0 var(--space-3);
  color: var(--muted);
  font-size: 13px;
}
</style>
