<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { semuaKelasRawatAktif } from '@/modules/organisasi/apiLokasi'
import { KUNCI_LOKASI } from '@/modules/organisasi/lokasi'
import { formatRupiah, jumlahKomponen } from '../tarif'
import type { IsianHargaTarif } from '../tipe'

/**
 * Baris harga per kelas rawat (jasa sarana, jasa pelayanan, BHP) dengan pratinjau total. Daftar ini mengganti seluruh
 * harga tarif saat usulan berlaku (kontrak v0.18.0). Aturan kelas unik dan angka diperiksa `periksaHarga` oleh pemanggil.
 */
const baris = defineModel<IsianHargaTarif[]>({ required: true })
defineProps<{ galat?: string }>()
const kelas = useQuery({ queryKey: KUNCI_LOKASI.kelasAktif, queryFn: semuaKelasRawatAktif, staleTime: 60_000 })

function tambah() {
  baris.value = [...baris.value, { kelas_rawat_id: '', jasa_sarana: '', jasa_pelayanan: '', bhp: '' }]
}
function hapus(i: number) {
  baris.value = baris.value.filter((_, j) => j !== i)
}
function isi(i: number, k: keyof IsianHargaTarif, v: string) {
  baris.value = baris.value.map((b, j) => (j === i ? { ...b, [k]: v } : b))
}
const KOMPONEN = [['jasa_sarana', 'Jasa sarana'], ['jasa_pelayanan', 'Jasa pelayanan'], ['bhp', 'BHP']] as const
</script>

<template>
  <fieldset class="harga">
    <legend>Harga per kelas rawat (Rp)</legend>
    <p v-if="!baris.length" class="ket">Belum ada kelas. Tambahkan minimal satu kelas rawat.</p>
    <div v-for="(b, i) in baris" :key="i" class="kelas" role="group" :aria-label="`Harga baris ${i + 1}`">
      <label class="kolom">
        <span>Kelas rawat</span>
        <select class="isian" :value="b.kelas_rawat_id" @change="isi(i, 'kelas_rawat_id', ($event.target as HTMLSelectElement).value)">
          <option value="" disabled>Pilih</option>
          <option v-for="k in kelas.data.value ?? []" :key="k.id" :value="k.id">{{ k.kode }} · {{ k.nama }}</option>
        </select>
      </label>
      <label v-for="[k, label] in KOMPONEN" :key="k" class="kolom">
        <span>{{ label }}</span>
        <input class="isian angka" inputmode="decimal" autocomplete="off" :value="b[k]" @input="isi(i, k, ($event.target as HTMLInputElement).value)" />
      </label>
      <div class="kolom">
        <span>Total</span>
        <output class="total">{{ jumlahKomponen(b) ? formatRupiah(jumlahKomponen(b)!) : '—' }}</output>
      </div>
      <button type="button" class="tombol" :aria-label="`Hapus harga baris ${i + 1}`" @click="hapus(i)">Hapus</button>
    </div>
    <p v-if="galat" class="kolom-error" role="alert">{{ galat }}</p>
    <button type="button" class="tombol" @click="tambah"><Ikon nama="tambah" :ukuran="16" />Tambah kelas</button>
  </fieldset>
</template>

<style scoped>
.harga {
  margin: 0 0 var(--space-3);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
legend {
  padding: 0 var(--space-1);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.kelas {
  display: grid;
  grid-template-columns: minmax(130px, 1.2fr) repeat(3, minmax(90px, 1fr)) minmax(100px, 1fr) auto;
  align-items: end;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}
.kolom {
  display: grid;
  gap: var(--space-1);
  font-size: 12px;
  color: var(--muted);
}
.angka {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.total {
  display: flex;
  align-items: center;
  min-height: 32px;
  color: var(--text);
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}
.ket {
  margin: 0 0 var(--space-2);
  color: var(--muted);
  font-size: 12.5px;
}
@media (max-width: 1023.98px) {
  .kelas {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
