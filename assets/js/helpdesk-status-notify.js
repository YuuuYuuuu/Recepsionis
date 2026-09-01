(function (global) {
    'use strict';

    var DOTLOTTIE_SRC = 'https://unpkg.com/@dotlottie/player-component@2.7.12/dist/dotlottie-player.mjs';
    var playerReadyPromise = null;

    function assetsBaseUrl() {
        var base = global.__RECEPSIONIS_ASSETS_BASE_URL__ || '../assets/';
        return base.charAt(base.length - 1) === '/' ? base : base + '/';
    }

    function doneLottieUrl() {
        return assetsBaseUrl() + 'images/Done_1.lottie';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function ensureDotLottiePlayer() {
        if (global.customElements && global.customElements.get('dotlottie-player')) {
            return Promise.resolve();
        }
        if (playerReadyPromise) {
            return playerReadyPromise;
        }
        playerReadyPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.type = 'module';
            script.src = DOTLOTTIE_SRC;
            script.onload = function () {
                if (global.customElements && global.customElements.whenDefined) {
                    global.customElements.whenDefined('dotlottie-player').then(resolve).catch(reject);
                } else {
                    resolve();
                }
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });
        return playerReadyPromise;
    }

    function getContainer() {
        var container = document.getElementById('hdStatusNotifyContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'hdStatusNotifyContainer';
            container.className = 'hd-status-notify-container';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }
        return container;
    }

    function removeToast(toast) {
        if (!toast || !toast.parentElement) return;
        toast.classList.add('is-leaving');
        setTimeout(function () {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 280);
    }

    function showHelpdeskStatusNotify(title, message, duration) {
        duration = typeof duration === 'number' ? duration : 4200;
        var safeTitle = escapeHtml(title || 'Status diperbarui');
        var safeMessage = escapeHtml(message || '');

        return ensureDotLottiePlayer()
            .then(function () {
                var container = getContainer();
                var toast = document.createElement('div');
                toast.className = 'hd-status-notify';
                toast.innerHTML =
                    '<div class="hd-status-notify-lottie">'
                    + '<dotlottie-player src="' + escapeHtml(doneLottieUrl()) + '" background="transparent" speed="1" loop="false" autoplay></dotlottie-player>'
                    + '</div>'
                    + '<div class="hd-status-notify-text"><strong>' + safeTitle + '</strong><span>' + safeMessage + '</span></div>'
                    + '<button type="button" class="hd-status-notify-close" aria-label="Tutup">&times;</button>';

                var closeBtn = toast.querySelector('.hd-status-notify-close');
                if (closeBtn) {
                    closeBtn.addEventListener('click', function () {
                        removeToast(toast);
                    });
                }

                container.appendChild(toast);

                setTimeout(function () {
                    removeToast(toast);
                }, duration);

                return toast;
            })
            .catch(function () {
                if (typeof global.showSuccess === 'function') {
                    return global.showSuccess(title, message, duration);
                }
            });
    }

    function helpdeskStatusLabel(status) {
        var map = {
            pending: 'Pending',
            in_progress: 'Sedang diproses',
            resolved: 'Selesai',
            expired: 'Expired',
        };
        return map[String(status || '').toLowerCase()] || String(status || '');
    }

    global.showHelpdeskStatusNotify = showHelpdeskStatusNotify;
    global.helpdeskStatusLabel = helpdeskStatusLabel;
})(window);
