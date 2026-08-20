/**
 * Staff Call Notification - popup + polling + preferensi suara per admin.
 */

(function() {
    'use strict';

    if (window.__STAFF_CALL_NOTIFY_INIT__) {
        return;
    }
    window.__STAFF_CALL_NOTIFY_INIT__ = true;

    let audioElement = null;
    let audioFileAvailable = true;
    let audioContext = null;
    let isPlaying = false;
    let knownCallIds = new Set();
    let knownTicketIds = new Set();
    let checkInterval = null;
    let notificationContainer = null;
    let soundInterval = null;
    let audioEnabled = false;
    let pendingSoundRequest = false;
    let notificationsEnabled = (function() {
        try {
            const saved = window.localStorage.getItem('recepsionis_staff_call_notifications_enabled');
            return saved === null ? true : saved === '1';
        } catch (_) {
            return true;
        }
    })();
    let soundEnabled = (function() {
        try {
            const saved = window.localStorage.getItem('recepsionis_staff_call_sound_enabled');
            return saved === null ? true : saved === '1';
        } catch (_) {
            return true;
        }
    })();
    let socketClientLoader = null;

    const POLL_MS = 2500;
    const DISMISSED_TICKETS_KEY = 'recepsionis_dismissed_helpdesk_tickets';

    function loadDismissedTicketIds() {
        try {
            const raw = window.localStorage.getItem(DISMISSED_TICKETS_KEY);
            if (!raw) {
                return new Set();
            }
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) {
                return new Set();
            }
            return new Set(parsed.map(function(id) { return Number(id); }).filter(function(id) { return id > 0; }));
        } catch (_) {
            return new Set();
        }
    }

    function saveDismissedTicketIds() {
        try {
            window.localStorage.setItem(
                DISMISSED_TICKETS_KEY,
                JSON.stringify(Array.from(dismissedTicketIds))
            );
        } catch (_) {
            // ignore
        }
    }

    let dismissedTicketIds = loadDismissedTicketIds();

    function adminBaseUrl() {
        const raw = window.__RECEPSIONIS_ADMIN_BASE_URL__ || '../admin/';
        return String(raw).replace(/\/?$/, '/');
    }

    function apiBaseUrl() {
        const raw = window.__RECEPSIONIS_API_BASE_URL__ || '../api/';
        return String(raw).replace(/\/?$/, '/');
    }

    function socketBaseUrl() {
        const raw = window.__LIVE_SOCKET_URL__ || 'http://127.0.0.1:3001';
        return String(raw).replace(/\/+$/, '');
    }

    function socketTokenUrl() {
        return window.__SOCKET_TOKEN_URL__ || (apiBaseUrl() + 'socket_token.php');
    }

    function loadSocketIoClient() {
        if (typeof window.io === 'function') {
            return Promise.resolve(window.io);
        }
        if (socketClientLoader) {
            return socketClientLoader;
        }

        socketClientLoader = new Promise(function(resolve, reject) {
            const script = document.createElement('script');
            script.src = new URL('/socket.io/socket.io.js', socketBaseUrl()).href;
            script.async = true;
            script.onload = function() {
                if (typeof window.io === 'function') {
                    resolve(window.io);
                    return;
                }
                reject(new Error('Socket.io client tidak tersedia.'));
            };
            script.onerror = function() {
                reject(new Error('Gagal memuat socket.io client.'));
            };
            document.head.appendChild(script);
        });

        return socketClientLoader;
    }

    function showTemporaryInfo(callId, message) {
        const notification = document.querySelector('.staff-call-notification[data-call-id="' + callId + '"]');
        if (!notification) {
            return;
        }
        const body = notification.querySelector('.scn-details');
        const actions = notification.querySelector('.scn-actions');
        if (!body || !actions) {
            return;
        }

        let info = notification.querySelector('.notification-inline-info');
        if (!info) {
            info = document.createElement('div');
            info.className = 'notification-inline-info scn-live-hint';
            body.appendChild(info);
        }
        info.innerHTML = '<i class="bi bi-check-circle"></i> ' + escapeHtml(message);
        actions.style.opacity = '0.7';
    }

    function connectAdminSocket() {
        return fetch(socketTokenUrl(), { credentials: 'same-origin' })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data || !data.success || !data.token) {
                    throw new Error(data && data.message ? data.message : 'Gagal mendapatkan token realtime.');
                }
                return loadSocketIoClient().then(function(io) {
                    return new Promise(function(resolve, reject) {
                        const socket = io(socketBaseUrl(), {
                            auth: { token: data.token },
                            transports: ['polling', 'websocket'],
                            reconnection: false
                        });

                        const cleanupError = function(err) {
                            socket.off('connect', handleConnect);
                            socket.off('connect_error', handleError);
                            try {
                                socket.disconnect();
                            } catch (_) {
                                // ignore
                            }
                            reject(err instanceof Error ? err : new Error(String(err || 'Koneksi realtime gagal.')));
                        };

                        const handleConnect = function() {
                            socket.off('connect_error', handleError);
                            resolve(socket);
                        };

                        const handleError = function(err) {
                            cleanupError(err);
                        };

                        socket.once('connect', handleConnect);
                        socket.once('connect_error', handleError);
                    });
                });
            });
    }

    function preferencesUrl() {
        return apiBaseUrl() + 'admin_notification_preferences.php';
    }

    function removeLegacyToolbar() {
        document.querySelectorAll('.staff-call-toolbar, .staff-call-pref-toggle, .staff-call-sound-toggle').forEach(function(node) {
            node.remove();
        });
        const container = document.getElementById('staff-call-notifications');
        if (container) {
            container.querySelectorAll('.staff-call-toolbar').forEach(function(node) {
                node.remove();
            });
        }
    }

    function ensureNotificationContainer() {
        removeLegacyToolbar();
        if (notificationContainer) {
            return notificationContainer;
        }
        notificationContainer = document.createElement('div');
        notificationContainer.id = 'staff-call-notifications';
        notificationContainer.className = 'staff-call-notifications-container';
        document.body.appendChild(notificationContainer);
        return notificationContainer;
    }

    function ringtoneUrl() {
        if (window.__RECEPSIONIS_ASSETS_BASE_URL__) {
            return String(window.__RECEPSIONIS_ASSETS_BASE_URL__).replace(/\/?$/, '/') + 'nada.mp3';
        }
        try {
            return new URL('../assets/nada.mp3', window.location.href).href;
        } catch (_) {
            return '../assets/nada.mp3';
        }
    }

    function persistPreferences(payload) {
        fetch(preferencesUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        }).catch(function(error) {
            console.error('Error saving staff call preference:', error);
        });
    }

    function clearActiveNotifications() {
        knownCallIds.clear();
        knownTicketIds.clear();
        stopNotificationSound();
        document.querySelectorAll('.staff-call-notification').forEach(function(node) {
            node.remove();
        });
    }

    function setNotificationsEnabled(nextValue, persist) {
        notificationsEnabled = !!nextValue;
        try {
            window.localStorage.setItem('recepsionis_staff_call_notifications_enabled', notificationsEnabled ? '1' : '0');
        } catch (_) {
            // ignore
        }
        if (!notificationsEnabled) {
            clearActiveNotifications();
        }
        if (!persist) {
            return;
        }
        persistPreferences({ notifications_enabled: notificationsEnabled });
    }

    function setSoundEnabled(nextValue, persist) {
        soundEnabled = !!nextValue;
        try {
            window.localStorage.setItem('recepsionis_staff_call_sound_enabled', soundEnabled ? '1' : '0');
        } catch (_) {
            // ignore
        }
        if (!soundEnabled) {
            pendingSoundRequest = false;
            stopNotificationSound();
        }
        if (!persist) {
            return;
        }
        persistPreferences({ sound_enabled: soundEnabled });
    }

    function applyPreferences(notifOn, soundOn, persist) {
        setNotificationsEnabled(!!notifOn, !!persist);
        setSoundEnabled(!!soundOn, !!persist);
    }

    function loadPreferences() {
        return fetch(preferencesUrl(), { credentials: 'same-origin' })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data && data.success && data.preferences) {
                    if (typeof data.preferences.notifications_enabled === 'boolean') {
                        notificationsEnabled = data.preferences.notifications_enabled;
                    }
                    soundEnabled = !!data.preferences.sound_enabled;
                    try {
                        window.localStorage.setItem(
                            'recepsionis_staff_call_notifications_enabled',
                            notificationsEnabled ? '1' : '0'
                        );
                        window.localStorage.setItem('recepsionis_staff_call_sound_enabled', soundEnabled ? '1' : '0');
                    } catch (_) {
                        // ignore
                    }
                    if (!notificationsEnabled) {
                        clearActiveNotifications();
                    }
                }
            })
            .catch(function(error) {
                console.error('Error loading staff call preference:', error);
            });
    }

    function initAudio() {
        if (audioElement) {
            return;
        }

        audioElement = new Audio();
        audioElement.src = ringtoneUrl();
        audioElement.volume = 0.8;
        audioElement.preload = 'auto';
        audioElement.load();

        audioElement.addEventListener('ended', function() {
            isPlaying = false;
        });

        audioElement.addEventListener('error', function(e) {
            console.error('Error loading audio file:', e);
            audioFileAvailable = false;
        });

        const enableAudioOnInteraction = function() {
            enableAudioAndMaybeRing();
        };

        const addUnlockListeners = function() {
            document.addEventListener('click', enableAudioOnInteraction);
            document.addEventListener('keydown', enableAudioOnInteraction);
            document.addEventListener('touchstart', enableAudioOnInteraction);
            document.addEventListener('mousedown', enableAudioOnInteraction);
        };

        const removeUnlockListeners = function() {
            document.removeEventListener('click', enableAudioOnInteraction);
            document.removeEventListener('keydown', enableAudioOnInteraction);
            document.removeEventListener('touchstart', enableAudioOnInteraction);
            document.removeEventListener('mousedown', enableAudioOnInteraction);
        };

        window.__staffCallAddUnlockListeners = addUnlockListeners;
        window.__staffCallRemoveUnlockListeners = removeUnlockListeners;
        addUnlockListeners();
    }

    function enableAudioAndMaybeRing() {
        audioEnabled = true;
        if (!audioElement || !soundEnabled) {
            return;
        }
        if (!audioFileAvailable) {
            if (pendingSoundRequest) {
                playFallbackTone();
                pendingSoundRequest = false;
            }
            return;
        }

        try {
            const playPromise = audioElement.play();
            if (playPromise !== undefined) {
                playPromise
                    .then(function() {
                        audioElement.pause();
                        audioElement.currentTime = 0;
                        if (pendingSoundRequest) {
                            pendingSoundRequest = false;
                            playNotificationSound();
                        }
                        if (window.__staffCallRemoveUnlockListeners) {
                            window.__staffCallRemoveUnlockListeners();
                        }
                    })
                    .catch(function() {
                        // Browser still blocks until explicit user interaction.
                    });
            }
        } catch (_) {
            // Ignore and wait for next interaction.
        }
    }

    function playNotificationSound() {
        if (!soundEnabled || !notificationsEnabled) {
            return;
        }
        if (!audioEnabled) {
            pendingSoundRequest = true;
            return;
        }
        if (isPlaying) {
            return;
        }

        if (audioFileAvailable && audioElement) {
            try {
                audioElement.currentTime = 0;
                const playPromise = audioElement.play();
                if (playPromise !== undefined) {
                    playPromise
                        .then(function() {
                            isPlaying = true;
                        })
                        .catch(function() {
                            audioFileAvailable = false;
                            playFallbackTone();
                        });
                    return;
                }
            } catch (e) {
                audioFileAvailable = false;
            }
        }
        playFallbackTone();
    }

    function playFallbackTone() {
        if (!soundEnabled) {
            return;
        }
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) {
                return;
            }
            if (!audioContext) {
                audioContext = new Ctx();
            }
            if (audioContext.state === 'suspended') {
                audioContext.resume();
            }

            const now = audioContext.currentTime;
            [0, 0.45].forEach(function(offset) {
                const start = now + offset;
                const osc = audioContext.createOscillator();
                const gain = audioContext.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(950, start);
                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.2, start + 0.03);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.3);
                osc.connect(gain);
                gain.connect(audioContext.destination);
                osc.start(start);
                osc.stop(start + 0.32);
            });
            isPlaying = true;
            setTimeout(function() {
                isPlaying = false;
            }, 900);
        } catch (e) {
            console.error('Error playing fallback tone:', e);
        }
    }

    function stopNotificationSound() {
        isPlaying = false;
        if (audioElement) {
            try {
                audioElement.pause();
                audioElement.currentTime = 0;
            } catch (e) {
                console.error('Error stopping sound:', e);
            }
        }
        if (knownCallIds.size === 0 && knownTicketIds.size === 0 && soundInterval) {
            clearInterval(soundInterval);
            soundInterval = null;
        }
    }

    function startSoundLoopForActiveItems() {
        if (soundInterval) {
            return;
        }
        soundInterval = setInterval(function() {
            if (knownCallIds.size === 0 && knownTicketIds.size === 0) {
                clearInterval(soundInterval);
                soundInterval = null;
                return;
            }
            playNotificationSound();
        }, 2200);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function visitorInitials(name) {
        const parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
        if (parts.length === 0) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    function triggerSoundInSyncWithPaint() {
        const run = function() { playNotificationSound(); };
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(function() {
                requestAnimationFrame(run);
            });
        } else {
            setTimeout(run, 0);
        }
    }

    function createNotificationPopup(call) {
        const notification = document.createElement('div');
        notification.className = 'staff-call-notification';
        notification.dataset.callId = call.id;
        const acceptBtn = '<button type="button" class="scn-btn scn-btn--accept" onclick="answerStaffCall(' + call.id + ', this)">' +
                '<i class="bi bi-check-circle-fill"></i> Terima panggilan</button>';

        const title = 'Panggilan Staff';
        const category = escapeHtml(call.category_name || 'Tanpa kategori');
        const phone = escapeHtml(call.visitor_phone || '-');
        const message = escapeHtml(call.message || '-');
        const name = escapeHtml(call.visitor_name || 'Tamu');
        const initials = escapeHtml(visitorInitials(call.visitor_name));

        notification.innerHTML = ''
            + '<div class="scn-card">'
            + '  <div class="scn-header">'
            + '    <div class="scn-header-icon" aria-hidden="true"><i class="bi bi-telephone-inbound-fill"></i></div>'
            + '    <div class="scn-header-text">'
            + '      <div class="scn-title-row">'
            + '        <span class="scn-pulse-dot" aria-hidden="true"></span>'
            + '        <span class="scn-title">' + title + '</span>'
            + '      </div>'
            + '      <span class="scn-subtitle">Membutuhkan respons PIC</span>'
            + '    </div>'
            + '    <button type="button" class="scn-close" onclick="stopStaffCallNotification(' + call.id + ')" aria-label="Tutup">'
            + '      <i class="bi bi-x-lg"></i>'
            + '    </button>'
            + '  </div>'
            + '  <div class="scn-body">'
            + '    <div class="scn-visitor">'
            + '      <div class="scn-avatar">' + initials + '</div>'
            + '      <div class="scn-visitor-info">'
            + '        <div class="scn-name">' + name + '</div>'
            + '        <div class="scn-category"><i class="bi bi-tag-fill"></i> ' + category + '</div>'
            + '      </div>'
            + '    </div>'
            + '    <div class="scn-details">'
            + '      <div class="scn-detail">'
            + '        <span class="scn-detail-label"><i class="bi bi-telephone-fill"></i> Telepon</span>'
            + '        <span class="scn-detail-value">' + phone + '</span>'
            + '      </div>'
            + '      <div class="scn-detail scn-detail--message">'
            + '        <span class="scn-detail-label"><i class="bi bi-chat-left-text-fill"></i> Keperluan</span>'
            + '        <span class="scn-detail-value">' + message + '</span>'
            + '      </div>'
            + '    </div>'
            + '    <div class="scn-actions">'
            +          acceptBtn
            + '      <button type="button" class="scn-btn scn-btn--mute" onclick="stopStaffCallNotification(' + call.id + ')">'
            + '        <i class="bi bi-volume-mute-fill"></i> Hentikan suara'
            + '      </button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        return notification;
    }

    function createHelpdeskTicketPopup(ticket) {
        const notification = document.createElement('div');
        notification.className = 'staff-call-notification scn-helpdesk-ticket';
        notification.dataset.ticketId = ticket.id;
        notification.dataset.notificationType = 'helpdesk_it';

        const name = escapeHtml(ticket.nama || 'Pelapor');
        const nomor = escapeHtml(ticket.nomor || '-');
        const kelas = escapeHtml(ticket.kelas || '-');
        const kendala = escapeHtml(ticket.kendala || '-');
        const initials = escapeHtml(visitorInitials(ticket.nama));

        notification.innerHTML = ''
            + '<div class="scn-card">'
            + '  <div class="scn-header scn-header--helpdesk">'
            + '    <div class="scn-header-icon" aria-hidden="true"><i class="bi bi-headset"></i></div>'
            + '    <div class="scn-header-text">'
            + '      <div class="scn-title-row">'
            + '        <span class="scn-pulse-dot" aria-hidden="true"></span>'
            + '        <span class="scn-title">Tiket Helpdesk IT</span>'
            + '      </div>'
            + '      <span class="scn-subtitle">Laporan dari form QR kelas</span>'
            + '    </div>'
            + '    <button type="button" class="scn-close" onclick="dismissHelpdeskTicketNotification(' + ticket.id + ')" aria-label="Tutup">'
            + '      <i class="bi bi-x-lg"></i>'
            + '    </button>'
            + '  </div>'
            + '  <div class="scn-body">'
            + '    <div class="scn-visitor">'
            + '      <div class="scn-avatar scn-avatar--helpdesk">' + initials + '</div>'
            + '      <div class="scn-visitor-info">'
            + '        <div class="scn-name">' + name + '</div>'
            + '        <div class="scn-category"><i class="bi bi-ticket-detailed-fill"></i> Helpdesk IT</div>'
            + '      </div>'
            + '    </div>'
            + '    <div class="scn-details">'
            + '      <div class="scn-detail">'
            + '        <span class="scn-detail-label"><i class="bi bi-telephone-fill"></i> Nomor</span>'
            + '        <span class="scn-detail-value">' + nomor + '</span>'
            + '      </div>'
            + '      <div class="scn-detail">'
            + '        <span class="scn-detail-label"><i class="bi bi-mortarboard-fill"></i> Kelas</span>'
            + '        <span class="scn-detail-value">' + kelas + '</span>'
            + '      </div>'
            + '      <div class="scn-detail scn-detail--message">'
            + '        <span class="scn-detail-label"><i class="bi bi-chat-left-text-fill"></i> Kendala</span>'
            + '        <span class="scn-detail-value">' + kendala + '</span>'
            + '      </div>'
            + '    </div>'
            + '    <div class="scn-actions">'
            + '      <button type="button" class="scn-btn scn-btn--accept" onclick="openHelpdeskTicketFromNotification(' + ticket.id + ')">'
            + '        <i class="bi bi-box-arrow-up-right"></i> Buka tiket'
            + '      </button>'
            + '      <button type="button" class="scn-btn scn-btn--mute" onclick="dismissHelpdeskTicketNotification(' + ticket.id + ')">'
            + '        <i class="bi bi-volume-mute-fill"></i> Hentikan suara'
            + '      </button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        return notification;
    }

    function showHelpdeskNotification(ticket) {
        const ticketId = Number(ticket.id);
        if (!notificationsEnabled || ticketId <= 0) {
            return;
        }
        if (dismissedTicketIds.has(ticketId)) {
            knownTicketIds.add(ticketId);
            return;
        }
        if (knownTicketIds.has(ticketId)) {
            return;
        }

        knownTicketIds.add(ticketId);
        const container = ensureNotificationContainer();
        const notification = createHelpdeskTicketPopup(ticket);
        container.appendChild(notification);

        triggerSoundInSyncWithPaint();
        startSoundLoopForActiveItems();

        setTimeout(function() {
            if (notification.parentNode) {
                notification.remove();
                knownTicketIds.delete(ticketId);
                stopNotificationSound();
            }
        }, 30000);
    }

    function dismissHelpdeskTicket(ticketId) {
        const id = Number(ticketId);
        if (id <= 0) {
            return;
        }
        dismissedTicketIds.add(id);
        saveDismissedTicketIds();
        knownTicketIds.add(id);
        removeHelpdeskNotification(id, false);
    }

    function removeHelpdeskNotification(ticketId, clearDismissed) {
        const id = Number(ticketId);
        knownTicketIds.delete(id);
        if (clearDismissed && dismissedTicketIds.has(id)) {
            dismissedTicketIds.delete(id);
            saveDismissedTicketIds();
        }
        stopNotificationSound();

        const notification = document.querySelector('.staff-call-notification[data-ticket-id="' + id + '"]');
        if (notification) {
            notification.remove();
        }
    }

    function helpdeskTicketsPageUrl() {
        return adminBaseUrl() + 'staff_calls.php?channel=tickets&status=pending';
    }

    function isOnHelpdeskTicketsPage() {
        try {
            const path = window.location.pathname || '';
            if (path.indexOf('staff_calls.php') === -1) {
                return false;
            }
            const params = new URLSearchParams(window.location.search || '');
            return params.get('channel') === 'tickets';
        } catch (_) {
            return false;
        }
    }

    function showNotification(call) {
        if (!notificationsEnabled || knownCallIds.has(call.id)) {
            return;
        }

        knownCallIds.add(call.id);
        const container = ensureNotificationContainer();
        const notification = createNotificationPopup(call);
        container.appendChild(notification);

        triggerSoundInSyncWithPaint();
        startSoundLoopForActiveItems();

        setTimeout(function() {
            if (notification.parentNode) {
                notification.remove();
                knownCallIds.delete(call.id);
                stopNotificationSound();
            }
        }, 30000);
    }

    function removeNotification(callId) {
        knownCallIds.delete(callId);
        stopNotificationSound();

        const notification = document.querySelector('.staff-call-notification[data-call-id="' + callId + '"]');
        if (notification) {
            notification.remove();
        }
    }

    function checkStaffCalls() {
        if (!notificationsEnabled) {
            return;
        }

        fetch(apiBaseUrl() + 'get_pending_staff_calls.php', { credentials: 'same-origin' })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    return;
                }

                if (data.notifications_enabled === false) {
                    notificationsEnabled = false;
                    clearActiveNotifications();
                    return;
                }

                if (typeof data.sound_enabled === 'boolean') {
                    soundEnabled = data.sound_enabled;
                }

                if (!Array.isArray(data.calls)) {
                    return;
                }

                data.calls.forEach(function(call) {
                    if (!knownCallIds.has(call.id)) {
                        showNotification(call);
                    }
                });

                const currentCallIds = new Set(data.calls.map(function(call) { return call.id; }));
                Array.from(knownCallIds).forEach(function(callId) {
                    if (!currentCallIds.has(callId)) {
                        removeNotification(callId);
                    }
                });
            })
            .catch(function(error) {
                console.error('Error checking staff calls:', error);
            });
    }

    function checkHelpdeskTickets() {
        if (!notificationsEnabled) {
            return;
        }

        fetch(apiBaseUrl() + 'get_pending_helpdesk_it_tickets.php', { credentials: 'same-origin' })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    return;
                }

                if (typeof data.sound_enabled === 'boolean') {
                    soundEnabled = data.sound_enabled;
                }

                if (!Array.isArray(data.tickets)) {
                    return;
                }

                data.tickets.forEach(function(ticket) {
                    const ticketId = Number(ticket.id);
                    if (dismissedTicketIds.has(ticketId)) {
                        knownTicketIds.add(ticketId);
                        return;
                    }
                    if (!knownTicketIds.has(ticketId)) {
                        showHelpdeskNotification(ticket);
                    }
                });

                const currentTicketIds = new Set(data.tickets.map(function(ticket) { return Number(ticket.id); }));
                Array.from(knownTicketIds).forEach(function(ticketId) {
                    if (!currentTicketIds.has(ticketId)) {
                        removeHelpdeskNotification(ticketId, true);
                    }
                });
                Array.from(dismissedTicketIds).forEach(function(ticketId) {
                    if (!currentTicketIds.has(ticketId)) {
                        dismissedTicketIds.delete(ticketId);
                    }
                });
                saveDismissedTicketIds();
            })
            .catch(function(error) {
                console.error('Error checking helpdesk tickets:', error);
            });
    }

    function formatActionCount(count) {
        const value = parseInt(count, 10) || 0;
        return value > 99 ? '99+' : String(value);
    }

    function upsertHelpdeskBadge(host, badgeSelector, count, badgeClass, badgeAttr) {
        if (!host) {
            return;
        }

        let badge = host.querySelector(badgeSelector);
        if (count <= 0) {
            if (badge) {
                badge.remove();
            }
            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = badgeClass;
            if (badgeAttr) {
                badge.setAttribute('data-helpdesk-badge', badgeAttr);
            }
            host.appendChild(badge);
        }

        badge.textContent = formatActionCount(count);
    }

    function updateSegmentBadge(link, count) {
        if (!link) {
            return;
        }

        let badge = link.querySelector('.adm-segment-badge');
        if (count <= 0) {
            if (badge) {
                badge.remove();
            }
            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'adm-segment-badge';
            link.appendChild(badge);
        }

        badge.textContent = formatActionCount(count);
    }

    function updateHelpdeskActionBadges(counts) {
        if (!counts) {
            return;
        }

        const total = parseInt(counts.total, 10) || 0;
        const calls = parseInt(counts.calls, 10) || 0;
        const tickets = parseInt(counts.tickets, 10) || 0;

        const sidebarLink = document.querySelector('a.nav-link[data-helpdesk-nav="sidebar"], a.nav-link[href*="staff_calls.php"]');
        upsertHelpdeskBadge(
            sidebarLink,
            '.helpdesk-action-badge',
            total,
            'badge bg-danger rounded-pill notification-badge helpdesk-action-badge',
            'total'
        );

        document.querySelectorAll('[data-helpdesk-badge]').forEach(function(el) {
            const key = el.getAttribute('data-helpdesk-badge');
            if (!key || el.classList.contains('helpdesk-action-badge')) {
                return;
            }

            let value = 0;
            if (key === 'total' || key === 'pending') {
                value = total;
            } else if (key === 'calls') {
                value = calls;
            } else if (key === 'tickets') {
                value = tickets;
            }

            updateSegmentBadge(el, value);
        });

        const heroBadge = document.querySelector('.pic-dash-hero-badge[data-helpdesk-badge="total"]');
        if (heroBadge) {
            if (total <= 0) {
                heroBadge.remove();
            } else {
                heroBadge.textContent = formatActionCount(total) + ' perlu ditanggapi';
            }
        }

        const dashboardCardIcon = document.querySelector('[data-helpdesk-nav="dashboard-card"] .pic-dash-action-icon');
        upsertHelpdeskBadge(
            dashboardCardIcon,
            '.pic-dash-action-badge',
            total,
            'pic-dash-action-badge helpdesk-action-badge',
            'total'
        );

        const cardHeaderBadge = document.querySelector('.pic-dash-card-header .helpdesk-action-badge[data-helpdesk-badge="total"]');
        if (cardHeaderBadge) {
            if (total <= 0) {
                cardHeaderBadge.remove();
            } else {
                cardHeaderBadge.textContent = formatActionCount(total);
            }
        }
    }

    function checkHelpdeskActionCounts() {
        fetch(apiBaseUrl() + 'get_helpdesk_action_counts.php', { credentials: 'same-origin' })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data && data.success) {
                    updateHelpdeskActionBadges(data);
                }
            })
            .catch(function(error) {
                console.error('Error checking helpdesk action counts:', error);
            });
    }

    function checkIncomingNotifications() {
        checkStaffCalls();
        checkHelpdeskTickets();
        checkHelpdeskActionCounts();
    }

    window.stopStaffCallNotification = function(callId) {
        removeNotification(callId);
    };

    window.stopHelpdeskTicketNotification = function(ticketId) {
        dismissHelpdeskTicket(ticketId);
    };

    window.dismissHelpdeskTicketNotification = function(ticketId) {
        dismissHelpdeskTicket(ticketId);
    };

    window.openHelpdeskTicketFromNotification = function(ticketId) {
        dismissHelpdeskTicket(ticketId);
        if (!isOnHelpdeskTicketsPage()) {
            window.location.href = helpdeskTicketsPageUrl();
        }
    };

    window.acceptLiveChatFromNotification = function(callId, button) {
        // Live chat dinonaktifkan — terima sebagai panggilan staff biasa.
        return window.answerStaffCall(callId, button);
    };

    window.answerStaffCall = function(callId, button) {
        button.disabled = true;
        button.innerHTML = '<i class="bi bi-hourglass-split"></i> Memproses...';

        const formData = new FormData();
        formData.append('call_id', callId);

        fetch(apiBaseUrl() + 'answer_staff_call.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    removeNotification(callId);
                    setTimeout(function() {
                        window.location.reload();
                    }, 500);
                } else {
                    alert(data.message || 'Gagal menandai panggilan sebagai terjawab');
                    button.disabled = false;
                    button.innerHTML = '<i class="bi bi-check-circle"></i> Terima';
                }
            })
            .catch(function(error) {
                console.error('Error answering call:', error);
                alert('Terjadi kesalahan');
                button.disabled = false;
                button.innerHTML = '<i class="bi bi-check-circle"></i> Terima';
            });
    };

    function init() {
        removeLegacyToolbar();
        initAudio();
        loadPreferences().then(function() {
            enableAudioAndMaybeRing();
            checkIncomingNotifications();
            checkInterval = setInterval(checkIncomingNotifications, POLL_MS);
        });

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                checkIncomingNotifications();
            }
        });

        window.addEventListener('focus', checkIncomingNotifications);
    }

    window.recepsionisStaffCallNotify = {
        applyPreferences: applyPreferences,
        unlockAudio: enableAudioAndMaybeRing,
        testSound: function() {
            audioEnabled = true;
            playFallbackTone();
            if (audioFileAvailable && audioElement) {
                playNotificationSound();
            }
        },
        getState: function() {
            return {
                notificationsEnabled: notificationsEnabled,
                soundEnabled: soundEnabled,
                audioEnabled: audioEnabled,
            };
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.addEventListener('beforeunload', function() {
        if (checkInterval) {
            clearInterval(checkInterval);
        }
        if (soundInterval) {
            clearInterval(soundInterval);
        }
        stopNotificationSound();
    });
})();
