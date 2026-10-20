<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilPenyiapan, konfirmasiLangkah } from '../api'
import DialogLewati from './DialogLewati.vue'
import DialogMuatPaket from './DialogMuatPaket.vue'
import { KET_LANGKAH, KUNCI_PENYIAPAN, LABEL_SISTEM, LABEL_STATUS, PIL_STATUS, TAUTAN_LANGKAH } from '../penyiapan'
import type { HasilMuatReferensi, LangkahPenyiapan, SistemReferensi, StatusPenyiapan } from '../tipe'

/**
 * Isi Pengaturan → Penyiapan instalasi (tiket 1.8f fe 1, FR-54-08): sembilan langkah berurutan dengan status dan syarat
 * yang dihitung server, tautan ke layar pengisi datanya (bukan formulir duplikat), konfirmasi per langkah (bisa
 * diulang, tercatat audit), lewati langkah 8 dengan alasan, dan unggah paket referensi langkah 4. Tombol mengikuti
 * `dapat_*` dari server.
 */
const qc = useQueryClient()
const kueri = useQuery({ queryKey: KUNCI_PENYIAPAN.status, queryFn: ambilPenyiapan, staleTime: 0 })
const data = computed(() => kueri.data.value ?? null)
const bolehMuat = computed(() => boleh('master_data.katalog.input'))
const tuntas = computed(() => data.value?.langkah.filter((l) => l.status === 'selesai' || l.status === 'dilewati').length ?? 0)
const perluDikerjakan = computed(() => data.value?.langkah.filter((l) => l.status !== 'menunggu_fase_2').length ?? 0)

function perbarui(s: StatusPenyiapan) {
  qc.setQueryData(KUNCI_PENYIAPAN.status, s)
}

const sibuk = ref<string | null>(null)
async function konfirmasi(l: LangkahPenyiapan) {
  sibuk.value = l.kode
  try {
    perbarui(await konfirmasiLangkah(l.kode))
    tampilkanToast(`Langkah ${l.no} dikonfirmasi selesai.`)
  } catch (err) {
    tampilkanToast(pesanError(err), 'error')
    void kueri.refetch()
  } finally {
    sibuk.value = null
  }
}

const lewati = ref<LangkahPenyiapan | null>(null)
const bukaLewati = ref(false)
function mulaiLewati(l: LangkahPenyiapan) {
  lewati.value = l
  bukaLewati.value = true
}
function selesaiLewati(s: StatusPenyiapan) {
  perbarui(s)
  tampilkanToast(`Langkah ${lewati.value?.no ?? ''} ditandai dilewati.`)
}

const sistemMuat = ref<SistemReferensi>('wilayah')
const bukaMuat = ref(false)
function mulaiMuat(s: SistemReferensi) {
  sistemMuat.value = s
  bukaMuat.value = true
}
function selesaiMuat(h: HasilMuatReferensi) {
  tampilkanToast(`${LABEL_SISTEM[h.sistem]} versi ${h.versi} dimuat: ${h.jumlah_baru} baru, ${h.jumlah_diubah} diubah, ${h.jumlah_dinonaktifkan} dinonaktifkan.`)
  void kueri.refetch()
}
</script>

<template>
  <div class="daftar-langkah">
    <div v-if="!data && kueri.isPending.value" class="kartu kartu-isi" role="status">Memuat status penyiapan…</div>
    <div v-else-if="!data" class="kartu kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else>
      <section class="kartu ringkasan" aria-labelledby="judul-ringkasan">
        <div class="kartu-isi">
          <h2 id="judul-ringkasan">{{ tuntas }} dari {{ perluDikerjakan }} langkah tuntas</h2>
          <p v-if="data.siap_melayani" class="catatan catatan-info">
            <Ikon nama="centang" :ukuran="16" />
            <span>Instalasi siap melayani pasien sejak {{ formatTanggalWaktu(data.siap_melayani_sejak!) }}.</span>
          </p>
          <p v-else class="catatan catatan-peringatan">
            <Ikon nama="peringatan" :ukuran="16" />
            <span>Instalasi belum bisa melayani pasien sampai langkah 1–6 selesai. Langkah 7 (formularium) diisi bersama modul farmasi.</span>
          </p>
        </div>
      </section>

      <ol class="langkah" aria-label="Langkah penyiapan">
        <li v-for="l in data.langkah" :key="l.kode" class="kartu" :aria-labelledby="`judul-langkah-${l.kode}`">
          <div class="kartu-kepala">
            <div class="kepala-kiri">
              <span class="no" aria-hidden="true">{{ l.no }}</span>
              <h2 :id="`judul-langkah-${l.kode}`"><span class="sr-only">Langkah {{ l.no }}: </span>{{ l.judul }}</h2>
            </div>
            <span class="pil" :class="PIL_STATUS[l.status]">{{ LABEL_STATUS[l.status] }}</span>
          </div>
          <div class="kartu-isi">
            <ul v-if="l.syarat.length" class="syarat" :aria-label="`Syarat langkah ${l.no}`">
              <li v-for="s in l.syarat" :key="s.kode" :class="{ terpenuhi: s.terpenuhi }">
                <Ikon :nama="s.terpenuhi ? 'centang' : 'tutup'" :ukuran="14" />
                <span class="sr-only">{{ s.terpenuhi ? 'Terpenuhi:' : 'Belum terpenuhi:' }}</span>
                <span>{{ s.label }}</span>
                <span v-if="s.keterangan" class="ket">· {{ s.keterangan }}</span>
              </li>
            </ul>
            <p v-if="KET_LANGKAH[l.kode]" class="ket-langkah">{{ KET_LANGKAH[l.kode] }}</p>

            <div v-if="l.paket" class="paket" role="region" :aria-label="`Paket referensi`" tabindex="0">
              <table>
                <thead>
                  <tr>
                    <th scope="col">Paket</th>
                    <th scope="col">Versi termuat</th>
                    <th scope="col">Kode aktif</th>
                    <th v-if="bolehMuat" scope="col"><span class="sr-only">Aksi</span></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="p in l.paket" :key="p.sistem">
                    <th scope="row">
                      {{ LABEL_SISTEM[p.sistem] }}
                      <span v-if="!p.wajib" class="ket">Opsional · berkas dari RS</span>
                    </th>
                    <td>
                      <template v-if="p.versi">
                        <code>{{ p.versi }}</code>
                        <span class="ket">{{ p.sumber }} · {{ formatTanggalWaktu(p.dimuat_pada!) }}</span>
                      </template>
                      <span v-else class="ket">{{ p.wajib ? 'Belum dimuat' : 'Menunggu berkas RS' }}</span>
                    </td>
                    <td>{{ p.jumlah_kode_aktif.toLocaleString('id-ID') }}</td>
                    <td v-if="bolehMuat">
                      <button type="button" class="tombol" :aria-label="`Muat paket ${LABEL_SISTEM[p.sistem]}`" @click="mulaiMuat(p.sistem)">Muat</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <p v-if="l.konfirmasi_terakhir" class="konfirmasi">
              {{ l.konfirmasi_terakhir.dilewati ? 'Dilewati' : 'Dikonfirmasi' }} oleh {{ l.konfirmasi_terakhir.oleh?.nama ?? 'sistem' }},
              {{ formatTanggalWaktu(l.konfirmasi_terakhir.pada) }}<template v-if="l.konfirmasi_terakhir.alasan"> · “{{ l.konfirmasi_terakhir.alasan }}”</template>
              <template v-if="l.status === 'sebagian'"> · syarat kini belum lengkap</template>
            </p>

            <div v-if="TAUTAN_LANGKAH[l.kode]?.length || l.dapat_dikonfirmasi || l.dapat_dilewati" class="aksi">
              <RouterLink v-for="t in TAUTAN_LANGKAH[l.kode] ?? []" :key="t.rute" :to="t.rute" class="tombol">{{ t.label }}</RouterLink>
              <button v-if="l.dapat_dilewati" type="button" class="tombol" @click="mulaiLewati(l)">Lewati</button>
              <button v-if="l.dapat_dikonfirmasi" type="button" class="tombol tombol-utama" :disabled="sibuk !== null" @click="konfirmasi(l)">
                {{ sibuk === l.kode ? 'Mengonfirmasi…' : l.status === 'selesai' ? 'Konfirmasi ulang' : 'Konfirmasi selesai' }}
              </button>
            </div>
          </div>
        </li>
      </ol>
    </template>
    <DialogLewati v-if="lewati" v-model:open="bukaLewati" :langkah="lewati" @selesai="selesaiLewati" />
    <DialogMuatPaket v-model:open="bukaMuat" :sistem="sistemMuat" @selesai="selesaiMuat" />
  </div>
</template>

<style scoped>
.ringkasan h2 {
  margin: 0 0 var(--space-2);
  font-size: 16px;
}
.ringkasan .catatan {
  margin: 0;
}
.langkah {
  display: grid;
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
  padding: 0;
  list-style: none;
}
.kepala-kiri {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  min-width: 0;
}
.kepala-kiri h2 {
  margin: 0;
  font-size: 15px;
}
.no {
  display: inline-grid;
  flex: none;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: var(--brand-25);
  color: var(--brand-700);
  font-weight: var(--font-weight-semibold);
}
.syarat {
  display: grid;
  gap: 4px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 13px;
}
.syarat li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  color: var(--muted);
}
.syarat li.terpenuhi {
  color: var(--text);
}
.syarat li.terpenuhi :deep(svg) {
  color: var(--success);
}
.ket,
.ket-langkah,
.konfirmasi {
  color: var(--muted);
  font-size: 12.5px;
}
.ket-langkah,
.konfirmasi {
  margin: var(--space-2) 0 0;
}
td .ket,
th .ket {
  display: block;
}
.paket {
  margin-top: var(--space-3);
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.paket table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.paket th,
.paket td {
  padding: 6px var(--space-2);
  border-bottom: 1px solid var(--border);
  text-align: left;
  vertical-align: top;
}
.paket thead th {
  background: var(--bg);
  font-weight: var(--font-weight-semibold);
}
.paket tbody th {
  font-weight: var(--font-weight-medium);
}
.paket code {
  font-family: var(--font-mono);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
  margin-top: var(--space-3);
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .aksi .tombol {
    min-height: 44px;
  }
}
</style>
