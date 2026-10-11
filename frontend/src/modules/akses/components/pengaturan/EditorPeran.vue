<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import Tombol from '@/design-system/components/Tombol.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilPeran, gantiIzinPeran, type PeranBerversi } from '../../apiPengaturan'
import { bandingkan, kodeAksi, kodeDariRincian, labelIzin, periksa, type IndeksMatriks } from '../../matriks'
import { errorKolom, KUNCI, pesanError } from '../../pengaturan'
import DialogInfoPeran from './DialogInfoPeran.vue'
import DialogSimpanIzin from './DialogSimpanIzin.vue'
import MatriksIzin from './MatriksIzin.vue'

/**
 * Editor satu peran: matriks + simpan dengan ringkasan dan dasar (MD-2), If-Match dan penanganan 412 tanpa
 * membuang suntingan diam-diam, 422 (termasuk penyetuju_belum_ditetapkan) dan 409 butuh_persetujuan (backend lama)
 * menyorot node yang ditolak. Sejak 1.2d izin sensitif baru menjadi persetujuan menunggu: izin itu tampil tercentang
 * dan terkunci (dasar selisih = izin + izin_menunggu) dan selalu ikut dikirim (server idempoten).
 */
const props = defineProps<{ peranId: string; indeks: IndeksMatriks }>()
const emit = defineEmits<{ dibuat: [id: string] }>()

const qc = useQueryClient()
const kueri = useQuery({ queryKey: computed(() => KUNCI.peran(props.peranId)), queryFn: () => ambilPeran(props.peranId) })
/** Versi yang sedang disunting (dasar selisih dan If-Match). Bisa tertinggal dari cache bila ada suntingan. */
const dasar = ref<PeranBerversi | null>(null)
const peran = computed(() => dasar.value?.peran)

const izin = ref<Set<string>>(new Set())
const sorot = ref<Set<string>>(new Set())
const pesan = ref<string | null>(null)
const errorEditor = ref<string | null>(null)
/** Akibat penggabungan dengan versi terbaru yang perlu diperiksa sebelum simpan. */
const peringatan = ref<string | null>(null)
/** Versi server yang lebih baru dari dasar sementara ada suntingan (412, atau muat ulang latar). */
const bentrok = ref<PeranBerversi | null>(null)

/** Izin yang menunggu persetujuan, per kode. Tidak bisa dilepas dari matriks; ditarik lewat Persetujuan akses. */
const menunggu = computed(() => new Map((peran.value?.izin_menunggu ?? []).map((m) => [m.kode, m])))
/** Dasar selisih: izin berlaku + izin menunggu (keduanya tampil tercentang). */
const basis = computed(() => (peran.value ? [...peran.value.izin, ...menunggu.value.keys()] : []))
const selisih = computed(() => (peran.value ? bandingkan(props.indeks, basis.value, izin.value) : { ditambah: [], dicabut: [] }))
const adaPerubahan = computed(() => selisih.value.ditambah.length + selisih.value.dicabut.length > 0)

/**
 * Izin menunggu beserta prasyaratnya (lihat node itu dan lihat sub-menu induk untuk bagian): tidak boleh terlepas lewat
 * aksi berantai (mis. lihat induk dicabut), agar simpan tidak ditolak 422 lihat_wajib/induk_belum_diberikan.
 */
function denganMenunggu(himpunan: Iterable<string>, kodeMenunggu: Iterable<string>): Set<string> {
  const hasil = new Set(himpunan)
  for (const k of kodeMenunggu) {
    hasil.add(k)
    const b = props.indeks.perKode.get(k)?.baris
    const lihat = b ? kodeAksi(b.node, 'lihat') : undefined
    const lihatInduk = b?.subInduk ? kodeAksi(b.subInduk, 'lihat') : undefined
    if (lihat) hasil.add(lihat)
    if (lihatInduk) hasil.add(lihatInduk)
  }
  return hasil
}

function terapkanDasar(b: PeranBerversi, izinBaru: Set<string>) {
  dasar.value = b
  izin.value = denganMenunggu(izinBaru, (b.peran.izin_menunggu ?? []).map((m) => m.kode))
  sorot.value = new Set()
  errorEditor.value = null
  peringatan.value = null
  bentrok.value = null
}

// Versi baru dari server (muat pertama, muat ulang saat tab kembali fokus, sesudah simpan). Tanpa suntingan:
// matriks ikut versi baru. Dengan suntingan: suntingan TIDAK diganti; perbedaan ditampilkan sebagai bentrok.
watch(
  () => kueri.data.value,
  (baru) => {
    if (!baru) return
    const lama = dasar.value
    if (!lama || lama.peran.id !== baru.peran.id || !adaPerubahan.value) terapkanDasar(baru, new Set(baru.peran.izin))
    else if (baru.peran.versi !== lama.peran.versi) bentrok.value = baru
  },
  { immediate: true },
)
watch(
  () => props.peranId,
  () => {
    pesan.value = null
  },
)

const bolehUbah = computed(() => boleh('pengaturan.peran.perbaiki'))
const bacaSaja = computed(() => !peran.value?.dapat_disunting || !bolehUbah.value || bentrok.value !== null)
defineExpose({ adaPerubahan })

const KETERANGAN_KUNCI = {
  peran_bawaan_terkunci: 'Peran bawaan sistem: isinya dikunci dan tidak bisa diubah dari layar.',
  peran_sendiri: 'Anda memegang peran ini. Isi peran yang Anda pegang hanya bisa diubah superadmin lain (PRD 9.2.4).',
} as const

function ubahIzin(baru: Set<string>) {
  // Izin menunggu (dan prasyaratnya) tidak bisa terlepas lewat aksi berantai (mis. lihat dicabut).
  izin.value = denganMenunggu(baru, menunggu.value.keys())
  pesan.value = null
}

function batalkan() {
  if (peran.value) izin.value = new Set(basis.value)
  sorot.value = new Set()
  errorEditor.value = null
}

// ── Simpan ──
const dialogSimpan = ref(false)
const sibuk = ref(false)
const errorDialog = ref<string | null>(null)
const formSimpan = ref<InstanceType<typeof DialogSimpanIzin> | null>(null)

/**
 * Sebelum ringkasan simpan, periksa versi terbaru: tolak/tarik/kedaluwarsa tidak menaikkan versi peran, jadi izin yang
 * tidak lagi menunggu harus dilepas dari suntingan — bila tidak, izin itu terkirim lagi dan diam-diam diajukan ulang.
 * Versi berbeda (mis. disetujui orang lain) = bentrok seperti 412.
 */
const memeriksa = ref(false)
async function bukaSimpan() {
  if (memeriksa.value) return
  errorDialog.value = null
  peringatan.value = null
  const lama = dasar.value
  if (lama) {
    memeriksa.value = true
    try {
      const segar = await ambilPeran(props.peranId)
      if (segar.peran.versi !== lama.peran.versi) {
        bentrok.value = segar
        return
      }
      const masih = new Set((segar.peran.izin_menunggu ?? []).map((m) => m.kode))
      const basi = (lama.peran.izin_menunggu ?? []).map((m) => m.kode).filter((k) => !masih.has(k))
      if (basi.length) {
        dasar.value = segar
        qc.setQueryData(KUNCI.peran(props.peranId), segar)
        const sisa = new Set(izin.value)
        basi.forEach((k) => sisa.delete(k))
        izin.value = sisa
        peringatan.value = `Tidak lagi menunggu persetujuan (ditolak, ditarik, atau kedaluwarsa): ${basi.map((k) => labelIzin(props.indeks, k)).join('; ')}. Izin itu dilepas dari suntingan; centang lagi bila ingin mengajukan ulang, lalu simpan.`
        return
      }
    } catch {
      // Gagal memeriksa: tetap lanjut; server tetap memeriksa versi (412) saat simpan.
    } finally {
      memeriksa.value = false
    }
  }
  dialogSimpan.value = true
}

async function simpan(isian: { dasar_perubahan: string; alasan: string | null }) {
  if (!dasar.value) return
  sibuk.value = true
  errorDialog.value = null
  try {
    const hasil = await gantiIzinPeran(props.peranId, dasar.value.etag, { izin: [...izin.value].sort(), ...isian })
    terapkanDasar({ peran: hasil.peran, etag: hasil.etag }, new Set(hasil.peran.izin))
    qc.setQueryData(KUNCI.peran(props.peranId), { peran: hasil.peran, etag: hasil.etag })
    void qc.invalidateQueries({ queryKey: KUNCI.daftarPeran })
    void qc.invalidateQueries({ queryKey: KUNCI.daftarAkun })
    // Menu "Lihat sebagai" akun pemegang peran ini ikut berubah.
    void qc.invalidateQueries({ queryKey: KUNCI.semuaAkun })
    const p = hasil.perubahan
    const tunggu = (p.menunggu_persetujuan ?? []).filter((x) => x.status === 'menunggu')
    const penyetuju = [...new Set(tunggu.map((x) => x.penyetuju_peran?.nama ?? 'pemilik data'))].join(', ')
    pesan.value =
      `Izin ${hasil.peran.nama} tersimpan: ${p.ditambah.length} ditambah, ${p.dicabut.length} dicabut, ${p.akun_terdampak} akun terdampak.` +
      (tunggu.length ? ` ${tunggu.length} izin sensitif menunggu persetujuan ${penyetuju} (${tunggu.map((x) => x.nomor).join(', ')}).` : '')
    void qc.invalidateQueries({ queryKey: KUNCI.persetujuan })
    dialogSimpan.value = false
  } catch (e) {
    tanganiGagal(e)
  } finally {
    sibuk.value = false
  }
}

function tanganiGagal(e: unknown) {
  if (!(e instanceof ApiError)) {
    errorDialog.value = pesanError(e)
    return
  }
  if (e.kode === 'validasi_gagal') {
    const kolom = errorKolom(e, ['dasar_perubahan', 'alasan'])
    if (Object.keys(kolom).length) formSimpan.value?.setErrors(kolom)
    else errorDialog.value = e.message
    return
  }
  if (e.status === 412) {
    dialogSimpan.value = false
    void muatBentrok()
    return
  }
  const kode = kodeDariRincian(e.rincian)
  if (e.kode === 'butuh_persetujuan') {
    dialogSimpan.value = false
    sorot.value = new Set(kode)
    const daftar = kode.map((k) => labelIzin(props.indeks, k)).join('; ')
    errorEditor.value = `Tidak ada yang disimpan. Izin sensitif butuh persetujuan pemilik data${daftar ? `: ${daftar}` : ''}. Hapus centang izin itu untuk menyimpan sisanya.`
    return
  }
  if (e.kode === 'penyetuju_belum_ditetapkan') {
    dialogSimpan.value = false
    sorot.value = new Set(kode)
    errorEditor.value = `Tidak ada yang disimpan. ${e.message} Baris yang ditolak disorot di matriks.`
    return
  }
  if (['lihat_wajib', 'induk_belum_diberikan', 'izin_tidak_dikenal'].includes(e.kode)) {
    dialogSimpan.value = false
    sorot.value = new Set(kode)
    errorEditor.value = `${e.message} Baris yang ditolak disorot di matriks.`
    return
  }
  if (e.kode === 'peran_bawaan_terkunci' || e.kode === 'peran_sendiri') {
    dialogSimpan.value = false
    errorEditor.value = e.message
    void kueri.refetch()
    return
  }
  errorDialog.value = e.message
}

async function muatBentrok() {
  try {
    bentrok.value = await ambilPeran(props.peranId)
  } catch (e) {
    errorEditor.value = pesanError(e)
  }
}

const selisihOrangLain = computed(() => (bentrok.value && peran.value ? bandingkan(props.indeks, peran.value.izin, new Set(bentrok.value.peran.izin)) : null))

/**
 * Suntingan sendiri diterapkan di atas versi terbaru; pengguna meninjau lalu menyimpan lagi. Izin yang tidak lagi
 * utuh (lihat atau sub-menu induknya dicabut di versi terbaru) dilepas dan disebutkan, agar tidak ditolak 422.
 */
function terapkanDiAtasTerbaru() {
  const b = bentrok.value
  if (!b) return
  const baru = new Set(b.peran.izin)
  selisih.value.ditambah.forEach((k) => baru.add(k))
  selisih.value.dicabut.forEach((k) => baru.delete(k))
  const dilepas = periksa(props.indeks, baru).map((m) => m.kode)
  dilepas.forEach((k) => baru.delete(k))
  terapkanDasar(b, baru)
  if (dilepas.length) peringatan.value = `Dilepas karena lihat atau induknya dicabut di versi terbaru: ${dilepas.map((k) => labelIzin(props.indeks, k)).join('; ')}. Periksa sebelum menyimpan.`
}
function pakaiTerbaru() {
  const b = bentrok.value
  if (b) terapkanDasar(b, new Set(b.peran.izin))
}

// ── Info peran ──
const dialogInfo = ref(false)
const modeInfo = ref<'ubah' | 'salin'>('ubah')
function bukaInfo(mode: 'ubah' | 'salin') {
  modeInfo.value = mode
  dialogInfo.value = true
}
function infoSelesai(hasil: PeranBerversi) {
  void qc.invalidateQueries({ queryKey: KUNCI.daftarPeran })
  if (modeInfo.value === 'salin') {
    qc.setQueryData(KUNCI.peran(hasil.peran.id), hasil)
    emit('dibuat', hasil.peran.id)
  } else {
    qc.setQueryData(KUNCI.peran(props.peranId), hasil)
    pesan.value = `Info peran ${hasil.peran.nama} tersimpan.`
  }
}
</script>

<template>
  <section class="kartu editor" aria-labelledby="judul-editor">
    <div v-if="kueri.isPending.value" class="kartu-isi memuat" role="status">Memuat peran…</div>
    <div v-else-if="kueri.isError.value" class="kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else-if="peran">
      <div class="kartu-kepala">
        <div class="identitas">
          <h2 id="judul-editor">{{ peran.nama }}</h2>
          <p>
            <code>{{ peran.kode }}</code> · {{ peran.jumlah_akun }} akun memegang peran ini · diubah {{ formatTanggalWaktu(peran.diubah_pada) }}
          </p>
          <div class="pil-baris">
            <span v-if="peran.bawaan_sistem" class="pil pil-merek">Bawaan sistem</span>
            <span v-if="peran.wajib_mfa" class="pil pil-info">Wajib MFA</span>
            <span v-if="peran.memuat_izin_sensitif" class="pil pil-peringatan"><Ikon nama="gembok" :ukuran="12" />Memuat izin sensitif</span>
            <span v-if="peran.status === 'nonaktif'" class="pil pil-netral">Nonaktif</span>
          </div>
        </div>
        <div class="aksi">
          <span v-if="!bacaSaja" class="pil" :class="adaPerubahan ? 'pil-info' : 'pil-netral'" role="status">
            {{ adaPerubahan ? `${selisih.ditambah.length} ditambah, ${selisih.dicabut.length} dicabut` : 'Tidak ada perubahan' }}
          </span>
          <button v-can="'pengaturan.peran.input'" type="button" class="tombol" @click="bukaInfo('salin')"><Ikon nama="salin" :ukuran="16" />Salin peran</button>
          <!-- PATCH menaikkan versi peran; selama matriks belum disimpan, ubah info ditahan agar tidak bentrok dengan diri sendiri. -->
          <button
            v-if="peran.dapat_disunting && bolehUbah"
            type="button"
            class="tombol"
            :disabled="adaPerubahan"
            :title="adaPerubahan ? 'Simpan atau batalkan perubahan matriks dulu' : undefined"
            aria-describedby="ket-ubah-info"
            @click="bukaInfo('ubah')"
          >
            Ubah info
          </button>
          <span id="ket-ubah-info" class="sr-only">{{ adaPerubahan ? 'Simpan atau batalkan perubahan matriks dulu.' : '' }}</span>
          <template v-if="!bacaSaja">
            <button type="button" class="tombol" :disabled="!adaPerubahan" @click="batalkan">Batalkan</button>
            <Tombol varian="utama" :disabled="!adaPerubahan" :sibuk="memeriksa" @click="bukaSimpan">{{ memeriksa ? 'Memeriksa…' : 'Simpan perubahan' }}</Tombol>
          </template>
        </div>
      </div>

      <div class="kartu-isi pesan-area">
        <p v-if="peran.alasan_terkunci" class="catatan catatan-netral">
          <Ikon nama="gembok" :ukuran="16" />
          <span>{{ KETERANGAN_KUNCI[peran.alasan_terkunci] }} Matriks ditampilkan dalam mode baca.</span>
        </p>
        <p v-else-if="!bolehUbah" class="catatan catatan-netral">
          <Ikon nama="info" :ukuran="16" />
          <span>Akun Anda hanya boleh melihat peran. Matriks ditampilkan dalam mode baca.</span>
        </p>
        <p v-if="pesan" class="catatan catatan-info" role="status">
          <Ikon nama="centang" :ukuran="16" />
          <span>{{ pesan }}</span>
        </p>
        <p v-if="peringatan" class="catatan catatan-peringatan" role="alert">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>{{ peringatan }}</span>
        </p>
        <p v-if="errorEditor" class="catatan catatan-bahaya" role="alert">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>{{ errorEditor }}</span>
        </p>
        <div v-if="bentrok && selisihOrangLain" class="bentrok" role="alert">
          <p class="catatan catatan-peringatan">
            <Ikon nama="peringatan" :ukuran="16" />
            <span>Peran ini diubah orang lain sejak Anda membukanya. Suntingan Anda belum disimpan.</span>
          </p>
          <div class="bentrok-isi">
            <section>
              <h3>Perubahan orang lain</h3>
              <ul>
                <li v-for="k in selisihOrangLain.ditambah" :key="`t${k}`">+ {{ labelIzin(indeks, k) }}</li>
                <li v-for="k in selisihOrangLain.dicabut" :key="`c${k}`">− {{ labelIzin(indeks, k) }}</li>
                <li v-if="!selisihOrangLain.ditambah.length && !selisihOrangLain.dicabut.length">Hanya info peran (nama, uraian, MFA, atau status).</li>
              </ul>
            </section>
            <section>
              <h3>Suntingan Anda</h3>
              <ul>
                <li v-for="k in selisih.ditambah" :key="`t${k}`">+ {{ labelIzin(indeks, k) }}</li>
                <li v-for="k in selisih.dicabut" :key="`c${k}`">− {{ labelIzin(indeks, k) }}</li>
              </ul>
            </section>
          </div>
          <div class="bentrok-aksi">
            <button type="button" class="tombol tombol-utama" @click="terapkanDiAtasTerbaru">Terapkan suntingan saya di atas versi terbaru</button>
            <button type="button" class="tombol" @click="pakaiTerbaru">Buang suntingan saya</button>
          </div>
        </div>
      </div>

      <MatriksIzin :indeks="indeks" :izin="izin" :tersimpan="basis" :baca-saja="bacaSaja" :sorot="sorot" :menunggu="menunggu" @update:izin="ubahIzin" />

      <DialogSimpanIzin
        ref="formSimpan"
        v-model:open="dialogSimpan"
        :nama-peran="peran.nama"
        :indeks="indeks"
        :ditambah="selisih.ditambah"
        :dicabut="selisih.dicabut"
        :akun-terdampak="peran.jumlah_akun"
        :sibuk="sibuk"
        :error="errorDialog"
        @simpan="simpan"
      />
      <DialogInfoPeran v-model:open="dialogInfo" :mode="modeInfo" :peran="peran" :etag="dasar?.etag ?? null" @selesai="infoSelesai" @muat-ulang="kueri.refetch()" />
    </template>
  </section>
</template>

<style scoped>
.editor {
  min-width: 0;
}
.memuat {
  color: var(--muted);
}
.identitas {
  min-width: 0;
}
.identitas code {
  font-family: var(--font-mono);
  font-size: 12px;
}
.pil-baris {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  margin-top: 6px;
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.pesan-area:empty {
  display: none;
}
.pesan-area {
  padding-top: 0;
  padding-bottom: 0;
}
.pesan-area > :last-child {
  margin-bottom: var(--space-3);
}
.bentrok-isi {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--space-3);
  margin-top: var(--space-2);
  font-size: 13px;
}
.bentrok h3 {
  margin: 0 0 4px;
  font-size: 13px;
}
.bentrok ul {
  margin: 0;
  padding-left: 18px;
}
.bentrok-aksi {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-top: var(--space-3);
}
</style>
