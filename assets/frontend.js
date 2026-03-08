(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }
  function qsa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function ensureYmapsLoaded(apiKey) {
    return new Promise((resolve, reject) => {
      if (window.ymaps && typeof window.ymaps.ready === 'function') {
        window.ymaps.ready(resolve);
        return;
      }
      if (!apiKey) {
        reject(new Error('Yandex Maps API key is missing'));
        return;
      }
      const existing = document.querySelector('script[data-ygp-ymaps="1"]');
      if (existing) {
        const timer = setInterval(() => {
          if (window.ymaps && typeof window.ymaps.ready === 'function') {
            clearInterval(timer);
            window.ymaps.ready(resolve);
          }
        }, 50);
        setTimeout(() => {
          clearInterval(timer);
          reject(new Error('Yandex Maps failed to load'));
        }, 15000);
        return;
      }

      const s = document.createElement('script');
      s.src = `https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=${encodeURIComponent(
        apiKey
      )}`;
      s.async = true;
      s.defer = true;
      s.dataset.ygpYmaps = '1';
      s.onload = () => {
        if (!window.ymaps) {
          reject(new Error('ymaps not found after load'));
          return;
        }
        window.ymaps.ready(resolve);
      };
      s.onerror = () => reject(new Error('Yandex Maps script load error'));
      document.head.appendChild(s);
    });
  }


  function debounce(fn, ms) {
    let t = null;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  // ===== ЦЕНТРАЛИЗОВАННОЕ ХРАНИЛИЩЕ ДАННЫХ (LocalStorage API) =====
  const YGPStorage = {
    // Ключи для доставки
    DELIVERY: {
      TYPE: 'ygp_delivery_type',
      ADDRESS: 'ygp_delivery_address',
      COORDS: 'ygp_delivery_coords',
      MIN_ORDER: 'ygp_delivery_min_order',
      ZONE_PRICE: 'ygp_delivery_zone_price',
      TIME: 'ygp_delivery_time',
      FLAT: 'ygp_delivery_flat',
      INTERCOM: 'ygp_delivery_intercom',
      ENTRANCE: 'ygp_delivery_entrance',
      FLOOR: 'ygp_delivery_floor',
    },
    // Ключи для самовывоза
    PICKUP: {
      TYPE: 'ygp_pickup_type',
      POINT_ID: 'ygp_pickup_point_id',
      POINT_NAME: 'ygp_pickup_point_name',
      POINT_ADDRESS: 'ygp_pickup_point_address',
      COORDS: 'ygp_pickup_coords',
      FILTER: 'ygp_pickup_filter',
    },
    // Общие ключи
    COMMON: {
      CURRENT_MODE: 'ygp_current_mode', // 'delivery' или 'pickup'
    },

    _get(key, fallback = null) {
      try {
        const v = localStorage.getItem(key);
        return (v === null || v === 'null' || v === 'undefined') ? fallback : v;
      } catch (e) {
        return fallback;
      }
    },
    _set(key, value) {
      try {
        if (value === null || value === undefined) {
          localStorage.removeItem(key);
        } else {
          localStorage.setItem(key, String(value));
        }
      } catch (e) {}
    },
    _getJSON(key, fallback = null) {
      try {
        const s = this._get(key);
        if (!s) return fallback;
        return JSON.parse(s);
      } catch (e) {
        return fallback;
      }
    },
    _setJSON(key, value) {
      try {
        if (value === null || value === undefined) {
          localStorage.removeItem(key);
        } else {
          localStorage.setItem(key, JSON.stringify(value));
        }
      } catch (e) {}
    },

    // === API для доставки ===
    getDeliveryData() {
      return {
        address: this._get(this.DELIVERY.ADDRESS, ''),
        coords: this._getJSON(this.DELIVERY.COORDS, null),
        minOrder: parseFloat(this._get(this.DELIVERY.MIN_ORDER, '0')) || 0,
        zonePrice: parseFloat(this._get(this.DELIVERY.ZONE_PRICE, '0')) || 0,
        time: this._get(this.DELIVERY.TIME, ''),
        flat: this._get(this.DELIVERY.FLAT, ''),
        intercom: this._get(this.DELIVERY.INTERCOM, ''),
        entrance: this._get(this.DELIVERY.ENTRANCE, ''),
        floor: this._get(this.DELIVERY.FLOOR, ''),
      };
    },
    setDeliveryData(data) {
      if (data.address !== undefined) this._set(this.DELIVERY.ADDRESS, data.address);
      if (data.coords !== undefined) this._setJSON(this.DELIVERY.COORDS, data.coords);
      if (data.minOrder !== undefined) this._set(this.DELIVERY.MIN_ORDER, data.minOrder);
      if (data.zonePrice !== undefined) this._set(this.DELIVERY.ZONE_PRICE, data.zonePrice);
      if (data.time !== undefined) this._set(this.DELIVERY.TIME, data.time);
      if (data.flat !== undefined) this._set(this.DELIVERY.FLAT, data.flat);
      if (data.intercom !== undefined) this._set(this.DELIVERY.INTERCOM, data.intercom);
      if (data.entrance !== undefined) this._set(this.DELIVERY.ENTRANCE, data.entrance);
      if (data.floor !== undefined) this._set(this.DELIVERY.FLOOR, data.floor);
    },

    // === API для самовывоза ===
    getPickupData() {
      return {
        pointId: this._get(this.PICKUP.POINT_ID, ''),
        pointName: this._get(this.PICKUP.POINT_NAME, ''),
        pointAddress: this._get(this.PICKUP.POINT_ADDRESS, ''),
        coords: this._getJSON(this.PICKUP.COORDS, null),
        filter: this._get(this.PICKUP.FILTER, ''),
      };
    },
    setPickupData(data) {
      if (data.pointId !== undefined) this._set(this.PICKUP.POINT_ID, data.pointId);
      if (data.pointName !== undefined) this._set(this.PICKUP.POINT_NAME, data.pointName);
      if (data.pointAddress !== undefined) this._set(this.PICKUP.POINT_ADDRESS, data.pointAddress);
      if (data.coords !== undefined) this._setJSON(this.PICKUP.COORDS, data.coords);
      if (data.filter !== undefined) this._set(this.PICKUP.FILTER, data.filter);
    },

    // === Текущий режим ===
    getCurrentMode() {
      return this._get(this.COMMON.CURRENT_MODE, 'delivery') === 'pickup' ? 'pickup' : 'delivery';
    },
    setCurrentMode(mode) {
      this._set(this.COMMON.CURRENT_MODE, mode === 'pickup' ? 'pickup' : 'delivery');
    },

    // === Миграция legacy данных (вызывать 1 раз при init) ===
    migrateLegacyData() {
      // Переносим старые ключи в новую структуру (если они есть)
      const legacyAddr = this._get('yandex_delivery_address_text') || this._get('yandex_address_text');
      const legacyCoords = this._getJSON('yandex_delivery_address_coords') || this._getJSON('yandex_address_coords');
      const legacyFlat = this._get('yandex_address_flat');
      const legacyIntercom = this._get('yandex_address_intercom');
      const legacyEntrance = this._get('yandex_address_entrance');
      const legacyFloor = this._get('yandex_address_floor');
      const legacyMinOrder = this._get('yandex_delivery_min_order') || this._get('yandex_min_order');
      const legacyZonePrice = this._get('yandex_delivery_zone_price') || this._get('yandex_zone_price');
      const legacyTime = this._get('yandex_delivery_time');

      const legacyPickupId = this._get('yandex_pickup_point_id');
      const legacyPickupText = this._get('yandex_pickup_point_text');
      const legacyPickupCoords = this._getJSON('yandex_pickup_coords');
      const legacyPickupFilter = this._get('yandex_pickup_filter');

      if (legacyAddr && !this._get(this.DELIVERY.ADDRESS)) {
        this.setDeliveryData({
          address: legacyAddr,
          coords: legacyCoords,
          minOrder: legacyMinOrder || 0,
          zonePrice: legacyZonePrice || 0,
          time: legacyTime || '',
          flat: legacyFlat || '',
          intercom: legacyIntercom || '',
          entrance: legacyEntrance || '',
          floor: legacyFloor || '',
        });
      }
      if (legacyPickupId && !this._get(this.PICKUP.POINT_ID)) {
        this.setPickupData({
          pointId: legacyPickupId,
          pointName: legacyPickupText || '',
          pointAddress: legacyPickupText || '',
          coords: legacyPickupCoords,
          filter: legacyPickupFilter || '',
        });
      }

      // Перенос текущего режима
      const legacyMode = this._get('yandex_delivery_type');
      if (legacyMode && !this._get(this.COMMON.CURRENT_MODE)) {
        this.setCurrentMode(legacyMode);
      }
    },
  };

  function init() {
    const data = window.YGP_DATA || {};
    const popup = qs('#yandex-address-popup');
    if (!popup) return;

    const input = qs('#yandex-address-input', popup);
    const confirmBtn = qs('#confirm-address', popup);
    const result = qs('#address-result', popup);
    const minOrderMsg = qs('#min-order-message', popup);
    const deliveryNotice = qs('#delivery-notice', popup);
    const resultsBox = qs('#autocompleteResults', popup);
    const contentArea = qs('#content-area', popup);
    const titleEl = qs('#modal-title', popup);
    const zoomInBtn = qs('#ygp-zoom-in', popup);
    const zoomOutBtn = qs('#ygp-zoom-out', popup);
    const mapGeolocateBtn = qs('#ygp-map-geolocate', popup);
    const closeBtns = qsa('.close-geo-target', popup);
    const deliveryToggles = qsa('[data-delivery-toggle="1"]', popup);
    const centerMarkerEl = qs('#ygp-center-marker', popup);
    const loaderEl = qs('#ygp-loader', popup);
    const mapEl = qs('#map', popup);

    const apiKey = data.apiKey || '';
    const zones = Array.isArray(data.zones) ? data.zones : [];
    const pickupPoints = Array.isArray(data.pickupPoints) ? data.pickupPoints : [];
    const ajaxUrl = data.ajaxUrl || '/wp-admin/admin-ajax.php';
    const defaultCity = (typeof data.defaultCity === 'string' && data.defaultCity.trim()) ? data.defaultCity.trim() : '';
    const defaultCityName = defaultCity ? defaultCity + ', ' : 'Санкт-Петербург, Ленинградская область, ';
    const defaultCityCoords = Array.isArray(data.defaultCityCoords) && data.defaultCityCoords.length >= 2
      ? data.defaultCityCoords
      : [55.75, 37.61];
    const currencySymbol = (typeof data.currencySymbol === 'string' && data.currencySymbol) ? data.currencySymbol : '₽';
    const accentColor = (typeof data.accentColor === 'string' && data.accentColor) ? data.accentColor : '#51267d';

    function formatAmount(num) {
      const n = Number(num);
      if (!Number.isFinite(n)) return '0';
      return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');
    }

    // Мигрируем legacy данные при первом запуске
    YGPStorage.migrateLegacyData();

    let deliveryType = YGPStorage.getCurrentMode();
    let selectedPickupPointId = YGPStorage.getPickupData().pointId || null;

    let deliveryMap = null;
    let currentCoords = null; // delivery coords (map center)
    let zonePolygons = []; // {zone, polygon}
    let pickupPlacemarks = [];
    let lastGeocodeKey = null;
    let isGeocoding = false;
    let pendingCoords = null;
    let centerPollTimer = null;
    let lastCenterSnapshot = null;
    let autocompleteEnabled = false;
    let pickupFilter = '';
    let isDeliveryAvailable = true;
    let minOrderAmount = 0;
    let zonePrice = 0;

    // zoneSync удалён — теперь AJAX отправляется ТОЛЬКО при нажатии "Подтвердить"

    function updateBottomSheet() {}


    let savedScrollY = 0;
    function openPopup() {
      savedScrollY = window.scrollY || document.documentElement.scrollTop;
      document.documentElement.classList.add('popup-open');
      document.body.classList.add('popup-open');
      popup.style.display = 'flex';
      popup.classList.add('active');
      // lazy init maps after open (avoid issues when container is hidden)
      ensureYmapsLoaded(apiKey)
        .then(() => {
          initMapsIfNeeded();
          applyDeliveryTypeUI();
          startCenterPolling();
          updateBottomSheet();
        })
        .catch(() => {});
    }
    function closePopup() {
      popup.classList.remove('active');
      document.documentElement.classList.remove('popup-open');
      document.body.classList.remove('popup-open');
      window.scrollTo(0, savedScrollY);
      setTimeout(() => {
        popup.style.display = 'none';
      }, 200);
      stopCenterPolling();
    }

    function setFormFocusState(isFocused) {
      if (window.innerWidth <= 768) return; // form-focus отключён в моб. версии
      const modalCard = popup.querySelector('.modal-card');
      if (modalCard) {
        modalCard.classList.toggle('form-focus', isFocused);
      }
    }
    window.openYandexAddressPopup = openPopup;
    window.closeYandexAddressPopup = closePopup;

    // Если попап открылся не через openPopup (например, другим скриптом),
    // всё равно инициализируем карты как только он стал видимым.
    function ensureInitWhenVisible() {
      if (!popup) return;
      const isVisible =
        popup.classList.contains('active') ||
        (popup.style.display && popup.style.display !== 'none');
      if (!isVisible) return;
      // карты уже созданы
      if (deliveryMap) return;
      ensureYmapsLoaded(apiKey)
        .then(() => {
          initMapsIfNeeded();
          applyDeliveryTypeUI();
        })
        .catch(() => {});
    }

    function getConfirmLabel() {
      return 'Подтвердить';
    }

    function setConfirmLoading(isLoading) {
      if (!confirmBtn) return;
      if (isLoading) {
        confirmBtn.disabled = true;
        confirmBtn.classList.add('ygp-loading');
        confirmBtn.innerHTML = '<span class="ygp-spinner"></span> Сохранение...';
      } else {
        confirmBtn.disabled = false;
        confirmBtn.classList.remove('ygp-loading');
        confirmBtn.innerText = getConfirmLabel();
      }
    }

    function resetConfirmButton() {
      if (!confirmBtn) return;
      confirmBtn.classList.remove('ready-point');
      confirmBtn.disabled = false;
      confirmBtn.classList.remove('ygp-loading');
      confirmBtn.innerText = getConfirmLabel();
    }

    function saveToLocalStorage() {
      YGPStorage.setCurrentMode(deliveryType);

      if (deliveryType === 'delivery') {
        YGPStorage.setDeliveryData({
          address: input.value || '',
          coords: (currentCoords && Array.isArray(currentCoords) && currentCoords.length === 2) ? currentCoords : null,
        });
      } else {
        // Самовывоз: поле input = фильтр, не адрес
        YGPStorage.setPickupData({
          filter: input.value || '',
          coords: (currentCoords && Array.isArray(currentCoords) && currentCoords.length === 2) ? currentCoords : null,
          pointId: selectedPickupPointId || '',
        });
      }
    }

    function syncDeliveryDataToServer() {
      const dd = YGPStorage.getDeliveryData();
      const pd = YGPStorage.getPickupData();
      const params = new URLSearchParams({
        action: 'save_delivery_data_atomic',
        delivery_type: deliveryType,
        min_order: deliveryType === 'delivery' ? (dd.minOrder || 0) : 0,
        zone_price: deliveryType === 'delivery' ? (dd.zonePrice || 0) : 0,
        delivery_time: deliveryType === 'delivery' ? (dd.time || '') : '',
        address: deliveryType === 'delivery' ? (dd.address || '') : '',
        flat: dd.flat || '',
        intercom: dd.intercom || '',
        entrance: dd.entrance || '',
        floor: dd.floor || '',
        pickup_point_id: deliveryType === 'pickup' ? (pd.pointId || '') : '',
      });
      fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params }).catch(() => {});
    }

    function hideAutocomplete() {
      if (!resultsBox) return;
      resultsBox.innerHTML = '';
      resultsBox.style.display = 'none';
    }

    function showAutocomplete() {
      if (!resultsBox) return;
      resultsBox.style.display = 'block';
    }

    function setAddressInput(value, opts) {
      const fromUser = opts && opts.fromUser;
      input.value = value || '';
      if (!fromUser) {
        autocompleteEnabled = false;
        hideAutocomplete();
      }
      // события нужны внешним обработчикам, но onInput их отфильтрует
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      updateBottomSheet();
    }

    // form-focus: только при фокусе на ОСНОВНОМ поле адреса (на доп. полях смена layout закрывает клавиатуру)
    if (input) {
      input.addEventListener('focus', () => setFormFocusState(true));
    }
    if (popup) {
      popup.addEventListener('focusout', (e) => {
        setTimeout(() => {
          const active = document.activeElement;
          const inPopup = popup.contains(active);
          const inFormFields = active && (active.id === 'yandex-address-input' || active.closest('#additional-address-fields'));
          if (!inPopup || !inFormFields) setFormFocusState(false);
        }, 50);
      });
    }

    function loadFromLocalStorage() {
      deliveryType = YGPStorage.getCurrentMode();

      if (deliveryType === 'delivery') {
        const data = YGPStorage.getDeliveryData();
        currentCoords = data.coords;
        if (data.address) {
          setAddressInput(data.address, { fromUser: false });
        }

        // Доп. поля для доставки
        const flatEl = qs('#address-flat', popup);
        const intercomEl = qs('#address-intercom', popup);
        const entranceEl = qs('#address-entrance', popup);
        const floorEl = qs('#address-floor', popup);
        if (flatEl) flatEl.value = data.flat;
        if (intercomEl) intercomEl.value = data.intercom;
        if (entranceEl) entranceEl.value = data.entrance;
        if (floorEl) floorEl.value = data.floor;
      } else {
        const data = YGPStorage.getPickupData();
        selectedPickupPointId = data.pointId || null;
        currentCoords = data.coords;
        // Поле поиска оставляем пустым (только для поиска по названию)
        input.value = '';
        pickupFilter = '';
      }
    }

    function setSpinner(isLoading) {
      if (!loaderEl) return;
      loaderEl.style.display = isLoading ? 'block' : 'none';
    }

    function cleanAddressText(fullAddress) {
      const parts = String(fullAddress || '')
        .split(',')
        .map((p) => p.trim())
        .filter(Boolean);
      if (parts.length >= 3) {
        const street = parts[parts.length - 2];
        const house = parts[parts.length - 1];
        const city = parts[parts.length - 3];
        return `${street}, ${house}, ${city}`;
      }
      return String(fullAddress || '');
    }

    function parseTimeToMinutes(val) {
      if (!val) return null;
      const m = String(val).trim().match(/^(\d{1,2}):(\d{2})$/);
      if (!m) return null;
      const h = parseInt(m[1], 10);
      const min = parseInt(m[2], 10);
      if (Number.isNaN(h) || Number.isNaN(min) || h > 23 || min > 59) return null;
      return h * 60 + min;
    }

    function parseLegacyHours(text) {
      const m = String(text || '').match(/(\d{1,2}:\d{2}).*(\d{1,2}:\d{2})/);
      if (!m) return { start: '', end: '' };
      return { start: m[1], end: m[2] };
    }

    function getWorkHours(p) {
      let start = p.work_start || p.workStart || '';
      let end = p.work_end || p.workEnd || '';
      if ((!start || !end) && p.work_hours) {
        const legacy = parseLegacyHours(p.work_hours);
        start = start || legacy.start;
        end = end || legacy.end;
      }
      return { start, end };
    }

    function getPickupStatus(p) {
      const now = new Date();
      const isSunday = now.getDay() === 0;
      if (isSunday && p.is_closed_on_sunday) {
        return { isOpen: false, text: 'Закрыто по воскресеньям', canSelect: false, start: '', end: '' };
      }
      const { start, end } = getWorkHours(p);
      const startMin = parseTimeToMinutes(start);
      const endMin = parseTimeToMinutes(end);
      if (startMin === null || endMin === null) {
        return { isOpen: true, text: 'Время работы не указано', canSelect: true, start, end };
      }
      if (startMin === endMin) {
        return { isOpen: true, text: 'Круглосуточно', canSelect: true, start, end };
      }
      const nowMin = now.getHours() * 60 + now.getMinutes();
      let isOpen = false;
      if (startMin < endMin) {
        isOpen = nowMin >= startMin && nowMin < endMin;
      } else {
        // работа через полночь
        isOpen = nowMin >= startMin || nowMin < endMin;
      }
      const text = isOpen
        ? `Магазин закроется в ${end}`
        : `Магазин откроется в ${start}`;
      return { isOpen, text, canSelect: isOpen, start, end };
    }

    // Фоллбэк: reverse geocode через HTTP API (на случай, если ymaps.geocode вернул пусто/ошибку)
    function reverseGeocodeHttp(coords) {
      const lat = coords && coords[0];
      const lon = coords && coords[1];
      if (!apiKey || typeof lat !== 'number' || typeof lon !== 'number') return Promise.resolve('');
      const geocode = `${lon},${lat}`;
      const url = `https://geocode-maps.yandex.ru/1.x/?apikey=${encodeURIComponent(
        apiKey
      )}&format=json&geocode=${encodeURIComponent(geocode)}&results=1`;
      return fetch(url)
        .then((r) => r.json())
        .then((data) => {
          const fm =
            data &&
            data.response &&
            data.response.GeoObjectCollection &&
            data.response.GeoObjectCollection.featureMember &&
            data.response.GeoObjectCollection.featureMember[0];
          if (!fm || !fm.GeoObject) return '';
          const txt = fm.GeoObject.metaDataProperty.GeocoderMetaData.text;
          return txt || '';
        })
        .catch(() => '');
    }


    function updateDeliveryToggleButtons() {
      deliveryToggles.forEach((tg) => {
        qsa('[data-type]', tg).forEach((btn) => {
          const t = btn.getAttribute('data-type');
          if (t === deliveryType) btn.classList.add('active');
          else btn.classList.remove('active');
        });
      });
    }

    function applyLayout() {
      const modalCard = popup.querySelector('.modal-card');
      if (modalCard) {
        modalCard.classList.toggle('mode-pickup', deliveryType === 'pickup');
        modalCard.classList.toggle('mode-delivery', deliveryType !== 'pickup');
      }
      if (mapGeolocateBtn) {
        mapGeolocateBtn.style.display = deliveryType === 'delivery' ? '' : 'none';
      }
      if (centerMarkerEl) {
        centerMarkerEl.style.display = deliveryType === 'delivery' ? '' : 'none';
      }
    }

    function updateDeliveryNotice() {
      if (!deliveryNotice) return;
      if (deliveryType === 'pickup') {
        const hasOpen = pickupPoints.some((p) => p && p.coords && getPickupStatus(p).canSelect);
        if (!hasOpen) {
          deliveryNotice.style.display = 'flex';
          deliveryNotice.className = 'delivery-notice unavailable';
          deliveryNotice.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="8" x2="12" y2="12"></line>
              <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <div class="notice-text">Все пункты самовывоза закрыты</div>
          `;
        } else {
          deliveryNotice.style.display = 'none';
        }
        return;
      }
      if (deliveryType !== 'delivery') {
        deliveryNotice.style.display = 'none';
        return;
      }
      deliveryNotice.style.display = 'flex';
      if (isDeliveryAvailable) {
        deliveryNotice.className = 'delivery-notice available';
        const priceAmount = formatAmount(zonePrice);
        const minAmount = formatAmount(minOrderAmount);
        const priceHtml =
          `<span class="ygp-delivery-price">${priceAmount} ${currencySymbol}</span>`;
        const freeFromHtml = (zonePrice > 0 && minOrderAmount > 0)
          ? ` — бесплатно от <span class="ygp-delivery-free-from">${minAmount} ${currencySymbol}</span>`
          : '';
        deliveryNotice.innerHTML = `
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
          </svg>
          <div class="notice-text">
            Доставка <span class="min-order">${priceHtml}${freeFromHtml}</span>
          </div>
        `;
      } else {
        deliveryNotice.className = 'delivery-notice unavailable';
        deliveryNotice.innerHTML = `
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <div class="notice-text">
            <div>Доставка недоступна в этой зоне</div>
          </div>
        `;
      }
    }

    function updateConfirmState() {
      if (!confirmBtn) return;
      if (deliveryType === 'delivery') {
        confirmBtn.disabled = !isDeliveryAvailable;
        return;
      }
      confirmBtn.disabled = !selectedPickupPointId;
    }

    function applyDeliveryTypeUI() {
      if (deliveryType === 'pickup') {
        if (selectedPickupPointId) {
          const p = pickupPoints.find((x) => x && x.id === selectedPickupPointId);
          const status = p ? getPickupStatus(p) : null;
          if (!status || !status.canSelect) {
            selectedPickupPointId = null;
            YGPStorage.setPickupData({ pointId: '', pointName: '', pointAddress: '', coords: null, filter: '' });
          }
        }
        const pickupData = YGPStorage.getPickupData();
        let pc = null;
        if (selectedPickupPointId) {
          const p = pickupPoints.find((x) => x && x.id === selectedPickupPointId);
          if (p && p.coords) pc = p.coords;
        }
        if (!pc && pickupData.coords) pc = pickupData.coords;
        if (pc && deliveryMap) {
          deliveryMap.setCenter(pc, 15);
          currentCoords = pc;
        } else if (deliveryMap) {
          deliveryMap.setCenter(defaultCityCoords, 10);
          currentCoords = defaultCityCoords;
        }

        if (titleEl) titleEl.textContent = 'Выберите филиал';
        input.placeholder = 'Поиск филиала';
        input.readOnly = false;
        hideAutocomplete();

        zonePolygons.forEach((x) => x.polygon && x.polygon.options.set('visible', false));
        stopCenterPolling();
        renderPickupMapPoints();
        if (deliveryMap && deliveryMap.container && deliveryMap.container.fitToViewport) {
          setTimeout(() => deliveryMap.container.fitToViewport(), 50);
        }
      } else {
        // Восстанавливаем центр карты и данные зоны: сохранённые или fallback
        const deliveryData = YGPStorage.getDeliveryData();
        minOrderAmount = Number(deliveryData.minOrder) || 0;
        zonePrice = Number(deliveryData.zonePrice) || 0;
        const coordsToUse = (deliveryData.coords && Array.isArray(deliveryData.coords)) ? deliveryData.coords : defaultCityCoords;
        if (deliveryMap) {
          deliveryMap.setCenter(coordsToUse, deliveryData.coords ? 15 : 10);
          currentCoords = coordsToUse;
        }

        if (titleEl) titleEl.textContent = 'Адрес доставки';
        input.placeholder = 'Введите адрес доставки';
        input.readOnly = false;

        zonePolygons.forEach((x) => x.polygon && x.polygon.options.set('visible', true));
        pickupPlacemarks.forEach((pm) => deliveryMap && deliveryMap.geoObjects.remove(pm));
        pickupPlacemarks = [];
        startCenterPolling();
        if (deliveryMap && deliveryMap.container && deliveryMap.container.fitToViewport) {
          setTimeout(() => deliveryMap.container.fitToViewport(), 50);
        }
      }
      updateDeliveryToggleButtons();
      applyLayout();
      updateDeliveryNotice();
      updateConfirmState();
      renderContent();
    }
    function centerChanged(a, b) {
      if (!a || !b) return true;
      const d1 = Math.abs(a[0] - b[0]);
      const d2 = Math.abs(a[1] - b[1]);
      return d1 > 0.00001 || d2 > 0.00001; // ~1м+
    }

    function startCenterPolling() {
      if (centerPollTimer || deliveryType !== 'delivery') return;
      centerPollTimer = setInterval(() => {
        if (!deliveryMap || deliveryType !== 'delivery') return;
        const center = deliveryMap.getCenter();
        if (!lastCenterSnapshot || centerChanged(center, lastCenterSnapshot)) {
          lastCenterSnapshot = center;
          currentCoords = center;
          getAddressFromCoords(center, { force: true });
        }
      }, 600);
    }

    function stopCenterPolling() {
      if (centerPollTimer) {
        clearInterval(centerPollTimer);
        centerPollTimer = null;
      }
    }

    function findZoneByCoords(coords) {
      for (const x of zonePolygons) {
        try {
          if (x.polygon.geometry.contains(coords)) return x.zone;
        } catch (e) {}
      }
      return null;
    }

    function coordsKey(coords) {
      if (!coords || !Array.isArray(coords) || coords.length !== 2) return '';
      const a = Number(coords[0]).toFixed(6);
      const b = Number(coords[1]).toFixed(6);
      return `${a},${b}`;
    }

    async function getAddressFromCoords(coords, opts) {
      const force = opts && opts.force;
      const key = coordsKey(coords);
      if (!force && key && lastGeocodeKey === key) return;
      if (isGeocoding) {
        pendingCoords = coords;
        return;
      }
      isGeocoding = true;
      setSpinner(true);
      if (result) result.style.display = 'none';
      try {
        // 1) Пробуем штатный ymaps.geocode
        const fullAddress = await (async () => {
          try {
            const res = await window.ymaps.geocode(coords, { results: 1 });
            const geoObject = res && res.geoObjects ? res.geoObjects.get(0) : null;
            const line =
              geoObject && typeof geoObject.getAddressLine === 'function'
                ? geoObject.getAddressLine()
                : '';
            if (line) return line;
          } catch (e) {
            // ignore, пойдём в fallback
          }
          // 2) Fallback — HTTP геокодер
          return reverseGeocodeHttp(coords);
        })();

        if (!fullAddress) {
          if (result) {
            result.style.display = 'block';
            result.innerText =
              'Не удалось определить адрес по карте. Попробуйте приблизить карту или выбрать точку кликом.';
          }
          return;
        }
        setAddressInput(cleanAddressText(fullAddress), { fromUser: false });
        if (key) lastGeocodeKey = key;
        
        // Проверяем доступность доставки
        const matchedZone = findZoneByCoords(coords);
        if (matchedZone) {
          isDeliveryAvailable = true;
          minOrderAmount = matchedZone.min_total || 0;
          zonePrice = Number.isFinite(Number(matchedZone.price)) ? Number(matchedZone.price) : 0;
          const zTime = String(matchedZone.time || '');

          // Сохраняем ТОЛЬКО в localStorage — AJAX будет только при нажатии "Подтвердить"
          YGPStorage.setDeliveryData({
            minOrder: minOrderAmount,
            zonePrice: zonePrice,
            time: zTime,
          });
        } else {
          isDeliveryAvailable = false;
          minOrderAmount = 0;
          zonePrice = 0;

          // Нет зоны — очищаем данные зоны ТОЛЬКО в localStorage
          YGPStorage.setDeliveryData({
            minOrder: 0,
            zonePrice: 0,
            time: '',
          });
        }
        updateDeliveryNotice();
        updateConfirmState();
        
        saveToLocalStorage();
      } catch (e) {
        if (result) {
          result.style.display = 'block';
          result.innerText = 'Ошибка геокодинга. Попробуйте ещё раз.';
        }
      } finally {
        isGeocoding = false;
        setSpinner(false);
        if (pendingCoords) {
          const next = pendingCoords;
          pendingCoords = null;
          // запускаем следующий запрос сразу после текущего
          getAddressFromCoords(next, { force: true });
        }
      }
    }

    function renderContent() {
      if (!contentArea) return;

      if (deliveryType === 'delivery') {
        // Не пересоздаём форму при resize (открытие клавиатуры) — иначе теряется фокус
        if (contentArea.querySelector('#additional-address-fields')) return;
        contentArea.innerHTML = `
          <div id="additional-address-fields" class="form-grid">
            <div class="form-group">
              <label>Подъезд</label>
              <input type="text" id="address-entrance" class="form-control" placeholder="">
            </div>
            <div class="form-group">
              <label>Этаж</label>
              <input type="text" id="address-floor" class="form-control" placeholder="">
            </div>
            <div class="form-group">
              <label>Квартира</label>
              <input type="text" id="address-flat" class="form-control" placeholder="">
            </div>
            <div class="form-group">
              <label>Домофон</label>
              <input type="text" id="address-intercom" class="form-control" placeholder="">
            </div>
          </div>
        `;

        // Загружаем сохранённые доп. поля из YGPStorage
        const deliveryData = YGPStorage.getDeliveryData();
        const flatEl = qs('#address-flat', contentArea);
        const intercomEl = qs('#address-intercom', contentArea);
        const entranceEl = qs('#address-entrance', contentArea);
        const floorEl = qs('#address-floor', contentArea);
        const readAdditionalFields = () => ({
          flat: flatEl ? flatEl.value : '',
          intercom: intercomEl ? intercomEl.value : '',
          entrance: entranceEl ? entranceEl.value : '',
          floor: floorEl ? floorEl.value : '',
        });
        const saveAdditionalFieldsDebounced = debounce(() => {
          YGPStorage.setDeliveryData(readAdditionalFields());
          resetConfirmButton();
        }, 300);
        qsa('#additional-address-fields input', contentArea).forEach((field) => {
          const fieldName = field.id.replace('address-', '');
          field.value = deliveryData[fieldName] || '';
          field.addEventListener('input', () => saveAdditionalFieldsDebounced());
          field.addEventListener('focus', () => {
            setFormFocusState(true);
            requestAnimationFrame(() => {
              field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
          });
        });

        return;
      }

      const filter = (pickupFilter || '').trim().toLowerCase();
      const pts = pickupPoints.filter((p) => {
        if (!p || !p.coords) return false;
        if (!filter) return true;
        // Поиск только по названию точки, не по адресу
        const name = (p.name || '').toLowerCase();
        return name.includes(filter);
      });

      if (!pts.length) {
        contentArea.innerHTML = '<div class="branch-empty">Точки самовывоза не найдены</div>';
        return;
      }

      contentArea.innerHTML = pts
        .map((p) => {
          const status = getPickupStatus(p);
          const selected = p.id && p.id === selectedPickupPointId ? ' selected' : '';
          const muted = status.canSelect ? '' : ' style="opacity:0.6"';
          return `
            <div class="branch-item${selected}" data-pickup-id="${p.id}"${muted}>
              <div class="title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="color:var(--primary)">
                  <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
                </svg>
                ${p.name || 'Самовывоз'}
              </div>
              <div class="address">${p.address || ''}</div>
              <span class="status">${status.text}</span>
            </div>
          `;
        })
        .join('');

      qsa('.branch-item', contentArea).forEach((item) => {
        const id = item.getAttribute('data-pickup-id');
        const point = pickupPoints.find((p) => p.id === id);
        if (!point) return;
        const status = getPickupStatus(point);
        if (!status.canSelect) return;
        item.addEventListener('click', () => selectPickupPoint(point));
      });
    }

    function renderPickupList() {
      renderContent();
    }

    function selectPickupPoint(p) {
      const status = getPickupStatus(p);
      if (!status.canSelect) {
        return;
      }
      deliveryType = 'pickup';
      selectedPickupPointId = p.id || null;
      currentCoords = p.coords;

      // Сохраняем выбранный ПВЗ через YGPStorage
      YGPStorage.setPickupData({
        pointId: p.id || '',
        pointName: p.name || '',
        pointAddress: p.address || '',
        coords: p.coords,
      });

      // Не затираем строку поиска/адрес в input адресом ПВЗ.
      // В режиме самовывоза input используется как фильтр списка.
      if (deliveryMap) deliveryMap.setCenter(p.coords, 15);
      if (result) result.style.display = 'none';
      if (minOrderMsg) minOrderMsg.style.display = 'none';
      updateConfirmState();
      resetConfirmButton();
      applyDeliveryTypeUI();
      saveToLocalStorage();
      syncDeliveryDataToServer();
      renderPickupList();
    }

    function renderPickupMapPoints() {
      if (!deliveryMap) return;
      pickupPlacemarks.forEach((pm) => deliveryMap.geoObjects.remove(pm));
      pickupPlacemarks = [];
      pickupPoints.forEach((p) => {
        if (!p || !p.coords) return;
        const status = getPickupStatus(p);
        const pm = new window.ymaps.Placemark(
          p.coords,
          {
            balloonContentHeader: p.name ? `<b>${p.name}</b>` : '',
            balloonContentBody: `${p.address || ''}${
              status.text ? `<br><small>${status.text}</small>` : ''
            }`,
          },
          {
            preset: 'islands#dotIcon',
            iconColor: status.isOpen ? accentColor : '#999999',
          }
        );
        pm.events.add('click', () => selectPickupPoint(p));
        pickupPlacemarks.push(pm);
        deliveryMap.geoObjects.add(pm);
      });
    }

    function initMapsIfNeeded() {
      loadFromLocalStorage();

      if (!deliveryMap && mapEl) {
        deliveryMap = new window.ymaps.Map(mapEl, {
          center: currentCoords || defaultCityCoords,
          zoom: 10,
          controls: [],
        });

        currentCoords = currentCoords || deliveryMap.getCenter();

        // zones polygons on delivery map
        zonePolygons = [];
        zones.forEach((z) => {
          const polygon = new window.ymaps.Polygon([z.coords], {}, {
            fillColor: (z.color || '#ff7043') + '55',
            strokeColor: z.color || '#ff7043',
            strokeWidth: 3,
            opacity: 0.5,
            interactivityModel: 'default#transparent',
          });
          deliveryMap.geoObjects.add(polygon);
          zonePolygons.push({ zone: z, polygon });
        });

        // Как в вашем /test: обновляем адрес только после окончания движения карты
        deliveryMap.events.add('actionstart', () => {
          if (deliveryType === 'pickup') return;
        });

        const updateByCenter = debounce(() => {
          if (deliveryType === 'pickup') return;
          currentCoords = deliveryMap.getCenter();
          getAddressFromCoords(currentCoords, { force: true });
          saveToLocalStorage();
          resetConfirmButton();
        }, 150);
        deliveryMap.events.add('actionend', updateByCenter);

        // Дополнительный безопасный триггер (если actionend не сработал)
        deliveryMap.events.add('boundschange', debounce(() => {
          if (deliveryType === 'pickup') return;
          currentCoords = deliveryMap.getCenter();
          getAddressFromCoords(currentCoords, { force: true });
          saveToLocalStorage();
          resetConfirmButton();
        }, 250));

        // По клику: центрируем карту и сразу определяем адрес
        deliveryMap.events.add('click', (e) => {
          if (deliveryType === 'pickup') return;
          const coords = e.get('coords');
          currentCoords = coords;
          deliveryMap.setCenter(coords, 17);
          getAddressFromCoords(coords, { force: true });
          saveToLocalStorage();
          resetConfirmButton();
        });

        // Если карта создана в скрытом блоке — подстроим вьюпорт
        if (deliveryMap.container && deliveryMap.container.fitToViewport) {
          setTimeout(() => deliveryMap.container.fitToViewport(), 50);
        }
      }

      // set center from storage if any
      if (currentCoords && Array.isArray(currentCoords)) {
        if (deliveryMap) deliveryMap.setCenter(currentCoords, 15);
      }

      renderContent();

      if (deliveryType === 'pickup') {
        renderPickupMapPoints();
      }

      // Если открыли доставку и адрес пустой — подтянем его с карты сразу
      if (deliveryType === 'delivery' && (!input.value || input.value.trim().length < 3) && currentCoords) {
        getAddressFromCoords(currentCoords, { force: true });
      }
    }

    // UI events: закрытие только по кнопке закрыть и кнопке подтвердить, не по клику на фон
    closeBtns.forEach((btn) => btn.addEventListener('click', closePopup));

    // Наблюдаем за открытием попапа любым способом
    try {
      const mo = new MutationObserver(() => ensureInitWhenVisible());
      mo.observe(popup, { attributes: true, attributeFilter: ['style', 'class'] });
    } catch (e) {}

    qsa('.bar-shortcode, .minamount-global, .open-yandex-address-popup, [data-open-yandex-popup="1"]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        openPopup();
      });
    });

    // Делегированный обработчик (нужен для checkout: Woo часто перерисовывает DOM через AJAX,
    // и прямые обработчики на элементах теряются)
    document.addEventListener(
      'click',
      (e) => {
        try {
          const t = e && e.target;
          if (!t || !t.closest) return;
          const el = t.closest('.open-yandex-address-popup, [data-open-yandex-popup="1"]');
          if (!el) return;
          e.preventDefault();
          e.stopPropagation();
          openPopup();
        } catch (err) {}
      },
      true
    );

    deliveryToggles.forEach((tg) => {
      tg.addEventListener('click', (e) => {
        const btn = e.target && e.target.closest ? e.target.closest('[data-type]') : null;
        if (!btn) return;
        const nextType = btn.getAttribute('data-type') === 'pickup' ? 'pickup' : 'delivery';
        if (nextType === deliveryType) return;

        // Сохраняем состояние текущего режима перед переключением
        if (deliveryType === 'delivery') {
          YGPStorage.setDeliveryData({
            address: input.value || YGPStorage.getDeliveryData().address,
            coords: currentCoords,
          });
        } else {
          YGPStorage.setPickupData({
            filter: input.value || '',
            coords: currentCoords,
          });
        }

        deliveryType = nextType;

        // Восстанавливаем значение input под новый режим
        if (deliveryType === 'pickup') {
          // Для самовывоза - очищаем поле поиска (только для поиска по названию)
          input.value = '';
          pickupFilter = '';
        } else {
          const deliveryData = YGPStorage.getDeliveryData();
          if (deliveryData.address) input.value = deliveryData.address;
        }

        result.style.display = 'none';
        minOrderMsg.style.display = 'none';
        resetConfirmButton();
        applyDeliveryTypeUI();
        saveToLocalStorage();
        syncDeliveryDataToServer();
      });
    });

    if (zoomInBtn) {
      zoomInBtn.addEventListener('click', () => {
        if (deliveryMap) deliveryMap.setZoom(deliveryMap.getZoom() + 1);
      });
    }
    if (zoomOutBtn) {
      zoomOutBtn.addEventListener('click', () => {
        if (deliveryMap) deliveryMap.setZoom(deliveryMap.getZoom() - 1);
      });
    }
    if (mapGeolocateBtn) {
      mapGeolocateBtn.addEventListener('click', () => {
        if (deliveryType === 'pickup') return;
        ensureYmapsLoaded(apiKey)
          .then(() => {
            window.ymaps.geolocation.get().then(
              (res) => {
                const coords = res.geoObjects.position;
                currentCoords = coords;
                if (deliveryMap) deliveryMap.setCenter(coords, 15);
                getAddressFromCoords(coords);
                saveToLocalStorage();
                resetConfirmButton();
              },
              () => {
                currentCoords = defaultCityCoords;
                if (deliveryMap) deliveryMap.setCenter(defaultCityCoords, 15);
                getAddressFromCoords(defaultCityCoords);
                saveToLocalStorage();
                resetConfirmButton();
              }
            );
          })
          .catch(() => {});
      });
    }
    window.addEventListener('resize', () => {
      applyDeliveryTypeUI();
    });
    hideAutocomplete();

    // autocomplete (delivery only) — ограничено регионом выбранного города
    const onInput = debounce(() => {
      if (deliveryType === 'pickup') {
        pickupFilter = input.value || '';
        renderContent();
        hideAutocomplete();
        return;
      }
      const query = (input.value || '').trim();
      if (query.length < 3) {
        hideAutocomplete();
        resetConfirmButton();
        return;
      }
      const fullQuery = defaultCityName + query;
      fetch(
        `https://geocode-maps.yandex.ru/1.x/?apikey=${encodeURIComponent(
          apiKey
        )}&format=json&geocode=${encodeURIComponent(fullQuery)}&results=5`
      )
        .then((r) => r.json())
        .then((data) => {
          const features =
            data &&
            data.response &&
            data.response.GeoObjectCollection &&
            data.response.GeoObjectCollection.featureMember
              ? data.response.GeoObjectCollection.featureMember
              : [];
          resultsBox.innerHTML = '';
          features.forEach((item) => {
            const geo = item.GeoObject;
            const address = geo.metaDataProperty.GeocoderMetaData.text;
            const [lon, lat] = geo.Point.pos.split(' ');
            const div = document.createElement('div');
            div.className = 'autocomplete-item';
            div.textContent = address;
            div.addEventListener('click', () => {
              const parts = address.split(',').map((p) => p.trim());
              let cleanAddress = address;
              if (parts.length >= 3) {
                const street = parts[parts.length - 2];
                const house = parts[parts.length - 1];
                const city = parts[parts.length - 3];
                cleanAddress = `${street}, ${house}, ${city}`;
              }
              setAddressInput(cleanAddress, { fromUser: false });
              resultsBox.innerHTML = '';
              currentCoords = [parseFloat(lat), parseFloat(lon)];
              if (deliveryMap) deliveryMap.setCenter(currentCoords, 15);
              saveToLocalStorage();
              resetConfirmButton();
            });
            resultsBox.appendChild(div);
          });
          if (features.length) {
            showAutocomplete();
          } else {
            hideAutocomplete();
          }
        });
    }, 250);

    input.addEventListener('input', (e) => {
      if (e && e.isTrusted) {
        autocompleteEnabled = true;
      } else if (!autocompleteEnabled) {
        return;
      }
      onInput();
    });

    document.addEventListener('click', (e) => {
      if (!resultsBox.contains(e.target) && e.target !== input) hideAutocomplete();
    });


    // confirm
    confirmBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();

      // Проверяем disabled состояние (защита от двойных кликов)
      if (confirmBtn.disabled || confirmBtn.classList.contains('ygp-loading')) {
        return;
      }

      // Показываем loader
      setConfirmLoading(true);

      try {
        const coords =
          currentCoords && Array.isArray(currentCoords)
            ? currentCoords
            : deliveryMap
            ? deliveryMap.getCenter()
            : defaultCityCoords;

        let deliveryTime = '';
        let minOrder = 0;
        let zonePrice = 0;
        const fullAddress = input.value || '';

        if (deliveryType === 'pickup') {
          const point = pickupPoints.find((p) => p && p.id && p.id === selectedPickupPointId);
          if (!point) {
            setConfirmLoading(false);
            return;
          }
          const status = getPickupStatus(point);
          if (!status.canSelect) {
            setConfirmLoading(false);
            return;
          }
          if (result) result.style.display = 'none';
          deliveryTime = status.text || 'Самовывоз';
          minOrder = 0;
          zonePrice = 0;
        } else {
          // КРИТИЧНО: сначала определяем зону, берём ВСЕ данные из неё
          const matchedZone = findZoneByCoords(coords);
          if (!matchedZone) {
            setConfirmLoading(false);
            return;
          }
          if (result) result.style.display = 'none';
          // Берём данные зоны
          deliveryTime = matchedZone.time || '';
          minOrder = matchedZone.min_total || 0;
          zonePrice = Number.isFinite(Number(matchedZone.price)) ? Number(matchedZone.price) : 0;
        }
        if (minOrderMsg) minOrderMsg.style.display = 'none';

      // update UI on page (existing selectors)
      const timeSelectors = [
        '#builderwidget-8 > div > div:nth-child(2) > div > div > div.uk-width-expand.uk-margin-remove-first-child > h3',
        '.fixed-cart-bar .fixed-cart-bar__time',
        '.yandex-delivery-time',
      ];
      const minSelectors = [
        '#builderwidget-8 > div > div:nth-child(3) > div > div > div.uk-width-expand.uk-margin-remove-first-child .minamount-global',
        '.yandex-min-order-amount',
      ];
      timeSelectors.forEach((sel) => {
        const el = document.querySelector(sel);
        if (el) el.innerText = deliveryTime;
      });
      minSelectors.forEach((sel) => {
        const el = document.querySelector(sel);
        if (el) el.innerText = deliveryType === 'pickup' ? '0 ' + currencySymbol : formatAmount(minOrder) + ' ' + currencySymbol;
      });

      const minAmountLabel = document.querySelector('.bar-shortcode .minamount-labeled');
      if (minAmountLabel) {
        minAmountLabel.innerText =
          deliveryType === 'pickup' ? 'Самовывоз' : `Минимальный заказ ${formatAmount(minOrder)} ${currencySymbol}`;
      }

        // Доп. поля: в самовывозе их нет в DOM — берем старые значения
        const flat =
          deliveryType === 'delivery'
            ? qs('#address-flat', popup)?.value || ''
            : YGPStorage.getDeliveryData().flat;
        const intercom =
          deliveryType === 'delivery'
            ? qs('#address-intercom', popup)?.value || ''
            : YGPStorage.getDeliveryData().intercom;
        const entrance =
          deliveryType === 'delivery'
            ? qs('#address-entrance', popup)?.value || ''
            : YGPStorage.getDeliveryData().entrance;
        const floor =
          deliveryType === 'delivery'
            ? qs('#address-floor', popup)?.value || ''
            : YGPStorage.getDeliveryData().floor;

        // 1) Сохраняем данные в YGPStorage
        if (deliveryType === 'delivery') {
          YGPStorage.setDeliveryData({
            address: fullAddress,
            coords: coords,
            minOrder: minOrder,
            zonePrice: zonePrice,
            time: deliveryTime,
            flat,
            intercom,
            entrance,
            floor,
          });
        } else {
          // Явно сохраняем все данные точки (работает везде на сайте)
          const point = pickupPoints.find((p) => p && p.id && p.id === selectedPickupPointId);
          YGPStorage.setPickupData({
            pointId: point ? (point.id || '') : (YGPStorage.getPickupData().pointId || ''),
            pointName: point ? (point.name || '') : (YGPStorage.getPickupData().pointName || ''),
            pointAddress: point ? (point.address || '') : (YGPStorage.getPickupData().pointAddress || ''),
            coords: point && point.coords ? point.coords : (YGPStorage.getPickupData().coords || null),
            filter: '',
          });
        }
        YGPStorage.setCurrentMode(deliveryType);

        // 2) ЕДИНЫЙ AJAX для АТОМАРНОГО сохранения ВСЕХ данных в WC()->session
        // КРИТИЧНО: ЖДЁМ полного завершения AJAX перед update_checkout
        const saveResponse = await fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'save_delivery_data_atomic',
            delivery_type: deliveryType,
            min_order: minOrder,
            zone_price: zonePrice,
            delivery_time: deliveryTime,
            address: fullAddress,
            flat,
            intercom,
            entrance,
            floor,
            pickup_point_id: deliveryType === 'pickup' ? selectedPickupPointId || '' : '',
          }),
        });
        
        const saveResult = await saveResponse.json();
        console.log('✓ Данные сохранены атомарно:', saveResult);

        // 3) ТОЛЬКО ПОСЛЕ успешного сохранения — обновляем checkout
        const isCheckout =
          (document.body && document.body.classList.contains('woocommerce-checkout')) ||
          !!document.querySelector('form.checkout');

        if (isCheckout) {
          // Переключаем shipping method на нужный
          const prefix = deliveryType === 'pickup' ? 'ygp_pickup' : 'ygp_delivery';
          const inputs = Array.from(
            document.querySelectorAll('input[name^="shipping_method"]')
          );
          if (inputs.length) {
            const names = Array.from(new Set(inputs.map((i) => i.name).filter(Boolean)));
            names.forEach((name) => {
              const candidate = inputs.find(
                (i) => i.name === name && String(i.value || '').indexOf(prefix) === 0
              );
              if (candidate && !candidate.checked) {
                candidate.checked = true;
                candidate.dispatchEvent(new Event('change', { bubbles: true }));
              }
            });
          }

          // Принудительный update_checkout и ждём его завершения
          if (window.jQuery && typeof window.ygpTriggerUpdateCheckout === 'function') {
            window.ygpTriggerUpdateCheckout(true);
            // Ждём updated_checkout с увеличенным таймаутом
            await new Promise((resolve) => {
              const timer = setTimeout(resolve, 5000); // timeout 5s
              window.jQuery(document.body).one('updated_checkout', () => {
                clearTimeout(timer);
                console.log('✓ Checkout обновлён');
                resolve();
              });
            });
          }
        }

        // 5) Скрываем loader, обновляем UI, закрываем попап
        updateDeliveryNotice();
      } catch (err) {
        console.error('Ошибка подтверждения:', err);
      } finally {
        setConfirmLoading(false);
        closePopup();
      }
    });

    // Restore shortcodes on page load
    (function restore() {
      const mode = YGPStorage.getCurrentMode();
      const deliveryData = YGPStorage.getDeliveryData();

      if (deliveryData.time) {
        ['.yandex-delivery-time', '.fixed-cart-bar .fixed-cart-bar__time'].forEach((sel) => {
          const el = document.querySelector(sel);
          if (el) el.innerText = deliveryData.time;
        });
      }

      ['.yandex-min-order-amount'].forEach((sel) => {
        const el = document.querySelector(sel);
        if (!el) return;
        if (mode === 'pickup') el.innerText = '0';
        else if (deliveryData.minOrder) el.innerText = formatAmount(deliveryData.minOrder);
      });

      const label = document.querySelector('.bar-shortcode .minamount-labeled');
      if (label) {
        if (deliveryData.address) {
          label.innerText = mode === 'pickup' ? 'Самовывоз' : `Минимальный заказ ${formatAmount(deliveryData.minOrder)} ${currencySymbol}`;
        } else {
          label.innerText = 'Укажите адрес';
        }
      }
    })();

    // Auto-open by URL ?open_geo=1
    try {
      const sp = new URLSearchParams(window.location.search);
      if (sp.get('open_geo') === '1') openPopup();
    } catch (e) {}

    // Если попап уже виден на момент загрузки — инициализируем
    ensureInitWhenVisible();
    if (popup && popup.style.display && popup.style.display !== 'none') {
      startCenterPolling();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();