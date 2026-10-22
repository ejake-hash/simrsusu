<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { buatPasien, ubahPasien } from '../api'
import { LABEL_JENIS_KELAMIN } from '../pasien'
import type { IsianPasien, JenisKelaminPasien, KontakDaruratPasien, Pasien, UbahPasien } from '../tipe'

/**
 * Tambah atau perbaiki pasien (tiket 2.5, kontrak v0.19.0). Pasien tanpa identitas (Mr. X, FR-01-05): aktifkan
 * "pasien tanpa identitas" → nama, NIK, dan tanggal lahir boleh kosong, server tetap memberi No. RM. Melengkapi Mr. X
 * = matikan sakelar lalu isi data (wajib nama + tanggal lahir). Perbaikan wajib alasan (Permenkes 24/2022 Ps. 30)
 * dan memakai If-Match. NIK/paspor utuh hanya bisa diisi/diubah dengan izin bagian identitas.
 */
const props = defineProps<{ pasien: Berversi<Pasien> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Pasien>]; muatUlang: [] }>()

const KOLOM = ['nama', 'nik', 'no_paspor', 'no_kartu_bpjs', 'tanggal_lahir', 'tempat_lahir', 'jenis_kelamin', 'kewarganegaraan', 'telepon', 'ciri_pengenal', 'alasan_perubahan'] as const
const REF = {
  agama: { islam: 'Islam', kristen: 'Kristen', katolik: 'Katolik', hindu: 'Hindu', buddha: 'Buddha', konghucu: 'Konghucu' },
  pendidikan: { sd: 'SD', smp: 'SMP', sma: 'SMA/SMK', d3: 'Diploma III', s1: 'Sarjana (S1)' },
  pekerjaan: { pns: 'PNS', swasta: 'Karyawan swasta', wiraswasta: 'Wiraswasta', pelajar: 'Pelajar/Mahasiswa', irt: 'Ibu rumah tangga' },
  status_kawin: { belum_kawin: 'Belum kawin', kawin: 'Kawin', cerai_hidup: 'Cerai hidup', cerai_mati: 'Cerai mati' },
} as const

const ubahMode = computed(() => props.pasien !== null)
/** Akun boleh mengisi/mengubah NIK/paspor utuh (saat ubah: field dikirim server hanya bila berizin). */
const bolehIdentitas = computed(() => (ubahMode.value ? props.pasien!.data.nik !== undefined : boleh('pendaftaran.pasien.identitas.lihat')))

const tanpaIdentitas = ref(false)
const nama = ref('')
const nik = ref('')
const noPaspor = ref('')
const noKartu = ref('')
const tanggalLahir = ref('')
const tempatLahir = ref('')
const jenisKelamin = ref<JenisKelaminPasien>('tidak_diketahui')
const kewarganegaraan = ref<'wni' | 'wna'>('wni')
const agama = ref('')
const pendidikan = ref('')
const pekerjaan = ref('')
const statusKawin = ref('')
const telepon = ref('')
const ciri = ref('')
const alamatJalan = ref('')
const alamatWilayah = ref('')
const kontak = ref<KontakDaruratPasien[]>([])
const alasan = ref('')

const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const p = props.pasien?.data
  tanpaIdentitas.value = p?.tanpa_identitas ?? false
  nama.value = p && !p.tanpa_identitas ? p.nama : ''
  nik.value = p?.nik ?? ''
  noPaspor.value = p?.no_paspor ?? ''
  noKartu.value = p?.no_kartu_bpjs ?? ''
  tanggalLahir.value = p?.tanggal_lahir ?? ''
  tempatLahir.value = p?.tempat_lahir ?? ''
  jenisKelamin.value = p?.jenis_kelamin ?? 'tidak_diketahui'
  kewarganegaraan.value = p?.kewarganegaraan ?? 'wni'
  agama.value = p?.agama?.kode ?? ''
  pendidikan.value = p?.pendidikan?.kode ?? ''
  pekerjaan.value = p?.pekerjaan?.kode ?? ''
  statusKawin.value = p?.status_kawin?.kode ?? ''
  telepon.value = p?.telepon ?? ''
  ciri.value = p?.ciri_pengenal ?? ''
  alamatJalan.value = p?.alamat?.jalan ?? ''
  alamatWilayah.value = p?.alamat?.wilayah.kode ?? ''
  kontak.value = p ? p.kontak_darurat.map((k) => ({ ...k })) : []
  alasan.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

function tambahKontak() {
  if (kontak.value.length < 3) kontak.value.push({ nama: '', hubungan: '', telepon: '' })
}
const hapusKontak = (i: number) => kontak.value.splice(i, 1)

/** Kumpulkan isian non-identitas yang terisi; nilai kosong → tidak dikirim (POST) atau null (PATCH). */
function kumpulkan(): IsianPasien {
  const alamat = alamatJalan.value.trim() && alamatWilayah.value ? { jalan: alamatJalan.value.trim(), wilayah_kode: alamatWilayah.value } : null
  const isi: IsianPasien = {
    jenis_kelamin: jenisKelamin.value,
    kewarganegaraan: kewarganegaraan.value,
    nama: nama.value.trim() || null,
    tanggal_lahir: tanggalLahir.value || null,
    tempat_lahir: tempatLahir.value.trim() || null,
    no_kartu_bpjs: noKartu.value.trim() || null,
    agama: agama.value || null,
    pendidikan: pendidikan.value || null,
    pekerjaan: pekerjaan.value || null,
    status_kawin: statusKawin.value || null,
    telepon: telepon.value.trim() || null,
    ciri_pengenal: ciri.value.trim() || null,
    alamat,
    kontak_darurat: kontak.value.filter((k) => k.nama.trim() || k.telepon.trim()),
    tanpa_identitas: tanpaIdentitas.value,
  }
  if (bolehIdentitas.value) {
    isi.nik = nik.value.trim() || null
    isi.no_paspor = noPaspor.value.trim() || null
  }
  return isi
}

function validasi(isi: IsianPasien): Record<string, string> {
  const e: Record<string, string> = {}
  if (!isi.tanpa_identitas) {
    if (!isi.nama) e.nama = 'Isi nama pasien.'
    if (!isi.tanggal_lahir) e.tanggal_lahir = 'Isi tanggal lahir.'
    if (isi.kewarganegaraan === 'wna' && !isi.nik && !isi.no_paspor) e.no_paspor = 'WNA tanpa NIK wajib mengisi nomor paspor.'
  }
  if (bolehIdentitas.value && nik.value.trim() && !/^[0-9]{16}$/.test(nik.value.trim())) e.nik = 'NIK harus 16 digit angka.'
  if (noKartu.value.trim() && !/^[0-9]{13}$/.test(noKartu.value.trim())) e.no_kartu_bpjs = 'No. kartu BPJS harus 13 digit angka.'
  for (const [i, k] of kontak.value.entries()) {
    if ((k.nama.trim() || k.telepon.trim()) && !/^\+?[0-9]{8,15}$/.test(k.telepon.trim())) e[`kontak_${i}`] = 'Telepon kontak 8–15 digit.'
  }
  if (ubahMode.value && alasan.value.trim().length < 5) e.alasan_perubahan = 'Isi alasan perubahan, minimal 5 karakter.'
  return e
}

async function kirim() {
  const isi = kumpulkan()
  const e = validasi(isi)
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<Pasien>
    if (props.pasien) {
      const ubah: UbahPasien = { ...isi, alasan_perubahan: alasan.value.trim() }
      hasil = await ubahPasien(props.pasien.data.id, props.pasien.etag, ubah)
    } else {
      hasil = await buatPasien(isi)
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, KOLOM)
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Data pasien ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else if (err instanceof ApiError && (err.kode === 'nik_sudah_terdaftar' || err.kode === 'no_kartu_sudah_terdaftar')) {
      const r = err.rincian[0] as { no_rm?: string } | undefined
      error.value = `${err.message}${r?.no_rm ? ` Buka No. RM ${r.no_rm} alih-alih membuat pasien baru.` : ''}`
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="pasien ? `Perbaiki data pasien ${pasien.data.no_rm}` : 'Daftarkan pasien baru'" lebar="lebar" :sibuk="sibuk">
    <form id="form-pasien" novalidate @submit.prevent="kirim">
      <label class="sakelar-identitas">
        <input v-model="tanpaIdentitas" type="checkbox" :disabled="ubahMode && !pasien?.data.tanpa_identitas" />
        <span>
          <b>Pasien tanpa identitas (Mr. X)</b>
          <small>Untuk pasien gawat darurat yang belum dikenali. Nama, NIK, dan tanggal lahir boleh kosong; pasien tetap mendapat No. RM dan dapat dilengkapi kemudian.</small>
        </span>
      </label>

      <div v-if="tanpaIdentitas">
        <KolomIsian v-slot="a" label="Ciri pengenal" :error="kesalahan.ciri_pengenal" petunjuk="Ciri fisik, tempat dan waktu ditemukan, atau pengantar.">
          <textarea v-bind="a" v-model="ciri" class="isian" rows="2" maxlength="500"></textarea>
        </KolomIsian>
      </div>
      <template v-else>
        <KolomIsian v-slot="a" label="Nama lengkap" :error="kesalahan.nama" wajib>
          <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="150" />
        </KolomIsian>
        <div class="dua-kolom">
          <KolomIsian v-slot="a" label="Tanggal lahir" :error="kesalahan.tanggal_lahir" wajib>
            <input v-bind="a" v-model="tanggalLahir" type="date" class="isian" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Tempat lahir" :error="kesalahan.tempat_lahir">
            <input v-bind="a" v-model="tempatLahir" class="isian" autocomplete="off" maxlength="100" />
          </KolomIsian>
        </div>
      </template>

      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Jenis kelamin" :error="kesalahan.jenis_kelamin" wajib>
          <select v-bind="a" v-model="jenisKelamin" class="isian">
            <option v-for="(label, kode) in LABEL_JENIS_KELAMIN" :key="kode" :value="kode">{{ label }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Kewarganegaraan" :error="kesalahan.kewarganegaraan">
          <select v-bind="a" v-model="kewarganegaraan" class="isian">
            <option value="wni">WNI</option>
            <option value="wna">WNA</option>
          </select>
        </KolomIsian>
      </div>

      <fieldset v-if="bolehIdentitas" class="grup">
        <legend>Identitas <Ikon nama="gembok" :ukuran="14" /></legend>
        <div class="dua-kolom">
          <KolomIsian v-slot="a" label="NIK" :error="kesalahan.nik" petunjuk="16 digit sesuai KTP.">
            <input v-bind="a" v-model="nik" class="isian kode" inputmode="numeric" autocomplete="off" maxlength="16" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="No. paspor" :error="kesalahan.no_paspor" petunjuk="Untuk WNA tanpa NIK.">
            <input v-bind="a" v-model="noPaspor" class="isian kode" autocomplete="off" maxlength="20" @input="noPaspor = noPaspor.toUpperCase()" />
          </KolomIsian>
        </div>
      </fieldset>
      <p v-else class="catatan" role="note">
        <Ikon nama="gembok" :ukuran="14" />
        <span>NIK dan paspor hanya bisa diisi oleh akun berizin identitas pasien. NIK yang tersimpan ditampilkan termasking.</span>
      </p>

      <KolomIsian v-slot="a" label="No. kartu BPJS" :error="kesalahan.no_kartu_bpjs">
        <input v-bind="a" v-model="noKartu" class="isian kode" inputmode="numeric" autocomplete="off" maxlength="13" />
      </KolomIsian>

      <fieldset class="grup">
        <legend>Data sosial (opsional)</legend>
        <div class="empat-kolom">
          <KolomIsian v-slot="a" label="Agama">
            <select v-bind="a" v-model="agama" class="isian"><option value="">—</option><option v-for="(l, k) in REF.agama" :key="k" :value="k">{{ l }}</option></select>
          </KolomIsian>
          <KolomIsian v-slot="a" label="Pendidikan">
            <select v-bind="a" v-model="pendidikan" class="isian"><option value="">—</option><option v-for="(l, k) in REF.pendidikan" :key="k" :value="k">{{ l }}</option></select>
          </KolomIsian>
          <KolomIsian v-slot="a" label="Pekerjaan">
            <select v-bind="a" v-model="pekerjaan" class="isian"><option value="">—</option><option v-for="(l, k) in REF.pekerjaan" :key="k" :value="k">{{ l }}</option></select>
          </KolomIsian>
          <KolomIsian v-slot="a" label="Status kawin">
            <select v-bind="a" v-model="statusKawin" class="isian"><option value="">—</option><option v-for="(l, k) in REF.status_kawin" :key="k" :value="k">{{ l }}</option></select>
          </KolomIsian>
        </div>
        <div class="dua-kolom">
          <KolomIsian v-slot="a" label="Telepon" :error="kesalahan.telepon">
            <input v-bind="a" v-model="telepon" class="isian kode" inputmode="tel" autocomplete="off" maxlength="15" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Alamat (jalan)">
            <input v-bind="a" v-model="alamatJalan" class="isian" autocomplete="off" maxlength="200" />
          </KolomIsian>
        </div>
        <KolomIsian v-slot="a" label="Kelurahan/desa" petunjuk="Kode wilayah Kemendagri.">
          <select v-bind="a" v-model="alamatWilayah" class="isian">
            <option value="">—</option>
            <option value="12.71.01.1001">Contoh Kelurahan A · Kota Contoh</option>
            <option value="12.71.01.1002">Contoh Kelurahan B · Kota Contoh</option>
          </select>
        </KolomIsian>
      </fieldset>

      <fieldset class="grup">
        <legend>Kontak darurat (opsional, maks. 3)</legend>
        <div v-for="(k, i) in kontak" :key="i" class="kontak">
          <input v-model="k.nama" class="isian" placeholder="Nama" aria-label="Nama kontak darurat" maxlength="150" />
          <input v-model="k.hubungan" class="isian" placeholder="Hubungan" aria-label="Hubungan" maxlength="50" />
          <input v-model="k.telepon" class="isian kode" :class="{ 'isian-salah': kesalahan[`kontak_${i}`] }" placeholder="Telepon" aria-label="Telepon kontak darurat" inputmode="tel" maxlength="15" />
          <button type="button" class="tombol tombol-tautan" :aria-label="`Hapus kontak ${i + 1}`" @click="hapusKontak(i)"><Ikon nama="tutup" :ukuran="16" /></button>
        </div>
        <button v-if="kontak.length < 3" type="button" class="tombol" @click="tambahKontak"><Ikon nama="tambah" :ukuran="16" />Tambah kontak</button>
      </fieldset>

      <KolomIsian v-if="ubahMode" v-slot="a" label="Alasan perubahan" :error="kesalahan.alasan_perubahan" petunjuk="Permenkes 24/2022 Ps. 30: setiap perbaikan direkam. Minimal 5 karakter." wajib>
        <input v-bind="a" v-model="alasan" class="isian" autocomplete="off" maxlength="200" />
      </KolomIsian>

      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-pasien" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : pasien ? 'Simpan perubahan' : 'Daftarkan pasien' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.sakelar-identitas {
  display: flex;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--bg);
  cursor: pointer;
}
.sakelar-identitas input {
  margin-top: 2px;
}
.sakelar-identitas span {
  display: grid;
  gap: 2px;
}
.sakelar-identitas small {
  color: var(--muted);
}
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.empat-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.kode {
  font-family: var(--font-mono);
}
.grup {
  margin: 0 0 var(--space-4);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.grup legend {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: 0 var(--space-1);
  font-weight: var(--font-weight-semibold);
}
.kontak {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr) auto;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
}
.isian-salah {
  border-color: var(--danger);
}
.muat-ulang {
  margin-top: var(--space-2);
}
@media (max-width: 768px) {
  .kontak {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
