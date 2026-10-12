<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'
import { useRouter } from 'vue-router'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { daftarNotifikasi, tandaiDibaca, tandaiSemuaDibaca } from '../api'
import { KUNCI_NOTIF, waktuRelatif } from '../notifikasi'
import { useKueriBerPolling } from '../polling'
import type { HalamanNotifikasi, Notifikasi } from '../tipe'

/**
 * Lonceng di header (tiket 1.7 fe 1): jumlah belum dibaca dari polling 30 detik (ETag, permintaan latar), panel 10
 * notifikasi belum dibaca terbaru. Membuka satu notifikasi menandainya dibaca lalu membuka tautannya. "Tandai semua
 * dibaca" mengirim `sampai` = notifikasi terbaru yang tampil; notifikasi kritis tidak ikut (dibuka satu per satu).
 */
const qc = useQueryClient()
const router = useRouter()
const idPanel = `panel-notifikasi-${useId()}`
const buka = ref(false)
const tombol = ref<HTMLButtonElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const sibuk = ref(false)

const { kueri, segarkan } = useKueriBerPolling<HalamanNotifikasi>(KUNCI_NOTIF.lonceng, (p) => daftarNotifikasi({ status: 'belum_dibaca' }, null, 10, p))
const jumlah = computed(() => kueri.data.value?.meta.jumlah_belum_dibaca ?? 0)
const daftar = computed(() => kueri.data.value?.data ?? [])
const adaKritis = computed(() => daftar.value.some((n) => n.prioritas === 'kritis'))
const labelTombol = computed(() => (jumlah.value ? `Notifikasi, ${jumlah.value} belum dibaca` : 'Notifikasi'))

function segarkanSemua() {
  void segarkan()
  void qc.invalidateQueries({ queryKey: ['notifikasi', 'daftar'] })
}
async function alihkan() {
  buka.value = !buka.value
  if (buka.value) {
    segarkanSemua()
    await nextTick()
    panel.value?.querySelector<HTMLElement>('a, button')?.focus()
    document.addEventListener('pointerdown', klikLuar)
  } else tutup(false)
}
function tutup(fokuskan = true) {
  buka.value = false
  document.removeEventListener('pointerdown', klikLuar)
  if (fokuskan) tombol.value?.focus()
}
function klikLuar(e: PointerEvent) {
  const t = e.target as Node
  if (!panel.value?.contains(t) && !tombol.value?.contains(t)) tutup(false)
}
onBeforeUnmount(() => document.removeEventListener('pointerdown', klikLuar))

async function bukaNotifikasi(n: Notifikasi) {
  try {
    await tandaiDibaca(n.id)
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
    return
  }
  segarkanSemua()
  tutup(false)
  if (n.tautan) await router.push(n.tautan)
}
async function semuaDibaca() {
  sibuk.value = true
  try {
    const n = await tandaiSemuaDibaca(daftar.value[0]?.dibuat_pada ?? null)
    tampilkanToast(n ? `${n} notifikasi ditandai dibaca.` : 'Tidak ada notifikasi yang bisa ditandai sekaligus.')
    segarkanSemua()
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <div class="lonceng" @keydown.esc.stop="buka && tutup()">
    <button
      ref="tombol"
      type="button"
      class="tombol-ikon"
      :aria-label="labelTombol"
      :title="labelTombol"
      :aria-expanded="buka"
      :aria-controls="idPanel"
      @click="alihkan"
    >
      <Ikon nama="lonceng" />
      <span v-if="jumlah" class="lencana" aria-hidden="true">{{ jumlah > 99 ? '99+' : jumlah }}</span>
    </button>
    <section v-show="buka" :id="idPanel" ref="panel" class="panel" aria-labelledby="judul-lonceng">
      <div class="panel-kepala">
        <h2 id="judul-lonceng">Notifikasi</h2>
        <button v-if="jumlah" type="button" class="tombol tombol-tautan" :disabled="sibuk" @click="semuaDibaca">Tandai semua dibaca</button>
      </div>
      <p v-if="kueri.isPending.value" class="keadaan" role="status">Memuat…</p>
      <p v-else-if="kueri.isError.value" class="keadaan" role="alert">{{ pesanError(kueri.error.value) }}</p>
      <p v-else-if="!daftar.length" class="keadaan">Tidak ada notifikasi yang belum dibaca.</p>
      <ul v-else class="daftar">
        <li v-for="n in daftar" :key="n.id">
          <button type="button" class="item" :class="{ kritis: n.prioritas === 'kritis' }" @click="bukaNotifikasi(n)">
            <span class="judul">
              <span v-if="n.prioritas === 'kritis'" class="pil pil-bahaya">Kritis</span>
              <span v-else-if="n.prioritas === 'penting'" class="pil pil-peringatan">Penting</span>
              {{ n.judul }}
            </span>
            <span v-if="n.isi" class="isi">{{ n.isi }}</span>
            <time class="waktu" :datetime="n.dibuat_pada">{{ waktuRelatif(n.dibuat_pada).replace('lewat ', '') }} lalu</time>
          </button>
        </li>
      </ul>
      <p v-if="adaKritis" class="catatan-kecil">Notifikasi kritis tidak ikut ditandai sekaligus; buka satu per satu.</p>
      <div class="panel-kaki">
        <RouterLink to="/notifikasi" class="tombol" @click="tutup(false)">Lihat semua</RouterLink>
        <RouterLink to="/notifikasi?tab=preferensi" class="tombol tombol-tautan" @click="tutup(false)">Atur notifikasi</RouterLink>
      </div>
    </section>
  </div>
</template>

<style scoped>
.lonceng {
  position: relative;
  flex: none;
}
.tombol-ikon {
  position: relative;
  box-sizing: border-box;
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border: 1px solid var(--border-input);
  border-radius: 10px;
  background: var(--surface);
  color: var(--muted);
  cursor: pointer;
}
.tombol-ikon:hover {
  background: var(--bg);
  color: var(--text);
}
.tombol-ikon:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.lencana {
  position: absolute;
  top: -6px;
  right: -6px;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: 9px;
  background: var(--danger);
  color: var(--surface);
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
  line-height: 18px;
  text-align: center;
}
.panel {
  position: absolute;
  z-index: 30;
  top: calc(100% + 8px);
  right: 0;
  width: min(380px, calc(100vw - 24px));
  max-height: min(520px, calc(100vh - 96px));
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  box-shadow: var(--shadow-laci);
}
.panel-kepala,
.panel-kaki {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.panel-kepala {
  border-bottom: 1px solid var(--border);
}
.panel-kepala h2 {
  margin: 0;
  font-size: 14px;
}
.panel-kaki {
  border-top: 1px solid var(--border);
}
.keadaan,
.catatan-kecil {
  margin: 0;
  padding: var(--space-3) var(--space-4);
  color: var(--muted);
  font-size: 13px;
}
.catatan-kecil {
  padding-top: 0;
  font-size: 12px;
}
.daftar {
  margin: 0;
  padding: var(--space-1);
  list-style: none;
}
.item {
  display: grid;
  gap: 2px;
  width: 100%;
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  border: 0;
  border-radius: var(--radius-input);
  background: none;
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.item:hover,
.item:focus-visible {
  outline: none;
  background: var(--brand-25);
}
.item.kritis {
  box-shadow: inset 3px 0 0 var(--danger);
}
.judul {
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.isi,
.waktu {
  color: var(--muted);
  font-size: 12px;
}
</style>
