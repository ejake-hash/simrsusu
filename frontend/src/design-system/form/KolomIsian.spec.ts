import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import KolomIsian from './KolomIsian.vue'

const pasang = (props: Record<string, unknown>) =>
  mount(KolomIsian, { props: { label: 'Dasar perubahan', ...props }, slots: { default: `<template #default="a"><input v-bind="a" class="isian" /></template>` } })

describe('KolomIsian', () => {
  it('label terhubung ke kontrol; wajib ditandai', () => {
    const w = pasang({ wajib: true, petunjuk: 'Nomor SK, memo, atau persetujuan.' })
    const input = w.get('input')
    expect(w.get('label').attributes('for')).toBe(input.attributes('id'))
    expect(input.attributes('aria-required')).toBe('true')
    expect(input.attributes('aria-invalid')).toBeUndefined()
    expect(input.attributes('aria-describedby')).toBe(`${input.attributes('id')}-petunjuk`)
  })

  it('error tampil di dekat kolom dan dibacakan bersama kontrolnya', () => {
    const w = pasang({ error: 'Dasar perubahan wajib diisi.', petunjuk: 'Nomor SK.' })
    const input = w.get('input')
    const id = input.attributes('id')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toBe(`${id}-petunjuk ${id}-error`)
    expect(w.get(`#${id}-error`).text()).toBe('Dasar perubahan wajib diisi.')
  })
})
