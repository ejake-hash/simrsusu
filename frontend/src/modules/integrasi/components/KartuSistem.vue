<script setup lang="ts">
import { computed, ref } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { simpanKredensial, ujiKoneksi } from '../api'
import { LABEL_CB, LABEL_KOLOM, LABEL_LINGKUNGAN, PIL_CB } from '../integrasi'
import type { KredensialLingkungan, LingkunganIntegrasi, StatusSistemIntegrasi } from '../tipe'
import DialogKredensial from './DialogKredensial.vue'

/**
 * Kartu status satu sistem integrasi (tiket 1.9 fe 1): lingkungan aktif, circuit breaker, queue, peringatan, error dan
 * uji koneksi terakhir (tanpa angka tiruan; semua dari GET /integrasi/sistem). `kredensial` null = pemantau per tujuan:
 * bagian kredensial tidak tampil. Dengan kredensial: tulis-saja per lingkungan, aktif/nonaktif, uji koneksi.
 */
const props = defineProps<{ status: StatusSistemIntegrasi }>()
const emit = defineEmits<{ berubah: [status: StatusSistemIntegrasi] }>()
const bolehUbah = computed(() => props.status.kredensial !== null && boleh('pengaturan.integrasi.perbaiki'))
const s = computed(() => props.status)

const dialog = ref(false)
const pilihan = ref<KredensialLingkungan | null>(null)
function ubah(k: KredensialLingkungan) {
  pilihan.value = k
  dialog.value = true
}
function selesai(baru: StatusSistemIntegrasi) {
  emit('berubah', baru)
  tampilkanToast(`Kredensial ${baru.nama} disimpan.`)
}

const menguji = ref<LingkunganIntegrasi | null>(null)
async function uji(l: LingkunganIntegrasi) {
  menguji.value = l
  try {
    const h = await ujiKoneksi(s.value.kode, l)
    emit('berubah', { ...s.value, uji_koneksi_terakhir: h })
    tampilkanToast(h.berhasil ? `${s.value.nama} ${LABEL_LINGKUNGAN[l].toLowerCase()} tersambung.` : `${s.value.nama}: ${h.pesan}`, h.berhasil ? 'ok' : 'error')
  } catch (err) {
    const pesan =
      err instanceof ApiError && err.status === 429 && err.cobaLagiDetik
        ? `Uji koneksi terlalu sering; coba lagi dalam ${err.cobaLagiDetik} detik.`
        : err instanceof ApiError && err.kode === 'kredensial_belum_lengkap'
          ? `Kredensial belum lengkap: ${err.rincian.map((r) => LABEL_KOLOM[String(r.kolom ?? '').replace(/^kolom\./, '')] ?? r.kolom).join(', ')}.`
          : pesanError(err)
    tampilkanToast(pesan, 'error')
  } finally {
    menguji.value = null
  }
}

const konfirmasiNonaktif = ref(false)
const sibukStatus = ref(false)
async function aktifkan(l: LingkunganIntegrasi) {
  sibukStatus.value = true
  try {
    emit('berubah', await simpanKredensial(s.value.kode, { lingkungan: l, aktifkan: true }))
    tampilkanToast(`${s.value.nama}: ${LABEL_LINGKUNGAN[l].toLowerCase()} kini aktif.`)
  } catch (err) {
    tampilkanToast(pesanError(err), 'error')
  } finally {
    sibukStatus.value = false
  }
}
async function nonaktifkan() {
  if (!s.value.lingkungan_aktif) return
  sibukStatus.value = true
  try {
    emit('berubah', await simpanKredensial(s.value.kode, { lingkungan: s.value.lingkungan_aktif, nonaktifkan: true }))
    konfirmasiNonaktif.value = false
    tampilkanToast(`${s.value.nama} dinonaktifkan; kiriman baru tertahan.`)
  } catch (err) {
    tampilkanToast(pesanError(err), 'error')
  } finally {
    sibukStatus.value = false
  }
}
const tampilKolom = (k: KredensialLingkungan['kolom'][number]) =>
  !k.terisi ? 'Belum diisi' : !k.rahasia ? (k.nilai ?? '') : k.akhir ? `Terisi ····${k.akhir}` : 'Terisi'
</script>

<template>
  <section class="kartu kartu-sistem" :aria-labelledby="`judul-sistem-${s.kode}`">
    <div class="kartu-kepala">
      <div>
        <h2 :id="`judul-sistem-${s.kode}`">{{ s.nama }}</h2>
        <p>{{ s.lingkungan_aktif ? `Lingkungan aktif: ${LABEL_LINGKUNGAN[s.lingkungan_aktif]}` : 'Belum ada lingkungan aktif' }}</p>
      </div>
      <span class="pil" :class="PIL_CB[s.circuit_breaker.status]">{{ LABEL_CB[s.circuit_breaker.status] }}</span>
    </div>
    <div class="kartu-isi">
      <p v-for="p in s.peringatan" :key="p.kode" class="catatan catatan-peringatan">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>
          {{ p.pesan }}
          <RouterLink v-if="p.kode === 'kode_ppk_kosong'" to="/pengaturan/profil-rs">Isi kode PPK di Profil RS</RouterLink>
        </span>
      </p>
      <p v-if="s.circuit_breaker.status !== 'tertutup'" class="catatan catatan-bahaya">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>
          {{ s.circuit_breaker.gagal_beruntun }} kali gagal beruntun sejak {{ s.circuit_breaker.sejak ? formatTanggalWaktu(s.circuit_breaker.sejak) : '—' }}; kiriman
          ditahan di kotak keluar<template v-if="s.circuit_breaker.coba_lagi_pada"> sampai {{ formatTanggalWaktu(s.circuit_breaker.coba_lagi_pada) }}</template>.
        </span>
      </p>

      <dl class="queue" aria-label="Kotak keluar">
        <div>
          <dt>Menunggu</dt>
          <dd>{{ s.queue.menunggu }}</dd>
        </div>
        <div :class="{ sorot: s.queue.tertahan > 0 }">
          <dt>Tertahan</dt>
          <dd>{{ s.queue.tertahan }}</dd>
        </div>
        <div :class="{ sorot: s.queue.gagal > 0 }">
          <dt>Gagal</dt>
          <dd>{{ s.queue.gagal }}</dd>
        </div>
        <div>
          <dt>Terkirim 24 jam</dt>
          <dd>{{ s.queue.terkirim_24_jam }}</dd>
        </div>
      </dl>
      <p v-if="s.queue.menunggu_tertua_pada" class="ket">Menunggu paling lama sejak {{ formatTanggalWaktu(s.queue.menunggu_tertua_pada) }}.</p>
      <p v-if="s.error_terakhir" class="ket">Error terakhir ({{ formatTanggalWaktu(s.error_terakhir.pada) }}): {{ s.error_terakhir.pesan }}</p>
      <p v-if="s.uji_koneksi_terakhir" class="ket" :class="{ gagal: !s.uji_koneksi_terakhir.berhasil }">
        Uji koneksi {{ LABEL_LINGKUNGAN[s.uji_koneksi_terakhir.lingkungan].toLowerCase() }} {{ formatTanggalWaktu(s.uji_koneksi_terakhir.pada) }}:
        {{ s.uji_koneksi_terakhir.berhasil ? 'berhasil' : 'gagal' }}<template v-if="s.uji_koneksi_terakhir.kode_http"> (HTTP {{ s.uji_koneksi_terakhir.kode_http }})</template><template v-if="s.uji_koneksi_terakhir.latensi_ms !== null"> · {{ s.uji_koneksi_terakhir.latensi_ms }} ms</template> — {{ s.uji_koneksi_terakhir.pesan }}
      </p>

      <div v-if="s.kredensial" class="lingkungan">
        <section v-for="k in s.kredensial" :key="k.lingkungan" class="ling" :aria-labelledby="`judul-ling-${s.kode}-${k.lingkungan}`">
          <div class="ling-kepala">
            <h3 :id="`judul-ling-${s.kode}-${k.lingkungan}`">{{ LABEL_LINGKUNGAN[k.lingkungan] }}</h3>
            <span v-if="k.aktif" class="pil pil-ok">Aktif</span>
            <span v-else-if="!k.lengkap" class="pil pil-netral">Belum lengkap</span>
          </div>
          <dl class="kolom">
            <div v-for="c in k.kolom" :key="c.nama">
              <dt>{{ LABEL_KOLOM[c.nama] ?? c.nama }}</dt>
              <dd :class="{ kosong: !c.terisi }"><code v-if="c.terisi && !c.rahasia">{{ tampilKolom(c) }}</code><template v-else>{{ tampilKolom(c) }}</template></dd>
            </div>
          </dl>
          <p v-if="k.diubah_pada" class="ket">Diubah {{ k.diubah_oleh?.nama ?? 'sistem' }}, {{ formatTanggalWaktu(k.diubah_pada) }}</p>
          <div v-if="bolehUbah" class="aksi">
            <button type="button" class="tombol" @click="ubah(k)">Ubah kredensial</button>
            <button type="button" class="tombol" :disabled="!k.lengkap || menguji !== null" @click="uji(k.lingkungan)">{{ menguji === k.lingkungan ? 'Menguji…' : 'Uji koneksi' }}</button>
            <button v-if="!k.aktif && k.lengkap" type="button" class="tombol" :disabled="sibukStatus" @click="aktifkan(k.lingkungan)">Aktifkan</button>
            <button v-if="k.aktif" type="button" class="tombol" :disabled="sibukStatus" @click="konfirmasiNonaktif = true">Nonaktifkan</button>
          </div>
        </section>
      </div>
    </div>
    <DialogKredensial v-if="pilihan" v-model:open="dialog" :sistem="s" :kredensial="pilihan" @selesai="selesai" />
    <Dialog v-model:open="konfirmasiNonaktif" :judul="`Nonaktifkan ${s.nama}?`" peringatan :sibuk="sibukStatus">
      <p class="catatan catatan-peringatan">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>Tidak ada lagi kiriman ke {{ s.nama }}. Kiriman baru tetap dicatat tetapi tertahan sampai lingkungan diaktifkan dan dikirim ulang.</span>
      </p>
      <template #aksi>
        <button type="button" class="tombol" :disabled="sibukStatus" @click="konfirmasiNonaktif = false">Batal</button>
        <Tombol varian="bahaya" :sibuk="sibukStatus" @click="nonaktifkan">Nonaktifkan</Tombol>
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.kartu-kepala h2 {
  margin: 0;
  font-size: 15px;
}
.kartu-kepala p {
  margin: 2px 0 0;
  color: var(--muted);
  font-size: 12.5px;
}
.queue {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
  gap: var(--space-2);
  margin: 0 0 var(--space-2);
}
.queue div {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.queue div.sorot {
  box-shadow: inset 3px 0 0 var(--warn);
}
.queue dt {
  color: var(--muted);
  font-size: 12px;
}
.queue dd {
  margin: 0;
  font-size: 18px;
  font-weight: var(--font-weight-semibold);
}
.ket {
  margin: var(--space-1) 0 0;
  color: var(--muted);
  font-size: 12.5px;
  overflow-wrap: anywhere;
}
.ket.gagal {
  color: var(--danger);
}
.lingkungan {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: var(--space-3);
  margin-top: var(--space-3);
}
.ling {
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  min-width: 0;
}
.ling-kepala {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
}
.ling-kepala h3 {
  margin: 0;
  font-size: 14px;
}
.kolom {
  display: grid;
  gap: 4px;
  margin: 0;
  font-size: 13px;
}
.kolom div {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: var(--space-2);
}
.kolom dt {
  color: var(--muted);
}
.kolom dd {
  margin: 0;
  overflow-wrap: anywhere;
}
.kolom dd.kosong {
  color: var(--muted);
}
.kolom code {
  font-family: var(--font-mono);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-top: var(--space-3);
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .aksi .tombol {
    min-height: 44px;
  }
}
</style>
