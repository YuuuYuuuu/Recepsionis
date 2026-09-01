(function (global) {
  'use strict';

  function defaultLogoUrl() {
    if (global.__QR_LOGO_URL__) {
      return String(global.__QR_LOGO_URL__);
    }
    return '../assets/images/official-logo-demk.png';
  }

  function logoAspectRatio() {
    var ratio = Number(global.__QR_LOGO_ASPECT__);
    return Number.isFinite(ratio) && ratio > 0 ? ratio : 3.84;
  }

  function logoPlate(px) {
    var ratio = logoAspectRatio();
    var width = Math.max(28, Math.round(px * 0.3));
    var height = Math.max(10, Math.round(width / ratio));
    var padX = Math.max(4, Math.round(width * 0.08));
    var padY = Math.max(3, Math.round(height * 0.12));
    var plateW = width + padX * 2;
    var plateH = height + padY * 2;
    var radius = Math.max(4, Math.round(plateH * 0.24));

    return { width: width, height: height, plateW: plateW, plateH: plateH, radius: radius };
  }

  function renderQrWithLogo(container, url, size, logoUrl) {
    if (!container || !url || typeof global.QRCode === 'undefined') {
      return;
    }

    var px = Number(size) || 148;
    var plate = logoPlate(px);
    var src = logoUrl || defaultLogoUrl();

    container.innerHTML = '';
    container.classList.add('qr-with-logo');
    container.style.width = px + 'px';
    container.style.height = px + 'px';

    var code = document.createElement('div');
    code.className = 'qr-with-logo__code';
    container.appendChild(code);

    var plateEl = document.createElement('span');
    plateEl.className = 'qr-logo-plate';
    plateEl.style.width = plate.plateW + 'px';
    plateEl.style.height = plate.plateH + 'px';
    plateEl.style.borderRadius = plate.radius + 'px';

    var logo = document.createElement('img');
    logo.className = 'qr-logo-mark';
    logo.src = src;
    logo.alt = '';
    logo.width = plate.width;
    logo.height = plate.height;
    logo.style.width = plate.width + 'px';
    logo.style.height = plate.height + 'px';
    logo.style.objectFit = 'contain';
    plateEl.appendChild(logo);
    container.appendChild(plateEl);

    new global.QRCode(code, {
      text: url,
      width: px,
      height: px,
      correctLevel: global.QRCode.CorrectLevel.H,
    });
  }

  global.recepsionisRenderQrWithLogo = renderQrWithLogo;
})(window);
