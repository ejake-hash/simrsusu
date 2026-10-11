// Uji beban k6 (PRD 14.1.1): skenario masuk + GET /api/v1/saya dengan profil acuan diperkecil.
// Hanya ke lokal/staging dengan akun sintetis, BUKAN di CI dan bukan produksi. Cara pakai: README.md.
import http from 'k6/http'
import { check, fail, sleep } from 'k6'

const BASE = __ENV.SIMRS_URL || 'http://127.0.0.1:8000'
// Origin SPA yang terdaftar di SANCTUM_STATEFUL_DOMAINS, agar permintaan diperlakukan sebagai SPA bercookie.
const ORIGIN = __ENV.SIMRS_ORIGIN || 'http://localhost:5173'
const NAMA = __ENV.K6_NAMA_PENGGUNA || 'contoh.admisi'
const SANDI = __ENV.K6_SANDI
const DURASI = __ENV.DURASI || '2m'
// Profil acuan 800 pengguna aktif serentak; bawaan 5% = 40 VU.
const SKALA = Number(__ENV.SKALA || 0.05)

if (!SANDI) fail('K6_SANDI wajib diisi (sandi akun sintetis, AKUN_SINTETIS_SANDI).')

const HEADER = { Origin: ORIGIN, Referer: `${ORIGIN}/`, Accept: 'application/json' }

export const options = {
  scenarios: {
    // Di bawah batas masuk 10/menit per nama pengguna (config/autentikasi.php).
    masuk: { executor: 'constant-arrival-rate', exec: 'masuk', rate: 6, timeUnit: '1m', duration: DURASI, preAllocatedVUs: 2 },
    saya: { executor: 'constant-vus', exec: 'saya', vus: Math.max(1, Math.round(800 * SKALA)), duration: DURASI },
  },
  // Anggaran PRD 14.1: baca P95 ≤ 200 ms, simpan P95 ≤ 500 ms; error < 0,1% (14.1.1).
  thresholds: {
    'http_req_duration{scenario:saya}': ['p(95)<200'],
    'http_req_duration{scenario:masuk}': ['p(95)<500'],
    'http_req_failed{scenario:saya}': ['rate<0.001'],
    'checks{scenario:masuk}': ['rate>0.99'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
}

function tokenCsrf(jar) {
  const nilai = jar.cookiesForURL(BASE)['XSRF-TOKEN']
  return nilai ? decodeURIComponent(nilai[0]) : ''
}

function masukSekali(jar) {
  http.get(`${BASE}/sanctum/csrf-cookie`, { headers: HEADER, jar, tags: { name: 'csrf-cookie' } })
  const res = http.post(`${BASE}/api/v1/autentikasi/masuk`, JSON.stringify({ nama_pengguna: NAMA, sandi: SANDI }), {
    headers: { ...HEADER, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': tokenCsrf(jar) },
    jar,
    tags: { name: 'masuk' },
  })
  const ok = check(res, { 'masuk 200 status=masuk': (r) => r.status === 200 && r.json('status') === 'masuk' })
  return { ok, res }
}

// Satu sesi untuk skenario `saya`, dipakai bersama semua VU (yang diukur kapasitas server, bukan masuk).
export function setup() {
  const jar = http.cookieJar()
  const { ok, res } = masukSekali(jar)
  if (!ok) fail(`Gagal masuk (${res.status}): periksa K6_SANDI, SIMRS_ORIGIN, dan jaringan MFA.`)
  return { cookie: jar.cookiesForURL(BASE) }
}

export function masuk() {
  const jar = http.cookieJar()
  jar.clear(BASE)
  if (!masukSekali(jar).ok) return
  const res = http.post(`${BASE}/api/v1/autentikasi/keluar`, null, {
    headers: { ...HEADER, 'X-XSRF-TOKEN': tokenCsrf(jar) },
    jar,
    tags: { name: 'keluar' },
  })
  check(res, { 'keluar 2xx': (r) => r.status >= 200 && r.status < 300 })
}

export function saya(data) {
  // k6 mengosongkan cookie jar VU di awal setiap iterasi, jadi cookie sesi dipasang ulang.
  const jar = http.cookieJar()
  for (const [nama, nilai] of Object.entries(data.cookie)) jar.set(BASE, nama, nilai[0])
  const res = http.get(`${BASE}/api/v1/saya`, { headers: HEADER, jar, tags: { name: 'saya' } })
  check(res, { 'saya 200': (r) => r.status === 200 })
  // Jeda pikir pengguna: ±1 permintaan per detik per VU.
  sleep(0.5 + Math.random())
}
