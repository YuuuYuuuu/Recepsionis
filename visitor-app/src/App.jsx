import { useCallback, useEffect, useState } from 'react'
import DynamicBlueWallpaper from './DynamicBlueWallpaper.jsx'
import HeaderSection from './HeaderSection.jsx'
import ServiceCard from './ServiceCard.jsx'
import { ProdiIcon, RoomIcon, StaffIcon } from './ServiceIcons.jsx'
import VirtualReceptionist from './VirtualReceptionist.jsx'
import StaffCallForm from './StaffCallForm.jsx'

function openBootstrapModal(elementId) {
  const el = document.getElementById(elementId)
  if (el && window.bootstrap?.Modal) {
    window.bootstrap.Modal.getOrCreateInstance(el).show()
    return true
  }
  return false
}

function getVisitorPhpBaseUrl() {
  if (typeof window !== 'undefined' && window.__VISITOR_BASE_URL__) {
    return String(window.__VISITOR_BASE_URL__)
  }
  const envUrl = import.meta.env?.VITE_VISITOR_BASE_URL
  if (envUrl && String(envUrl).trim()) {
    return String(envUrl).trim().replace(/\/?$/, '/')
  }
  if (import.meta.env?.DEV) {
    return 'http://127.0.0.1:8000/Recepsionis/visitor/'
  }
  try {
    const href = new URL('index.php', window.location.href)
    const base = href.href.replace(/index\.php(?:[?#].*)?$/, '')
    return base.endsWith('/') ? base : `${base}/`
  } catch {
    return '/Recepsionis/visitor/'
  }
}

function getVisitorServices() {
  const defaults = {
    rooms: {
      title: 'Daftar Ruangan',
      description: 'Cari ruangan, gedung, dan lokasi di kampus.',
      cta: 'Lihat ruangan',
    },
    prodi: {
      title: 'Program Studi',
      description: 'Jelajahi program studi yang ada di kampus.',
      cta: 'Lihat prodi',
    },
    staff: {
      title: 'Panggil Staff',
      description: 'Hubungi operator, notifikasi langsung ke tim.',
      cta: 'Panggil sekarang',
    },
  }

  if (typeof window !== 'undefined' && window.__VISITOR_SERVICES__) {
    const services = window.__VISITOR_SERVICES__
    return {
      rooms: { ...defaults.rooms, ...(services.rooms || {}) },
      prodi: { ...defaults.prodi, ...(services.prodi || {}) },
      staff: { ...defaults.staff, ...(services.staff || {}) },
    }
  }

  return defaults
}

export default function App() {
  const [highlighted, setHighlighted] = useState(null)
  const [staffCallOpen, setStaffCallOpen] = useState(false)
  const services = getVisitorServices()

  useEffect(() => {
    const root = document.getElementById('visitor-landing-root')
    root?.classList.add('visitor-kiosk-root')
    return () => root?.classList.remove('visitor-kiosk-root')
  }, [])

  useEffect(() => {
    if (!highlighted) return undefined
    const t = window.setTimeout(() => setHighlighted(null), 4800)
    return () => window.clearTimeout(t)
  }, [highlighted])

  const activateRooms = useCallback(() => {
    setHighlighted('rooms')
    if (!openBootstrapModal('roomsModal')) {
      window.location.href = `${getVisitorPhpBaseUrl()}index.php?open=rooms`
    }
  }, [])

  const activateProdi = useCallback(() => {
    setHighlighted('prodi')
    window.location.href = `${getVisitorPhpBaseUrl()}prodi.php`
  }, [])

  const activateStaff = useCallback(() => {
    setHighlighted('staff')
    setStaffCallOpen(true)
  }, [])

  return (
    <div className="kiosk-shell tw-relative tw-flex tw-min-h-[100dvh] tw-flex-col">
      {staffCallOpen && <StaffCallForm onClose={() => setStaffCallOpen(false)} />}
      <DynamicBlueWallpaper />

      <div className="tw-relative tw-z-10 tw-flex tw-flex-1 tw-flex-col tw-justify-start tw-pb-20">
        <HeaderSection />

        <main className="tw-px-5 tw-pt-10 sm:tw-px-8 sm:tw-pt-12 lg:tw-px-10 lg:tw-pt-14">
          <div className="tw-mx-auto tw-grid tw-w-full tw-max-w-7xl tw-grid-cols-1 tw-items-stretch tw-gap-5 md:tw-grid-cols-3 md:tw-gap-6 lg:tw-gap-7">
            <ServiceCard
              id="service-daftar-ruangan"
              theme="green"
              icon={RoomIcon}
              title={services.rooms.title}
              description={services.rooms.description}
              ctaLabel={services.rooms.cta}
              highlighted={highlighted === 'rooms'}
              delay={0.08}
              onActivate={activateRooms}
            />
            <ServiceCard
              id="service-program-studi"
              theme="orange"
              icon={ProdiIcon}
              title={services.prodi.title}
              description={services.prodi.description}
              ctaLabel={services.prodi.cta}
              highlighted={highlighted === 'prodi'}
              delay={0.16}
              onActivate={activateProdi}
            />
            <ServiceCard
              id="service-panggil-staff"
              theme="blue"
              icon={StaffIcon}
              title={services.staff.title}
              description={services.staff.description}
              ctaLabel={services.staff.cta}
              highlighted={highlighted === 'staff'}
              delay={0.24}
              onActivate={activateStaff}
            />
          </div>
        </main>
      </div>

      <VirtualReceptionist />
    </div>
  )
}
