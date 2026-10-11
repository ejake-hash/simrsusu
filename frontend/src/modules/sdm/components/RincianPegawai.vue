<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilPegawai, batalkanIzinPraktik, batalkanKewenangan, semuaIzinPraktik, semuaKewenangan, unduhDokumenIzinPraktik } from '../api'
import {
  KUNCI_SDM,
  LABEL_JENIS_IZIN,
  LABEL_PROFESI,
  LABEL_STATUS_IZIN,
  LABEL_STATUS_PEGAWAI,
  LABEL_STATUS_RKK,
  PIL_STATUS_IZIN,
  PIL_STATUS_PEGAWAI,
  PIL_STATUS_RKK,
  ukuranBerkas,
} from '../sdm'
import type { IzinPraktik, KewenanganKlinis, PegawaiRinci } from '../tipe'
import DialogBatal from './DialogBatal.vue'
import DialogBuatAkun from './DialogBuatAkun.vue'
import DialogIzinPraktik from './DialogIzinPraktik.vue'
import DialogKeluar from './DialogKeluar.vue'
import DialogKewenangan from './DialogKewenangan.vue'
import DialogPegawai from './DialogPegawai.vue'

/**
 * Rincian pegawai: data kepegawaian, NIK tersamar (tombol "Tampilkan NIK" hanya bila `nik_dapat_dilihat` dan NIK
 * ada), izin praktik dan RKK (bagian disembunyikan tanpa sdm.kredensial.lihat), dan akun (Buat akun bila
 * pengaturan.akun.input). NIK utuh diambil lewat permintaan tersendiri (diaudit server), tidak masuk cache kueri,
 * dan disembunyikan lagi sesudah 30 detik atau saat pegawai berganti.
 */
const props = defineProps<{ pegawaiId: string }>()
const emit = defineEmits<{ tutup: []; berubah: [] }>()
const qc = useQueryClient()

const kueri = useQuery({ queryKey: computed(() => KUNCI_SDM.rinciPegawai(props.pegawaiId)), queryFn: () => ambilPegawai(props.pegawaiId) })
const p = computed(() => kueri.data.value?.data ?? null)
const tidakDitemukan = computed(() => [403, 404].includes((kueri.error.value as { status?: number } | null)?.status ?? 0))

const bolehKredensial = computed(() => boleh('sdm.kredensial.lihat'))
const izin = useQuery({ queryKey: computed(() => KUNCI_SDM.izinPraktik(props.pegawaiId)), queryFn: () => semuaIzinPraktik(props.pegawaiId), enabled: bolehKredensial })
const rkk = useQuery({ queryKey: computed(() => KUNCI_SDM.kewenangan(props.pegawaiId)), queryFn: () => semuaKewenangan(props.pegawaiId), enabled: bolehKredensial })

// ── NIK utuh: hanya di state komponen ini ──
const nikUtuh = ref<string | null>(null)
const memuatNik = ref(false)
let pewaktuNik: ReturnType<typeof setTimeout> | undefined
function sembunyikanNik() {
  nikUtuh.value = null
  clearTimeout(pewaktuNik)
}
async function tampilkanNik() {
  memuatNik.value = true
  try {
    const r = await ambilPegawai(props.pegawaiId, true)
    nikUtuh.value = r.data.nik ?? null
    pewaktuNik = setTimeout(sembunyikanNik, 30_000)
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuatNik.value = false
  }
}
watch(() => props.pegawaiId, sembunyikanNik)
onBeforeUnmount(sembunyikanNik)

// ── Dialog ──
const dialogUbah = ref(false)
const dialogKeluar = ref(false)
const dialogIzin = ref(false)
const dialogRkk = ref(false)
const dialogAkun = ref(false)
const dialogBatal = ref(false)
const batal = ref<{ judul: string; uraian: string; jalankan: (alasan: string) => Promise<unknown> } | null>(null)
/** Versi segar sebelum mengubah (ETag); kueri rinci bisa basi bila panel lama terbuka. */
const target = ref<Berversi<PegawaiRinci> | null>(null)
async function bukaUbah(jenis: 'ubah' | 'keluar') {
  try {
    target.value = await ambilPegawai(props.pegawaiId)
    if (jenis === 'ubah') dialogUbah.value = true
    else dialogKeluar.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  }
}
function segarkan() {
  void qc.invalidateQueries({ queryKey: KUNCI_SDM.pegawai })
  emit('berubah')
}
function pegawaiTersimpan(hasil: Berversi<PegawaiRinci>, pesan: string) {
  qc.setQueryData(KUNCI_SDM.rinciPegawai(hasil.data.id), { ...hasil, data: { ...hasil.data, nik: undefined } })
  tampilkanToast(pesan)
  segarkan()
}
function batalkanIzin(x: IzinPraktik) {
  batal.value = {
    judul: `Batalkan ${LABEL_JENIS_IZIN[x.jenis]} ${x.nomor}?`,
    uraian: 'Catatan tetap tersimpan sebagai riwayat dengan status dibatalkan. Untuk memperbaiki data, catat ulang izin yang benar.',
    jalankan: async (alasan) => {
      await batalkanIzinPraktik(props.pegawaiId, x.id, alasan)
      tampilkanToast(`${LABEL_JENIS_IZIN[x.jenis]} ${x.nomor} dibatalkan.`)
      segarkan()
    },
  }
  dialogBatal.value = true
}
function batalkanRkk(x: KewenanganKlinis) {
  batal.value = {
    judul: `Batalkan RKK ${x.kategori}?`,
    uraian: 'Catatan tetap tersimpan sebagai riwayat dengan status dibatalkan. Untuk memperbaiki data, catat ulang RKK yang benar.',
    jalankan: async (alasan) => {
      await batalkanKewenangan(props.pegawaiId, x.id, alasan)
      tampilkanToast(`RKK ${x.kategori} dibatalkan.`)
      segarkan()
    },
  }
  dialogBatal.value = true
}
const mengunduh = ref<string | null>(null)
async function unduh(x: IzinPraktik) {
  mengunduh.value = x.id
  try {
    await unduhDokumenIzinPraktik(props.pegawaiId, x.id)
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    mengunduh.value = null
  }
}
const tgl = (t: string | null) => (t ? formatTanggal(`${t}T00:00:00`) : null)
</script>

<template>
  <section class="kartu rincian" aria-labelledby="judul-rincian-pegawai" :aria-busy="kueri.isFetching.value || undefined">
    <div v-if="kueri.isPending.value" class="kartu-isi keadaan" role="status">Memuat pegawai…</div>
    <div v-else-if="kueri.isError.value" class="kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ tidakDitemukan ? 'Pegawai tidak ditemukan atau tidak boleh Anda lihat.' : pesanError(kueri.error.value) }}</p>
      <button v-if="tidakDitemukan" type="button" class="tombol" @click="emit('tutup')">Tutup</button>
      <button v-else type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else-if="p">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-rincian-pegawai" tabindex="-1">{{ p.nama }}</h2>
          <p>{{ [p.jabatan, p.unit?.nama].filter(Boolean).join(' · ') || 'Jabatan dan unit belum diisi' }}</p>
        </div>
        <span class="pil" :class="PIL_STATUS_PEGAWAI[p.status]">{{ LABEL_STATUS_PEGAWAI[p.status] }}</span>
      </div>
      <div class="kartu-isi">
        <div v-if="boleh('sdm.pegawai.perbaiki')" class="aksi-atas">
          <button type="button" class="tombol" @click="bukaUbah('ubah')">Ubah data</button>
          <button v-if="p.status !== 'keluar'" type="button" class="tombol" :class="{ 'tombol-bahaya': p.status === 'aktif' }" @click="bukaUbah('keluar')">
            {{ p.status === 'akan_keluar' ? 'Batalkan rencana keluar' : 'Catat keluar' }}
          </button>
        </div>
        <p v-if="p.status !== 'aktif'" class="catatan" :class="p.status === 'keluar' ? 'catatan-netral' : 'catatan-peringatan'">
          <Ikon nama="info" :ukuran="16" />
          <span>
            {{ p.status === 'keluar' ? 'Keluar sejak' : 'Keluar mulai' }} {{ tgl(p.tanggal_keluar) }}: {{ p.alasan_keluar }}.
            {{ p.status === 'keluar' ? 'Akunnya nonaktif.' : 'Akun dinonaktifkan pada tanggal itu.' }}
          </span>
        </p>

        <dl class="info">
          <div>
            <dt>NIP</dt>
            <dd>{{ p.nip ?? '—' }}</dd>
          </div>
          <div>
            <dt>NIK</dt>
            <dd class="nik">
              <code v-if="nikUtuh">{{ nikUtuh }}</code>
              <span v-else>{{ p.nik_samaran ?? 'Belum diisi' }}</span>
              <template v-if="p.nik_dapat_dilihat && p.nik_samaran">
                <button v-if="nikUtuh" type="button" class="tombol tombol-tautan" @click="sembunyikanNik">Sembunyikan</button>
                <button v-else type="button" class="tombol tombol-tautan" :disabled="memuatNik" @click="tampilkanNik">{{ memuatNik ? 'Memuat…' : 'Tampilkan NIK' }}</button>
              </template>
            </dd>
          </div>
          <div>
            <dt>Profesi</dt>
            <dd>{{ p.profesi ? LABEL_PROFESI[p.profesi] : '—' }}</dd>
          </div>
          <div>
            <dt>Jenis kepegawaian</dt>
            <dd>{{ p.jenis_kepegawaian ?? '—' }}</dd>
          </div>
          <div>
            <dt>Unit kerja</dt>
            <dd>{{ p.unit ? `${p.unit.kode} · ${p.unit.nama}` : '—' }}</dd>
          </div>
          <div>
            <dt>TMT</dt>
            <dd>{{ tgl(p.tmt) ?? '—' }}</dd>
          </div>
          <div>
            <dt>ID SATUSEHAT</dt>
            <dd>{{ p.ihs_practitioner_id ?? 'Belum terdaftar' }}</dd>
          </div>
        </dl>
        <p v-if="nikUtuh" class="ket" role="status">NIK utuh ditampilkan dan pembacaannya tercatat di jejak audit. Disembunyikan lagi otomatis.</p>

        <!-- ── Akun ── -->
        <section class="bagian" aria-labelledby="judul-akun-pegawai">
          <div class="bagian-kepala">
            <h3 id="judul-akun-pegawai">Akun aplikasi</h3>
            <button
              v-if="!p.punya_akun && p.status !== 'keluar' && boleh('pengaturan.akun.input')"
              type="button"
              class="tombol"
              @click="dialogAkun = true"
            >
              <Ikon nama="tambah" :ukuran="16" />Buat akun
            </button>
          </div>
          <p v-if="p.akun" class="baris-akun">
            <code>{{ p.akun.nama_pengguna }}</code>
            <span class="pil" :class="p.akun.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ p.akun.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
            <RouterLink class="tombol" :to="{ path: '/pengaturan/akun', query: { akun: p.akun.id } }">Buka di Akun & akses</RouterLink>
          </p>
          <p v-else-if="p.punya_akun" class="ket">Pegawai ini sudah punya akun.</p>
          <p v-else class="ket">{{ p.status === 'keluar' ? 'Pegawai sudah keluar; akun tidak dapat dibuat.' : 'Belum punya akun.' }}</p>
        </section>

        <!-- ── Izin praktik ── -->
        <section v-if="bolehKredensial" class="bagian" aria-labelledby="judul-izin-praktik">
          <div class="bagian-kepala">
            <h3 id="judul-izin-praktik">Izin praktik</h3>
            <button v-can="'sdm.kredensial.input'" type="button" class="tombol" @click="dialogIzin = true"><Ikon nama="tambah" :ukuran="16" />Catat izin</button>
          </div>
          <p v-if="izin.isPending.value" class="ket" role="status">Memuat izin praktik…</p>
          <p v-else-if="izin.isError.value" class="ket" role="alert">{{ pesanError(izin.error.value) }}</p>
          <ul v-else-if="izin.data.value?.length" class="daftar-catatan" aria-labelledby="judul-izin-praktik">
            <li v-for="x in izin.data.value" :key="x.id" :class="{ batal: x.status === 'batal' }">
              <div class="catatan-kepala">
                <span><b>{{ LABEL_JENIS_IZIN[x.jenis] }}</b> <code>{{ x.nomor }}</code></span>
                <span class="pil" :class="PIL_STATUS_IZIN[x.status]">{{ LABEL_STATUS_IZIN[x.status] }}</span>
              </div>
              <p class="ket">
                Terbit {{ tgl(x.terbit) }} · {{ x.berlaku_sampai ? `berlaku sampai ${tgl(x.berlaku_sampai)}` : 'seumur hidup' }}
                <template v-if="x.dokumen"> · dokumen {{ x.dokumen.jenis_mime === 'application/pdf' ? 'PDF' : 'gambar' }} {{ ukuranBerkas(x.dokumen.ukuran) }}</template>
              </p>
              <p v-if="x.status === 'batal'" class="ket">Dibatalkan {{ x.dibatalkan_oleh?.nama }}, {{ formatTanggalWaktu(x.dibatalkan_pada) }}: {{ x.alasan_batal }}</p>
              <span v-if="x.dokumen || (x.status !== 'batal' && boleh('sdm.kredensial.perbaiki'))" class="aksi-catatan">
                <button v-if="x.dokumen" type="button" class="tombol" :aria-label="`Unduh dokumen ${LABEL_JENIS_IZIN[x.jenis]} ${x.nomor}`" :disabled="mengunduh === x.id" @click="unduh(x)">
                  {{ mengunduh === x.id ? 'Mengunduh…' : 'Unduh dokumen' }}
                </button>
                <button
                  v-if="x.status !== 'batal' && boleh('sdm.kredensial.perbaiki')"
                  type="button"
                  class="tombol tombol-bahaya"
                  :aria-label="`Batalkan ${LABEL_JENIS_IZIN[x.jenis]} ${x.nomor}`"
                  @click="batalkanIzin(x)"
                >
                  Batalkan
                </button>
              </span>
            </li>
          </ul>
          <p v-else class="ket">Belum ada izin praktik tercatat.</p>
        </section>

        <!-- ── RKK ── -->
        <section v-if="bolehKredensial" class="bagian" aria-labelledby="judul-rkk">
          <div class="bagian-kepala">
            <h3 id="judul-rkk">Kewenangan klinis (RKK)</h3>
            <button v-can="'sdm.kredensial.input'" type="button" class="tombol" @click="dialogRkk = true"><Ikon nama="tambah" :ukuran="16" />Catat RKK</button>
          </div>
          <p v-if="rkk.isPending.value" class="ket" role="status">Memuat RKK…</p>
          <p v-else-if="rkk.isError.value" class="ket" role="alert">{{ pesanError(rkk.error.value) }}</p>
          <ul v-else-if="rkk.data.value?.length" class="daftar-catatan" aria-labelledby="judul-rkk">
            <li v-for="x in rkk.data.value" :key="x.id" :class="{ batal: x.status === 'batal' }">
              <div class="catatan-kepala">
                <b>{{ x.kategori }}</b>
                <span class="pil" :class="PIL_STATUS_RKK[x.status]">{{ LABEL_STATUS_RKK[x.status] }}</span>
              </div>
              <p class="ket">{{ tgl(x.berlaku_dari) }} – {{ tgl(x.berlaku_sampai) }} · {{ x.komite }}<template v-if="x.dasar"> · {{ x.dasar }}</template></p>
              <p class="kode-tindakan"><code v-for="k in x.kode_tindakan" :key="k">{{ k }}</code></p>
              <p v-if="x.status === 'batal'" class="ket">Dibatalkan {{ x.dibatalkan_oleh?.nama }}, {{ formatTanggalWaktu(x.dibatalkan_pada) }}: {{ x.alasan_batal }}</p>
              <span v-if="x.status !== 'batal' && boleh('sdm.kredensial.perbaiki')" class="aksi-catatan">
                <button type="button" class="tombol tombol-bahaya" :aria-label="`Batalkan RKK ${x.kategori}`" @click="batalkanRkk(x)">Batalkan</button>
              </span>
            </li>
          </ul>
          <p v-else class="ket">Belum ada RKK tercatat.</p>
        </section>
      </div>

      <DialogPegawai v-model:open="dialogUbah" :pegawai="target" @selesai="(h) => pegawaiTersimpan(h, 'Data pegawai disimpan.')" @muat-ulang="segarkan" />
      <DialogKeluar
        v-model:open="dialogKeluar"
        :pegawai="target"
        @selesai="(h) => pegawaiTersimpan(h, h.data.status === 'aktif' ? 'Rencana keluar dibatalkan.' : `${h.data.nama} dicatat keluar mulai ${tgl(h.data.tanggal_keluar)}.`)"
        @muat-ulang="segarkan"
      />
      <DialogIzinPraktik v-model:open="dialogIzin" :pegawai-id="pegawaiId" @selesai="(x) => (tampilkanToast(`${LABEL_JENIS_IZIN[x.jenis]} ${x.nomor} dicatat.`), segarkan())" />
      <DialogKewenangan v-model:open="dialogRkk" :pegawai-id="pegawaiId" @selesai="(x) => (tampilkanToast(`RKK ${x.kategori} dicatat.`), segarkan())" />
      <DialogBuatAkun v-model:open="dialogAkun" :pegawai="p" @selesai="segarkan" />
      <DialogBatal v-model:open="dialogBatal" :judul="batal?.judul ?? ''" :uraian="batal?.uraian ?? ''" :batalkan="batal?.jalankan ?? null" />
    </template>
  </section>
</template>

<style scoped>
.rincian {
  min-width: 0;
}
.keadaan {
  color: var(--muted);
}
.aksi-atas {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}
.catatan {
  margin: 0 0 var(--space-3);
}
.info {
  display: grid;
  gap: var(--space-2);
  margin: 0;
}
.info div {
  display: grid;
  grid-template-columns: 140px minmax(0, 1fr);
  gap: var(--space-2);
}
.info dt {
  color: var(--muted);
  font-size: 12.5px;
}
.info dd {
  margin: 0;
  overflow-wrap: anywhere;
}
.nik {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
code {
  font-family: var(--font-mono);
}
.ket {
  margin: var(--space-1) 0 0;
  color: var(--muted);
  font-size: 12.5px;
}
.bagian {
  margin-top: var(--space-4);
  padding-top: var(--space-4);
  border-top: 1px solid var(--border);
}
.bagian-kepala {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
}
.bagian-kepala h3 {
  margin: 0;
  font-size: 14px;
}
.baris-akun {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
}
.daftar-catatan {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar-catatan li {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.daftar-catatan li.batal {
  background: var(--bg);
}
.catatan-kepala {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}
.kode-tindakan {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin: var(--space-1) 0 0;
  font-size: 12px;
}
.kode-tindakan code {
  padding: 0 6px;
  border: 1px solid var(--border);
  border-radius: 4px;
}
.aksi-catatan {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-top: var(--space-2);
}
@media (max-width: 599.98px) {
  .info div {
    grid-template-columns: minmax(0, 1fr);
    gap: 0;
  }
}
</style>
