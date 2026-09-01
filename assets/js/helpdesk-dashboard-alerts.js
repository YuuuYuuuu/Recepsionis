/**
 * Helpdesk dashboard — laporan masuk sebagai card list + alert_1.lottie
 */
(function (global) {
    'use strict';

    var POLL_MS = 2500;
    var DISMISSED_KEY = 'recepsionis_dismissed_helpdesk_tickets';
    var knownTicketIds = new Set();
    var pollTimer = null;
    var config = {
        apiBase: '../api/',
        ticketsUrl: '',
        statusApiUrl: '',
        alertLottieUrl: '',
    };

    function apiBaseUrl() {
        var raw = global.__RECEPSIONIS_API_BASE_URL__ || config.apiBase;
        return String(raw).replace(/\/?$/, '/');
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function loadDismissedIds() {
        try {
            var raw = global.localStorage.getItem(DISMISSED_KEY);
            if (!raw) {
                return new Set();
            }
            var parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) {
                return new Set();
            }
            return new Set(parsed.map(function (id) { return Number(id); }).filter(function (id) { return id > 0; }));
        } catch (_) {
            return new Set();
        }
    }

    function saveDismissedIds(set) {
        try {
            global.localStorage.setItem(DISMISSED_KEY, JSON.stringify(Array.from(set)));
        } catch (_) {
            // ignore
        }
    }

    function displayName(ticket) {
        var name = String(ticket.nama || '').trim();
        if (name !== '') {
            return name;
        }
        var kelas = String(ticket.kelas || '').trim();
        if (kelas !== '') {
            return kelas;
        }
        return 'Pelapor';
    }

    function renderField(label, icon, value) {
        var safeValue = escapeHtml(value || '-');
        return ''
            + '<div class="hd-alert-field">'
            + '  <dt><i class="bi ' + icon + '"></i> ' + escapeHtml(label) + '</dt>'
            + '  <dd>' + safeValue + '</dd>'
            + '</div>';
    }

    function statusApiUrl() {
        if (config.statusApiUrl) {
            return config.statusApiUrl;
        }
        return apiBaseUrl() + 'helpdesk_it_update_status.php';
    }

    function renderCard(ticket, animateIn) {
        var id = Number(ticket.id) || 0;
        var timeAgo = escapeHtml(ticket.time_ago || 'Baru saja');

        return ''
            + '<article class="hd-alert-card' + (animateIn ? ' is-entering' : '') + '" data-ticket-id="' + id + '">'
            + '  <div class="hd-alert-card-lottie" aria-hidden="true">'
            + '    <dotlottie-wc src="' + escapeHtml(config.alertLottieUrl) + '" backgroundColor="transparent" speed="1" loop autoplay></dotlottie-wc>'
            + '  </div>'
            + '  <div class="hd-alert-card-body">'
            + '    <div class="hd-alert-card-head">'
            + '      <strong>' + escapeHtml(displayName(ticket)) + '</strong>'
            + '      <span>' + timeAgo + '</span>'
            + '    </div>'
            + '    <dl class="hd-alert-fields">'
            +          renderField('Nomor', 'bi-telephone-fill', ticket.nomor || '-')
            +          renderField('Kelas', 'bi-mortarboard-fill', ticket.kelas || '-')
            +          renderField('Kendala', 'bi-chat-left-text-fill', ticket.kendala || '-')
            + '    </dl>'
            + '    <div class="hd-alert-actions">'
            + '      <button type="button" class="hd-alert-btn hd-alert-btn--primary" data-hd-tindak="' + id + '">'
            + '        <i class="bi bi-hand-index-thumb"></i> Tindak'
            + '      </button>'
            + '      <button type="button" class="hd-alert-btn hd-alert-btn--ghost" data-hd-dismiss="' + id + '">'
            + '        <i class="bi bi-volume-mute-fill"></i> Hentikan suara'
            + '      </button>'
            + '    </div>'
            + '  </div>'
            + '</article>';
    }

    function getListEl() {
        return document.getElementById('hdAlertList');
    }

    function ringForNewTickets(newIds) {
        if (!newIds.length) {
            return;
        }
        if (global.recepsionisStaffCallNotify && typeof global.recepsionisStaffCallNotify.ringHelpdesk === 'function') {
            global.recepsionisStaffCallNotify.ringHelpdesk();
        }
    }

    function stopRing() {
        if (global.recepsionisStaffCallNotify && typeof global.recepsionisStaffCallNotify.stopRing === 'function') {
            global.recepsionisStaffCallNotify.stopRing();
        } else if (typeof global.dismissHelpdeskTicketNotification === 'function') {
            // fallback: existing helper stops sound when clearing notifications
        }
    }

    function removeCardFromList(ticketId) {
        var id = Number(ticketId);
        var card = document.querySelector('.hd-alert-card[data-ticket-id="' + id + '"]');
        if (card) {
            card.remove();
        }

        var list = getListEl();
        if (list && !list.querySelector('.hd-alert-card')) {
            list.innerHTML = '<div class="hd-alert-empty">Belum ada laporan masuk.</div>';
        }
    }

    function bindListActions(root) {
        var scope = root || document;

        scope.querySelectorAll('[data-hd-dismiss]').forEach(function (btn) {
            if (btn.dataset.bound === '1') {
                return;
            }
            btn.dataset.bound = '1';
            btn.addEventListener('click', function () {
                var id = Number(btn.getAttribute('data-hd-dismiss'));
                dismissTicket(id);
            });
        });

        scope.querySelectorAll('[data-hd-tindak]').forEach(function (btn) {
            if (btn.dataset.bound === '1') {
                return;
            }
            btn.dataset.bound = '1';
            btn.addEventListener('click', function () {
                var id = Number(btn.getAttribute('data-hd-tindak'));
                takeTicket(id, btn);
            });
        });
    }

    function dismissTicket(ticketId) {
        var id = Number(ticketId);
        if (id <= 0) {
            return;
        }
        var dismissed = loadDismissedIds();
        dismissed.add(id);
        saveDismissedIds(dismissed);
        knownTicketIds.add(id);

        removeCardFromList(id);
        stopRing();
        if (typeof global.dismissHelpdeskTicketNotification === 'function') {
            global.dismissHelpdeskTicketNotification(id);
        }
    }

    function takeTicket(ticketId, button) {
        var id = Number(ticketId);
        if (id <= 0) {
            return;
        }

        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="bi bi-hourglass-split"></i> Memproses...';
        }

        var formData = new FormData();
        formData.append('ticket_id', String(id));
        formData.append('status', 'in_progress');
        formData.append('claim', '1');

        fetch(statusApiUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'Gagal menindak tiket.');
                }

                knownTicketIds.add(id);
                var dismissed = loadDismissedIds();
                dismissed.add(id);
                saveDismissedIds(dismissed);

                removeCardFromList(id);
                stopRing();

                if (typeof global.dismissHelpdeskTicketNotification === 'function') {
                    global.dismissHelpdeskTicketNotification(id);
                }

                if (typeof global.showHelpdeskStatusNotify === 'function') {
                    global.showHelpdeskStatusNotify(
                        'Tiket ditindak',
                        'Tiket #' + id + ' sekarang ditangani oleh Anda.',
                        3200
                    );
                }
            })
            .catch(function (error) {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = '<i class="bi bi-hand-index-thumb"></i> Tindak';
                }
                global.alert(error.message || 'Gagal menindak tiket.');
            });
    }

    function updateCardContent(card, ticket) {
        if (!card) {
            return;
        }
        var timeEl = card.querySelector('.hd-alert-card-head span');
        if (timeEl) {
            timeEl.textContent = ticket.time_ago || 'Baru saja';
        }
        var fields = card.querySelectorAll('.hd-alert-field dd');
        if (fields.length >= 3) {
            fields[0].textContent = ticket.nomor || '-';
            fields[1].textContent = ticket.kelas || '-';
            fields[2].textContent = ticket.kendala || '-';
        }
        var titleEl = card.querySelector('.hd-alert-card-head strong');
        if (titleEl) {
            titleEl.textContent = displayName(ticket);
        }
    }

    function syncTickets(tickets) {
        var list = getListEl();
        if (!list) {
            return;
        }

        if (!Array.isArray(tickets)) {
            tickets = [];
        }

        var dismissed = loadDismissedIds();
        var visible = tickets.filter(function (ticket) {
            var id = Number(ticket.id);
            return id > 0 && !dismissed.has(id);
        });

        var newIds = [];
        visible.forEach(function (ticket) {
            var id = Number(ticket.id);
            if (!knownTicketIds.has(id)) {
                newIds.push(id);
            }
            knownTicketIds.add(id);
        });

        ringForNewTickets(newIds);

        list.querySelector('.hd-alert-empty')?.remove();

        if (visible.length === 0) {
            list.querySelectorAll('.hd-alert-card').forEach(function (card) {
                card.remove();
            });
            if (!list.querySelector('.hd-alert-empty')) {
                list.innerHTML = '<div class="hd-alert-empty">Belum ada laporan masuk.</div>';
            }
            if (tickets.length === 0) {
                stopRing();
            }
            return;
        }

        var visibleIds = new Set(visible.map(function (ticket) { return Number(ticket.id); }));
        list.querySelectorAll('.hd-alert-card').forEach(function (card) {
            var id = Number(card.getAttribute('data-ticket-id'));
            if (!visibleIds.has(id)) {
                card.remove();
            }
        });

        visible.forEach(function (ticket) {
            var id = Number(ticket.id);
            var existing = list.querySelector('.hd-alert-card[data-ticket-id="' + id + '"]');
            if (existing) {
                updateCardContent(existing, ticket);
                return;
            }

            var wrapper = document.createElement('div');
            wrapper.innerHTML = renderCard(ticket, true);
            var card = wrapper.firstElementChild;
            if (!card) {
                return;
            }
            list.appendChild(card);
            bindListActions(card);
            global.setTimeout(function () {
                card.classList.remove('is-entering');
            }, 400);
        });
    }

    function pollTickets() {
        fetch(apiBaseUrl() + 'get_pending_helpdesk_it_tickets.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    return;
                }
                if (data.notifications_enabled === false) {
                    syncTickets([]);
                    return;
                }
                syncTickets(data.tickets || []);
            })
            .catch(function (error) {
                console.error('Error polling helpdesk dashboard alerts:', error);
            });
    }

    function init(options) {
        config.apiBase = options && options.apiBase ? options.apiBase : config.apiBase;
        config.ticketsUrl = options && options.ticketsUrl ? options.ticketsUrl : '';
        config.statusApiUrl = options && options.statusApiUrl ? options.statusApiUrl : '';
        config.alertLottieUrl = options && options.alertLottieUrl ? options.alertLottieUrl : '';

        if (!getListEl()) {
            return;
        }

        pollTickets();
        pollTimer = global.setInterval(pollTickets, POLL_MS);

        global.addEventListener('focus', pollTickets);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                pollTickets();
            }
        });
    }

    global.recepsionisHelpdeskDashboardAlerts = {
        init: init,
        syncTickets: syncTickets,
        dismiss: dismissTicket,
    };

    global.addEventListener('beforeunload', function () {
        if (pollTimer) {
            global.clearInterval(pollTimer);
        }
    });
})(window);
