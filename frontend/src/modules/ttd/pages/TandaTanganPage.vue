<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useSesiStore } from '@/app/sesi'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import IsianKataSandi from '@/design-system/form/IsianSandi.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ApiError } from '@/shared/api/klien'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { aktivasiTtd, gantiPinTtd } from '../api'
import { perbaruiStatusTtd } from '../ttd'

/**
 * Tanda tangan elektronik akun sendiri (tiket 1.6 fe 1, menu akun): arti tanda tangan, status, aktivasi (sandi akun +
 * PIN dua kali; juga aktivasi ulang sesudah reset), dan ganti PIN. Aturan PIN diperiksa server (pesan `rincian`
 * ditampilkan apa adanya); klien hanya memeriksa 6 angka dan pengulangan. PIN dan sandi dikosongkan sesudah dikirim.
 * Tidak ada jalur menetapkan PIN untuk orang lain; lupa PIN = minta reset ke bagian SDM (disetujui atasan langsung).
 */
const sesi = useSesiStore()
const status = computed(() => sesi.pengguna?.ttd ?? null)
const terkunci = computed(() => !!status.value?.terkunci_sampai && new Date(status.value.terkunci_sampai) > new Date())

const aktivasi = reactive({ sandi: '', pin: '', pin_ulang: '' })
const errorAktivasi = ref<Record<string, string>>({})
const pesanAktivasi = ref<string | null>(null)
const sibukAktivasi = ref(false)

const ganti = reactive({ pin_lama: '', pin_baru: '', pin_ulang: '' })
const errorGanti = ref<Record<string, string>>({})
const pesanGanti = ref<string | null>(null)
const sibukGanti = ref(false)

const ENAM = /^[0-9]{6}$/
const angka = (v: string) => v.replace(/\D/g, '').slice(0, 6)

/** rincian `{kolom, kode, pesan}` → pesan per kolom; sisanya pesan umum. */
function petakan(e: unknown, kolom: string[]): { kolom: Record<string, string>; umum: string | null } {
  if (!(e instanceof ApiError)) return { kolom: {}, umum: 'Respons server tidak dikenali. Coba lagi.' }
  const hasil: Record<string, string> = {}
  for (const r of e.rincian) if (typeof r.kolom === 'string' && kolom.includes(r.kolom) && r.pesan) hasil[r.kolom] = String(r.pesan)
  if (e.status === 403 && e.kode === 'ttd_terkunci') {
    return { kolom: hasil, umum: `Tanda tangan terkunci karena PIN salah 5 kali. Coba lagi${e.cobaLagiDetik ? ` dalam ${Math.ceil(e.cobaLagiDetik / 60)} menit` : ' nanti'}.` }
  }
  return { kolom: hasil, umum: Object.keys(hasil).length ? null : e.message }
}

async function aktifkan() {
  const e: Record<string, string> = {}
  if (!aktivasi.sandi) e.sandi = 'Isi sandi akun Anda.'
  if (!ENAM.test(aktivasi.pin)) e.pin = 'PIN harus 6 angka.'
  else if (aktivasi.pin_ulang !== aktivasi.pin) e.pin_ulang = 'Pengulangan PIN tidak sama.'
  errorAktivasi.value = e
  pesanAktivasi.value = null
  if (Object.keys(e).length) return
  sibukAktivasi.value = true
  try {
    const s = await aktivasiTtd({ ...aktivasi })
    perbaruiStatusTtd(s)
    tampilkanToast('Tanda tangan elektronik aktif.')
  } catch (err) {
    const h = petakan(err, ['sandi', 'pin', 'pin_ulang'])
    errorAktivasi.value = h.kolom
    pesanAktivasi.value = h.umum
  } finally {
    Object.assign(aktivasi, { sandi: '', pin: '', pin_ulang: '' })
    sibukAktivasi.value = false
  }
}

async function gantiPin() {
  const e: Record<string, string> = {}
  if (!ENAM.test(ganti.pin_lama)) e.pin_lama = 'Isi PIN lama (6 angka).'
  if (!ENAM.test(ganti.pin_baru)) e.pin_baru = 'PIN baru harus 6 angka.'
  else if (ganti.pin_ulang !== ganti.pin_baru) e.pin_ulang = 'Pengulangan PIN tidak sama.'
  errorGanti.value = e
  pesanGanti.value = null
  if (Object.keys(e).length) return
  sibukGanti.value = true
  try {
    const s = await gantiPinTtd({ ...ganti })
    perbaruiStatusTtd(s)
    tampilkanToast('PIN diganti. Sesi tanda tangan berakhir; PIN baru dipakai mulai sekarang.')
  } catch (err) {
    const h = petakan(err, ['pin_lama', 'pin_baru', 'pin_ulang'])
    errorGanti.value = h.kolom
    pesanGanti.value = h.umum
    if (err instanceof ApiError && err.kode === 'ttd_terkunci') perbaruiStatusTtd({ terkunci_sampai: new Date(Date.now() + (err.cobaLagiDetik ?? 900) * 1000).toISOString(), sesi_berlaku_sampai: null })
  } finally {
    Object.assign(ganti, { pin_lama: '', pin_baru: '', pin_ulang: '' })
    sibukGanti.value = false
  }
}
</script>

<template>
  <div class="halaman">
    <header class="kepala">
      <h1>Tanda tangan elektronik</h1>
      <p>Tanda tangan T1 untuk mengesahkan keputusan di SIMRS, dibuka dengan PIN pribadi Anda.</p>
    </header>

    <div class="tata">
      <section class="kartu" aria-labelledby="judul-status-ttd">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-status-ttd">Status</h2>
          </div>
          <span v-if="status" class="pil" :class="status.aktif ? (terkunci ? 'pil-bahaya' : 'pil-ok') : 'pil-netral'">
            {{ status.aktif ? (terkunci ? 'Terkunci' : 'Aktif') : status.dicabut_pada ? 'Dicabut' : 'Belum aktif' }}
          </span>
        </div>
        <div class="kartu-isi">
          <template v-if="status">
            <p v-if="status.aktif">Aktif sejak {{ formatTanggalWaktu(status.diaktifkan_pada) }}.</p>
            <p v-else-if="status.dicabut_pada" class="catatan catatan-peringatan">
              <Ikon nama="peringatan" :ukuran="16" />
              <span>TTE Anda dicabut karena reset pada {{ formatTanggalWaktu(status.dicabut_pada) }}. Tanda tangan lama tetap sah. Aktifkan ulang dengan PIN baru.</span>
            </p>
            <p v-else>Belum diaktifkan.</p>
            <p v-if="terkunci" class="catatan catatan-bahaya" role="alert">
              <Ikon nama="gembok" :ukuran="16" />
              <span>Terkunci sampai {{ formatTanggalWaktu(status.terkunci_sampai) }} karena PIN salah 5 kali.</span>
            </p>
            <p v-if="status.reset_menunggu" class="catatan catatan-info">
              <Ikon nama="info" :ukuran="16" />
              <span>Ada pengajuan reset TTE untuk akun Anda yang menunggu persetujuan atasan langsung.</span>
            </p>
            <p v-if="status.sesi_berlaku_sampai" class="ket">Sesi tanda tangan berlaku sampai {{ formatTanggalWaktu(status.sesi_berlaku_sampai) }}.</p>
          </template>
          <h3>Arti tanda tangan Anda</h3>
          <ul class="arti">
            <li>Hanya Anda yang bisa menandatangani dengan akun ini; tidak ada admin yang bisa menandatangani atas nama Anda.</li>
            <li>Isi yang ditandatangani dihitung dan dicap waktu oleh server, lalu bisa diverifikasi kapan saja. Bila isinya berubah, verifikasi menandainya.</li>
            <li>Jangan beri tahu PIN kepada siapa pun. PIN salah 5 kali mengunci tanda tangan sementara dan memberi tahu atasan langsung Anda.</li>
            <li>Lupa PIN: minta reset ke bagian SDM; reset disetujui atasan langsung, lalu Anda mengaktifkan ulang sendiri.</li>
          </ul>
        </div>
      </section>

      <section v-if="status && !status.aktif" class="kartu" aria-labelledby="judul-aktivasi">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-aktivasi">{{ status.dicabut_pada ? 'Aktifkan ulang' : 'Aktifkan' }}</h2>
            <p>Sandi akun diminta agar sesi yang ditinggal terbuka tidak bisa membuat TTE atas nama Anda.</p>
          </div>
        </div>
        <form class="kartu-isi" novalidate @submit.prevent="aktifkan">
          <KolomIsian id="ttd-sandi" v-slot="a" label="Sandi akun" :error="errorAktivasi.sandi">
            <IsianKataSandi v-bind="a" v-model="aktivasi.sandi" name="sandi" autocomplete="current-password" />
          </KolomIsian>
          <div class="dua-kolom">
            <KolomIsian v-slot="a" label="PIN baru" :error="errorAktivasi.pin" petunjuk="6 angka; bukan angka sama, berulang, atau berurutan.">
              <input v-bind="a" v-model="aktivasi.pin" type="password" inputmode="numeric" autocomplete="one-time-code" data-1p-ignore data-lpignore="true" data-form-type="other" maxlength="6" class="isian pin" @input="aktivasi.pin = angka(aktivasi.pin)" />
            </KolomIsian>
            <KolomIsian v-slot="a" label="Ulangi PIN" :error="errorAktivasi.pin_ulang">
              <input v-bind="a" v-model="aktivasi.pin_ulang" type="password" inputmode="numeric" autocomplete="one-time-code" data-1p-ignore data-lpignore="true" data-form-type="other" maxlength="6" class="isian pin" @input="aktivasi.pin_ulang = angka(aktivasi.pin_ulang)" />
            </KolomIsian>
          </div>
          <p v-if="pesanAktivasi" class="catatan catatan-bahaya" role="alert">
            <Ikon nama="peringatan" :ukuran="16" />
            <span>{{ pesanAktivasi }}</span>
          </p>
          <Tombol type="submit" varian="utama" :sibuk="sibukAktivasi">{{ sibukAktivasi ? 'Mengaktifkan…' : 'Aktifkan tanda tangan' }}</Tombol>
        </form>
      </section>

      <section v-if="status?.aktif" class="kartu" aria-labelledby="judul-ganti-pin">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-ganti-pin">Ganti PIN</h2>
            <p>Kunci tanda tangan tetap; tanda tangan lama tidak terpengaruh. PIN lama yang salah ikut dihitung ke penguncian.</p>
          </div>
        </div>
        <form class="kartu-isi" novalidate @submit.prevent="gantiPin">
          <KolomIsian v-slot="a" label="PIN lama" :error="errorGanti.pin_lama">
            <input v-bind="a" v-model="ganti.pin_lama" type="password" inputmode="numeric" autocomplete="one-time-code" data-1p-ignore data-lpignore="true" data-form-type="other" maxlength="6" class="isian pin" :disabled="terkunci" @input="ganti.pin_lama = angka(ganti.pin_lama)" />
          </KolomIsian>
          <div class="dua-kolom">
            <KolomIsian v-slot="a" label="PIN baru" :error="errorGanti.pin_baru">
              <input v-bind="a" v-model="ganti.pin_baru" type="password" inputmode="numeric" autocomplete="one-time-code" data-1p-ignore data-lpignore="true" data-form-type="other" maxlength="6" class="isian pin" :disabled="terkunci" @input="ganti.pin_baru = angka(ganti.pin_baru)" />
            </KolomIsian>
            <KolomIsian v-slot="a" label="Ulangi PIN baru" :error="errorGanti.pin_ulang">
              <input v-bind="a" v-model="ganti.pin_ulang" type="password" inputmode="numeric" autocomplete="one-time-code" data-1p-ignore data-lpignore="true" data-form-type="other" maxlength="6" class="isian pin" :disabled="terkunci" @input="ganti.pin_ulang = angka(ganti.pin_ulang)" />
            </KolomIsian>
          </div>
          <p v-if="pesanGanti" class="catatan catatan-bahaya" role="alert">
            <Ikon nama="peringatan" :ukuran="16" />
            <span>{{ pesanGanti }}</span>
          </p>
          <Tombol type="submit" :sibuk="sibukGanti" :disabled="terkunci">{{ sibukGanti ? 'Menyimpan…' : 'Ganti PIN' }}</Tombol>
        </form>
      </section>
    </div>
  </div>
</template>

<style scoped>
.kepala {
  margin-bottom: var(--space-4);
}
.kepala h1 {
  margin: 0 0 var(--space-1);
  font-size: 22px;
  letter-spacing: -0.01em;
}
.kepala p {
  margin: 0;
  color: var(--muted);
}
.tata {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr));
  align-items: start;
  gap: var(--space-4);
}
.kartu-isi p {
  margin: 0 0 var(--space-2);
}
.kartu-isi h3 {
  margin: var(--space-3) 0 var(--space-1);
  font-size: 13.5px;
}
.arti {
  margin: 0;
  padding-left: var(--space-4);
  color: var(--muted);
  font-size: 13px;
}
.arti li + li {
  margin-top: var(--space-1);
}
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.pin {
  font-family: var(--font-mono);
  letter-spacing: 0.3em;
}
.ket {
  color: var(--muted);
  font-size: 12.5px;
}
</style>
