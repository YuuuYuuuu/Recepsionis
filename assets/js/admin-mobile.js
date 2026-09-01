(function () {
    'use strict';

    var body = document.body;
    var toggle = document.getElementById('adminMenuToggle');
    var backdrop = document.getElementById('adminSidebarBackdrop');
    var sidebar = document.getElementById('adminSidebar');

    document.querySelectorAll('[data-nav-accordion]').forEach(function (group) {
        var btn = group.querySelector('.nav-group-toggle');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function () {
            var open = group.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    if (!sidebar || !toggle) {
        return;
    }

    function openSidebar() {
        body.classList.add('admin-sidebar-open');
        toggle.setAttribute('aria-expanded', 'true');
        document.documentElement.style.overflow = 'hidden';
    }

    function closeSidebar() {
        body.classList.remove('admin-sidebar-open');
        toggle.setAttribute('aria-expanded', 'false');
        document.documentElement.style.overflow = '';
    }

    function toggleSidebar() {
        if (body.classList.contains('admin-sidebar-open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    toggle.addEventListener('click', toggleSidebar);

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    sidebar.querySelectorAll('a.nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.matchMedia('(max-width: 991.98px)').matches) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) {
            closeSidebar();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });
})();
