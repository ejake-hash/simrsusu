import { customRef, watch, type Ref } from 'vue'

/** Salinan `sumber` yang baru berubah setelah `jedaMs` tanpa perubahan (mis. kolom cari → permintaan API). */
export function refDebounced<T>(sumber: Ref<T>, jedaMs: number): Readonly<Ref<T>> {
  let nilai = sumber.value
  let pemicu: () => void = () => {}
  let pewaktu: ReturnType<typeof setTimeout> | undefined
  watch(sumber, (baru) => {
    clearTimeout(pewaktu)
    pewaktu = setTimeout(() => {
      nilai = baru
      pemicu()
    }, jedaMs)
  })
  return customRef((lacak, picu) => {
    pemicu = picu
    return {
      get: () => {
        lacak()
        return nilai
      },
      set: () => {},
    }
  })
}
