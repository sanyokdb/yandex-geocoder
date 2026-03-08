(function ($) {
  $(function () {
    $('body').addClass('ygp-enhanced');

    var $wrap = $('.wrap');
    if (!$wrap.length) return;

    // Кнопка "Добавить зону доставки"
    $wrap.find('a.button, .button').filter(function () {
      return /Добавить зону доставки/i.test($(this).text());
    }).addClass('ygp-btn-add');


    // Кнопка "Сохранить зоны доставки"
    $wrap.find('input[type="submit"], .button').filter(function () {
      return /Сохранить зоны доставки/i.test($(this).val() || $(this).text());
    }).addClass('ygp-btn-save');

    const container = document.getElementById('zonesContainer');
    const pickupContainer = document.getElementById('pickupPointsContainer');
    if (!container && !pickupContainer) {
      // На вкладке настроек — только accent picker
      var accentInput = document.getElementById('ygp_accent_color');
      var accentHex = document.getElementById('ygp_accent_hex');
      if (accentInput && accentHex) {
        accentInput.addEventListener('input', function() { accentHex.textContent = accentInput.value; });
        accentInput.addEventListener('change', function() { accentHex.textContent = accentInput.value; });
      }
      return;
    }

    function enhanceZone(zone) {
      if (!zone || zone.classList.contains('ygp-processed')) return;
      // Это карточка самовывоза — обработаем отдельно
      if (zone.querySelector('.pp-name')) return enhancePickup(zone);
      zone.classList.add('ygp-processed', 'ygp-card');

      const title  = zone.querySelector('h3.zone-title');       // оставляем
      const nameEl = zone.querySelector('input.zone-name');
      const colorEl= zone.querySelector('input.zone-color');

      const price  = zone.querySelector('input.zone-price');
      const min    = zone.querySelector('input.zone-min');
      const time   = zone.querySelector('input.zone-time');

      const coords = zone.querySelector('textarea.zone-coords');
      const delBtn = zone.querySelector('button.delete-zone');

      // ---------- ШАПКА ----------
      const header = document.createElement('div');
      header.className = 'ygp-header';

      const left = document.createElement('div');
      left.className = 'ygp-header-left';

      const nameWrap = document.createElement('div');
      nameWrap.className = 'ygp-name';
      if (nameEl) {
        const l = document.createElement('label'); l.textContent = 'Название:';
        nameEl.placeholder = 'Название';
        nameWrap.appendChild(l); nameWrap.appendChild(nameEl);
      }

      const colorWrap = document.createElement('div');
      colorWrap.className = 'ygp-color';
      if (colorEl) {
        const l = document.createElement('label'); l.textContent = 'Цвет:';
        colorWrap.appendChild(l); colorWrap.appendChild(colorEl);
      }

      left.appendChild(nameWrap);
      left.appendChild(colorWrap);

      const right = document.createElement('div');
      right.className = 'ygp-header-right';
      if (delBtn) {
        delBtn.classList.add('ygp-danger', 'button'); // видимая как wp-кнопка
        right.appendChild(delBtn);
      }

      header.appendChild(left);
      header.appendChild(right);

      // ---------- СЕТКА 3 ПОЛЯ ----------
      const grid = document.createElement('div');
      grid.className = 'ygp-grid';

      function cell(lbl, el) {
        if (!el) return null;
        const w = document.createElement('div');
        const l = document.createElement('label'); l.textContent = lbl;
        w.appendChild(l); w.appendChild(el);
        return w;
      }
      [ cell('Мин. сумма заказа:', min),
        cell('Цена доставки:',    price),
        cell('Время доставки:',   time)
      ].forEach(x => x && grid.appendChild(x));

      // ---------- КООРДИНАТЫ (спойлер) ----------
      let details = null;
      if (coords) {
        coords.classList.add('ygp-coords');
        details = document.createElement('details');
        details.className = 'ygp-details';
        const sum = document.createElement('summary');
        sum.textContent = 'Координаты полигона';
        details.appendChild(sum);
        const box = document.createElement('div');
        box.className = 'ygp-details-box';
        box.appendChild(coords);
        details.appendChild(box);
      }

      // ---------- Сборка ----------
      const keepTitle = title ? title : null;
      zone.innerHTML = '';
      if (keepTitle) zone.appendChild(keepTitle);
      zone.appendChild(header);
      zone.appendChild(grid);
      if (details) zone.appendChild(details);
    }

    function enhancePickup(zone) {
      if (!zone || zone.classList.contains('ygp-processed')) return;
      zone.classList.add('ygp-processed', 'ygp-card', 'ygp-pickup-card');

      const title = zone.querySelector('h3.zone-title');
      const nameEl = zone.querySelector('input.pp-name');
      const addressEl = zone.querySelector('input.pp-address');
      const workStart = zone.querySelector('input.pp-work-start');
      const workEnd = zone.querySelector('input.pp-work-end');
      const minOrder = zone.querySelector('input.pp-min-order');
      const deliveryPrice = zone.querySelector('input.pp-delivery-price');
      const sundayOff = zone.querySelector('input.pp-sunday-off');
      const delBtn = zone.querySelector('button.pp-delete');

      const header = document.createElement('div');
      header.className = 'ygp-header';
      const left = document.createElement('div');
      left.className = 'ygp-header-left';
      const nameWrap = document.createElement('div');
      nameWrap.className = 'ygp-name';
      if (nameEl) {
        const l = document.createElement('label'); l.textContent = 'Название:';
        nameEl.placeholder = 'Название';
        nameWrap.appendChild(l); nameWrap.appendChild(nameEl);
      }
      left.appendChild(nameWrap);

      const right = document.createElement('div');
      right.className = 'ygp-header-right';
      if (delBtn) {
        delBtn.classList.add('ygp-danger', 'button');
        right.appendChild(delBtn);
      }
      header.appendChild(left);
      header.appendChild(right);

      const grid = document.createElement('div');
      grid.className = 'ygp-grid ygp-grid-2';
      function cell(lbl, el, full) {
        if (!el) return null;
        const w = document.createElement('div');
        if (full) w.className = 'ygp-full';
        const l = document.createElement('label'); l.textContent = lbl;
        w.appendChild(l); w.appendChild(el);
        return w;
      }
      [
        cell('Адрес:', addressEl, true),
        cell('Начало работы:', workStart),
        cell('Конец работы:', workEnd),
        cell('Мин. заказ:', minOrder),
        cell('Цена доставки:', deliveryPrice)
      ].forEach(x => x && grid.appendChild(x));

      if (sundayOff) {
        const sundayWrap = document.createElement('div');
        sundayWrap.className = 'ygp-full ygp-sunday-off-wrap';
        const sundayLabel = document.createElement('label');
        sundayLabel.className = 'ygp-sunday-off-label';
        sundayLabel.appendChild(sundayOff);
        sundayLabel.appendChild(document.createTextNode(' Воскресенье - выходной'));
        sundayWrap.appendChild(sundayLabel);
        grid.appendChild(sundayWrap);
      }

      const keepTitle = title ? title : null;
      zone.innerHTML = '';
      if (keepTitle) zone.appendChild(keepTitle);
      zone.appendChild(header);
      zone.appendChild(grid);

      if (nameEl && title) {
        const defaultName = title.textContent || 'Точка самовывоза';
        nameEl.addEventListener('input', () => {
          title.textContent = nameEl.value || defaultName;
        });
      }
    }

    function watch(containerEl) {
      if (!containerEl) return;
      containerEl.querySelectorAll('.zone-block').forEach(enhanceZone);
      const mo = new MutationObserver(muts => {
        muts.forEach(m => m.addedNodes.forEach(n => {
          if (n.nodeType === 1 && n.classList.contains('zone-block')) enhanceZone(n);
          if (n.nodeType === 1) n.querySelectorAll && n.querySelectorAll('.zone-block').forEach(enhanceZone);
        }));
      });
      mo.observe(containerEl, { childList: true, subtree: true });
    }

    watch(container);
    watch(pickupContainer);

    var accentInput = document.getElementById('ygp_accent_color');
    var accentHex = document.getElementById('ygp_accent_hex');
    if (accentInput && accentHex) {
      accentInput.addEventListener('input', function() { accentHex.textContent = accentInput.value; });
      accentInput.addEventListener('change', function() { accentHex.textContent = accentInput.value; });
    }
  });
})(jQuery);
