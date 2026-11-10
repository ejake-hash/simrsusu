<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { useKueriBerPolling } from '@/modules/notifikasi/polling'
import { boleh } from '@/shared/composables/usePermission'
import { bacaPetaTempatTidur, tandaiKosongSiap } from '../api'
import {
  bolehTandaiKosongSiap,
  gabungPilihanLokasi,
  INTERVAL_PETA,
  kelompokkanPeta,
  KUNCI_PETA,
  kunciStatus,
  labelStatus,
  pesanGalatStatus,
  pilihanBangsal,
  pilihanLantai,
  ringkasStatus,
  saringPeta,
  tandaKunci,
  tandaStatus,
  tandaTambahan,
  teksHariRawat,
  URUTAN_STATUS,
  type KunciStatus,
  type PilihanLokasi,
} from '../peta'
import type { FilterPetaServer, PetaTempatTidur, TempatTidurPeta } from '../tipe'

/**
 * Rawat Inap → Peta tempat tidur (tiket 2.27, kontrak v0.35.0; FR-08-01, FR-08-20, FR-08-04 bagian status). Semua TT yang
 * berlaku di cakupan petugas, dikelompokkan gedung → lantai → bangsal → kamar sesuai urutan server. Gedung dan bangsal
 * disaring server; lantai, status, dan kode TT disaring di layar. Polling K1 sama dengan papan pasien IGD.
 */
const gedungId = ref('')
const bangsalId = ref('')
const lokasi = ref<PilihanLokasi>({ gedung: [], bangsal: [] })
const opsiBangsal = computed(() => pilihanBangsal(lokasi.value, gedungId.value))
// Bangsal yang bukan milik gedung baru tidak ikut dikirim (tanpa permintaan antara), lalu dikosongkan.
const bangsalBerlaku = computed(() => (opsiBangsal.value.some((b) => b.id === bangsalId.value) ? bangsalId.value : ''))
watch(gedungId, () => {
  if (!bangsalBerlaku.value) bangsalId.value = ''
})
const filterServer = computed<FilterPetaServer>(() => ({ gedung_id: gedungId.value || null, bangsal_id: bangsalBerlaku.value || null }))
const { kueri, segarkan } = useKueriBerPolling<PetaTempatTidur>(
  () => KUNCI_PETA.peta(filterServer.value),
  (p) => bacaPetaTempatTidur(filterServer.value, p),
  { interval: INTERVAL_PETA },
)

const data = computed<TempatTidurPeta[]>(() => kueri.data.value?.data ?? [])
const terpotong = computed(() => kueri.data.value?.meta.terpotong ?? false)

watch(data, (d) => (lokasi.value = gabungPilihanLokasi(lokasi.value, d)), { immediate: true })

const lantai = ref('')
const status = ref<KunciStatus | ''>('')
const cari = ref('')
// Lantai berasal dari peta yang dimuat; ganti gedung/bangsal mengosongkannya agar tidak menyaring ke lantai yang tidak ada.
watch(filterServer, () => (lantai.value = ''))
const opsiLantai = computed(() => pilihanLantai(data.value))

/** Ringkasan mengikuti lokasi yang dipilih (gedung, bangsal, lantai), bukan filter status dan kode. */
const ringkasan = computed(() => ringkasStatus(saringPeta(data.value, { lantai: lantai.value })))
const tampil = computed(() => saringPeta(data.value, { lantai: lantai.value, status: status.value, cari: cari.value }))
const kelompok = computed(() => kelompokkanPeta(tampil.value))
const adaFilterKlien = computed(() => !!(lantai.value || status.value || cari.value.trim()))
function hapusFilterKlien() {
  lantai.value = ''
  status.value = ''
  cari.value = ''
}

const izinPerbaiki = computed(() => boleh('rawat_inap.peta_tempat_tidur.perbaiki'))
const keteranganTt = (tt: TempatTidurPeta) => [tt.kelas_rawat.nama, ...tandaTambahan(tt)].join(' · ')

// ── Tandai kosong siap: konfirmasi singkat, lalu peta dimuat ulang sebelum hasil diumumkan ──
const sasaran = ref<TempatTidurPeta | null>(null)
const bukaDialog = ref(false)
const menyimpan = ref(false)
let fokusSesudah: string | null = null
function minta(tt: TempatTidurPeta) {
  sasaran.value = tt
  fokusSesudah = null
  bukaDialog.value = true
}
async function simpan() {
  const tt = sasaran.value
  if (!tt) return
  menyimpan.value = true
  let pesan: [string, 'ok' | 'error']
  try {
    await tandaiKosongSiap(tt.id)
    pesan = [`TT ${tt.kode} kosong siap.`, 'ok']
  } catch (e) {
    pesan = [pesanGalatStatus(e, tt.kode), 'error']
  }
  try {
    await segarkan()
  } finally {
    menyimpan.value = false
    // Tombol aksi hilang sesudah status berubah: fokus ke kode TT itu, bukan ke body.
    fokusSesudah = `tt-${tt.id}`
    bukaDialog.value = false
  }
  tampilkanToast(...pesan)
}
// TT bisa hilang dari peta (404, keluar cakupan): fokus ke judul kartu filter, bukan kembali ke tombol yang sudah tiada.
const fokusSesudahTutup = () => (fokusSesudah ? (document.getElementById(fokusSesudah) ?? document.getElementById('judul-filter-peta')) : null)
</script>

<template>
  <HalamanModul
    izin="rawat_inap.peta_tempat_tidur.lihat"
    judul="Peta tempat tidur"
    :uraian="`Status setiap tempat tidur (TT) per gedung, lantai, bangsal, dan kamar. Diperbarui otomatis tiap ${INTERVAL_PETA / 1000} detik.`"
  >
    <section class="kartu" aria-labelledby="judul-filter-peta">
      <div class="kartu-kepala">
        <h2 id="judul-filter-peta" tabindex="-1">Filter dan ringkasan</h2>
      </div>
      <div class="kartu-isi">
        <div class="filter">
          <div class="isian-filter">
            <label for="filter-gedung-peta">Gedung</label>
            <select id="filter-gedung-peta" v-model="gedungId" class="isian">
              <option value="">Semua gedung</option>
              <option v-for="g in lokasi.gedung" :key="g.id" :value="g.id">{{ g.nama }}</option>
            </select>
          </div>
          <div class="isian-filter">
            <label for="filter-bangsal-peta">Bangsal</label>
            <select id="filter-bangsal-peta" v-model="bangsalId" class="isian">
              <option value="">Semua bangsal</option>
              <option v-for="b in opsiBangsal" :key="b.id" :value="b.id">{{ b.nama }}</option>
            </select>
          </div>
          <div class="isian-filter">
            <label for="filter-lantai-peta">Lantai</label>
            <select id="filter-lantai-peta" v-model="lantai" class="isian">
              <option value="">Semua lantai</option>
              <option v-for="l in opsiLantai" :key="l.nilai" :value="l.nilai">{{ l.label }}</option>
            </select>
          </div>
          <div class="isian-filter">
            <label for="filter-status-peta">Status</label>
            <select id="filter-status-peta" v-model="status" class="isian">
              <option value="">Semua status</option>
              <option v-for="s in URUTAN_STATUS" :key="s" :value="s">{{ labelStatus(s) }}</option>
            </select>
          </div>
          <div class="isian-filter isian-cari">
            <label for="cari-kode-tt">Cari kode TT</label>
            <input id="cari-kode-tt" v-model="cari" type="search" class="isian" autocomplete="off" spellcheck="false" />
          </div>
        </div>

        <ul v-if="kueri.data.value" class="ringkasan" aria-label="Jumlah TT per status">
          <li v-for="r in ringkasan" :key="r.kunci">
            <Lencana :jenis="tandaKunci(r.kunci).jenis" tanpa-ikon>
              <Ikon :nama="tandaKunci(r.kunci).ikon" :ukuran="12" />
              {{ r.label }} <b class="angka">{{ r.jumlah }}</b>
            </Lencana>
          </li>
        </ul>
        <div v-if="terpotong" class="blok">
          <Pesan jenis="peringatan">Peta hanya memuat 1.000 TT pertama. Pilih gedung atau bangsal untuk mempersempit peta.</Pesan>
        </div>
      </div>
    </section>

    <KeadaanKosong v-if="kueri.isPending.value" jenis="memuat" judul="Memuat peta tempat tidur" class="kartu keadaan" />
    <KeadaanKosong v-else-if="kueri.isError.value" jenis="error" judul="Peta tempat tidur gagal dimuat" :uraian="pesanError(kueri.error.value)" class="kartu keadaan">
      <template #aksi><Tombol @click="segarkan()">Coba lagi</Tombol></template>
    </KeadaanKosong>
    <KeadaanKosong
      v-else-if="data.length === 0"
      judul="Belum ada tempat tidur"
      :uraian="gedungId || bangsalId ? 'Tidak ada TT yang berlaku di gedung atau bangsal ini.' : 'Belum ada TT yang berlaku di cakupan Anda. TT diatur di Master Data → Organisasi, Unit, Ruang & Tempat Tidur.'"
      class="kartu keadaan"
    />
    <KeadaanKosong v-else-if="tampil.length === 0" judul="Tidak ada TT yang cocok" uraian="Ubah lantai, status, atau kode TT yang dicari." class="kartu keadaan">
      <template v-if="adaFilterKlien" #aksi><Tombol @click="hapusFilterKlien">Hapus filter</Tombol></template>
    </KeadaanKosong>

    <section v-for="g in kelompok" :key="g.kunci" class="kartu gedung" :aria-labelledby="`gedung-${g.kunci}`">
      <div class="kartu-kepala">
        <h2 :id="`gedung-${g.kunci}`">{{ g.nama }}</h2>
      </div>
      <div v-for="l in g.lantai" :key="l.kunci" class="lantai">
        <h3>{{ l.nama }}</h3>
        <div v-for="b in l.bangsal" :key="b.id" class="bangsal">
          <h4>{{ b.nama }}</h4>
          <div v-for="k in b.kamar" :key="k.id" class="kamar">
            <p :id="`kamar-${l.kunci}-${k.id}`" class="nama-kamar">{{ k.nama }}</p>
            <ul class="daftar-tt" :aria-labelledby="`kamar-${l.kunci}-${k.id}`">
              <li v-for="tt in k.tt" :key="tt.id">
                <div role="group" :aria-label="`TT ${tt.kode}`" class="tt" :class="`tt-${kunciStatus(tt)}`">
                  <span :id="`tt-${tt.id}`" class="kode" tabindex="-1">{{ tt.kode }}</span>
                  <span class="status">
                    <Lencana :jenis="tandaStatus(tt).jenis" tanpa-ikon><Ikon :nama="tandaStatus(tt).ikon" :ukuran="12" />{{ tandaStatus(tt).label }}</Lencana>
                    <span v-if="teksHariRawat(tt)" class="hari">{{ teksHariRawat(tt) }}</span>
                  </span>
                  <span class="ket">{{ keteranganTt(tt) }}</span>
                  <Tombol v-if="bolehTandaiKosongSiap(tt, izinPerbaiki)" class="aksi" :aria-label="`Tandai kosong siap ${tt.kode}`" @click="minta(tt)">Tandai kosong siap</Tombol>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <Dialog
      v-model:open="bukaDialog"
      :judul="sasaran ? `Tandai ${sasaran.kode} kosong siap?` : 'Tandai kosong siap?'"
      uraian="TT selesai dibersihkan dan bisa dipilih untuk pasien berikutnya. Perubahan tercatat di riwayat status TT."
      :sibuk="menyimpan"
      :fokus-sesudah-tutup="fokusSesudahTutup"
    >
      <template #aksi>
        <Tombol :disabled="menyimpan || undefined" @click="bukaDialog = false">Batal</Tombol>
        <Tombol varian="utama" :sibuk="menyimpan" @click="simpan">Tandai kosong siap</Tombol>
      </template>
    </Dialog>
  </HalamanModul>
</template>

<style scoped>
.kartu + .kartu {
  margin-top: var(--space-4);
}
.filter {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3);
}
.isian-filter {
  display: grid;
  gap: var(--space-1);
  flex: 1 1 160px;
  max-width: 240px;
}
.isian-cari {
  flex-basis: 200px;
}
.isian-filter label {
  font-size: 13px;
  color: var(--muted);
}
.ringkasan {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-3) 0 0;
  padding: 0;
  list-style: none;
}
.angka {
  font-variant-numeric: tabular-nums;
}
.blok {
  margin-top: var(--space-3);
}
.keadaan {
  display: grid;
}
.lantai {
  padding: var(--space-3) var(--space-4) var(--space-4);
}
.lantai + .lantai {
  border-top: 1px solid var(--border);
}
h3 {
  margin: 0 0 var(--space-2);
  font-size: 14px;
}
.bangsal + .bangsal {
  margin-top: var(--space-4);
}
h4 {
  margin: 0 0 var(--space-2);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
  color: var(--muted);
}
.kamar + .kamar {
  margin-top: var(--space-3);
}
.nama-kamar {
  margin: 0 0 var(--space-1);
  font-size: 12px;
  color: var(--muted);
}
.daftar-tt {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(176px, 1fr));
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.tt {
  display: grid;
  align-content: start;
  gap: 6px;
  height: 100%;
  box-sizing: border-box;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-input);
  background: var(--surface);
}
/* TT yang tidak bisa dipakai: tepi putus-putus dan latar redup sebagai penanda kedua selain teks status. */
.tt-tidak_dipakai,
.tt-ditutup {
  border-style: dashed;
  background: var(--bg);
}
.kode {
  font-family: var(--font-mono);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
  overflow-wrap: anywhere;
}
.kode:focus-visible,
#judul-filter-peta:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
  border-radius: 4px;
}
.status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.hari {
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  font-variant-numeric: tabular-nums;
}
.ket {
  font-size: 12px;
  color: var(--muted);
}
.aksi {
  justify-self: start;
}
@media (max-width: 767.98px) {
  .isian-filter {
    max-width: none;
  }
}
</style>
