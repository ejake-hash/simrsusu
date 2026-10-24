<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { cariPasien, usulkanGabung } from '../api'
import { formatNoRm, formatTanggal } from '../pasien'
import type { Pasien, UsulanGabung } from '../tipe'

/**
 * Usulkan gabung RM ganda (tiket 2.9; FR-01-02). Kedua No. RM dicari dulu supaya petugas melihat nama dan tanggal
 * lahirnya (dua penanda identitas) sebelum mengusulkan. Belum ada data yang berubah sampai Rekam Medis memverifikasi.
 */
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: UsulanGabung] }>()

const noRmSumber = ref('')
const noRmTujuan = ref('')
const sumber = ref<Pasien | null>(null)
const tujuan = ref<Pasien | null>(null)
const alasan = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  noRmSumber.value = noRmTujuan.value = alasan.value = ''
  sumber.value = tujuan.value = null
  kesalahan.value = {}
  error.value = null
})
watch(noRmSumber, () => (sumber.value = null))
watch(noRmTujuan, () => (tujuan.value = null))

async function periksa(kolom: 'sumber' | 'tujuan') {
  const kata = (kolom === 'sumber' ? noRmSumber : noRmTujuan).value.trim()
  const hasil = ref<Pasien | null>(null)
  kesalahan.value = { ...kesalahan.value, [kolom]: '' }
  if (!/^[0-9]{1,12}$/.test(kata)) {
    kesalahan.value = { ...kesalahan.value, [kolom]: 'Isi No. RM berupa angka.' }
    return
  }
  try {
    hasil.value = (await cariPasien(kata, null, 1)).data[0] ?? null
  } catch (err) {
    error.value = pesanError(err)
    return
  }
  if (!hasil.value) kesalahan.value = { ...kesalahan.value, [kolom]: 'No. RM tidak ditemukan.' }
  else if (hasil.value.digabung_ke) kesalahan.value = { ...kesalahan.value, [kolom]: `RM ini sudah digabung ke ${formatNoRm(hasil.value.digabung_ke.no_rm)}.` }
  else (kolom === 'sumber' ? sumber : tujuan).value = hasil.value
}

const siap = computed(() => sumber.value !== null && tujuan.value !== null && alasan.value.trim().length >= 5)

async function kirim() {
  if (!sumber.value || !tujuan.value) {
    kesalahan.value = { ...(sumber.value ? {} : { sumber: 'Periksa No. RM yang digabung.' }), ...(tujuan.value ? {} : { tujuan: 'Periksa No. RM yang dipertahankan.' }) }
    return
  }
  if (sumber.value.id === tujuan.value.id) {
    kesalahan.value = { tujuan: 'RM tujuan harus berbeda dari RM yang digabung.' }
    return
  }
  if (alasan.value.trim().length < 5) {
    kesalahan.value = { alasan: 'Isi alasan, minimal 5 karakter.' }
    return
  }
  sibuk.value = true
  error.value = null
  try {
    const hasil = await usulkanGabung(sumber.value.id, tujuan.value.id, alasan.value.trim())
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['alasan', 'pasien_tujuan_id'])
    if (Object.keys(kolom).length) kesalahan.value = { alasan: kolom.alasan ?? '', tujuan: kolom.pasien_tujuan_id ?? '' }
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" judul="Usulkan gabung RM ganda" :sibuk="sibuk">
    <form id="form-usul-gabung" novalidate @submit.prevent="kirim">
      <p class="catatan catatan-info">
        <Ikon nama="info" :ukuran="16" />
        <span>Kunjungan RM yang digabung dipindah ke RM yang dipertahankan setelah Rekam Medis memverifikasi. RM lama tidak dihapus dan tetap bisa ditelusuri.</span>
      </p>
      <div class="baris">
        <KolomIsian v-slot="a" label="No. RM yang digabung (RM lama)" wajib :error="kesalahan.sumber">
          <input v-bind="a" v-model="noRmSumber" class="isian" inputmode="numeric" autocomplete="off" />
        </KolomIsian>
        <Tombol type="button" @click="periksa('sumber')">Periksa</Tombol>
      </div>
      <p v-if="sumber" class="ditemukan" role="status"><b>{{ sumber.nama }}</b>, lahir {{ formatTanggal(sumber.tanggal_lahir) }}</p>
      <div class="baris">
        <KolomIsian v-slot="a" label="No. RM yang dipertahankan" wajib :error="kesalahan.tujuan">
          <input v-bind="a" v-model="noRmTujuan" class="isian" inputmode="numeric" autocomplete="off" />
        </KolomIsian>
        <Tombol type="button" @click="periksa('tujuan')">Periksa</Tombol>
      </div>
      <p v-if="tujuan" class="ditemukan" role="status"><b>{{ tujuan.nama }}</b>, lahir {{ formatTanggal(tujuan.tanggal_lahir) }}</p>
      <KolomIsian v-slot="a" label="Alasan" wajib :error="kesalahan.alasan">
        <textarea v-bind="a" v-model="alasan" class="isian" rows="3" maxlength="300" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-usul-gabung" varian="utama" :sibuk="sibuk" :disabled="!siap">{{ sibuk ? 'Mengirim…' : 'Usulkan gabung' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.catatan {
  margin: 0 0 var(--space-3);
}
.baris {
  display: flex;
  align-items: flex-end;
  gap: var(--space-2);
}
.baris :deep(.kolom) {
  flex: 1;
}
.ditemukan {
  margin: 0 0 var(--space-3);
  color: var(--muted);
}
</style>
