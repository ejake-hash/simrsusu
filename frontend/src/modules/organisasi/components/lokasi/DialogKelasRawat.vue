<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import type { Berversi } from '../../api'
import { buatKelasRawat, ubahKelasRawat } from '../../apiLokasi'
import type { KelasRawat } from '../../tipeLokasi'

/**
 * Tambah atau ubah kelas rawat. Kelas lama dan KRIS boleh berdampingan (tanggal berlaku); isi kelas dan kode Aplicares
 * diisi RS dari dokumen resmi — tidak ada daftar bawaan. Status lewat DialogStatus.
 */
const props = defineProps<{ kelas: Berversi<KelasRawat> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<KelasRawat>]; muatUlang: [] }>()

const kode = ref('')
const nama = ref('')
const kodeAplicares = ref('')
const berlakuDari = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const k = props.kelas?.data
  kode.value = k?.kode ?? ''
  nama.value = k?.nama ?? ''
  kodeAplicares.value = k?.kode_aplicares ?? ''
  berlakuDari.value = ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

async function kirim() {
  const e: Record<string, string> = {}
  if (!/^[A-Z0-9][A-Z0-9._-]{0,29}$/.test(kode.value.trim())) e.kode = 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'
  if (!nama.value.trim()) e.nama = 'Isi nama kelas.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  const aplicares = kodeAplicares.value.trim() || null
  try {
    let hasil: Berversi<KelasRawat>
    if (props.kelas) {
      const k = props.kelas.data
      const ubah: Parameters<typeof ubahKelasRawat>[2] = { dasar_perubahan: dasar.value.trim() }
      if (kode.value.trim() !== k.kode) ubah.kode = kode.value.trim()
      if (nama.value.trim() !== k.nama) ubah.nama = nama.value.trim()
      if (aplicares !== k.kode_aplicares) ubah.kode_aplicares = aplicares
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahKelasRawat(k.id, props.kelas.etag, ubah)
    } else {
      hasil = await buatKelasRawat({ kode: kode.value.trim(), nama: nama.value.trim(), kode_aplicares: aplicares, berlaku_dari: berlakuDari.value || null, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['kode', 'nama', 'kode_aplicares', 'berlaku_dari', 'dasar_perubahan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Kelas rawat ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="kelas ? `Ubah kelas rawat ${kelas.data.kode}` : 'Tambah kelas rawat'" :sibuk="sibuk">
    <form id="form-kelas-rawat" novalidate @submit.prevent="kirim">
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Kode" :error="kesalahan.kode" wajib>
          <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" @input="kode = kode.toUpperCase()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Kode Aplicares (opsional)" :error="kesalahan.kode_aplicares" petunjuk="Dari dokumen resmi BPJS.">
          <input v-bind="a" v-model="kodeAplicares" class="isian kode" autocomplete="off" spellcheck="false" maxlength="20" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Nama kelas" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="100" />
      </KolomIsian>
      <KolomIsian v-if="!kelas" v-slot="a" label="Berlaku dari" :error="kesalahan.berlaku_dari" petunjuk="Kosong = hari ini.">
        <input v-bind="a" v-model="berlakuDari" type="date" class="isian" />
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
      <Tombol type="submit" form="form-kelas-rawat" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : kelas ? 'Simpan' : 'Tambah kelas' }}</Tombol>
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
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
