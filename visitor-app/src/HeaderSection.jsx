import { useEffect, useMemo, useState } from 'react'
import { motion } from 'framer-motion'

function formatDateOnly(d) {
  return new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }).format(d)
}

function pad2(n) {
  return String(n).padStart(2, '0')
}

function timeGreeting(hour) {
  if (hour < 11) return 'Selamat pagi'
  if (hour < 15) return 'Selamat siang'
  if (hour < 18) return 'Selamat sore'
  return 'Selamat malam'
}

function getSiteName() {
  if (typeof window !== 'undefined' && window.__SITE_NAME__) {
    return String(window.__SITE_NAME__).trim()
  }
  return 'E-Recepsionis'
}

function getVisitorLogoUrl() {
  if (typeof window !== 'undefined' && window.__VISITOR_LOGO_URL__) {
    return String(window.__VISITOR_LOGO_URL__).trim()
  }
  return '../assets/images/official-logo-demk.png'
}

function getVisitorLogoAlt() {
  if (typeof window !== 'undefined' && window.__VISITOR_LOGO_ALT__) {
    return String(window.__VISITOR_LOGO_ALT__).trim()
  }
  return getSiteName()
}

function getWelcomeTitle() {
  if (typeof window !== 'undefined' && window.__VISITOR_WELCOME_TITLE__) {
    return String(window.__VISITOR_WELCOME_TITLE__).trim()
  }
  return 'Selamat Datang'
}

export default function HeaderSection() {
  const [now, setNow] = useState(() => new Date())
  const siteName = getSiteName()
  const logoUrl = getVisitorLogoUrl()
  const logoAlt = getVisitorLogoAlt()
  const welcomeTitle = getWelcomeTitle()

  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000)
    return () => window.clearInterval(t)
  }, [])

  const greeting = useMemo(() => timeGreeting(now.getHours()), [now])
  const hours = pad2(now.getHours())
  const minutes = pad2(now.getMinutes())
  const seconds = pad2(now.getSeconds())

  return (
    <motion.header
      className="kiosk-header tw-px-5 tw-pt-4 sm:tw-px-8 sm:tw-pt-5 lg:tw-px-10"
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.55, ease: [0.22, 1, 0.36, 1] }}
    >
      <div className="tw-mx-auto tw-w-full tw-max-w-7xl">
        <div className="kiosk-hero-panel">
          <div className="kiosk-hero-grid">
          <div className="kiosk-hero-logo-slot">
            {logoUrl ? (
              <img
                src={logoUrl}
                alt={logoAlt}
                className="kiosk-brand-logo"
                width={300}
                height={68}
                decoding="async"
              />
            ) : null}
          </div>

          <div className="kiosk-hero-center">
            <p className="kiosk-hero-tag">{siteName}</p>
            <p className="kiosk-hero-greeting">{greeting}</p>
            <h1 className="kiosk-title kiosk-hero-title">{welcomeTitle}</h1>
          </div>

          <time dateTime={now.toISOString()} className="kiosk-hero-clock">
            <p className="kiosk-hero-date">{formatDateOnly(now)}</p>
            <p className="kiosk-hero-time">
              {hours}
              <span className="kiosk-hero-time-sep">:</span>
              {minutes}
              <span className="kiosk-hero-time-sec">{seconds}</span>
            </p>
          </time>
          </div>
        </div>
      </div>
    </motion.header>
  )
}
