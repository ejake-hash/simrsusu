<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import { adendumHasilLab } from '../api'
import { bacaIsianHasil, identitasPasien, kurangAlasanAdendum, perluMuatUlang, pesanGalatLab } from '../lab'
import type { HasilLab } from '../tipe'
import TampilanHasilLab from './TampilanHasilLab.vue'

/**
 * Adendum hasil lab tervalidasi (tiket 2.31; FR-26-06, 7.00.5 butir 3): nilai baru + alasan wajib, identitas dua penanda
 * dan hasil asli tampil sebelum konfirmasi (5.1 butir 9). Hasil asli tidak berubah; server menghitung ulang penanda.
 * Dipakai daftar kerja lab dan linimasa hasil episode.
 */
const props = defineProps<{ hasil: HasilLab | null; fokusSesudahTutup?: () => HTMLElement | null | undefined }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: HasilLab]; muatUlang: [] }>()

const nilai = ref('')
const alasan = ref('')
const galat = ref<unknown>(null)
const sudahCoba = ref(false)
const mengirim = ref(false)
watch(buka, (b) => {
  if (!b) return
  nilai.value = ''
  alasan.value = ''
  galat.value = null
  sudahCoba.value = false
})

const baca = computed(() => bacaIsianHasil(nilai.value, props.hasil?.jenis_hasil ?? null))
const kolomServer = computed(() => errorKolom(galat.value, ['nilai', 'nilai_teks', 'alasan']))
const errorNilai = computed(() => kolomServer.value.nilai ?? kolomServer.value.nilai_teks ?? (sudahCoba.value && 'galat' in baca.value ? baca.value.galat : null))
const errorAlasan = computed(() => kolomServer.value.alasan ?? (sudahCoba.value ? kurangAlasanAdendum(alasan.value) : null))
const pesanUmum = computed(() => (galat.value && !errorNilai.value && !errorAlasan.value ? pesanGalatLab(galat.value) : null))
const petunjuk = computed(() =>
  props.hasil?.jenis_hasil === 'teks' ? 'Teks hasil, paling banyak 500 karakter.' : `Angka, koma untuk desimal${props.hasil?.satuan ? `. Satuan ${props.hasil.satuan}` : ''}.`,
)

async function kirim() {
  const h = props.hasil
  sudahCoba.value = true
  galat.value = null
  if (!h || 'galat' in baca.value || kurangAlasanAdendum(alasan.value)) return
  mengirim.value = true
  try {
    const baru = await adendumHasilLab(h.id, { ...baca.value.isian, alasan: alasan.value.trim() })
    buka.value = false
    emit('selesai', baru)
  } catch (e) {
    if (perluMuatUlang(e)) emit('muatUlang')
    galat.value = e
  } finally {
    mengirim.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:open="buka"
    :judul="hasil ? `Adendum ${hasil.nama}` : 'Adendum hasil'"
    uraian="Hasil asli tetap tersimpan dan terlihat. Adendum tampil di bawahnya beserta waktu, pelaku, dan alasan."
    lebar="lebar"
    :sibuk="mengirim"
    :fokus-sesudah-tutup="fokusSesudahTutup"
  >
    <dl v-if="hasil" class="ringkas">
      <div>
        <dt>Pasien</dt>
        <dd>{{ identitasPasien(hasil) }}</dd>
      </div>
      <div>
        <dt>Pemeriksaan</dt>
        <dd><code>{{ hasil.kode_item }}</code> {{ hasil.nama }}</dd>
      </div>
      <div>
        <dt>Hasil sekarang</dt>
        <dd class="biasa"><TampilanHasilLab :hasil="hasil" /></dd>
      </div>
    </dl>
    <form id="form-adendum-hasil" class="form" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Hasil koreksi" wajib :petunjuk="petunjuk" :error="errorNilai ?? undefined">
        <input v-bind="a" v-model="nilai" type="text" class="isian" maxlength="500" autocomplete="off" :inputmode="hasil?.jenis_hasil === 'teks' ? 'text' : 'decimal'" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Alasan koreksi" wajib petunjuk="5 sampai 500 karakter. Terbaca oleh semua yang membuka hasil ini." :error="errorAlasan ?? undefined">
        <textarea v-bind="a" v-model="alasan" class="isian" rows="3" maxlength="500"></textarea>
      </KolomIsian>
    </form>
    <div role="alert">
      <div v-if="pesanUmum" class="blok-galat">
        <Pesan jenis="error">{{ pesanUmum }}</Pesan>
      </div>
    </div>
    <template #aksi>
      <Tombol :disabled="mengirim || undefined" @click="buka = false">Batal</Tombol>
      <Tombol type="submit" form="form-adendum-hasil" varian="utama" :sibuk="mengirim">Simpan adendum</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.ringkas {
  display: grid;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
}
.ringkas dt {
  font-size: 12px;
  color: var(--muted);
}
.ringkas dd {
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
.ringkas dd.biasa {
  font-weight: inherit;
}
.form {
  display: grid;
  gap: var(--space-3);
}
.blok-galat {
  margin-top: var(--space-3);
}
</style>
