<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import Tombol from './Tombol.vue'

/**
 * Formulir panjang yang dibagi per langkah (DESIGN.md: form panjang dibagi per bagian; wizard penyiapan 1.8f).
 * Penanda langkah = daftar berurutan dengan aria-current="step" dan status teks (selesai/sekarang/belum).
 * `sebelumLanjut` (opsional) memvalidasi langkah aktif; false = tetap di langkah ini. Fokus pindah ke judul langkah
 * baru agar pembaca layar dan papan ketik mengikuti. Isi tiap langkah lewat slot bernama sesuai `kode`.
 */
interface Langkah {
  kode: string
  label: string
}
const props = withDefaults(defineProps<{ langkah: Langkah[]; label: string; sibuk?: boolean; sebelumLanjut?: (kode: string) => boolean | Promise<boolean>; labelSelesai?: string }>(), {
  sibuk: false,
  sebelumLanjut: undefined,
  labelSelesai: 'Selesai',
})
const aktif = defineModel<string>({ required: true })
const emit = defineEmits<{ selesai: [] }>()

const id = `bertahap-${useId()}`
const judul = ref<HTMLElement | null>(null)
const memeriksa = ref(false)
const i = computed(() => Math.max(0, props.langkah.findIndex((l) => l.kode === aktif.value)))
const terakhir = computed(() => i.value === props.langkah.length - 1)

watch(aktif, async () => {
  await nextTick()
  judul.value?.focus()
})

async function lanjut() {
  if (props.sebelumLanjut) {
    const hasil = props.sebelumLanjut(aktif.value)
    // Validasi sinkron tidak menampilkan keadaan sibuk (tombol tidak berkedip).
    if (hasil instanceof Promise) {
      memeriksa.value = true
      try {
        if (!(await hasil)) return
      } finally {
        memeriksa.value = false
      }
    } else if (!hasil) return
  }
  if (terakhir.value) emit('selesai')
  else aktif.value = props.langkah[i.value + 1]!.kode
}
function kembali() {
  if (i.value > 0) aktif.value = props.langkah[i.value - 1]!.kode
}
const status = (n: number) => (n < i.value ? 'selesai' : n === i.value ? 'sekarang' : 'belum')
</script>

<template>
  <section class="bertahap" :aria-labelledby="`${id}-judul`">
    <ol class="penanda" :aria-label="label">
      <li v-for="(l, n) in langkah" :key="l.kode" :class="`langkah-${status(n)}`" :aria-current="n === i ? 'step' : undefined">
        <span class="nomor" aria-hidden="true">
          <Ikon v-if="status(n) === 'selesai'" nama="centang" :ukuran="14" />
          <template v-else>{{ n + 1 }}</template>
        </span>
        <span class="nama">{{ l.label }}</span>
        <span class="sr-only">{{ status(n) === 'selesai' ? ' (selesai)' : status(n) === 'sekarang' ? ' (langkah sekarang)' : ' (belum)' }}</span>
      </li>
    </ol>
    <h2 :id="`${id}-judul`" ref="judul" class="judul" tabindex="-1">Langkah {{ i + 1 }} dari {{ langkah.length }}: {{ langkah[i]?.label }}</h2>
    <div class="isi">
      <slot :name="aktif" />
    </div>
    <div class="aksi">
      <Tombol :disabled="i === 0 || sibuk || memeriksa" @click="kembali">Kembali</Tombol>
      <Tombol varian="utama" :sibuk="sibuk || memeriksa" :disabled="sibuk || memeriksa" @click="lanjut">{{ terakhir ? labelSelesai : 'Lanjut' }}</Tombol>
    </div>
  </section>
</template>

<style scoped>
.penanda {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2) var(--space-4);
  margin: 0 0 var(--space-4);
  padding: 0;
  list-style: none;
  font-size: 13px;
}
.penanda li {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--muted);
}
.nomor {
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border: 1px solid var(--border-input);
  border-radius: 50%;
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
}
.langkah-sekarang {
  color: var(--text);
  font-weight: var(--font-weight-semibold);
}
.langkah-sekarang .nomor {
  border-color: var(--brand-600);
  background: var(--brand-600);
  color: var(--surface);
}
.langkah-selesai .nomor {
  border-color: var(--success);
  background: var(--success-bg);
  color: var(--success);
}
.judul {
  margin: 0 0 var(--space-3);
  font-size: 16px;
}
.judul:focus {
  outline: none;
}
.isi {
  margin-bottom: var(--space-4);
}
.aksi {
  display: flex;
  justify-content: space-between;
  gap: var(--space-2);
  padding-top: var(--space-3);
  border-top: 1px solid var(--border);
}
</style>
