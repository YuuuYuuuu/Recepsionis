import { useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import receptionistImg from './assets/receptionist.svg?url'

const FULL_MESSAGE =
  'Halo! Kalau butuh arahan atau penjelasan singkat soal halaman ini, tanya saja ke saya ya 😊'

const INFO_TIPS = [
  {
    title: 'Informasi ruangan',
    text: 'Ketuk Daftar Ruangan untuk melihat ruang, gedung, dan lokasi.',
  },
  {
    title: 'Program studi',
    text: 'Ketuk Program Studi untuk membuka katalog prodi kampus.',
  },
  {
    title: 'Menghubungi staff',
    text: 'Ketuk Panggil Staff, isi formulir, lalu operator akan menerima notifikasi WhatsApp.',
  },
]

const BUBBLE_VARIANTS = [
  'Butuh bantuan?',
  'Tak perlu sungkan, lihat lihat sajaa',
  'binggung? mau ketemu langsung?',
  'mau konsul?',
  'Hai kamu, iya kamu', 
]

function useTypingAnimation(active, text, speed = 32) {
  const [display, setDisplay] = useState('')
  const iRef = useRef(0)
  const timerRef = useRef(null)

  useEffect(() => {
    if (!active) {
      setDisplay('')
      iRef.current = 0
      if (timerRef.current) clearInterval(timerRef.current)
      return
    }
    setDisplay('')
    iRef.current = 0
    if (timerRef.current) clearInterval(timerRef.current)
    timerRef.current = setInterval(() => {
      iRef.current += 1
      if (iRef.current <= text.length) {
        setDisplay(text.slice(0, iRef.current))
      } else {
        clearInterval(timerRef.current)
        timerRef.current = null
      }
    }, speed)
    return () => {
      if (timerRef.current) clearInterval(timerRef.current)
    }
  }, [active, text, speed])

  return display
}

export default function VirtualReceptionist() {
  const [open, setOpen] = useState(false)
  const [showBubble, setShowBubble] = useState(false)
  const [bubbleText, setBubbleText] = useState(BUBBLE_VARIANTS[0])
  const [reminder, setReminder] = useState('')
  const idleChatRef = useRef(null)
  const widgetRef = useRef(null)
  const bubbleIndexRef = useRef(0)

  const typingLine = useTypingAnimation(open, FULL_MESSAGE, 32)
  const typingDone = typingLine.length === FULL_MESSAGE.length

  useEffect(() => {
    const id = setInterval(() => {
      if (!open) {
        bubbleIndexRef.current = (bubbleIndexRef.current + 1) % BUBBLE_VARIANTS.length
        setBubbleText(BUBBLE_VARIANTS[bubbleIndexRef.current])
        setShowBubble(true)
        setTimeout(() => setShowBubble(false), 3200)
      }
    }, 10000)
    return () => clearInterval(id)
  }, [open])

  useEffect(() => {
    if (!open) {
      setReminder('')
      if (idleChatRef.current) clearTimeout(idleChatRef.current)
      return
    }
    idleChatRef.current = setTimeout(() => {
      setReminder(
        'Masih butuh penjelasan? Lihat ringkasan di atas. Klik di luar jendela ini jika sudah selesai.'
      )
    }, 26000)
    return () => {
      if (idleChatRef.current) clearTimeout(idleChatRef.current)
    }
  }, [open])

  useEffect(() => {
    if (!open) return undefined
    const onPointerDown = (e) => {
      const node = widgetRef.current
      if (node && !node.contains(e.target)) {
        setOpen(false)
      }
    }
    document.addEventListener('pointerdown', onPointerDown, true)
    return () => document.removeEventListener('pointerdown', onPointerDown, true)
  }, [open])

  return (
    <div
      ref={widgetRef}
      className="tw-pointer-events-none tw-fixed tw-bottom-6 tw-right-6 tw-z-[1020] tw-flex tw-flex-col tw-items-end tw-gap-2 sm:tw-bottom-8 sm:tw-right-8"
    >
      <AnimatePresence>
        {showBubble && !open && (
          <motion.div
            initial={{ opacity: 0, y: 6, scale: 0.92 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 4, scale: 0.95 }}
            className="tw-pointer-events-none tw-max-w-[168px] tw-rounded-lg tw-bg-slate-900 tw-px-2.5 tw-py-1.5 tw-text-[0.68rem] tw-font-medium tw-text-white tw-shadow-lg"
          >
            {bubbleText}
          </motion.div>
        )}
      </AnimatePresence>

      <AnimatePresence>
        {open && (
          <motion.div
            initial={{ opacity: 0, scale: 0, y: 20 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.85, y: 12 }}
            transition={{ type: 'spring', stiffness: 380, damping: 28 }}
            className="tw-pointer-events-auto tw-mb-1 tw-w-[min(calc(100vw-2rem),340px)] tw-origin-bottom-right tw-overflow-hidden tw-rounded-2xl tw-border tw-border-slate-200 tw-bg-white tw-shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="vr-title"
          >
            <div className="tw-flex tw-items-center tw-justify-between tw-border-b tw-border-slate-100 tw-bg-gradient-to-r tw-from-blue-600 tw-to-sky-600 tw-px-4 tw-py-3">
              <h2 id="vr-title" className="tw-text-sm tw-font-semibold tw-text-white">
                Virtual Resepsionis
              </h2>
              <button
                type="button"
                onClick={() => setOpen(false)}
                className="tw-rounded-lg tw-p-1 tw-text-white/90 tw-transition hover:tw-bg-white/15 hover:tw-text-white"
                aria-label="Tutup"
              >
                <span className="tw-sr-only">Tutup</span>
                <svg className="tw-h-5 tw-w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden>
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div className="tw-max-h-[min(60vh,380px)] tw-overflow-y-auto tw-px-4 tw-py-4">
              <p className="tw-mb-2 tw-text-sm tw-font-medium tw-text-slate-800">Selamat datang 👋</p>
              {typingLine.length === 0 && (
                <p className="tw-mb-1 tw-text-xs tw-italic tw-text-slate-400">Sedang mengetik...</p>
              )}
              <p className="tw-mb-4 tw-min-h-[3rem] tw-text-sm tw-leading-relaxed tw-text-slate-600">
                {typingLine}
                {typingLine.length < FULL_MESSAGE.length && typingLine.length > 0 && (
                  <span className="tw-ml-0.5 tw-inline-block tw-h-3 tw-w-1 tw-animate-pulse tw-bg-blue-600" />
                )}
              </p>

              {typingDone && (
                <motion.div
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.35 }}
                  className="tw-space-y-3 tw-border-t tw-border-slate-100 tw-pt-4"
                >
                  <p className="tw-text-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-slate-500">
                    Cara mendapatkan informasi
                  </p>
                  <ul className="tw-space-y-3 tw-text-sm tw-text-slate-600">
                    {INFO_TIPS.map((item) => (
                      <li key={item.title} className="tw-leading-snug">
                        <span className="tw-font-semibold tw-text-slate-800">{item.title}.</span>{' '}
                        {item.text}
                      </li>
                    ))}
                  </ul>
                  <p className="tw-rounded-lg tw-bg-slate-50 tw-px-3 tw-py-2 tw-text-xs tw-leading-relaxed tw-text-slate-500">
                    Klik di mana saja di luar jendela ini untuk menutup. Buka lagi lewat ikon resepsionis di pojok
                    kanan bawah jika ingin bertanya kembali.
                  </p>
                </motion.div>
              )}

              {reminder && typingDone && (
                <p className="tw-mt-3 tw-text-xs tw-italic tw-text-slate-500">{reminder}</p>
              )}
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      <motion.button
        type="button"
        aria-label={open ? 'Tutup Virtual Resepsionis' : 'Buka Virtual Resepsionis'}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="tw-pointer-events-auto tw-relative tw-h-14 tw-w-14 tw-shrink-0 tw-overflow-hidden tw-rounded-xl tw-border-2 tw-border-white tw-shadow-lg tw-outline-none tw-ring-2 tw-ring-blue-500/20 focus-visible:tw-ring-blue-500 sm:tw-h-16 sm:tw-w-16"
        animate={{ y: [0, -6, 0] }}
        transition={{ duration: 2.6, repeat: Infinity, ease: 'easeInOut' }}
        whileHover={{ scale: 1.06 }}
        whileTap={{ scale: 0.94 }}
      >
        <img src={receptionistImg} alt="" className="tw-h-full tw-w-full tw-object-cover" />
      </motion.button>
    </div>
  )
}
