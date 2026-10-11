<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import {
  aksiKhusus,
  aturAksi,
  aturTingkat,
  kodeAksi,
  LABEL_AKSI,
  LABEL_TINGKAT,
  menuTampil,
  pilihanTingkat,
  terkunciInduk,
  tingkatTampil,
  type BarisMatriks,
  type IndeksMatriks,
  type Tingkat,
} from '../../matriks'
import type { NodeMenu } from '../../tipePengaturan'

/**
 * Matriks satu peran (PRD 9.3): baris = pohon menu yang bisa dilipat, kolom = tingkat (jalan pintas) + aksi dasar
 * + aksi khusus yang terdaftar di node. Bagian nonaktif selama sub-menu induknya belum diberi lihat (MD-3).
 * Gembok = izin sensitif (menambahnya butuh persetujuan pemilik data, tiket 1.2d).
 */
const props = defineProps<{
  indeks: IndeksMatriks
  /** Himpunan izin yang sedang disunting. */
  izin: ReadonlySet<string>
  /** Izin tersimpan di server, untuk penanda "diubah". */
  tersimpan: readonly string[]
  bacaSaja: boolean
  /** Kode izin/menu yang ditolak server (422/409), disorot. */
  sorot: ReadonlySet<string>
}>()
const emit = defineEmits<{ 'update:izin': [izin: Set<string>] }>()

const DASAR = ['lihat', 'input', 'perbaiki'] as const
const asal = computed(() => new Set(props.tersimpan))

// Lipatan: menu yang sudah punya izin dibuka, sisanya dilipat agar pohon 100+ baris tetap terbaca.
const terbuka = ref(new Set<string>())
function bukaAwal() {
  terbuka.value = new Set(
    props.indeks.baris.filter((b) => b.node.anak.length && (props.indeks.turunan.get(b.node.kode) ?? []).some((k) => props.izin.has(k))).map((b) => b.node.kode),
  )
}
watch(() => props.tersimpan, bukaAwal, { immediate: true })
watch(
  () => props.sorot,
  (sorot) => {
    // Node yang ditolak server selalu terlihat.
    for (const kode of sorot) {
      const b = props.indeks.perKode.get(kode)?.baris ?? props.indeks.perNode.get(kode)
      b?.leluhur.forEach((n) => terbuka.value.add(n.kode))
    }
  },
)

const cari = ref('')
const cocokCari = computed(() => {
  const q = cari.value.trim().toLowerCase()
  if (!q) return null
  const hasil = new Set<string>()
  for (const b of props.indeks.baris) {
    if (b.node.label.toLowerCase().includes(q) || b.node.kode.includes(q)) {
      hasil.add(b.node.kode)
      b.leluhur.forEach((n) => hasil.add(n.kode))
    }
  }
  return hasil
})

const tampil = computed(() =>
  props.indeks.baris.filter((b) => (cocokCari.value ? cocokCari.value.has(b.node.kode) : b.leluhur.every((n) => terbuka.value.has(n.kode)))),
)

/** Baris grup disisipkan sebelum menu pertama setiap grup. */
const barisDenganGrup = computed(() => {
  const hasil: ({ jenis: 'grup'; kode: string; label: string } | { jenis: 'node'; b: BarisMatriks })[] = []
  let grup = ''
  for (const b of tampil.value) {
    if (b.grup.kode !== grup) {
      grup = b.grup.kode
      if (b.grup.label) hasil.push({ jenis: 'grup', kode: b.grup.kode, label: b.grup.label })
    }
    hasil.push({ jenis: 'node', b })
  }
  return hasil
})

function lipat(node: NodeMenu) {
  const s = new Set(terbuka.value)
  if (s.has(node.kode)) s.delete(node.kode)
  else s.add(node.kode)
  terbuka.value = s
}
function semua(buka: boolean) {
  terbuka.value = buka ? new Set(props.indeks.baris.filter((b) => b.node.anak.length).map((b) => b.node.kode)) : new Set()
}

function ubahTingkat(node: NodeMenu, tingkat: Tingkat) {
  emit('update:izin', aturTingkat(props.indeks, node, props.izin, tingkat))
}
function ubahAksi(node: NodeMenu, kode: string, nyala: boolean) {
  emit('update:izin', aturAksi(props.indeks, node, kode, nyala, props.izin))
}

const pekaAksi = (node: NodeMenu, aksi: (typeof DASAR)[number]) => node.izin.some((i) => i.jenis_aksi === aksi && i.sensitif)
/** Memilih tingkat ini menambah izin sensitif (semua aksi dasar sampai tingkat itu). */
const tingkatPeka = (node: NodeMenu, t: Tingkat) => t !== 'tidak' && DASAR.slice(0, DASAR.indexOf(t) + 1).some((a) => pekaAksi(node, a))
const berubah = (b: BarisMatriks) => b.node.izin.some((i) => props.izin.has(i.kode) !== asal.value.has(i.kode))
const disorot = (b: BarisMatriks) => props.sorot.has(b.node.kode) || b.node.izin.some((i) => props.sorot.has(i.kode))
const idKunci = (b: BarisMatriks) => `kunci-${b.node.id}`
const JENIS: Record<NodeMenu['tingkat'], string> = { menu: 'menu', sub_menu: 'sub-menu', bagian: 'bagian' }
</script>

<template>
  <div class="matriks">
    <div class="alat">
      <label class="cari">
        <span class="sr-only">Cari menu di matriks</span>
        <Ikon nama="cari" :ukuran="16" />
        <input v-model="cari" type="search" class="isian" placeholder="Cari menu atau kode" />
      </label>
      <button type="button" class="tombol" @click="semua(true)">Buka semua</button>
      <button type="button" class="tombol" @click="semua(false)">Lipat semua</button>
    </div>

    <div class="tbl-wrap" tabindex="0" role="region" aria-label="Matriks izin peran">
      <table class="tbl">
        <thead>
          <tr>
            <th scope="col" class="kol-node">Menu → sub-menu → bagian</th>
            <th scope="col" class="kol-tingkat">Tingkat</th>
            <th v-for="a in DASAR" :key="a" scope="col" class="kol-aksi">{{ LABEL_AKSI[a] }}</th>
            <th scope="col" class="kol-khusus">Aksi khusus</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="r in barisDenganGrup" :key="r.jenis === 'grup' ? `g-${r.kode}` : r.b.node.id">
            <tr v-if="r.jenis === 'grup'" class="baris-grup">
              <th colspan="6" scope="colgroup">{{ r.label }}</th>
            </tr>
            <tr v-else :class="{ 'baris-menu': r.b.kedalaman === 0, salah: disorot(r.b), dikunci: terkunciInduk(r.b, izin) }">
              <th scope="row" class="kol-node" :style="{ '--kedalaman': r.b.kedalaman }">
                <div class="node">
                  <button
                    v-if="r.b.node.anak.length && !cocokCari"
                    type="button"
                    class="lipat"
                    :aria-expanded="terbuka.has(r.b.node.kode)"
                    :aria-label="`${terbuka.has(r.b.node.kode) ? 'Lipat' : 'Buka'} ${r.b.node.label}`"
                    @click="lipat(r.b.node)"
                  >
                    <Ikon :nama="terbuka.has(r.b.node.kode) ? 'panah_bawah' : 'panah_kanan'" :ukuran="16" />
                  </button>
                  <span v-else class="lipat-kosong" />
                  <span class="label-node">{{ r.b.node.label }}</span>
                  <span class="pil pil-netral jenis">{{ JENIS[r.b.node.tingkat] }}</span>
                  <span v-if="!r.b.node.modul_aktif && r.b.kedalaman === 0" class="pil pil-peringatan">Modul nonaktif</span>
                  <span v-else-if="!r.b.node.tersedia && r.b.kedalaman === 0" class="pil pil-netral">Belum dibangun</span>
                  <span v-if="berubah(r.b)" class="pil pil-info">Diubah</span>
                  <span v-if="disorot(r.b)" class="pil pil-bahaya">
                    <Ikon nama="peringatan" :ukuran="12" />
                    Ditolak
                  </span>
                </div>
                <p v-if="terkunciInduk(r.b, izin)" :id="idKunci(r.b)" class="ket-kunci">Beri <b>lihat</b> pada {{ r.b.subInduk?.label }} dulu.</p>
              </th>

              <!-- Menu tanpa izin sendiri: tampil bila salah satu turunannya boleh dilihat. -->
              <td v-if="r.b.node.izin.length === 0" colspan="5" class="turunan">
                <template v-if="r.b.node.ikut_induk">Ikut induk</template>
                <template v-else-if="menuTampil(indeks, r.b.node, izin)">Tampil di sidebar</template>
                <template v-else>—</template>
              </td>
              <template v-else>
                <td class="kol-tingkat">
                  <select
                    v-if="pilihanTingkat(r.b.node).length"
                    class="isian pilih-tingkat"
                    :value="tingkatTampil(r.b.node, izin)"
                    :disabled="bacaSaja || terkunciInduk(r.b, izin)"
                    :aria-label="`Tingkat ${r.b.jalur}`"
                    :aria-describedby="terkunciInduk(r.b, izin) ? idKunci(r.b) : undefined"
                    @change="ubahTingkat(r.b.node, ($event.target as HTMLSelectElement).value as Tingkat)"
                  >
                    <option v-for="t in pilihanTingkat(r.b.node)" :key="t" :value="t">{{ LABEL_TINGKAT[t] }}{{ tingkatPeka(r.b.node, t) ? ' (sensitif)' : '' }}</option>
                    <option v-if="tingkatTampil(r.b.node, izin) === 'campuran'" value="campuran" disabled>Campuran</option>
                  </select>
                </td>
                <td v-for="a in DASAR" :key="a" class="kol-aksi">
                  <label v-if="kodeAksi(r.b.node, a)" class="sel" :class="{ berubah: izin.has(kodeAksi(r.b.node, a)!) !== asal.has(kodeAksi(r.b.node, a)!) }">
                    <input
                      type="checkbox"
                      :checked="izin.has(kodeAksi(r.b.node, a)!)"
                      :disabled="bacaSaja || terkunciInduk(r.b, izin)"
                      :aria-describedby="terkunciInduk(r.b, izin) ? idKunci(r.b) : undefined"
                      @change="ubahAksi(r.b.node, kodeAksi(r.b.node, a)!, ($event.target as HTMLInputElement).checked)"
                    />
                    <Ikon v-if="pekaAksi(r.b.node, a)" nama="gembok" :ukuran="12" class="gembok" />
                    <span class="sr-only">{{ LABEL_AKSI[a] }} {{ r.b.jalur }}{{ pekaAksi(r.b.node, a) ? ' (sensitif)' : '' }}</span>
                  </label>
                </td>
                <td>
                  <div class="khusus">
                    <label v-for="i in aksiKhusus(r.b.node)" :key="i.kode" class="cip" :class="{ berubah: izin.has(i.kode) !== asal.has(i.kode), peka: i.sensitif }">
                      <input
                        type="checkbox"
                        :checked="izin.has(i.kode)"
                        :disabled="bacaSaja || terkunciInduk(r.b, izin)"
                        @change="ubahAksi(r.b.node, i.kode, ($event.target as HTMLInputElement).checked)"
                      />
                      <Ikon v-if="i.sensitif" nama="gembok" :ukuran="12" />
                      {{ LABEL_AKSI[i.jenis_aksi] }}<span class="sr-only"> {{ r.b.jalur }}{{ i.sensitif ? ' (sensitif)' : '' }}</span>
                    </label>
                  </div>
                </td>
              </template>
            </tr>
          </template>
          <tr v-if="tampil.length === 0">
            <td colspan="6" class="kosong">Tidak ada menu yang cocok dengan "{{ cari }}".</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.alat {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border);
}
.cari {
  position: relative;
  flex: 1 1 220px;
  max-width: 320px;
}
.cari svg {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--subtle);
}
.cari .isian {
  padding-left: 32px;
}
.tbl-wrap {
  overflow: auto;
  max-height: min(70vh, 900px);
}
.tbl-wrap:focus-visible {
  outline: none;
  box-shadow: inset var(--ring);
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-table);
}
.tbl thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 10px var(--space-3);
  border-bottom: 1px solid var(--border);
  background: var(--bg);
  color: var(--muted);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  text-align: left;
  white-space: nowrap;
}
.tbl td,
.tbl tbody th {
  padding: 6px var(--space-3);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
  text-align: left;
  font-weight: var(--font-weight-regular);
}
.baris-grup th {
  padding: var(--space-2) var(--space-4);
  background: var(--bg);
  color: var(--subtle);
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.baris-menu .label-node {
  font-weight: var(--font-weight-semibold);
}
tr.salah {
  background: var(--danger-bg);
}
tr.dikunci td {
  color: var(--subtle);
}
.kol-node {
  min-width: 260px;
  padding-left: calc(var(--space-3) + var(--kedalaman, 0) * 20px) !important;
}
.kol-tingkat {
  width: 124px;
}
.pilih-tingkat {
  min-width: 108px;
}
.kol-khusus {
  min-width: 180px;
}
.kol-aksi {
  width: 64px;
  text-align: center !important;
}
.node {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.lipat,
.lipat-kosong {
  flex: none;
  width: 28px;
  height: 28px;
}
.lipat {
  display: grid;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: none;
  color: var(--muted);
  cursor: pointer;
}
.lipat:hover {
  background: var(--bg);
}
.lipat:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.ket-kunci {
  margin: 2px 0 0 34px;
  font-size: 12px;
  color: var(--muted);
}
.turunan {
  color: var(--muted);
  font-size: 12.5px;
}
.pilih-tingkat {
  min-height: 32px;
  padding: 0 6px;
  font-size: 12.5px;
}
.sel {
  display: inline-grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 6px;
  cursor: pointer;
}
.sel input,
.cip input {
  width: 18px;
  height: 18px;
  margin: 0;
  accent-color: var(--brand-600);
  cursor: pointer;
}
.sel input:disabled,
.cip input:disabled {
  cursor: not-allowed;
}
.sel {
  position: relative;
}
.gembok {
  position: absolute;
  right: 0;
  top: 0;
  color: var(--warn);
}
.sel.berubah,
.cip.berubah {
  outline: 2px dashed var(--info);
  outline-offset: -2px;
}
.khusus {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
}
.cip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 30px;
  padding: 2px var(--space-2);
  border: 1px solid var(--border);
  border-radius: 999px;
  font-size: 12px;
  cursor: pointer;
}
.cip.peka svg {
  color: var(--warn);
}
.kosong {
  padding: var(--space-6) !important;
  text-align: center !important;
  color: var(--muted);
}

@media (min-width: 768px) and (max-width: 1279.98px) {
  .lipat,
  .lipat-kosong,
  .sel {
    width: 44px;
    height: 44px;
  }
  .pilih-tingkat {
    min-height: 44px;
  }
  .cip {
    min-height: 44px;
  }
  .kol-node {
    min-width: 220px;
  }
  /* Indentasi sudah menunjukkan tingkat node; label jenis disembunyikan agar baris tidak terlipat. */
  .jenis {
    display: none;
  }
}
</style>
