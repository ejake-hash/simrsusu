<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui'
import { useId } from 'vue'

/**
 * Sakelar nyala/mati dengan label teks (status tidak hanya warna, PRD 5.1 butir 4). `terkunci` = tidak bisa
 * diubah dan alasannya ditampilkan di sebelahnya (mis. MFA superadmin tidak bisa dimatikan).
 */
withDefaults(defineProps<{ label: string; terkunci?: boolean; alasanTerkunci?: string }>(), {
  terkunci: false,
  alasanTerkunci: undefined,
})
const nyala = defineModel<boolean>({ required: true })
const id = `sakelar-${useId()}`
</script>

<template>
  <div class="sakelar">
    <SwitchRoot :id="id" v-model="nyala" class="sakelar-akar" :disabled="terkunci" :aria-describedby="alasanTerkunci ? `${id}-alasan` : undefined">
      <SwitchThumb class="sakelar-jempol" />
    </SwitchRoot>
    <label :for="id" class="sakelar-label">
      {{ label }}
      <span class="sakelar-status">{{ nyala ? 'Nyala' : 'Mati' }}</span>
    </label>
    <p v-if="alasanTerkunci" :id="`${id}-alasan`" class="sakelar-alasan">{{ alasanTerkunci }}</p>
  </div>
</template>

<style scoped>
.sakelar {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: var(--space-1) var(--space-3);
}
.sakelar-akar {
  position: relative;
  box-sizing: border-box;
  width: 40px;
  height: 24px;
  padding: 0;
  border: 1px solid var(--border-input);
  border-radius: 999px;
  background: var(--bg);
  cursor: pointer;
}
.sakelar-akar[data-state='checked'] {
  border-color: var(--brand-600);
  background: var(--brand-600);
}
.sakelar-akar:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}
.sakelar-akar:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
.sakelar-jempol {
  display: block;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--surface);
  box-shadow: var(--shadow);
  transform: translateX(2px);
  transition: transform 0.15s;
}
.sakelar-jempol[data-state='checked'] {
  transform: translateX(18px);
}
.sakelar-label {
  font-weight: var(--font-weight-medium);
}
.sakelar-status {
  margin-left: var(--space-2);
  color: var(--muted);
  font-weight: var(--font-weight-regular);
  font-size: 12.5px;
}
.sakelar-alasan {
  grid-column: 2;
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
}
@media (prefers-reduced-motion: reduce) {
  .sakelar-jempol {
    transition: none;
  }
}
</style>
