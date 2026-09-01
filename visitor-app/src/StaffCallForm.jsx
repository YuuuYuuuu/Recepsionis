import { useEffect, useState } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import { getLiveCategoriesUrl } from './getLiveCategoriesUrl.js'
import { getCallStaffUrl } from './getCallStaffUrl.js'

const fieldClass =
  'tw-w-full tw-rounded-2xl tw-border tw-border-slate-200 tw-bg-white/80 tw-px-4 tw-py-3 tw-font-sans tw-text-[0.95rem] tw-text-slate-900 tw-outline-none placeholder:tw-text-slate-400 focus:tw-border-sky-400/60 focus:tw-bg-white'

const labelClass = 'tw-mb-1.5 tw-block tw-text-xs tw-font-semibold tw-tracking-wide tw-text-slate-500'

export default function StaffCallForm({ onClose }) {
  const [step, setStep] = useState('form')
  const [categories, setCategories] = useState([])
  const [form, setForm] = useState({
    visitor_name: '',
    visitor_phone: '',
    category_id: '',
    message: '',
  })
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    const url = getLiveCategoriesUrl()
    fetch(url, { credentials: 'same-origin' })
      .then(async (r) => {
        const text = await r.text()
        let d
        try {
          d = JSON.parse(text)
        } catch {
          throw new Error('invalid_json')
        }
        if (!r.ok) throw new Error('http_' + r.status)
        if (!d.success || !Array.isArray(d.categories)) {
          throw new Error('bad_payload')
        }
        return d.categories
      })
      .then((list) => {
        setCategories(list)
        if (list.length === 0) {
          setError('Belum ada kategori aktif. Hubungi admin atau coba lagi nanti.')
        } else {
          setError('')
        }
      })
      .catch(() => {
        setError('Gagal memuat kategori. Periksa koneksi atau jalankan migrasi tabel complaint_categories.')
      })
  }, [])

  const submitForm = async (e) => {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      const body = new FormData()
      body.append('visitor_name', form.visitor_name.trim())
      body.append('visitor_phone', form.visitor_phone.trim())
      body.append('category_id', String(form.category_id))
      body.append('message', form.message.trim())

      const res = await fetch(getCallStaffUrl(), {
        method: 'POST',
        body,
        credentials: 'same-origin',
      })
      const text = await res.text()
      let data
      try {
        data = JSON.parse(text)
      } catch {
        throw new Error('invalid_json')
      }
      if (!data.success) {
        setError(data.message || 'Gagal mengirim panggilan staff.')
        return
      }
      setStep('success')
    } catch {
      setError('Koneksi gagal. Periksa jaringan lalu coba lagi.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      className="tw-fixed tw-inset-0 tw-z-[1000] tw-flex tw-items-center tw-justify-center tw-bg-[#041018]/75 tw-p-4 tw-backdrop-blur-md"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <motion.div
        layout
        initial={{ opacity: 0, y: 18, scale: 0.98 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
        className="tw-flex tw-w-full tw-max-w-xl tw-flex-col tw-overflow-hidden tw-rounded-[1.75rem] tw-border tw-border-white/80 tw-bg-[linear-gradient(145deg,rgba(255,255,255,0.94)_0%,rgba(255,255,255,0.78)_100%)] tw-shadow-[0_24px_60px_rgba(15,23,42,0.22)] tw-backdrop-blur-md"
        role="dialog"
        aria-modal="true"
        aria-labelledby="staff-call-title"
      >
        <div className="tw-flex tw-items-start tw-justify-between tw-gap-4 tw-border-b tw-border-slate-200/80 tw-px-6 tw-pb-4 tw-pt-5 sm:tw-px-7">
          <div className="tw-min-w-0">
            <p className="tw-mb-1 tw-text-[0.7rem] tw-font-semibold tw-uppercase tw-tracking-[0.14em] tw-text-sky-700">
              Helpdesk
            </p>
            <h2
              id="staff-call-title"
              className="tw-font-display tw-text-2xl tw-font-semibold tw-tracking-tight tw-text-slate-900"
            >
              Panggil Staff
            </h2>
            <p className="tw-mt-1 tw-text-sm tw-leading-relaxed tw-text-slate-500">
              {step === 'form'
                ? 'Isi data singkat. Tim akan dihubungi via WhatsApp.'
                : 'Permintaan Anda sudah diteruskan ke operator.'}
            </p>
          </div>
          <button type="button" onClick={onClose} className="staff-form-icon-close" aria-label="Tutup">
            <svg className="tw-h-4 tw-w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <AnimatePresence mode="wait">
          {step === 'form' && (
            <motion.form
              key="form"
              initial={{ opacity: 0, y: 8 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -8 }}
              onSubmit={submitForm}
              className="tw-flex tw-flex-col tw-gap-4 tw-px-6 tw-py-5 sm:tw-px-7 sm:tw-py-6"
            >
              {error && (
                <div className="tw-rounded-2xl tw-border tw-border-red-200 tw-bg-red-50 tw-px-3.5 tw-py-2.5 tw-text-sm tw-text-red-700">
                  {error}
                </div>
              )}

              <div className="tw-grid tw-grid-cols-1 tw-gap-4 sm:tw-grid-cols-2">
                <div>
                  <label className={labelClass} htmlFor="staff-name">
                    Nama
                  </label>
                  <input
                    id="staff-name"
                    className={fieldClass}
                    value={form.visitor_name}
                    onChange={(e) => setForm((f) => ({ ...f, visitor_name: e.target.value }))}
                    placeholder="Nama lengkap"
                    required
                    autoComplete="name"
                  />
                </div>
                <div>
                  <label className={labelClass} htmlFor="staff-phone">
                    No. Telepon
                  </label>
                  <input
                    id="staff-phone"
                    className={fieldClass}
                    value={form.visitor_phone}
                    onChange={(e) => setForm((f) => ({ ...f, visitor_phone: e.target.value }))}
                    placeholder="08xxxxxxxxxx"
                    required
                    inputMode="tel"
                    autoComplete="tel"
                  />
                </div>
              </div>

              <div>
                <label className={labelClass} htmlFor="staff-category">
                  Kategori
                </label>
                <select
                  id="staff-category"
                  className={`${fieldClass} tw-appearance-none`}
                  value={form.category_id}
                  onChange={(e) => setForm((f) => ({ ...f, category_id: e.target.value }))}
                  required
                >
                  <option value="">Pilih kategori</option>
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.nama_kategori}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className={labelClass} htmlFor="staff-message">
                  Keperluan
                </label>
                <textarea
                  id="staff-message"
                  className={`${fieldClass} tw-min-h-[88px] tw-resize-none`}
                  value={form.message}
                  onChange={(e) => setForm((f) => ({ ...f, message: e.target.value }))}
                  placeholder="Jelaskan keperluan Anda secara singkat…"
                  required
                  rows={3}
                />
              </div>

              <div className="tw-mt-2 tw-flex tw-items-center tw-justify-between tw-gap-4">
                <button type="button" onClick={onClose} className="staff-form-cancel">
                  Batal
                </button>
                <motion.button
                  type="submit"
                  disabled={submitting}
                  whileHover={submitting ? undefined : { y: -2 }}
                  whileTap={submitting ? undefined : { scale: 0.98 }}
                  className="staff-form-submit"
                >
                  {submitting ? (
                    <>
                      <span className="tw-h-4 tw-w-4 tw-animate-spin tw-rounded-full tw-border-2 tw-border-white/30 tw-border-t-white" />
                      Mengirim…
                    </>
                  ) : (
                    <>
                      Kirim panggilan
                      <svg className="tw-h-4 tw-w-4" viewBox="0 0 20 20" fill="none" aria-hidden>
                        <path
                          d="M4 10h11M11 5l5 5-5 5"
                          stroke="currentColor"
                          strokeWidth="1.8"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        />
                      </svg>
                    </>
                  )}
                </motion.button>
              </div>
            </motion.form>
          )}

          {step === 'success' && (
            <motion.div
              key="success"
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              className="tw-flex tw-flex-col tw-items-center tw-px-6 tw-py-10 tw-text-center sm:tw-px-8"
            >
              <div className="tw-mb-5 tw-flex tw-h-16 tw-w-16 tw-items-center tw-justify-center tw-rounded-full tw-bg-emerald-500/12 tw-ring-1 tw-ring-emerald-500/25">
                <svg className="tw-h-8 tw-w-8 tw-text-emerald-600" viewBox="0 0 24 24" fill="none" aria-hidden>
                  <path
                    d="M5 13l4 4L19 7"
                    stroke="currentColor"
                    strokeWidth="2.4"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  />
                </svg>
              </div>
              <h3 className="tw-font-display tw-text-xl tw-font-semibold tw-text-slate-900">Panggilan terkirim</h3>
              <p className="tw-mt-2 tw-max-w-sm tw-text-sm tw-leading-relaxed tw-text-slate-500">
                Operator kategori yang dipilih akan menerima notifikasi WhatsApp dan menindaklanjuti keperluan Anda.
              </p>
              <button type="button" onClick={onClose} className="staff-form-cancel tw-mt-7">
                Selesai
              </button>
            </motion.div>
          )}
        </AnimatePresence>
      </motion.div>
    </motion.div>
  )
}
