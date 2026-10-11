<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { buatKewenangan } from '../api'
import type { KewenanganKlinis } from '../tipe'

/**
 * Catat rincian kewenangan klinis (RKK) minimal: kategori, daftar kode tindakan, masa berlaku, komite pemberi
 * rekomendasi. Kode tindakan diisi RS dari referensi resmi (mis. ICD-9-CM); layar hanya memeriksa bentuknya, tidak
 * ada daftar bawaan. Perbaikan = batalkan lalu catat ulang.
 */
const props = defineProps<{ pegawaiId: string }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: KewenanganKlinis] }>()

const KODE = /^[0-9A-Za-z.-]{1,20}$/
const kategori = ref('')
const teksKode = ref('')
const dari = ref('')
const sampai = ref('')
const komite = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const sibuk = ref(false)

/** Pisah koma, titik koma, spasi, atau baris baru; duplikat dibuang. */
const kode = computed(() => [...new Set(teksKode.value.split(/[\s,;]+/).map((k) => k.trim()).filter(Boolean))])

watch(buka, (b) => {
  if (!b) return
  kategori.value = ''
  teksKode.value = ''
  dari.value = ''
  sampai.value = ''
  komite.value = ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
})

async function kirim() {
  const e: Record<string, string> = {}
  if (kategori.value.trim().length < 2) e.kategori = 'Kategori minimal 2 karakter.'
  const salah = kode.value.filter((k) => !KODE.test(k))
  if (!kode.value.length) e.kode_tindakan = 'Isi minimal satu kode tindakan.'
  else if (salah.length) e.kode_tindakan = `Bentuk kode tidak sah: ${salah.slice(0, 5).join(', ')}. Huruf, angka, titik, atau tanda hubung; paling panjang 20.`
  else if (kode.value.length > 500) e.kode_tindakan = 'Paling banyak 500 kode per catatan.'
  if (!dari.value) e.berlaku_dari = 'Isi tanggal mulai.'
  if (!sampai.value) e.berlaku_sampai = 'Isi tanggal berakhir.'
  else if (dari.value && sampai.value < dari.value) e.berlaku_sampai = 'Tidak boleh sebelum tanggal mulai.'
  if (komite.value.trim().length < 2) e.komite = 'Isi komite pemberi rekomendasi.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    const hasil = await buatKewenangan(props.pegawaiId, {
      kategori: kategori.value.trim(),
      kode_tindakan: kode.value,
      berlaku_dari: dari.value,
      berlaku_sampai: sampai.value,
      komite: komite.value.trim(),
      dasar: dasar.value.trim() || null,
    })
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['kategori', 'kode_tindakan', 'berlaku_dari', 'berlaku_sampai', 'komite', 'dasar'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" judul="Catat kewenangan klinis (RKK)" :sibuk="sibuk">
    <form id="form-kewenangan" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Kategori" :error="kesalahan.kategori" petunjuk="Mis. kelompok tindakan atau bidang kewenangan." wajib>
        <input v-bind="a" v-model="kategori" class="isian" autocomplete="off" maxlength="100" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Kode tindakan" :error="kesalahan.kode_tindakan" :petunjuk="`Dari referensi resmi RS; pisahkan dengan koma atau baris baru. ${kode.length} kode.`" wajib>
        <textarea v-bind="a" v-model="teksKode" class="isian kode" rows="3" spellcheck="false" />
      </KolomIsian>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Berlaku dari" :error="kesalahan.berlaku_dari" wajib>
          <input v-bind="a" v-model="dari" type="date" class="isian" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Berlaku sampai" :error="kesalahan.berlaku_sampai" wajib>
          <input v-bind="a" v-model="sampai" type="date" class="isian" />
        </KolomIsian>
      </div>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Komite" :error="kesalahan.komite" petunjuk="Pemberi rekomendasi, mis. Komite Medik." wajib>
          <input v-bind="a" v-model="komite" class="isian" autocomplete="off" maxlength="150" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Nomor SK (opsional)" :error="kesalahan.dasar">
          <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" maxlength="200" />
        </KolomIsian>
      </div>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-kewenangan" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Catat RKK' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.kode {
  font-family: var(--font-mono);
}
</style>
