<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, useId, watch } from 'vue'
import { useSesiStore } from '@/app/sesi'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import TtdModal from '@/design-system/components/TtdModal.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { perbaruiStatusTtd } from '@/modules/ttd/ttd'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { bacaResumeMedis, sahkanResumeMedis, susunResumeMedis } from '../api'
import {
  isianDariResume,
  isianResumeBerubah,
  IZIN_PEMULANGAN,
  KODE_TTE,
  KOLOM_ISIAN_RESUME,
  KUNCI_PEMULANGAN,
  pesanGalatResume,
  resumeBasi,
  ringkasResumeTtd,
  teksIdentitas,
} from '../pemulangan'
import type { IsianResumeMedis, ResumeMedis } from '../tipe'

/**
 * Resume medis pulang (FR-05-02, FR-05-04). Susun/susun ulang = POST yang memotret ulang sumber (CPPT sah tanpa label,
 * diagnosis, resep sah, alergi; tindakan belum tersedia) dan menyimpan isian DPJP yang berubah. Sahkan lewat TtdModal (PIN T1)
 * hanya bagi pemegang izin sahkan; penanda tangan = DPJP kunjungan, jadi akun lain masuk mode "minta tanda tangan" dan server
 * tetap menolak dengan 403 `bukan_pemutus`. Resume sah hanya dibaca. Isi klinis tidak ditulis ke konsol atau URL.
 */
const props = defineProps<{ kunjunganId: string }>()
const qc = useQueryClient()
const sesi = useSesiStore()
const idJudul = useId()
const kunci = computed(() => KUNCI_PEMULANGAN.resume(props.kunjunganId))

const kueri = useQuery({ queryKey: kunci, queryFn: () => bacaResumeMedis(props.kunjunganId) })
const resume = computed<ResumeMedis | null>(() => kueri.data.value ?? null)
const draf = computed(() => !resume.value || resume.value.status === 'draf')
const bisaSusun = computed(() => boleh(IZIN_PEMULANGAN.input) && draf.value)
const izinSahkan = computed(() => boleh(IZIN_PEMULANGAN.sahkan) && resume.value?.status === 'draf')
// TTE hanya pemilik akun (PRD 5.1): pegawai akun ≠ DPJP → tanpa tombol, cukup keterangan siapa pengesahnya. DPJP tak
// tercatat → tombol tetap ada dan server yang memutus.
const bukanDpjp = computed(() => !!resume.value?.dpjp && resume.value.dpjp.id !== (sesi.pengguna?.pegawai?.id ?? ''))
const bisaSahkan = computed(() => izinSahkan.value && !bukanDpjp.value)

const isian = ref<IsianResumeMedis>(isianDariResume(null))
watch(
  () => [resume.value?.id, resume.value?.versi] as const,
  () => (isian.value = isianDariResume(resume.value)),
  { immediate: true },
)
const perubahan = computed(() => isianResumeBerubah(resume.value, isian.value))
const adaPerubahan = computed(() => Object.keys(perubahan.value).length > 0)

const pesan = ref<string | null>(null)
const menyusun = ref(false)
async function susun() {
  if (!bisaSusun.value) return
  menyusun.value = true
  pesan.value = null
  try {
    const baru = resume.value === null
    const hasil = await susunResumeMedis(props.kunjunganId, perubahan.value)
    qc.setQueryData(kunci.value, hasil)
    tampilkanToast(baru ? 'Draf resume medis tersusun dari sumber terbaru.' : 'Draf resume medis tersimpan; sumber dipotret ulang.', 'ok')
  } catch (e) {
    if (resumeBasi(e) || (e instanceof ApiError && e.status === 409)) await kueri.refetch()
    pesan.value = pesanGalatResume(e, 'susun')
  } finally {
    menyusun.value = false
  }
}

// ── Sahkan (PIN T1). Penanda tangan = DPJP; identitas pasien ikut di judul objek (PRD 5.1 butir 9). ──
const dialogTtd = ref(false)
const pengguna = computed(() => ({ id: sesi.pengguna?.pegawai?.id ?? '', nama: sesi.pengguna?.pegawai?.nama ?? sesi.pengguna?.nama_pengguna ?? '' }))
const penanda = computed(() => (resume.value?.dpjp ? { id: resume.value.dpjp.id, nama: resume.value.dpjp.nama } : null))
const objekTtd = computed(() => {
  const r = resume.value
  if (!r) return []
  return [{ id: r.id, judul: `Resume medis · ${teksIdentitas(r.pasien)}`, ringkasan: ringkasResumeTtd(r) }]
})
function mintaSahkan() {
  pesan.value = null
  dialogTtd.value = true
}
async function tandatangani(pin: string | null, pernyataan: boolean) {
  const r = resume.value!
  let sah: ResumeMedis
  try {
    sah = await sahkanResumeMedis(r, { pin, pernyataan_disetujui: pernyataan })
  } catch (x) {
    // Pola PanelResep: status TTE di store ikut berubah sebelum galat ditafsirkan modal.
    if (x instanceof ApiError && x.kode === 'pin_wajib') perbaruiStatusTtd({ sesi_berlaku_sampai: null })
    if (x instanceof ApiError && x.kode === 'ttd_terkunci') perbaruiStatusTtd({ terkunci_sampai: new Date(Date.now() + (x.cobaLagiDetik ?? 900) * 1000).toISOString(), sesi_berlaku_sampai: null })
    if (x instanceof ApiError && !(KODE_TTE as readonly string[]).includes(x.kode)) {
      // Galat resume (bukan DPJP, SIP, sudah sah, versi berubah) tampil di kartu; modal ditutup, isi dimuat ulang bila basi.
      dialogTtd.value = false
      if (resumeBasi(x)) await kueri.refetch()
      pesan.value = pesanGalatResume(x, 'sahkan')
      return
    }
    throw x
  }
  qc.setQueryData(kunci.value, sah)
  tampilkanToast(`Resume medis ${sah.pasien.nama} sah, ditandatangani ${sah.disahkan_oleh?.nama ?? pengguna.value.nama}.`, 'ok')
}

const LABEL_JENIS_DIAGNOSIS = { utama: 'Utama', sekunder: 'Sekunder', komplikasi: 'Komplikasi', komorbid: 'Komorbid' } as const
const soap = (c: ResumeMedis['sumber']['cppt'][number]) =>
  ([['S', c.subjektif], ['O', c.objektif], ['A', c.asesmen], ['P', c.plan]] as const).filter(([, v]) => v)
const teksButirResep = (b: ResumeMedis['sumber']['resep'][number]['butir'][number]) =>
  [b.barang.nama, b.dosis, `${b.frekuensi_per_hari}× sehari`, `${b.jumlah_per_pakai} per pakai`, `${b.hari} hari`, b.rute, b.aturan_pakai].filter(Boolean).join(', ')
</script>

<template>
  <section class="kartu" :aria-labelledby="idJudul">
    <div class="kartu-kepala">
      <div>
        <h2 :id="idJudul">Resume medis</h2>
        <p>Disusun dari catatan yang sudah sah selama episode, dilengkapi isian DPJP, lalu disahkan DPJP dengan PIN tanda tangan.</p>
      </div>
      <Lencana v-if="resume?.status === 'sah'" jenis="ok">Sah</Lencana>
      <Lencana v-else-if="resume" jenis="info">Draf</Lencana>
    </div>

    <KeadaanKosong v-if="kueri.isPending.value" jenis="memuat" judul="Memuat resume medis" />
    <KeadaanKosong v-else-if="kueri.isError.value" jenis="error" judul="Resume medis gagal dimuat" :uraian="pesanGalatResume(kueri.error.value, 'muat')">
      <template #aksi><Tombol @click="kueri.refetch()">Coba lagi</Tombol></template>
    </KeadaanKosong>
    <KeadaanKosong
      v-else-if="!resume"
      judul="Resume belum disusun"
      :uraian="bisaSusun ? 'Susun resume untuk memotret CPPT, diagnosis, resep, dan alergi episode ini. Isian DPJP diisi sesudahnya.' : 'Resume disusun oleh dokter dengan izin pemulangan.'"
    >
      <template v-if="bisaSusun" #aksi>
        <Tombol varian="utama" :sibuk="menyusun" @click="susun">Susun resume</Tombol>
      </template>
    </KeadaanKosong>

    <div v-else class="kartu-isi isi">
      <dl class="ringkas">
        <div>
          <dt>DPJP (pengesah)</dt>
          <dd>{{ resume.dpjp?.nama ?? 'Belum tercatat' }}</dd>
        </div>
        <div>
          <dt>Disusun</dt>
          <dd>{{ resume.disusun_oleh?.nama ?? 'sistem' }}, {{ formatTanggalWaktu(resume.disusun_pada) }}</dd>
        </div>
        <div v-if="resume.status === 'sah'">
          <dt>Disahkan</dt>
          <dd><Ikon nama="centang" :ukuran="14" /> {{ resume.disahkan_oleh?.nama ?? 'DPJP' }}, {{ formatTanggalWaktu(resume.disahkan_pada) }}</dd>
        </div>
      </dl>
      <Pesan v-if="resume.status === 'sah'" jenis="ok">Resume sudah sah dan final. Perbaikan lewat adendum belum tersedia.</Pesan>

      <section class="sumber" aria-label="Potret sumber resume">
        <h3>Potret sumber <span class="ket">per {{ formatTanggalWaktu(resume.sumber.dipotret_pada) }}</span></h3>
        <Pesan v-if="resume.sumber.terpotong" jenis="peringatan">Salah satu sumber berisi lebih dari 500 butir; hanya 500 pertama yang ikut.</Pesan>
        <div class="kelompok">
          <h4>Diagnosis</h4>
          <p v-if="!resume.sumber.diagnosis.length" class="ket">Belum ada diagnosis aktif di episode ini.</p>
          <ul v-else>
            <li v-for="d in resume.sumber.diagnosis" :key="`${d.kunjungan_id}-${d.kode}-${d.jenis}`">
              <code>{{ d.kode }}</code> {{ d.tampilan ?? '' }} <span class="ket">({{ LABEL_JENIS_DIAGNOSIS[d.jenis] ?? d.jenis }})</span>
            </li>
          </ul>
        </div>
        <div class="kelompok">
          <h4>CPPT</h4>
          <p v-if="!resume.sumber.cppt.length" class="ket">Belum ada CPPT sah tanpa label di episode ini.</p>
          <ol v-else class="cppt">
            <li v-for="c in resume.sumber.cppt" :key="c.id">
              <span class="ket">{{ c.penulis }} ({{ c.profesi }}), sah {{ formatTanggalWaktu(c.disahkan_pada) ?? '-' }}</span>
              <p v-for="[huruf, teks] in soap(c)" :key="huruf"><b>{{ huruf }}:</b> {{ teks }}</p>
            </li>
          </ol>
          <p v-if="resume.sumber.cppt_berlabel > 0" class="ket"><Ikon nama="gembok" :ukuran="12" /> {{ resume.sumber.cppt_berlabel }} CPPT berlabel sensitivitas tidak ikut dipotret.</p>
        </div>
        <div class="kelompok">
          <h4>Resep</h4>
          <p v-if="!resume.sumber.resep.length" class="ket">Belum ada resep sah di episode ini.</p>
          <ul v-else>
            <li v-for="r in resume.sumber.resep" :key="r.id">
              <code>{{ r.no_resep }}</code> <span class="ket">sah {{ formatTanggalWaktu(r.disahkan_pada) ?? '-' }}</span>
              <ul class="butir-resep">
                <li v-for="(b, i) in r.butir" :key="i">{{ teksButirResep(b) }}</li>
              </ul>
            </li>
          </ul>
        </div>
        <div class="kelompok">
          <h4>Alergi</h4>
          <p v-if="!resume.sumber.alergi.length" class="ket">Tidak ada alergi aktif tercatat.</p>
          <ul v-else>
            <li v-for="(a, i) in resume.sumber.alergi" :key="i">
              <b>{{ a.zat }}</b> <span class="ket">({{ [a.jenis, a.reaksi, a.tingkat].filter(Boolean).join(', ') }})</span>
            </li>
          </ul>
        </div>
        <div class="kelompok">
          <h4>Tindakan</h4>
          <p class="ket">Belum tersedia: modul tindakan menyusul.</p>
        </div>
      </section>

      <form v-if="bisaSusun" class="isian-dpjp" novalidate @submit.prevent="susun">
        <h3>Isian DPJP</h3>
        <KolomIsian v-for="k in KOLOM_ISIAN_RESUME" :key="k.kunci" v-slot="a" :label="k.label" :petunjuk="`${k.petunjuk} Maksimal 4.000 karakter.`">
          <textarea v-bind="a" v-model="isian[k.kunci]" class="isian" rows="3" maxlength="4000"></textarea>
        </KolomIsian>
        <p class="ket">Setiap simpan memotret ulang sumber, jadi catatan yang baru disahkan ikut masuk.</p>
        <div class="aksi">
          <Tombol type="submit" :sibuk="menyusun">{{ adaPerubahan ? 'Simpan isian dan susun ulang' : 'Susun ulang dari sumber terbaru' }}</Tombol>
          <span v-if="bisaSahkan && adaPerubahan" class="ket">Simpan isian dulu agar yang disahkan sama dengan yang Anda baca.</span>
          <Tombol v-if="bisaSahkan" varian="utama" :disabled="adaPerubahan || menyusun || undefined" @click="mintaSahkan">Sahkan dengan PIN</Tombol>
          <span v-else-if="izinSahkan && bukanDpjp" class="ket">Disahkan oleh DPJP, {{ resume.dpjp?.nama }}, dengan akunnya sendiri.</span>
        </div>
      </form>
      <template v-else>
        <dl class="isian-baca">
          <div v-for="k in KOLOM_ISIAN_RESUME" :key="k.kunci">
            <dt>{{ k.label }}</dt>
            <dd>{{ resume.isian_dpjp[k.kunci] || 'Tidak diisi' }}</dd>
          </div>
        </dl>
        <div v-if="bisaSahkan" class="aksi">
          <Tombol varian="utama" @click="mintaSahkan">Sahkan dengan PIN</Tombol>
        </div>
        <p v-else-if="izinSahkan && bukanDpjp" class="ket">Disahkan oleh DPJP, {{ resume.dpjp?.nama }}, dengan akunnya sendiri.</p>
      </template>
    </div>

    <div v-if="pesan" class="kartu-isi" role="alert">
      <Pesan jenis="error">{{ pesan }}</Pesan>
    </div>
    <TtdModal v-model:open="dialogTtd" :objek="objekTtd" :pengguna="pengguna" :penanda="penanda" :sesi-berlaku-sampai="sesi.pengguna?.ttd?.sesi_berlaku_sampai ?? null" :tandatangani="tandatangani" />
  </section>
</template>

<style scoped>
.isi {
  display: grid;
  gap: var(--space-4);
}
.ringkas {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3) var(--space-6);
  margin: 0;
}
.ringkas dt,
.isian-baca dt {
  font-size: 12px;
  color: var(--muted);
}
.ringkas dd {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
h3 {
  margin: 0 0 var(--space-2);
  font-size: 15px;
}
h4 {
  margin: 0 0 var(--space-1);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
/* Potret sumber dibaca, bukan diisi: latar --bg membedakannya dari isian DPJP di bawahnya. */
.sumber {
  display: grid;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  background: var(--bg);
}
.kelompok ul,
.kelompok ol {
  display: grid;
  gap: var(--space-1);
  margin: 0;
  padding-left: var(--space-4);
}
.cppt p {
  margin: 0;
}
.butir-resep {
  margin-top: 2px;
  font-size: 13px;
}
.ket {
  font-size: 12.5px;
  font-weight: normal;
  color: var(--muted);
}
code {
  font-family: var(--font-mono);
  font-size: 12.5px;
}
.isian-dpjp {
  display: grid;
  gap: var(--space-3);
}
.isian-baca {
  display: grid;
  gap: var(--space-3);
  margin: 0;
}
.isian-baca dd {
  margin: 0;
  white-space: pre-wrap;
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: var(--space-3);
}
</style>
