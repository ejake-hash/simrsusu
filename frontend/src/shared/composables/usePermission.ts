import { ref, shallowRef, watchEffect, type Directive, type Ref } from 'vue'

/**
 * Kode izin aksi akun yang sedang masuk (`izin` dari GET /saya/menu, format <menu>.<sub_menu>.<aksi>).
 * Hanya untuk menyembunyikan tombol yang pasti ditolak; server tetap memeriksa setiap permintaan
 * dan tidak mengirim data bagian yang tidak diizinkan (PRD 9.2).
 */
const izinSaya = shallowRef<ReadonlySet<string>>(new Set())

/** Diisi dari cache menu (app/menu.ts); kosong saat belum masuk atau setelah keluar. */
export function aturIzin(izin: readonly string[]): void {
  izinSaya.value = new Set(izin)
}

export function boleh(kode: string): boolean {
  return izinSaya.value.has(kode)
}

export function usePermission() {
  return { boleh }
}

/** `v-can="'data_induk.akun.ubah'"`: elemen disembunyikan (atribut hidden) bila izin tidak ada. */
const pantauan = new WeakMap<HTMLElement, { kode: Ref<string>; berhenti: () => void }>()

export const vCan: Directive<HTMLElement, string> = {
  mounted(el, binding) {
    const kode = ref(binding.value)
    const berhenti = watchEffect(() => {
      el.hidden = !boleh(kode.value)
    })
    pantauan.set(el, { kode, berhenti })
  },
  updated(el, binding) {
    const p = pantauan.get(el)
    if (p) p.kode.value = binding.value
  },
  unmounted(el) {
    pantauan.get(el)?.berhenti()
    pantauan.delete(el)
  },
}
