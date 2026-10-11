<script setup lang="ts">
import Ikon from '@/design-system/ikon/Ikon.vue'
import { daftarToast, lanjutkanToast, tahanToast, tutupToast } from './toast'

/**
 * Wadah toast, dipasang sekali di AppShell. Wilayah live selalu ada di DOM agar toast baru dibacakan: berhasil/info
 * sopan (status), error tegas (alert). Pojok kanan bawah, di ponsel selebar layar di bawah.
 */
const IKON = { ok: 'centang', info: 'info', peringatan: 'peringatan', error: 'peringatan' } as const
</script>

<template>
  <div class="wadah-toast">
    <div role="status" aria-live="polite" class="lapis">
      <template v-for="t in daftarToast" :key="t.id">
        <div v-if="t.jenis !== 'error'" class="toast" :class="`toast-${t.jenis}`" @mouseenter="tahanToast(t.id)" @mouseleave="lanjutkanToast(t.id)" @focusin="tahanToast(t.id)" @focusout="lanjutkanToast(t.id)">
          <Ikon :nama="IKON[t.jenis]" :ukuran="16" />
          <span class="isi">{{ t.pesan }}</span>
          <button type="button" class="tutup" aria-label="Tutup notifikasi" @click="tutupToast(t.id)"><Ikon nama="tutup" :ukuran="14" /></button>
        </div>
      </template>
    </div>
    <div role="alert" class="lapis">
      <template v-for="t in daftarToast" :key="t.id">
        <div v-if="t.jenis === 'error'" class="toast toast-error">
          <Ikon :nama="IKON.error" :ukuran="16" />
          <span class="isi">{{ t.pesan }}</span>
          <button type="button" class="tutup" aria-label="Tutup notifikasi" @click="tutupToast(t.id)"><Ikon nama="tutup" :ukuran="14" /></button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.wadah-toast {
  position: fixed;
  right: var(--space-4);
  bottom: var(--space-4);
  z-index: 70;
  display: grid;
  gap: var(--space-2);
  width: min(380px, calc(100vw - 32px));
  pointer-events: none;
}
.lapis {
  display: grid;
  gap: var(--space-2);
}
.toast {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  padding: 10px 6px 10px 12px;
  border: 1px solid var(--border);
  border-left-width: 4px;
  border-radius: 10px;
  background: var(--surface);
  box-shadow: var(--shadow-laci);
  color: var(--text);
  font-size: 13px;
  pointer-events: auto;
  animation: toast-masuk 0.18s ease-out;
}
.toast svg {
  flex: none;
  margin-top: 1px;
}
.toast-ok {
  border-left-color: var(--success);
}
.toast-ok > svg {
  color: var(--success);
}
.toast-info {
  border-left-color: var(--info);
}
.toast-info > svg {
  color: var(--info);
}
.toast-peringatan {
  border-left-color: var(--warn);
}
.toast-peringatan > svg {
  color: var(--warn);
}
.toast-error {
  border-left-color: var(--danger);
}
.toast-error > svg {
  color: var(--danger);
}
.isi {
  flex: 1;
  min-width: 0;
  padding-top: 1px;
}
.tutup {
  flex: none;
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  margin: -4px 0;
  border: 0;
  border-radius: 6px;
  background: none;
  color: var(--muted);
  cursor: pointer;
}
.tutup:hover {
  background: var(--bg);
}
.tutup:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
@keyframes toast-masuk {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .tutup {
    width: 44px;
    height: 44px;
    margin: -12px 0;
  }
}
@media (prefers-reduced-motion: reduce) {
  .toast {
    animation: none;
  }
}
</style>
