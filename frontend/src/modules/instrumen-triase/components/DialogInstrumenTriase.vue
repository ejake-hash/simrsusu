<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import type { SkalaTriase } from '@/modules/pendaftaran/tipeKunjungan'
import { ApiError } from '@/shared/api/klien'
import { buatInstrumenTriase, ubahInstrumenTriase } from '../api'
import type { InstrumenTriaseDataInduk, UbahInstrumenTriase } from '../tipe'

/**
 * Tambah atau ubah instrumen triase (tiket 2.24). Skala = daftar tingkat berurutan (kode + label) yang dipilih perawat
 * saat triase; kosong = instrumen tanpa skala. Isinya ditetapkan RS; layar ini tidak membawa contoh klinis. Status
 * lewat DialogStatusInstrumenTriase.
 */
const props = defineProps<{ instrumen: Berversi<InstrumenTriaseDataInduk> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<InstrumenTriaseDataInduk>]; muatUlang: [] }>()

const MAKS_SKALA = 20
const KODE = /^[A-Z0-9][A-Z0-9._-]{0,29}$/
const KODE_SKALA = /^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/
const kode = ref('')
const nama = ref('')
const skala = ref<SkalaTriase[]>([])
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const i = props.instrumen?.data
  kode.value = i?.kode ?? ''
  nama.value = i?.nama ?? ''
  skala.value = (i?.skala ?? []).map((s) => ({ ...s }))
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

function tambahSkala() {
  if (skala.value.length < MAKS_SKALA) skala.value.push({ kode: '', label: '' })
}
function hapusSkala(n: number) {
  skala.value.splice(n, 1)
  kesalahan.value = {}
}

const samaSkala = (a: SkalaTriase[], b: SkalaTriase[]) => a.length === b.length && a.every((s, n) => s.kode === b[n]!.kode && s.label === b[n]!.label)

async function kirim() {
  const e: Record<string, string> = {}
  const k = kode.value.trim()
  const sk = skala.value.map((s) => ({ kode: s.kode.trim(), label: s.label.trim() }))
  if (!KODE.test(k)) e.kode = 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'
  if (nama.value.trim().length < 2) e.nama = 'Isi nama instrumen, minimal 2 karakter.'
  const terpakai = new Set<string>()
  sk.forEach((s, n) => {
    if (!KODE_SKALA.test(s.kode)) e[`skala.${n}.kode`] = 'Huruf, angka, titik, garis bawah, atau tanda hubung; 1–20 karakter.'
    else if (terpakai.has(s.kode)) e[`skala.${n}.kode`] = 'Kode skala sudah dipakai di instrumen ini.'
    terpakai.add(s.kode)
    if (!s.label) e[`skala.${n}.label`] = 'Isi label skala.'
  })
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<InstrumenTriaseDataInduk>
    if (props.instrumen) {
      const i = props.instrumen.data
      const ubah: UbahInstrumenTriase = { dasar_perubahan: dasar.value.trim() }
      if (k !== i.kode) ubah.kode = k
      if (nama.value.trim() !== i.nama) ubah.nama = nama.value.trim()
      if (!samaSkala(sk, i.skala)) ubah.skala = sk
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahInstrumenTriase(i.id, props.instrumen.etag, ubah)
    } else {
      hasil = await buatInstrumenTriase({ kode: k, nama: nama.value.trim(), skala: sk, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['kode', 'nama', 'skala', 'dasar_perubahan', ...sk.flatMap((_, n) => [`skala.${n}.kode`, `skala.${n}.label`])])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Instrumen ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="instrumen ? `Ubah instrumen ${instrumen.data.kode}` : 'Tambah instrumen triase'" :sibuk="sibuk">
    <form id="form-instrumen-triase" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Kode" :error="kesalahan.kode" wajib>
        <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" maxlength="30" @input="kode = kode.toUpperCase()" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Nama instrumen" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="150" />
      </KolomIsian>

      <fieldset class="skala">
        <legend>Skala (opsional)</legend>
        <p class="petunjuk">Tingkat yang dipilih perawat saat triase, urut tampil dari atas. Kosongkan bila instrumen tidak memakai skala.</p>
        <p v-if="kesalahan.skala" class="galat" role="alert">{{ kesalahan.skala }}</p>
        <ol v-if="skala.length" class="daftar-skala">
          <li v-for="(s, n) in skala" :key="n" class="baris-skala">
            <KolomIsian v-slot="a" :label="`Kode tingkat ${n + 1}`" :error="kesalahan[`skala.${n}.kode`]" wajib>
              <input v-bind="a" v-model="s.kode" class="isian kode" autocomplete="off" spellcheck="false" maxlength="20" />
            </KolomIsian>
            <KolomIsian v-slot="a" :label="`Label tingkat ${n + 1}`" :error="kesalahan[`skala.${n}.label`]" wajib>
              <input v-bind="a" v-model="s.label" class="isian" autocomplete="off" maxlength="100" />
            </KolomIsian>
            <Tombol class="hapus-skala" :aria-label="`Hapus tingkat ${n + 1}`" @click="hapusSkala(n)">Hapus</Tombol>
          </li>
        </ol>
        <Tombol :disabled="skala.length >= MAKS_SKALA || undefined" @click="tambahSkala"><Ikon nama="tambah" :ukuran="16" />Tambah tingkat</Tombol>
      </fieldset>

      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, notulen komite, atau persetujuan." wajib>
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
      <Tombol type="submit" form="form-instrumen-triase" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : instrumen ? 'Simpan' : 'Tambah instrumen' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.kode {
  font-family: var(--font-mono);
}
.skala {
  margin: 0 0 var(--space-3);
  padding: 0;
  border: 0;
}
.skala legend {
  padding: 0;
  margin-bottom: var(--space-1);
  font-weight: 600;
}
.petunjuk {
  margin: 0 0 var(--space-2);
  font-size: 13px;
  color: var(--muted);
}
.galat {
  margin: 0 0 var(--space-2);
  font-size: 13px;
  color: var(--danger);
}
.daftar-skala {
  display: grid;
  gap: var(--space-2);
  margin: 0 0 var(--space-2);
  padding: 0;
  list-style: none;
}
.baris-skala {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 2fr) auto;
  align-items: end;
  gap: 0 var(--space-2);
}
.hapus-skala {
  margin-bottom: var(--space-3);
}
@media (max-width: 480px) {
  .baris-skala {
    grid-template-columns: minmax(0, 1fr);
  }
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
