<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import type { Berversi } from '../../api'
import { buatKamar, semuaBangsalAktif, ubahKamar } from '../../apiLokasi'
import { KUNCI_LOKASI } from '../../lokasi'
import type { AtributKamar, Kamar, Lokasi } from '../../tipeLokasi'

/**
 * Jadikan sebuah ruang kamar rawat dalam bangsal, atau ubah bangsal, kapasitas maksimum, dan atribut standar kamar
 * (FR-08-19, daftar periksa; isi kriteria dari RS, tidak dikodekan). Kapasitas di bawah jumlah TT tetap disimpan dan
 * kamar ditandai melebihi kapasitas. `atribut_standar` dikirim utuh (mengganti seluruh objek).
 */
const props = defineProps<{ ruang: Lokasi; kamar: Berversi<Kamar> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Kamar>]; muatUlang: [] }>()

const bangsal = useQuery({ queryKey: KUNCI_LOKASI.bangsalAktif, queryFn: semuaBangsalAktif, enabled: buka, staleTime: 60_000 })

interface BarisAtribut {
  kunci: string
  nilai: string
}
const bangsalId = ref('')
/** v-model di input number memberi number begitu diisi; dinormalkan lewat String() saat dibaca. */
const kapasitas = ref<string | number>('')
const atribut = ref<BarisAtribut[]>([])
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

const teksNilai = (v: boolean | number | string) => (v === true ? 'ya' : v === false ? 'tidak' : String(v))
watch(buka, (b) => {
  if (!b) return
  const k = props.kamar?.data
  bangsalId.value = k?.bangsal.id ?? ''
  kapasitas.value = k?.kapasitas_maksimum != null ? String(k.kapasitas_maksimum) : ''
  atribut.value = k ? Object.entries(k.atribut_standar).map(([kunci, v]) => ({ kunci, nilai: teksNilai(v) })) : []
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

/** "ya"/"tidak" → boolean, angka → number, selain itu teks (maks. 100). */
function nilaiAtribut(teks: string): boolean | number | string {
  const t = teks.trim()
  if (t.toLowerCase() === 'ya') return true
  if (t.toLowerCase() === 'tidak') return false
  if (t !== '' && !Number.isNaN(Number(t.replace(',', '.')))) return Number(t.replace(',', '.'))
  return t
}
const KUNCI = /^[a-z][a-z0-9_]{1,49}$/
function bentukAtribut(): AtributKamar | null {
  const hasil: AtributKamar = {}
  for (const [i, b] of atribut.value.entries()) {
    if (!b.kunci.trim() && !b.nilai.trim()) continue
    if (!KUNCI.test(b.kunci.trim())) {
      kesalahan.value = { ...kesalahan.value, [`atribut-${i}`]: 'Kunci huruf kecil, angka, atau garis bawah; diawali huruf (mis. kamar_mandi_dalam).' }
      return null
    }
    hasil[b.kunci.trim()] = nilaiAtribut(b.nilai)
  }
  return hasil
}

const judul = computed(() => (props.kamar ? `Ubah kamar ${props.ruang.kode_lengkap}` : `Jadikan ${props.ruang.kode_lengkap} kamar rawat`))

async function kirim() {
  kesalahan.value = {}
  if (!bangsalId.value) kesalahan.value.bangsal_id = 'Pilih bangsal.'
  const teksKap = String(kapasitas.value).trim()
  const kap = teksKap === '' ? null : Number(teksKap)
  if (kap !== null && (!Number.isInteger(kap) || kap < 1 || kap > 99)) kesalahan.value.kapasitas_maksimum = 'Bilangan bulat 1–99, atau kosong.'
  if (dasar.value.trim().length < 3) kesalahan.value.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  const atr = bentukAtribut()
  if (Object.keys(kesalahan.value).length || !atr) return
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<Kamar>
    if (props.kamar) {
      const k = props.kamar.data
      const ubah: Parameters<typeof ubahKamar>[2] = { dasar_perubahan: dasar.value.trim() }
      if (bangsalId.value !== k.bangsal.id) ubah.bangsal_id = bangsalId.value
      if (kap !== k.kapasitas_maksimum) ubah.kapasitas_maksimum = kap
      if (JSON.stringify(atr) !== JSON.stringify(k.atribut_standar)) ubah.atribut_standar = atr
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahKamar(k.id, props.kamar.etag, ubah)
    } else {
      hasil = await buatKamar({ bangsal_id: bangsalId.value, lokasi_id: props.ruang.id, kapasitas_maksimum: kap, atribut_standar: atr, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    const kolom = errorKolom(e, ['bangsal_id', 'kapasitas_maksimum', 'atribut_standar', 'dasar_perubahan', 'lokasi_id'])
    if (Object.keys(kolom).length) {
      kesalahan.value = kolom
      if (kolom.lokasi_id) error.value = kolom.lokasi_id
    } else if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Kamar ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" lebar="lebar">
    <form id="form-kamar" novalidate @submit.prevent="kirim">
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Bangsal" :error="kesalahan.bangsal_id" wajib>
          <select v-bind="a" v-model="bangsalId" class="isian" :disabled="bangsal.isPending.value">
            <option value="" disabled>{{ bangsal.isPending.value ? 'Memuat bangsal…' : 'Pilih bangsal aktif' }}</option>
            <option v-for="b in bangsal.data.value ?? []" :key="b.id" :value="b.id">{{ b.kode }} · {{ b.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Kapasitas maksimum TT" :error="kesalahan.kapasitas_maksimum" petunjuk="Kosong = tanpa batas. Di bawah jumlah TT tetap disimpan dan ditandai.">
          <input v-bind="a" v-model="kapasitas" type="number" min="1" max="99" inputmode="numeric" class="isian" />
        </KolomIsian>
      </div>
      <fieldset class="atribut">
        <legend>Atribut standar kamar</legend>
        <p class="ket">Daftar periksa dari RS (kelas lama atau KRIS). Nilai: "ya", "tidak", angka, atau teks pendek.</p>
        <div v-for="(b, i) in atribut" :key="i" class="baris-atribut">
          <label class="sr-only" :for="`atribut-kunci-${i}`">Kunci atribut {{ i + 1 }}</label>
          <input :id="`atribut-kunci-${i}`" v-model="b.kunci" class="isian kode" placeholder="kamar_mandi_dalam" autocomplete="off" spellcheck="false" :aria-invalid="kesalahan[`atribut-${i}`] ? true : undefined" />
          <label class="sr-only" :for="`atribut-nilai-${i}`">Nilai atribut {{ i + 1 }}</label>
          <input :id="`atribut-nilai-${i}`" v-model="b.nilai" class="isian" placeholder="ya" autocomplete="off" maxlength="100" />
          <button type="button" class="tombol" :aria-label="`Hapus atribut ${b.kunci || i + 1}`" @click="atribut.splice(i, 1)">Hapus</button>
          <p v-if="kesalahan[`atribut-${i}`]" class="kolom-error">{{ kesalahan[`atribut-${i}`] }}</p>
        </div>
        <p v-if="kesalahan.atribut_standar" class="kolom-error" role="alert">{{ kesalahan.atribut_standar }}</p>
        <button type="button" class="tombol" :disabled="atribut.length >= 50" @click="atribut.push({ kunci: '', nilai: '' })"><Ikon nama="tambah" :ukuran="16" />Tambah atribut</button>
      </fieldset>
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
      <Tombol type="submit" form="form-kamar" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : kamar ? 'Simpan' : 'Jadikan kamar' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.atribut {
  margin: 0 0 var(--space-4);
  padding: 0;
  border: 0;
}
legend {
  padding: 0;
  font-size: 13px;
  font-weight: var(--font-weight-medium);
}
.ket {
  margin: 2px 0 var(--space-2);
  color: var(--muted);
  font-size: 12px;
}
.baris-atribut {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) auto;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
}
.baris-atribut .kolom-error {
  grid-column: 1 / -1;
  margin: 0;
  color: var(--danger);
  font-size: 12px;
}
.kode {
  font-family: var(--font-mono);
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
