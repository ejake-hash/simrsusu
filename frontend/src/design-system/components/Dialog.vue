<script setup lang="ts">
import { DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui'
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Dialog modal untuk konfirmasi tindakan berisiko (PRD 5.1 butir 6, 11, 17): fokus pindah ke dialog dan
 * terjebak di dalamnya, Esc dan klik di luar menutup KECUALI saat `sibuk` atau `tutupLewatTombol`, fokus kembali ke pemicu.
 * `peringatan` = role alertdialog (aksi yang mengubah akses/keamanan). `tutupLewatTombol` = Esc dan klik di luar tidak menutup
 * (mis. selama sandi sekali tampil terlihat), hanya tombol tutup/aksi.
 */
const props = withDefaults(
  defineProps<{ judul: string; uraian?: string; sibuk?: boolean; peringatan?: boolean; lebar?: 'sedang' | 'lebar'; tutupLewatTombol?: boolean }>(),
  { uraian: undefined, sibuk: false, peringatan: false, lebar: 'sedang', tutupLewatTombol: false },
)
const buka = defineModel<boolean>('open', { required: true })

function tahanTutupLuar(e: Event) {
  if (props.sibuk || props.tutupLewatTombol) e.preventDefault()
}
</script>

<template>
  <DialogRoot v-model:open="buka">
    <DialogPortal>
      <DialogOverlay class="dialog-tirai" />
      <DialogContent
        class="dialog"
        :class="`dialog-${lebar}`"
        :role="peringatan ? 'alertdialog' : 'dialog'"
        :aria-busy="sibuk || undefined"
        v-bind="uraian ? {} : { 'aria-describedby': undefined }"
        @escape-key-down="tahanTutupLuar"
        @interact-outside="tahanTutupLuar"
      >
        <header class="dialog-kepala">
          <div>
            <DialogTitle class="dialog-judul">{{ judul }}</DialogTitle>
            <DialogDescription v-if="uraian" class="dialog-uraian">{{ uraian }}</DialogDescription>
          </div>
          <button type="button" class="dialog-tutup" aria-label="Tutup" :disabled="sibuk" @click="buka = false">
            <Ikon nama="tutup" />
          </button>
        </header>
        <div class="dialog-isi">
          <slot />
        </div>
        <footer v-if="$slots.aksi" class="dialog-aksi">
          <slot name="aksi" />
        </footer>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>

<style>
/* Tidak scoped: konten di-teleport ke body. Nama kelas berawalan dialog- agar tidak bentrok. */
.dialog-tirai {
  position: fixed;
  inset: 0;
  z-index: 60;
  background: var(--tirai);
}
.dialog {
  position: fixed;
  left: 50%;
  top: 50%;
  z-index: 61;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  width: min(480px, calc(100vw - 32px));
  max-height: calc(100vh - 32px);
  transform: translate(-50%, -50%);
  border-radius: 14px;
  background: var(--surface);
  box-shadow: var(--shadow-laci);
  color: var(--text);
}
.dialog-lebar {
  width: min(640px, calc(100vw - 32px));
}
.dialog:focus {
  outline: none;
}
.dialog-kepala {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-4) 20px var(--space-3);
  border-bottom: 1px solid var(--border);
}
.dialog-judul {
  margin: 0;
  font-size: 16px;
  font-weight: var(--font-weight-semibold);
}
.dialog-uraian {
  margin: var(--space-1) 0 0;
  font-size: 13px;
  color: var(--muted);
}
.dialog-tutup {
  flex: none;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  margin: -8px -10px 0 0;
  border: 0;
  border-radius: var(--radius-input);
  background: transparent;
  color: var(--muted);
  cursor: pointer;
}
.dialog-tutup:hover:not(:disabled) {
  background: var(--bg);
}
.dialog-tutup:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}
.dialog-tutup:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.dialog-isi {
  overflow-y: auto;
  padding: var(--space-4) 20px;
}
.dialog-aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
  padding: var(--space-3) 20px var(--space-4);
  border-top: 1px solid var(--border);
}
</style>
