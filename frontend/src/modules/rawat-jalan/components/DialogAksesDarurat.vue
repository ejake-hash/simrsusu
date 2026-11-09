<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import { LABEL_KATEGORI_ALASAN, pesanBukaAksesDarurat } from '@/modules/rekam-medis/aksesDarurat'
import { type AksesDarurat, bukaAksesDarurat, type KategoriAlasanAksesRm } from '@/shared/api/aksesDarurat'

/** Buka akses darurat RM satu pasien (UC-33, FR-26-12, FR-26-26): kategori dan uraian alasan wajib; akses terbatas waktu dan ditinjau. */
const props = defineProps<{ pasienId: string; identitas: string }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ terbuka: [akses: AksesDarurat] }>()

const kategori = ref<KategoriAlasanAksesRm>('pelayanan_lanjutan')
const alasan = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  kategori.value = 'pelayanan_lanjutan'
  alasan.value = ''
  kesalahan.value = {}
  error.value = null
})

async function kirim() {
  if (alasan.value.trim().length < 10) {
    kesalahan.value = { alasan: 'Uraikan keadaan daruratnya, minimal 10 karakter.' }
    return
  }
  kesalahan.value = {}
  sibuk.value = true
  error.value = null
  try {
    const akses = await bukaAksesDarurat(props.pasienId, { kategori_alasan: kategori.value, alasan: alasan.value.trim() })
    buka.value = false
    emit('terbuka', akses)
  } catch (err) {
    const kolom = errorKolom(err, ['alasan', 'kategori_alasan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else error.value = pesanBukaAksesDarurat(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" judul="Buka akses darurat" :uraian="`${identitas}. Akses berlaku terbatas waktu dan hanya untuk pasien ini.`" :sibuk="sibuk" peringatan>
    <form id="form-akses-darurat" novalidate class="form" @submit.prevent="kirim">
      <p class="ket">Setiap pembacaan selama akses darurat tercatat atas nama Anda, lalu ditinjau Kepala Rekam Medis atau pejabat pelindungan data pribadi.</p>
      <KolomIsian v-slot="a" label="Kategori alasan" wajib :error="kesalahan.kategori_alasan">
        <select v-bind="a" v-model="kategori" class="isian">
          <option v-for="(l, k) in LABEL_KATEGORI_ALASAN" :key="k" :value="k">{{ l }}</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Uraian keadaan darurat" wajib :error="kesalahan.alasan" petunjuk="Minimal 10 karakter. Jangan menulis data pasien di sini.">
        <textarea v-bind="a" v-model="alasan" class="isian" rows="4" maxlength="500" />
      </KolomIsian>
      <div role="alert"><Pesan v-if="error" jenis="error">{{ error }}</Pesan></div>
    </form>
    <template #aksi>
      <Tombol :disabled="sibuk || undefined" @click="buka = false">Batal</Tombol>
      <Tombol type="submit" form="form-akses-darurat" varian="utama" :sibuk="sibuk">Buka akses darurat</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.form {
  display: grid;
  gap: var(--space-3);
}
.ket {
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
}
</style>
