import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { afterEach, describe, expect, it } from 'vitest'
import { klien } from '@/shared/api/klien'
import { bacaPeserta, bacaRujukan, terbitkanSep } from './apiSep'

const adapterAsli = klien.defaults.adapter
afterEach(() => {
  klien.defaults.adapter = adapterAsli
})

/** Adapter yang merekam permintaan dan menjawab `{ data: {} }`. */
function rekam(): InternalAxiosRequestConfig[] {
  const dicatat: InternalAxiosRequestConfig[] = []
  const adapter: AxiosAdapter = async (config) => {
    dicatat.push(config)
    return { data: { data: {} }, status: 200, statusText: 'OK', headers: {}, config }
  }
  klien.defaults.adapter = adapter
  return dicatat
}

describe('klien kepesertaan, rujukan, SEP (2.7)', () => {
  it('nomor hanya lewat header X-Nomor, tidak di URL atau query', async () => {
    const dicatat = rekam()
    await bacaPeserta('9990000000001', '2026-11-07')
    await bacaRujukan('SIM/1 ü', '2026-11-07', 'fktp')
    for (const c of dicatat) {
      expect(c.url).not.toMatch(/\d{13}|SIM/)
      expect(JSON.stringify(c.params)).not.toMatch(/\d{13}|SIM/)
    }
    expect(dicatat[0]!.headers.get('X-Nomor')).toBe('9990000000001')
    expect(dicatat[1]!.headers.get('X-Nomor')).toBe(encodeURIComponent('SIM/1 ü'))
    expect(dicatat[1]!.params).toEqual({ tanggal: '2026-11-07', asal: 'fktp' })
  })

  it('terbitkan SEP: POST ke kunjungan dengan Idempotency-Key', async () => {
    const dicatat = rekam()
    await terbitkanSep('k-1', { no_rujukan: 'SIMFKTP001', tunda: true }, 'kunci-1')
    await terbitkanSep('k-1', { no_rujukan: 'SIMFKTP001', tunda: true }, 'kunci-1')
    expect(dicatat[0]!.url).toBe('/kunjungan/k-1/sep')
    // Percobaan ulang memakai kunci yang sama.
    expect(dicatat.map((c) => c.headers.get('Idempotency-Key'))).toEqual(['kunci-1', 'kunci-1'])
  })
})
