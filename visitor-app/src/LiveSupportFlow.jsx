import { useCallback, useEffect, useRef, useState } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import { io } from 'socket.io-client'
import { getLiveSocketUrl } from './getLiveSocketUrl.js'
import { getLiveCategoriesUrl } from './getLiveCategoriesUrl.js'
import { getLiveGuestStatusUrl } from './getLiveGuestStatusUrl.js'

const SESSION_KEY = 'recepsionis_live_session_id'

export default function LiveSupportFlow({ onClose }) {
  const savedSid = typeof sessionStorage !== 'undefined' ? sessionStorage.getItem(SESSION_KEY) || '' : ''
  const [step, setStep] = useState(() => (savedSid ? 'waiting' : 'form'))
  const [categories, setCategories] = useState([])
  const [form, setForm] = useState({ guest_name: '', visitor_phone: '', category_id: '', message: '' })
  const [error, setError] = useState('')
  const [connecting, setConnecting] = useState(false)
  const [sessionId, setSessionId] = useState(savedSid)
  const [adminName, setAdminName] = useState('')
  const [messages, setMessages] = useState([])
  const [input, setInput] = useState('')
  const [typingFrom, setTypingFrom] = useState(null)
  const socketRef = useRef(null)
  const listRef = useRef(null)
  const typingTimer = useRef(null)
  /** Dipakai handler socket agar request_accepted tidak terlewat sebelum ACK guest_request selesai */
  const liveSessionIdRef = useRef(savedSid || '')

  useEffect(() => {
    liveSessionIdRef.current = sessionId
  }, [sessionId])

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

  const scrollBottom = useCallback(() => {
    requestAnimationFrame(() => {
      if (listRef.current) listRef.current.scrollTop = listRef.current.scrollHeight
    })
  }, [])

  useEffect(() => {
    scrollBottom()
  }, [messages, scrollBottom])

  const cleanupSocket = useCallback(() => {
    if (socketRef.current) {
      socketRef.current.removeAllListeners()
      socketRef.current.disconnect()
      socketRef.current = null
    }
  }, [])

  useEffect(() => {
    return () => cleanupSocket()
  }, [cleanupSocket])

  const bindGuestSocketHandlers = useCallback(
    (socket) => {
      const sidMatches = (id) => id && id === liveSessionIdRef.current
      socket.on('request_accepted', (payload) => {
        if (sidMatches(payload?.session_id)) {
          setAdminName(payload.admin_name || 'Admin')
          setStep('chat')
          setConnecting(false)
        }
      })
      socket.on('request_rejected', (payload) => {
        if (sidMatches(payload?.session_id)) {
          sessionStorage.removeItem(SESSION_KEY)
          liveSessionIdRef.current = ''
          setSessionId('')
          setError('Permintaan ditolak. Silakan coba lagi.')
          setStep('form')
          setConnecting(false)
        }
      })
      socket.on('receive_message', (msg) => {
        if (!sidMatches(msg?.session_id)) return
        setMessages((m) => [
          ...m,
          {
            sender: msg.sender,
            body: msg.body,
            created_at: msg.created_at,
            admin_name: msg.admin_name,
          },
        ])
      })
      socket.on('session_ended', (payload) => {
        if (sidMatches(payload?.session_id)) {
          sessionStorage.removeItem(SESSION_KEY)
          liveSessionIdRef.current = ''
          setSessionId('')
          setStep('ended')
          setConnecting(false)
          cleanupSocket()
        }
      })
      socket.on('typing_start', (p) => {
        if (sidMatches(p?.session_id) && p?.from === 'admin') setTypingFrom('admin')
      })
      socket.on('typing_stop', (p) => {
        if (sidMatches(p?.session_id)) setTypingFrom(null)
      })
    },
    [cleanupSocket]
  )

  /** Hanya untuk reload halaman saat masih waiting — submitForm sudah membuat socket baru */
  useEffect(() => {
    if (!sessionId || step !== 'waiting') return undefined
    if (socketRef.current) return undefined

    const url = getLiveSocketUrl()
    const socket = io(url, {
      transports: ['polling', 'websocket'],
      reconnection: true,
      reconnectionAttempts: 12,
      reconnectionDelay: 1500,
    })
    socketRef.current = socket
    liveSessionIdRef.current = sessionId
    bindGuestSocketHandlers(socket)
    socket.on('connect_error', () => {
      setError('Koneksi gagal. Periksa server live chat.')
      setConnecting(false)
    })
    socket.on('connect', () => {
      socket.emit('guest_rejoin', { session_id: sessionId }, (res) => {
        if (!res?.ok) {
          sessionStorage.removeItem(SESSION_KEY)
          liveSessionIdRef.current = ''
          setSessionId('')
          setStep('form')
          setError('Sesi tidak ditemukan atau sudah berakhir.')
          setConnecting(false)
          cleanupSocket()
          return
        }
        if (res.phase === 'chat') {
          setAdminName(res.admin_name || 'Admin')
          setStep('chat')
        }
        setConnecting(false)
      })
    })
  }, [sessionId, step, bindGuestSocketHandlers, cleanupSocket])

  useEffect(() => {
    if (step !== 'waiting' || !sessionId) return undefined
    const base = getLiveGuestStatusUrl()
    const tick = async () => {
      try {
        const url = `${base}?session_id=${encodeURIComponent(sessionId)}`
        const r = await fetch(url)
        const d = await r.json()
        if (!d.success) return
        if (d.phase === 'chat') {
          setAdminName(d.admin_name || 'Admin')
          setStep('chat')
        }
        if (d.phase === 'ended') {
          sessionStorage.removeItem(SESSION_KEY)
          liveSessionIdRef.current = ''
          setSessionId('')
          setStep('form')
          setError('Sesi telah berakhir.')
          cleanupSocket()
        }
      } catch {
        /* abaikan jaringan sementara */
      }
    }
    const id = setInterval(tick, 5000)
    tick()
    return () => clearInterval(id)
  }, [step, sessionId, cleanupSocket])

  const submitForm = (e) => {
    e.preventDefault()
    setError('')
    const category_id = parseInt(form.category_id, 10)
    if (!form.guest_name.trim() || !form.visitor_phone.trim() || !form.message.trim() || !category_id) {
      setError('Lengkapi semua field.')
      return
    }

    setConnecting(true)
    const url = getLiveSocketUrl()
    cleanupSocket()
    liveSessionIdRef.current = ''
    const socket = io(url, {
      transports: ['polling', 'websocket'],
    })
    socketRef.current = socket
    bindGuestSocketHandlers(socket)

    socket.on('connect', () => {
      socket.emit(
        'guest_request',
        {
          guest_name: form.guest_name.trim(),
          visitor_phone: form.visitor_phone.trim(),
          category_id,
          message: form.message.trim(),
        },
        (res) => {
          if (!res?.ok) {
            setConnecting(false)
            setError(
              res?.error === 'bad_category'
                ? 'Kategori tidak valid.'
                : res?.error === 'no_target_admin'
                  ? 'Belum ada admin aktif untuk topik ini.'
                  : 'Gagal mengirim permintaan.'
            )
            socket.disconnect()
            return
          }
          const sid = res.session_id
          liveSessionIdRef.current = sid
          sessionStorage.setItem(SESSION_KEY, sid)
          setSessionId(sid)
          setStep('waiting')
          setConnecting(false)
        }
      )
    })

    socket.on('connect_error', (err) => {
      if (import.meta.env?.DEV) {
        console.warn('[LiveSupport] connect_error', url, err?.message)
      }
      setConnecting(false)
      setError(
        `Tidak terhubung ke ${url}. Jalankan: cd realtime-server → npm start. ` +
          'Pastikan .env Node: CORS_ORIGIN kosong (dev) atau sertakan URL halaman Anda. Dari HP/LAN: firewall port 3001 terbuka.'
      )
    })
  }

  const sendMessage = () => {
    const t = input.trim()
    if (!t || !sessionId || !socketRef.current) return
    socketRef.current.emit('send_message', { session_id: sessionId, text: t }, (res) => {
      if (res?.ok) setInput('')
    })
    socketRef.current.emit('typing_stop', { session_id: sessionId })
  }

  const onInputChange = (v) => {
    setInput(v)
    if (!sessionId || !socketRef.current) return
    socketRef.current.emit('typing_start', { session_id: sessionId })
    if (typingTimer.current) clearTimeout(typingTimer.current)
    typingTimer.current = setTimeout(() => {
      socketRef.current?.emit('typing_stop', { session_id: sessionId })
    }, 1200)
  }

  const backToLanding = () => {
    sessionStorage.removeItem(SESSION_KEY)
    liveSessionIdRef.current = ''
    cleanupSocket()
    onClose?.()
  }

  return (
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      className="tw-fixed tw-inset-0 tw-z-[1000] tw-flex tw-items-center tw-justify-center tw-bg-[#041018]/60 tw-p-4 tw-backdrop-blur-md"
    >
      <motion.div
        layout
        className="tw-flex tw-h-[min(92vh,720px)] tw-w-full tw-max-w-lg tw-flex-col tw-overflow-hidden tw-rounded-[1.5rem] tw-border tw-border-white/80 tw-bg-[linear-gradient(145deg,rgba(255,255,255,0.96)_0%,rgba(255,255,255,0.84)_100%)] tw-shadow-[0_24px_60px_rgba(15,23,42,0.22)]"
      >
        <div className="tw-flex tw-items-center tw-justify-between tw-border-b tw-border-slate-700 tw-bg-gradient-to-r tw-from-blue-600 tw-to-sky-600 tw-px-4 tw-py-3">
          <div className="tw-flex tw-items-center tw-gap-3">
            <div className="tw-flex tw-h-11 tw-w-11 tw-items-center tw-justify-center tw-rounded-xl tw-bg-white/20 tw-text-xl">
              💬
            </div>
            <div>
              <p className="tw-text-sm tw-font-semibold tw-text-white">Live Support</p>
              <p className="tw-text-xs tw-text-white/80">
                {step === 'form' && 'Hubungi admin'}
                {step === 'waiting' && 'Menunggu admin…'}
                {step === 'chat' && (adminName ? `Terhubung dengan ${adminName}` : 'Chat')}
                {step === 'ended' && 'Sesi berakhir'}
              </p>
            </div>
          </div>
          <motion.button
            type="button"
            onClick={backToLanding}
            whileHover={{ scale: 1.08 }}
            whileTap={{ scale: 0.92 }}
            className="live-support-btn-close tw-flex tw-h-9 tw-w-9 tw-items-center tw-justify-center tw-rounded-full tw-transition-colors"
            aria-label="Tutup"
          >
            <svg className="tw-h-4 tw-w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </motion.button>
        </div>

        <AnimatePresence mode="wait">
          {step === 'form' && (
            <motion.form
              key="form"
              initial={{ opacity: 0, x: 20 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: -20 }}
              onSubmit={submitForm}
              className="tw-flex tw-flex-1 tw-flex-col tw-gap-3 tw-overflow-y-auto tw-p-4"
            >
              {error && (
                <div className="tw-rounded-lg tw-bg-red-50 tw-px-3 tw-py-2 tw-text-sm tw-text-red-700">{error}</div>
              )}
              <label className="tw-block tw-text-xs tw-font-medium tw-text-slate-500">Nama</label>
              <input
                className="tw-w-full tw-rounded-xl tw-border tw-border-slate-200 tw-bg-white/90 tw-px-3 tw-py-2 tw-text-sm tw-text-slate-900 placeholder:tw-text-slate-400"
                value={form.guest_name}
                onChange={(e) => setForm((f) => ({ ...f, guest_name: e.target.value }))}
                placeholder="Nama lengkap"
                required
              />
              <label className="tw-block tw-text-xs tw-font-medium tw-text-slate-500">No. Telepon</label>
              <input
                className="tw-w-full tw-rounded-xl tw-border tw-border-slate-200 tw-bg-white/90 tw-px-3 tw-py-2 tw-text-sm tw-text-slate-900 placeholder:tw-text-slate-400"
                value={form.visitor_phone}
                onChange={(e) => setForm((f) => ({ ...f, visitor_phone: e.target.value }))}
                placeholder="08xxxxxxxxxx"
                required
              />
              <label className="tw-block tw-text-xs tw-font-medium tw-text-slate-500">Kategori</label>
              <select
                className="tw-w-full tw-rounded-xl tw-border tw-border-slate-200 tw-bg-white/90 tw-px-3 tw-py-2 tw-text-sm tw-text-slate-900"
                value={form.category_id}
                onChange={(e) => setForm((f) => ({ ...f, category_id: e.target.value }))}
                required
              >
                <option value="">— Pilih —</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.nama_kategori}
                  </option>
                ))}
              </select>
              <label className="tw-block tw-text-xs tw-font-medium tw-text-slate-500">Pesan awal</label>
              <textarea
                className="tw-min-h-[100px] tw-w-full tw-rounded-xl tw-border tw-border-slate-200 tw-bg-white/90 tw-px-3 tw-py-2 tw-text-sm tw-text-slate-900 placeholder:tw-text-slate-400"
                value={form.message}
                onChange={(e) => setForm((f) => ({ ...f, message: e.target.value }))}
                placeholder="Jelaskan keperluan Anda…"
                required
              />
              <motion.button
                type="submit"
                disabled={connecting}
                whileHover={connecting ? undefined : { scale: 1.02, y: -1 }}
                whileTap={connecting ? undefined : { scale: 0.98 }}
                className="live-support-btn-primary tw-mt-3 tw-flex tw-w-full tw-items-center tw-justify-center tw-gap-2.5 tw-rounded-2xl tw-py-3.5 tw-text-sm tw-font-bold tw-tracking-wide tw-transition-transform"
              >
                <span className="tw-flex tw-items-center tw-justify-center tw-gap-2.5">
                  {connecting ? (
                    <>
                      <span className="tw-h-4 tw-w-4 tw-animate-spin tw-rounded-full tw-border-2 tw-border-white/30 tw-border-t-white" />
                      Menghubungkan…
                    </>
                  ) : (
                    <>
                      <svg className="tw-h-5 tw-w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                        <path
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          strokeWidth={2}
                          d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                        />
                      </svg>
                      Kirim & tunggu admin
                      <svg className="tw-h-4 tw-w-4 tw-transition-transform group-hover:tw-translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                      </svg>
                    </>
                  )}
                </span>
              </motion.button>
            </motion.form>
          )}

          {step === 'waiting' && (
            <motion.div
              key="wait"
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              className="tw-flex tw-flex-1 tw-flex-col tw-items-center tw-justify-center tw-gap-4 tw-p-8 tw-text-center"
            >
              <div className="tw-h-12 tw-w-12 tw-animate-spin tw-rounded-full tw-border-2 tw-border-sky-400 tw-border-t-transparent" />
              <p className="tw-text-slate-600">Menunggu admin menerima permintaan Anda…</p>
              <p className="tw-text-xs tw-text-slate-400">Jangan tutup halaman ini</p>
            </motion.div>
          )}

          {step === 'chat' && (
            <motion.div
              key="chat"
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              className="tw-flex tw-min-h-0 tw-flex-1 tw-flex-col"
            >
              <div
                ref={listRef}
                className="tw-flex-1 tw-space-y-3 tw-overflow-y-auto tw-bg-slate-50/80 tw-p-4"
              >
                {messages.map((m, i) => (
                  <div
                    key={`${m.created_at}-${i}`}
                    className={`tw-flex ${m.sender === 'guest' ? 'tw-justify-start' : 'tw-justify-end'}`}
                  >
                    <div
                      className={`tw-max-w-[85%] tw-rounded-2xl tw-px-3 tw-py-2 tw-text-sm ${
                        m.sender === 'guest'
                          ? 'tw-bg-white tw-text-slate-800 tw-ring-1 tw-ring-slate-200'
                          : 'tw-bg-sky-600 tw-text-white'
                      }`}
                    >
                      {m.sender === 'admin' && (
                        <p className="tw-mb-1 tw-text-[10px] tw-font-semibold tw-opacity-80">
                          {m.admin_name || 'Admin'}
                        </p>
                      )}
                      <p className="tw-whitespace-pre-wrap">{m.body}</p>
                      <p className="tw-mt-1 tw-text-[10px] tw-opacity-60">
                        {m.created_at
                          ? new Date(m.created_at).toLocaleTimeString('id-ID', {
                              hour: '2-digit',
                              minute: '2-digit',
                            })
                          : ''}
                      </p>
                    </div>
                  </div>
                ))}
                {typingFrom === 'admin' && (
                  <p className="tw-text-xs tw-italic tw-text-slate-500">Admin mengetik…</p>
                )}
              </div>
              <div className="tw-border-t tw-border-slate-200 tw-p-3">
                <div className="tw-flex tw-gap-2">
                  <input
                    className="tw-min-w-0 tw-flex-1 tw-rounded-xl tw-border tw-border-slate-200 tw-bg-white tw-px-3 tw-py-2 tw-text-sm tw-text-slate-900"
                    value={input}
                    onChange={(e) => onInputChange(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && !e.shiftKey && (e.preventDefault(), sendMessage())}
                    placeholder="Ketik pesan…"
                  />
                  <motion.button
                    type="button"
                    onClick={sendMessage}
                    whileHover={{ scale: 1.04 }}
                    whileTap={{ scale: 0.96 }}
                    className="live-support-btn-send tw-flex tw-shrink-0 tw-items-center tw-gap-1.5 tw-rounded-xl tw-px-4 tw-py-2 tw-text-sm tw-font-semibold tw-shadow-md"
                  >
                    <svg className="tw-h-4 tw-w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                    </svg>
                    Kirim
                  </motion.button>
                </div>
              </div>
            </motion.div>
          )}

          {step === 'ended' && (
            <motion.div
              key="ended"
              initial={{ opacity: 0, scale: 0.96 }}
              animate={{ opacity: 1, scale: 1 }}
              className="tw-flex tw-flex-1 tw-flex-col tw-items-center tw-justify-center tw-gap-4 tw-p-8 tw-text-center"
            >
              <p className="tw-text-lg tw-font-medium tw-text-slate-900">Sesi telah diakhiri</p>
              <p className="tw-text-sm tw-text-slate-500">Terima kasih. Anda dapat memulai percakapan baru kapan saja.</p>
              <motion.button
                type="button"
                onClick={backToLanding}
                whileHover={{ scale: 1.03 }}
                whileTap={{ scale: 0.97 }}
                className="live-support-btn-secondary tw-rounded-2xl tw-px-8 tw-py-2.5 tw-text-sm tw-font-semibold tw-shadow-md"
              >
                Kembali ke beranda
              </motion.button>
            </motion.div>
          )}
        </AnimatePresence>
      </motion.div>
    </motion.div>
  )
}
