<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Sakelar from '@/design-system/components/Sakelar.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import type { Berversi } from '../../api'
import { buatTempatTidur, semuaKelasRawatAktif, ubahTempatTidur } from '../../apiLokasi'
import { hariIniLokal, KUNCI_LOKASI, LABEL_JENIS_KELAMIN } from '../../lokasi'
import type { AturanJenisKelamin, TempatTidur } from '../../tipeLokasi'

/**
 * Tambah TT di kamar (kode TT = kode lengkap ruang + "-" + kode; tidak pernah dipakai ulang) atau ubah kelas rawat,
 * aturan jenis kelamin, isolasi, dan penanda ekstra. Kode dan kamar tidak bisa diubah (pindah = tutup lalu buat baru).
 * TT di atas kapasitas tetap dibuat; peringatan dikembalikan ke pemanggil.
 */
/** Kamar cukup id dan kode lengkap ruangnya (dari Kamar atau TempatTidur.kamar). */
const props = defineProps<{ kamar: { id: string; lokasi: { kode_lengkap: string } }; tempatTidur: Berversi<TempatTidur> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<TempatTidur>, peringatan: string[]]; muatUlang: [] }>()

const kelas = useQuery({ queryKey: KUNCI_LOKASI.kelasAktif, queryFn: semuaKelasRawatAktif, enabled: buka, staleTime: 60_000 })

const kode = ref('')
const kelasId = ref('')
const jenisKelamin = ref<AturanJenisKelamin>('campur')
const isolasi = ref(false)
const ekstra = ref(false)
const berlakuDari = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const t = props.tempatTidur?.data
  kode.value = ''
  kelasId.value = t?.kelas_rawat.id ?? ''
  jenisKelamin.value = t?.aturan_jenis_kelamin ?? 'campur'
  isolasi.value = t?.isolasi ?? false
  ekstra.value = t?.ekstra ?? false
  berlakuDari.value = hariIniLokal()
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

const kodeTt = computed(() => (kode.value.trim() ? `${props.kamar.lokasi.kode_lengkap}-${kode.value.trim()}` : null))
const judul = computed(() => (props.tempatTidur ? `Ubah TT ${props.tempatTidur.data.kode}` : `Tambah TT di kamar ${props.kamar.lokasi.kode_lengkap}`))

async function kirim() {
  const e: Record<string, string> = {}
  if (!props.tempatTidur && !/^[A-Z0-9]{1,10}$/.test(kode.value.trim())) e.kode = 'Huruf besar atau angka, 1–10 karakter.'
  if (!kelasId.value) e.kelas_rawat_id = 'Pilih kelas rawat.'
  if (!props.tempatTidur && berlakuDari.value && berlakuDari.value < hariIniLokal()) e.berlaku_dari = 'Tidak boleh sebelum hari ini.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    if (props.tempatTidur) {
      const t = props.tempatTidur.data
      const ubah: Parameters<typeof ubahTempatTidur>[2] = { dasar_perubahan: dasar.value.trim() }
      if (kelasId.value !== t.kelas_rawat.id) ubah.kelas_rawat_id = kelasId.value
      if (jenisKelamin.value !== t.aturan_jenis_kelamin) ubah.aturan_jenis_kelamin = jenisKelamin.value
      if (isolasi.value !== t.isolasi) ubah.isolasi = isolasi.value
      if (ekstra.value !== t.ekstra) ubah.ekstra = ekstra.value
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      const hasil = await ubahTempatTidur(t.id, props.tempatTidur.etag, ubah)
      buka.value = false
      emit('selesai', hasil, [])
    } else {
      const hasil = await buatTempatTidur({
        kamar_id: props.kamar.id,
        kode: kode.value.trim(),
        kelas_rawat_id: kelasId.value,
        aturan_jenis_kelamin: jenisKelamin.value,
        isolasi: isolasi.value,
        ekstra: ekstra.value,
        berlaku_dari: berlakuDari.value || null,
        dasar_perubahan: dasar.value.trim(),
      })
      buka.value = false
      emit('selesai', hasil, hasil.peringatan)
    }
  } catch (err) {
    const kolom = errorKolom(err, ['kode', 'kelas_rawat_id', 'aturan_jenis_kelamin', 'berlaku_dari', 'dasar_perubahan', 'kamar_id'])
    if (Object.keys(kolom).length) {
      kesalahan.value = kolom
      if (kolom.kamar_id) error.value = kolom.kamar_id
    } else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'TT ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk">
    <form id="form-tempat-tidur" novalidate @submit.prevent="kirim">
      <div class="dua-kolom">
        <KolomIsian v-if="!tempatTidur" v-slot="a" label="Kode TT" :error="kesalahan.kode" :petunjuk="kodeTt ? `Kode TT: ${kodeTt} (tidak pernah dipakai ulang)` : 'Segmen, mis. 1 atau A.'" wajib>
          <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" maxlength="10" @input="kode = kode.toUpperCase()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Kelas rawat" :error="kesalahan.kelas_rawat_id" wajib>
          <select v-bind="a" v-model="kelasId" class="isian" :disabled="kelas.isPending.value">
            <option value="" disabled>{{ kelas.isPending.value ? 'Memuat kelas…' : kelas.data.value?.length ? 'Pilih kelas rawat' : 'Belum ada kelas rawat aktif' }}</option>
            <option v-for="k in kelas.data.value ?? []" :key="k.id" :value="k.id">{{ k.kode }} · {{ k.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Aturan jenis kelamin" :error="kesalahan.aturan_jenis_kelamin">
          <select v-bind="a" v-model="jenisKelamin" class="isian">
            <option v-for="(label, nilai) in LABEL_JENIS_KELAMIN" :key="nilai" :value="nilai">{{ label }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-if="!tempatTidur" v-slot="a" label="Mulai dihitung" :error="kesalahan.berlaku_dari" petunjuk="Hari ini atau sesudahnya.">
          <input v-bind="a" v-model="berlakuDari" type="date" class="isian" :min="hariIniLokal()" />
        </KolomIsian>
      </div>
      <div class="sakelar">
        <Sakelar v-model="isolasi" label="Tempat tidur isolasi" />
        <Sakelar v-model="ekstra" label="TT ekstra (tambahan saat lonjakan, dihitung terpisah)" />
      </div>
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
      <Tombol type="submit" form="form-tempat-tidur" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : tempatTidur ? 'Simpan' : 'Tambah TT' }}</Tombol>
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
.sakelar {
  display: grid;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}
.kode {
  font-family: var(--font-mono);
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
