<?php
/*
Plugin Name: Яндекс Зоны Доставки WooCommerce
Description: Настройка зон доставки с использованием Яндекс.Карт и полигонов.
Version: 2.1
Author: Абдулбари Мурадил
Author URI: https://t.me/webwpcode
*/

if (!defined('ABSPATH')) exit;

define('YGP_LICENSE_HASH', '9ae883ad7cc2c562a96e2595598ecf0300926a2fda200a6b12b71b63ece3b52f');

register_activation_hook(__FILE__, function () {
    if (!get_option('ygp_license_activated')) {
        update_option('ygp_license_activated', 0);
    }
});

function ygp_verify_license_code(string $code): bool {
    $normalized = strtoupper(trim($code));
    if (empty($normalized)) return true;
    $hash = hash('sha256', 'ygp_lic_' . $normalized);
    return hash_equals(YGP_LICENSE_HASH, $hash);
}

function ygp_get_current_domain(): string {
    $url = home_url();
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

function ygp_is_licensed(): bool {
    if (!get_option('ygp_license_activated', 0)) return true;
    $bound = get_option('ygp_license_domain', '');
    if (empty($bound)) return true;
    return $bound === ygp_get_current_domain();
}

// ===== Helpers: delivery type (delivery / pickup) =====
function ygp_start_session_if_needed() {
    if (PHP_SESSION_NONE === session_status()) {
        @session_start();
    }
}

/**
 * WooCommerce session helpers (preferred over PHP sessions).
 */
function ygp_wc_session() {
    if (!function_exists('WC')) return null;
    $wc = WC();
    if (!$wc || !isset($wc->session) || !$wc->session) return null;
    return $wc->session;
}

function ygp_wc_session_get(string $key, $default = null) {
    $s = ygp_wc_session();
    if (!$s) return $default;
    $v = $s->get($key);
    return ($v === null || $v === '') ? $default : $v;
}

function ygp_wc_session_set(string $key, $value): void {
    $s = ygp_wc_session();
    if (!$s) return;
    // Ensure cookie for guests.
    if (method_exists($s, 'set_customer_session_cookie')) {
        $s->set_customer_session_cookie(true);
    }
    $s->set($key, $value);
}

function ygp_get_chosen_shipping_method(): string {
    if (!function_exists('WC') || !WC() || !WC()->session) return '';
    $chosen = WC()->session->get('chosen_shipping_methods');
    if (is_array($chosen) && !empty($chosen[0])) return (string)$chosen[0];
    return '';
}

function ygp_is_pickup_shipping_chosen(): bool {
    $chosen = ygp_get_chosen_shipping_method();
    return $chosen && strpos($chosen, 'ygp_pickup') === 0;
}

function ygp_get_user_delivery_type(): string {
    // Prefer Woo session (works for guests too).
    $from_wc = ygp_wc_session_get('ygp_delivery_type', null);
    if ($from_wc) return ((string)$from_wc === 'pickup') ? 'pickup' : 'delivery';

    // Fallback: infer from chosen shipping method (if any)
    if (ygp_is_pickup_shipping_chosen()) return 'pickup';
    $chosen = ygp_get_chosen_shipping_method();
    if ($chosen && strpos($chosen, 'ygp_delivery') === 0) return 'delivery';

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $t = get_user_meta($user_id, 'yandex_delivery_type', true);
        return $t ? (string)$t : 'delivery';
    }
    ygp_start_session_if_needed();
    return isset($_SESSION['yandex_delivery_type']) ? (string)$_SESSION['yandex_delivery_type'] : 'delivery';
}

function ygp_set_user_delivery_type(string $type): void {
    $type = ($type === 'pickup') ? 'pickup' : 'delivery';

    // Also store in Woo session for correct AJAX checkout behavior.
    ygp_wc_session_set('ygp_delivery_type', $type);

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        update_user_meta($user_id, 'yandex_delivery_type', $type);
        return;
    }
    ygp_start_session_if_needed();
    $_SESSION['yandex_delivery_type'] = $type;
}

function ygp_is_pickup_selected(): bool {
    return ygp_get_user_delivery_type() === 'pickup';
}

function ygp_get_pickup_point_id(): string {
    $from_wc = ygp_wc_session_get('ygp_pickup_point_id', '');
    if ($from_wc) return (string)$from_wc;

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $v = get_user_meta($user_id, 'yandex_pickup_point_id', true);
        return $v ? (string)$v : '';
    }
    ygp_start_session_if_needed();
    return isset($_SESSION['yandex_pickup_point_id']) ? (string)$_SESSION['yandex_pickup_point_id'] : '';
}

function ygp_set_pickup_point_id(string $pickup_point_id): void {
    $pickup_point_id = sanitize_text_field($pickup_point_id);
    ygp_wc_session_set('ygp_pickup_point_id', $pickup_point_id);

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        update_user_meta($user_id, 'yandex_pickup_point_id', $pickup_point_id);
        return;
    }
    ygp_start_session_if_needed();
    $_SESSION['yandex_pickup_point_id'] = $pickup_point_id;
}

// ===== WooCommerce Shipping Methods (Delivery / Pickup) =====
add_action('woocommerce_shipping_init', function () {
    if (!class_exists('WC_Shipping_Method')) return;

    if (!class_exists('WC_YGP_Shipping_Delivery')) {
    class WC_YGP_Shipping_Delivery extends WC_Shipping_Method {
        public function __construct($instance_id = 0) {
            $this->id                 = 'ygp_delivery';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Яндекс: Доставка';
            $this->method_description = 'Доставка по зонам (Яндекс полигоны).';
            $this->supports           = [
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            ];

            $this->init();

            $this->enabled = $this->get_option('enabled', 'yes');
            $this->title   = $this->get_option('title', 'Доставка');
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->instance_form_fields = [
                'enabled' => [
                    'title'   => 'Включить',
                    'type'    => 'checkbox',
                    'label'   => 'Включить метод доставки',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'       => 'Название',
                    'type'        => 'text',
                    'description' => 'Отображается покупателю на оформлении заказа.',
                    'default'     => 'Доставка',
                    'desc_tip'    => true,
                ],
                'fallback_cost' => [
                    'title'       => 'Цена доставки (по умолчанию)',
                    'type'        => 'price',
                    'description' => 'Если цена доставки по зоне не сохранена/не передана, будет использовано это значение.',
                    'default'     => '0',
                    'desc_tip'    => true,
                ],
            ];
        }

        public function calculate_shipping($package = []) {
            // Правило:
            // Если сумма корзины >= мин. суммы (по зоне) => доставка бесплатна (0)
            // Если сумма корзины < мин. суммы => доставка = "Цена доставки" (по зоне)
            $fallback_price = (float) $this->get_option('fallback_cost', 0);

            $zone_price_raw = ygp_wc_session_get('ygp_zone_price', null);
            $zone_price = ($zone_price_raw === null || $zone_price_raw === '') ? $fallback_price : (float)$zone_price_raw;
            if ($zone_price < 0) $zone_price = 0;

            // min_amount из попапа (ygp_min_order_amount). Пусто или 0 = фиксированная цена, без бесплатной доставки
            $min_raw = ygp_wc_session_get('ygp_min_order_amount', null);
            $min_amount = ($min_raw !== null && $min_raw !== '') ? (float)$min_raw : 0.0;
            if ($min_amount < 0) $min_amount = 0;
            $has_min = $min_amount > 0;

            $subtotal = 0.0;
            if (isset($package['contents_cost'])) {
                $subtotal = (float)$package['contents_cost'];
            } elseif (function_exists('WC') && WC() && WC()->cart) {
                $subtotal = (float)WC()->cart->subtotal;
            }

            // Нет минималки (пусто/0) => всегда фикс. цена. Есть минималка => бесплатно от мин. суммы
            $cost = !$has_min ? $zone_price : (($subtotal >= $min_amount) ? 0.0 : $zone_price);

            // DEBUG: логирование для отладки
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[YGP Delivery] calculate_shipping: ' . json_encode([
                    'zone_price_raw' => $zone_price_raw,
                    'zone_price' => $zone_price,
                    'min_raw' => $min_raw,
                    'min_amount' => $min_amount,
                    'has_min' => $has_min,
                    'subtotal' => $subtotal,
                    'cost' => $cost,
                ]));
            }

            $this->add_rate([
                'id'    => $this->id . ':' . $this->instance_id,
                'label' => $this->title,
                'cost'  => max(0, $cost),
            ]);
        }
    }
    }

    if (!class_exists('WC_YGP_Shipping_Pickup')) {
    class WC_YGP_Shipping_Pickup extends WC_Shipping_Method {
        public function __construct($instance_id = 0) {
            $this->id                 = 'ygp_pickup';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Яндекс: Самовывоз';
            $this->method_description = 'Самовывоз из выбранной точки.';
            $this->supports           = [
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            ];

            $this->init();

            $this->enabled = $this->get_option('enabled', 'yes');
            $this->title   = $this->get_option('title', 'Самовывоз');
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->instance_form_fields = [
                'enabled' => [
                    'title'   => 'Включить',
                    'type'    => 'checkbox',
                    'label'   => 'Включить метод самовывоза',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'       => 'Название',
                    'type'        => 'text',
                    'description' => 'Отображается покупателю на оформлении заказа.',
                    'default'     => 'Самовывоз',
                    'desc_tip'    => true,
                ],
                'cost' => [
                    'title'       => 'Стоимость',
                    'type'        => 'price',
                    'description' => 'Обычно 0.',
                    'default'     => '0',
                    'desc_tip'    => true,
                ],
            ];
        }

        public function calculate_shipping($package = []) {
            if (!ygp_has_open_pickup_points()) {
                return;
            }
            $cost = (float) $this->get_option('cost', 0);
            $this->add_rate([
                'id'    => $this->id . ':' . $this->instance_id,
                'label' => $this->title,
                'cost'  => max(0, $cost),
            ]);
        }
    }
    }
});

add_filter('woocommerce_shipping_methods', function ($methods) {
    $methods['ygp_delivery'] = 'WC_YGP_Shipping_Delivery';
    $methods['ygp_pickup']   = 'WC_YGP_Shipping_Pickup';
    return $methods;
});

// Обработка сохранения зон доставки
add_action('admin_post_save_yandex_zones', function () {
    if (!current_user_can('manage_options')) wp_die('Нет доступа');
    if (!ygp_is_licensed()) {
        wp_redirect(admin_url('admin.php?page=yandex_delivery&tab=settings&error=license'));
        exit;
    }
    if (isset($_POST['zones_data'])) {
        $zones = json_decode(stripslashes($_POST['zones_data']), true);
        if (is_array($zones)) {
            foreach ($zones as &$zone) {
                $zone['name'] = sanitize_text_field($zone['name'] ?? '');
                $min_raw = $zone['min_total'] ?? '';
                $zone['min_total'] = ($min_raw !== '') ? max(0.0, floatval($min_raw)) : 0.0;
            }
            update_option('yandex_delivery_zones', $zones);
        }
    }
    wp_redirect(admin_url('admin.php?page=yandex_delivery&tab=zones&saved=1'));
    exit;
});

// Обработка сохранения точек самовывоза
add_action('admin_post_save_yandex_pickup_points', function () {
    if (!current_user_can('manage_options')) wp_die('Нет доступа');
    if (!ygp_is_licensed()) {
        wp_redirect(admin_url('admin.php?page=yandex_delivery&tab=settings&error=license'));
        exit;
    }
    if (isset($_POST['pickup_points_data'])) {
        $points = json_decode(stripslashes($_POST['pickup_points_data']), true);
        if (is_array($points)) {
            $clean = [];
            foreach ($points as $p) {
                $coords = $p['coords'] ?? null;
                if (!is_array($coords) || count($coords) !== 2) continue;
                $clean[] = [
                    'id' => sanitize_text_field($p['id'] ?? wp_generate_uuid4()),
                    'name' => sanitize_text_field($p['name'] ?? ''),
                    'address' => sanitize_text_field($p['address'] ?? ''),
                    'work_start' => sanitize_text_field($p['work_start'] ?? ''),
                    'work_end' => sanitize_text_field($p['work_end'] ?? ''),
                    'is_closed_on_sunday' => !empty($p['is_closed_on_sunday']),
                    'coords' => [floatval($coords[0]), floatval($coords[1])],
                ];
            }
            update_option('yandex_pickup_points', $clean);
        }
    }
    wp_redirect(admin_url('admin.php?page=yandex_delivery&tab=pickup&saved=1'));
    exit;
});

function ygp_get_default_city_coords(): array {
    $v = get_option('ygp_default_city_coords', '55.75, 37.61');
    $v = trim((string) $v);
    if (preg_match('/^(-?\d+\.?\d*)\s*[,;\s]\s*(-?\d+\.?\d*)$/', $v, $m)) {
        return [(float) $m[1], (float) $m[2]];
    }
    if (preg_match('/\[?\s*(-?\d+\.?\d*)\s*[,;\s]\s*(-?\d+\.?\d*)\s*\]?/', $v, $m)) {
        return [(float) $m[1], (float) $m[2]];
    }
    return [55.75, 37.61];
}

function ygp_format_amount(float $num): string {
    return number_format((float) $num, 0, ',', "\xc2\xa0");
}

function ygp_lighten_hex(string $hex, int $percent = 90): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#f4f0f9';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $r = (int) round($r + (255 - $r) * $percent / 100);
    $g = (int) round($g + (255 - $g) * $percent / 100);
    $b = (int) round($b + (255 - $b) * $percent / 100);
    return sprintf('#%02x%02x%02x', min(255, $r), min(255, $g), min(255, $b));
}


// Создание страницы меню
add_action('admin_menu', function () { add_menu_page( 'Яндекс Геополигоны', 'Яндекс Геополигоны', 'manage_options', 'yandex_delivery', 'yandex_delivery_page', 'dashicons-location', 56 ); });
// Сохранение настроек
add_action('admin_init', function () { 
    register_setting('yandex_delivery_settings', 'yandex_api_key'); 
    register_setting('yandex_delivery_settings', 'ygp_default_city');
    register_setting('yandex_delivery_settings', 'ygp_default_city_coords', [
        'sanitize_callback' => function($v) {
            $v = trim((string) $v);
            if (preg_match('/^(-?\d+\.?\d*)\s*[,;\s]\s*(-?\d+\.?\d*)$/', $v, $m)) {
                return (float)$m[1] . ',' . (float)$m[2];
            }
            return '55.75,37.61';
        }
    ]);
    register_setting('yandex_delivery_settings', 'ygp_accent_color', [
        'sanitize_callback' => function ($v) {
            $v = sanitize_hex_color($v);
            return $v ?: '#51267d';
        }
    ]);
    add_action('admin_post_ygp_deactivate_license', function () {
        if (!current_user_can('manage_options')) wp_die('Доступ запрещён');
        check_admin_referer('ygp_deactivate_license');
        update_option('ygp_license_activated', 0);
        update_option('ygp_license_domain', '');
        wp_redirect(admin_url('admin.php?page=yandex_delivery&tab=settings&deactivated=1'));
        exit;
    });
    register_setting('yandex_delivery_settings', 'ygp_activation_code', [
        'sanitize_callback' => function ($v) {
            $v = trim(sanitize_text_field((string) $v));
            if ($v === '') {
                return '';
            }
            if (ygp_verify_license_code($v)) {
                update_option('ygp_license_activated', 1);
                update_option('ygp_license_domain', ygp_get_current_domain());
            }
            return ''; // не сохраняем введённый код
        }
    ]);
});

// Отображение страницы
function yandex_delivery_page() {
    $api_key = get_option('yandex_api_key');
    $zones = get_option('yandex_delivery_zones', []);
    $pickup_points = get_option('yandex_pickup_points', []);
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
    if (!ygp_is_licensed() && in_array($tab, ['zones', 'pickup'], true)) {
        wp_redirect(admin_url('admin.php?page=yandex_delivery&tab=settings&error=license'));
        exit;
    }
    ?>
    <div class="wrap">
        <h1>Яндекс Геополигоны</h1>
        <h2 class="nav-tab-wrapper">
            <a href="?page=yandex_delivery&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : '' ?>">Настройки</a>
            <?php if (ygp_is_licensed()): ?>
            <a href="?page=yandex_delivery&tab=zones" class="nav-tab <?php echo $tab === 'zones' ? 'nav-tab-active' : '' ?>">Зоны доставки</a>
            <a href="?page=yandex_delivery&tab=pickup" class="nav-tab <?php echo $tab === 'pickup' ? 'nav-tab-active' : '' ?>">Самовывоз</a>
            <?php else: ?>
            <span class="nav-tab nav-tab-disabled" style="color: #999; cursor: not-allowed;" title="Активируйте плагин">Зоны доставки</span>
            <span class="nav-tab nav-tab-disabled" style="color: #999; cursor: not-allowed;" title="Активируйте плагин">Самовывоз</span>
            <?php endif; ?>
        </h2>
        <?php if ($tab === 'zones'): ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style=" margin-top: 20px; ">
            <input type="hidden" name="action" value="save_yandex_zones">
            <input type="hidden" name="zones_data" id="zones_data_field">
            <div style="display: flex; gap: 20px;">
                <div id="map" style="width: 70%; height: 600px;"></div>
                <div style="width: 30%;">
                    <button type="button" id="addZone" class="button button-primary">Добавить зону доставки</button>
                    <div id="zonesContainer"></div>
                    <button type="submit" class="button button-secondary" style="margin-top: 10px;">Сохранить зоны доставки</button>
                </div>
            </div>
        </form>
        <script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=<?php echo esc_attr($api_key); ?>"></script>
        <script>
        let map, zoneCounter = 1;
        let currentDrawing = null;
        let zones = [];
        document.addEventListener('DOMContentLoaded', function () {
            ymaps.ready(function () {
                map = new ymaps.Map("map", {  center: <?php echo json_encode(ygp_get_default_city_coords()); ?>,  zoom: 11, controls: ['zoomControl']  });
                const savedZones = <?php echo wp_json_encode($zones); ?>;
                savedZones.forEach((z, index) => {
                    const polygon = new ymaps.Polygon([z.coords], {}, { strokeColor: z.color,  fillColor: z.color + '55',  strokeWidth: 3, editorDrawingCursor: "crosshair", draggable: true  });
                    map.geoObjects.add(polygon);
                    const zoneBlock = document.createElement('div');
                    zoneBlock.className = 'zone-block';
                    const defaultName = z.name || 'Новая зона';
                    zoneBlock.innerHTML = `
<h3 class="zone-title">${defaultName}</h3>
<label>Название:</label>
<input type="text" class="zone-name" value="${z.name || ''}"><br>
<label>Цвет:</label>
<input type="color" value="${z.color}" class="zone-color"><br>
<div style="display: flex; gap: 10px; flex-wrap: wrap;">
    <div style="flex: 1;"> <label>Цена доставки:</label>     <input type="number" class="zone-price" style="width: 100%;" value="${z.price ?? 0}" min="0" step="0.01">      </div>
    <div style="flex: 1;"> <label>Мин. сумма заказа:</label> <input type="number" class="zone-min" style="width: 100%;" value="${z.min_total ?? 0}" min="0" step="0.01" placeholder="0"> </div>
    <div style="flex: 1;"> <label>Время доставки:</label>    <input type="text" class="zone-time" style="width: 100%;" value="${z.time ?? ''}">  </div>
</div>
<textarea class="zone-coords" style="width: 100%; height: 46px; margin-top: 7px;" readonly>${JSON.stringify(z.coords)}</textarea>
<button type="button" class="button delete-zone" style=" background: #d10606; color: #fff; border: none; ">Удалить зону</button>
<hr>
                    `;
                    const nameInput = zoneBlock.querySelector('.zone-name');
                    const titleEl = zoneBlock.querySelector('.zone-title');
                    nameInput.addEventListener('input', () => {
                        titleEl.textContent = nameInput.value || defaultName;
                    });
                    document.getElementById('zonesContainer').appendChild(zoneBlock);
                    polygon.editor.startEditing();
                    polygon.geometry.events.add('change', function () {
                        const newCoords = polygon.geometry.getCoordinates()[0];
                        zoneBlock.querySelector('.zone-coords').value = JSON.stringify(newCoords);
                        z.coords = newCoords;
                    });
                    zones.push({ polygon, coords: z.coords, block: zoneBlock });
                    zoneBlock.querySelector('.delete-zone').addEventListener('click', () => {
                        map.geoObjects.remove(polygon);
                        zoneBlock.remove();
                        zones = zones.filter(item => item.polygon !== polygon);
                    });
                });
                zoneCounter = savedZones.length + 1;
            });
            document.getElementById('addZone').addEventListener('click', () => {
                stopDrawing();
                const color = '#' + Math.floor(Math.random() * 16777215).toString(16);
                const zoneBlock = document.createElement('div');
                zoneBlock.className = 'zone-block';
                const defaultName = `Зона доставки ${zoneCounter++}`;
                zoneBlock.innerHTML = `
<h3 class="zone-title">${defaultName}</h3>
<label>Название:</label>
<input type="text" class="zone-name" value=""><br>
<label>Цвет:</label>
<input type="color" value="${color}" class="zone-color"><br>
<div style="display: flex; gap: 10px; flex-wrap: wrap;">
    <div style="flex: 1;"> <label>Цена доставки:</label>     <input type="number" class="zone-price" style="width: 100%;" value="0" min="0" step="0.01">  </div>
    <div style="flex: 1;"> <label>Мин. сумма заказа:</label> <input type="number" class="zone-min" style="width: 100%;" value="" min="0" step="0.01" placeholder="0"> </div>
    <div style="flex: 1;"> <label>Время доставки:</label>    <input type="text" class="zone-time" style="width: 100%;" value="">    </div>
</div>
<textarea class="zone-coords" style="width: 100%; height: 46px; margin-top: 7px;" readonly></textarea>
<button type="button" class="button delete-zone" style=" background: #d10606; color: #fff; border: none; ">Удалить зону</button>
<hr>
                `;
                const nameInput = zoneBlock.querySelector('.zone-name');
                const titleEl = zoneBlock.querySelector('.zone-title');
                nameInput.addEventListener('input', () => {
                    titleEl.textContent = nameInput.value || defaultName;
                });
                document.getElementById('zonesContainer').appendChild(zoneBlock);
                let coords = [];
                let polygon = new ymaps.Polygon([], {}, { strokeColor: color, fillColor: color + '55', strokeWidth: 3, editorDrawingCursor: "crosshair", draggable: true });
                const clickHandler = (e) => {
                    const point = e.get('coords');
                    coords.push(point);
                    polygon.geometry.setCoordinates([coords]);
                    map.geoObjects.add(polygon);
                    zoneBlock.querySelector('.zone-coords').value = JSON.stringify(coords);
                };
                map.events.add('click', clickHandler);
                zoneBlock.querySelector('.zone-color').addEventListener('change', function () {
                    polygon.options.set('strokeColor', this.value);
                    polygon.options.set('fillColor', this.value + '55');
                });
                zoneBlock.querySelector('.delete-zone').addEventListener('click', () => {
                    map.geoObjects.remove(polygon);
                    zoneBlock.remove();
                    zones = zones.filter(item => item.polygon !== polygon);
                });
                currentDrawing = { polygon,  coords, block: zoneBlock,  handler: clickHandler };
                zones.push(currentDrawing);
            });
            const form = document.querySelector('form');
            form.addEventListener('submit', function (e) {
                const data = zones.map(z => ({
                    name: z.block.querySelector('.zone-name').value,
                    color: z.block.querySelector('.zone-color').value,
                    price: z.block.querySelector('.zone-price').value,
                                        min_total: (z.block.querySelector('.zone-min')?.value || '').trim(),
                                        time: z.block.querySelector('.zone-time')?.value || '',
                    coords: z.polygon.geometry.getCoordinates()[0]
                }));
                document.getElementById('zones_data_field').value = JSON.stringify(data);
            });
            function stopDrawing() {
                if (currentDrawing?.handler) { map.events.remove('click', currentDrawing.handler); }
                currentDrawing = null;
            }
        });
        </script>
        <?php elseif ($tab === 'pickup'): ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style=" margin-top: 20px; ">
            <input type="hidden" name="action" value="save_yandex_pickup_points">
            <input type="hidden" name="pickup_points_data" id="pickup_points_data_field">
            <div style="display: flex; gap: 20px;">
                <div id="pickup-map" style="width: 70%; height: 600px;"></div>
                <div style="width: 30%;">
                    <button type="button" id="addPickupPoint" class="button button-primary">Добавить точку самовывоза</button>
                    <div id="pickupPointsContainer"></div>
                    <button type="submit" class="button button-secondary" style="margin-top: 10px;">Сохранить точки самовывоза</button>
    </div>
            </div>
        </form>
        <script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=<?php echo esc_attr($api_key); ?>"></script>
        <script>
        (function(){
            const savedPoints = <?php echo wp_json_encode($pickup_points); ?>;
            let map = null;
            let points = [];
            let addingMode = false;
            let pointCounter = 1;

            function uid() {
                return 'pp_' + Math.random().toString(16).slice(2) + '_' + Date.now();
            }

            function reverseGeocode(coords) {
                return ymaps.geocode(coords).then(res => {
                    const geoObject = res.geoObjects.get(0);
                    return geoObject ? geoObject.getAddressLine() : '';
                }).catch(() => '');
            }

            function parseLegacyHours(text) {
                const m = String(text || '').match(/(\d{1,2}:\d{2}).*(\d{1,2}:\d{2})/);
                if (!m) return { start: '', end: '' };
                return { start: m[1], end: m[2] };
            }

            function renderPointBlock(p) {
                const block = document.createElement('div');
                block.className = 'zone-block';
                const defaultName = p.name || ('Точка самовывоза ' + (pointCounter++));
                const workStart = p.work_start || p.workStart || '';
                const workEnd = p.work_end || p.workEnd || '';
                const closedOnSunday = p.is_closed_on_sunday ? 'checked' : '';
                block.innerHTML = `
<h3 class="zone-title">${defaultName}</h3>
<label>Название:</label>
<input type="text" class="pp-name" value="${(p.name || '')}"><br>
<label>Адрес:</label>
<input type="text" class="pp-address" value="${(p.address || '')}" style="width: 100%;"><br>
<div style="display: flex; gap: 10px; flex-wrap: wrap;">
  <div style="flex: 1;">
    <label>Начало работы:</label>
    <input type="text" class="pp-work-start" value="${workStart}" placeholder="например: 10:00" style="width: 100%;">
            </div>
  <div style="flex: 1;">
    <label>Конец работы:</label>
    <input type="text" class="pp-work-end" value="${workEnd}" placeholder="например: 00:00" style="width: 100%;">
            </div>
        </div>
<div style="margin-top: 7px;">
  <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
    <input type="checkbox" class="pp-closed-on-sunday" ${closedOnSunday}>
    Выходной по воскресеньям
  </label>
</div>
<textarea class="pp-coords" style="width: 100%; height: 40px; margin-top: 7px;" readonly></textarea>
<button type="button" class="button pp-delete" style=" background: #d10606; color: #fff; border: none; ">Удалить точку</button>
<hr>
                `;
                const titleEl = block.querySelector('.zone-title');
                const nameEl = block.querySelector('.pp-name');
                nameEl.addEventListener('input', () => {
                    titleEl.textContent = nameEl.value || defaultName;
                });
                return block;
            }

            function updateCoordsTextarea(p) {
                const el = p.block.querySelector('.pp-coords');
                if (el) el.value = JSON.stringify(p.coords);
            }

            function attachPoint(p) {
                const container = document.getElementById('pickupPointsContainer');
                p.block = renderPointBlock(p);
                container.appendChild(p.block);
                updateCoordsTextarea(p);

                p.placemark.events.add('dragend', async () => {
                    const coords = p.placemark.geometry.getCoordinates();
                    p.coords = coords;
                    updateCoordsTextarea(p);
                    const addr = await reverseGeocode(coords);
                    if (addr) {
                        const addrEl = p.block.querySelector('.pp-address');
                        if (addrEl) addrEl.value = addr;
                    }
                });

                p.block.querySelector('.pp-delete').addEventListener('click', () => {
                    map.geoObjects.remove(p.placemark);
                    p.block.remove();
                    points = points.filter(x => x !== p);
                });
            }

            function addPointAt(coords) {
                const p = { id: uid(), name: '', address: '', work_start: '', work_end: '', is_closed_on_sunday: false, coords };
                p.placemark = new ymaps.Placemark(coords, {}, { draggable: true });
                map.geoObjects.add(p.placemark);
                points.push(p);
                attachPoint(p);
                reverseGeocode(coords).then(addr => {
                    if (!addr) return;
                    const addrEl = p.block.querySelector('.pp-address');
                    if (addrEl) addrEl.value = addr;
                });
            }

            document.addEventListener('DOMContentLoaded', function(){
                ymaps.ready(function(){
                    map = new ymaps.Map("pickup-map", { center: <?php echo json_encode(ygp_get_default_city_coords()); ?>, zoom: 11, controls: ['zoomControl'] });

                    (savedPoints || []).forEach(sp => {
                        const coords = sp.coords || [0,0];
                        const legacy = parseLegacyHours(sp.work_hours || '');
                        const p = {
                            id: sp.id || uid(),
                            name: sp.name || '',
                            address: sp.address || '',
                            work_start: sp.work_start || legacy.start || '',
                            work_end: sp.work_end || legacy.end || '',
                            is_closed_on_sunday: !!sp.is_closed_on_sunday,
                            coords: coords
                        };
                        p.placemark = new ymaps.Placemark(coords, {}, { draggable: true });
                        map.geoObjects.add(p.placemark);
                        points.push(p);
                        attachPoint(p);
                    });
                    pointCounter = points.length + 1;

                    map.events.add('click', function(e){
                        if (!addingMode) return;
                        addPointAt(e.get('coords'));
                        addingMode = false;
                        const btn = document.getElementById('addPickupPoint');
                        if (btn) btn.textContent = 'Добавить точку самовывоза';
                    });
                });

                document.getElementById('addPickupPoint').addEventListener('click', function(){
                    addingMode = !addingMode;
                    this.textContent = addingMode ? 'Кликните на карте, чтобы добавить точку' : 'Добавить точку самовывоза';
                });

                const form = document.querySelector('form');
                form.addEventListener('submit', function(){
                    const data = points.map(p => ({
                        id: p.id,
                        name: p.block.querySelector('.pp-name').value,
                        address: p.block.querySelector('.pp-address').value,
                        work_start: p.block.querySelector('.pp-work-start')?.value || '',
                        work_end: p.block.querySelector('.pp-work-end')?.value || '',
                        is_closed_on_sunday: p.block.querySelector('.pp-closed-on-sunday')?.checked || false,
                        coords: p.placemark.geometry.getCoordinates()
                    }));
                    document.getElementById('pickup_points_data_field').value = JSON.stringify(data);
            });
        });
        })();
        </script>
        <?php else: ?>
        <?php if (isset($_GET['deactivated']) && $_GET['deactivated'] === '1'): ?>
            <div class="notice notice-success is-dismissible"><p>Лицензия деактивирована.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'license'): ?>
            <div class="notice notice-warning is-dismissible"><p>Для доступа к зонам доставки и самовывозу необходимо активировать плагин.</p></div>
        <?php endif; ?>
        <form method="post" action="options.php" id="ygp-settings-form">
            <?php  
            settings_fields('yandex_delivery_settings');  
            do_settings_sections('yandex_delivery_settings');
            $default_city = get_option('ygp_default_city', '');
            $default_coords = get_option('ygp_default_city_coords', '55.75, 37.61');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API ключ Яндекс.Карт</th>
                    <td><input type="text" name="yandex_api_key" value="<?php echo esc_attr($api_key); ?>" style="width: 400px;" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Город по умолчанию</th>
                    <td>
                        <input type="text" name="ygp_default_city" value="<?php echo esc_attr($default_city); ?>" style="width: 400px;" placeholder="например: Москва" />
                        <p class="description">Название города для подсказок адресов.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Координаты центра карты</th>
                    <td>
                        <input type="text" name="ygp_default_city_coords" value="<?php echo esc_attr($default_coords); ?>" style="width: 400px;" placeholder="например: 55.75, 37.61" />
                        <p class="description">Широта и долгота через запятую. Все карты откроются в этой точке.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Акцентный цвет</th>
                    <td>
                        <input type="color" name="ygp_accent_color" id="ygp_accent_color" value="<?php echo esc_attr(get_option('ygp_accent_color', '#51267d')); ?>" style="width: 60px; height: 36px; padding: 2px; cursor: pointer; border-radius: 6px;" />
                        <span id="ygp_accent_hex" style="margin-left: 8px; vertical-align: middle;"><?php echo esc_html(get_option('ygp_accent_color', '#51267d')); ?></span>
                        <p class="description">Основной цвет кнопок, вкладок и выделений в попапе на фронте.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Активация плагина</th>
                    <td>
                        <?php if (ygp_is_licensed()): ?>
                            <p style="color: #12B76A; font-weight: 600;">✓ Активировано для домена: <?php echo esc_html(ygp_get_current_domain()); ?></p>
                            <p class="description">Лицензия привязана к этому домену. При переносе сайта на другой домен потребуется повторная активация.</p>
                            <button type="button" class="button" style="margin-top: 12px;" onclick="if(confirm('Деактивировать лицензию? Попап адреса перестанет работать.')){document.getElementById('ygp-deactivate-license-form').submit();}">
                                Деактивировать лицензию
                            </button>
                        <?php else: ?>
                            <p style="margin-top: 8px;">
                                <label for="ygp_activation_code">Введите код активации:</label><br>
                                <input type="text" name="ygp_activation_code" id="ygp_activation_code" value="" placeholder="XXXXXXXX" style="width: 400px; margin-top: 4px;" autocomplete="off" />
                            </p>
                            <p class="description">Вставьте лицензионный код и сохраните. После активации лицензия будет привязана к текущему домену. Без активации попап адреса не будет работать.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <form id="ygp-deactivate-license-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
            <?php wp_nonce_field('ygp_deactivate_license'); ?>
            <input type="hidden" name="action" value="ygp_deactivate_license">
        </form>
        <?php endif; ?>
    </div>
    <?php
}



add_shortcode('yandex_min_order_amount', 'yandex_min_order_amount_shortcode');
function yandex_min_order_amount_shortcode() {
    $min_order = get_transient('yandex_min_order_amount_transient');
    if (false === $min_order) {
        $min_order = get_user_min_order_amount();
        set_transient('yandex_min_order_amount_transient', $min_order, 5 * MINUTE_IN_SECONDS); // Кэшируем на 5 минут
    }
    return '<span class="yandex-min-order-amount">' . esc_html($min_order) . '</span>';
}

add_shortcode('yandex_delivery_time', 'yandex_delivery_time_shortcode');
function yandex_delivery_time_shortcode() {
    $delivery_time = get_transient('yandex_delivery_time_transient');
    if (false === $delivery_time) {
        // Здесь можно добавить логику для получения времени доставки по умолчанию, если нет сохраненного
        $delivery_time = ''; // Или какое-то значение по умолчанию
        set_transient('yandex_delivery_time_transient', $delivery_time, 5 * MINUTE_IN_SECONDS); // Кэшируем на 5 минут
    }
    return '<span class="yandex-delivery-time">' . esc_html($delivery_time) . '</span>';
}

// Шорткод: ссылка/кнопка для открытия попапа адреса
// Пример: [yandex_address_popup_link text="Укажите адрес" class="open-yandex-address-popup"]
add_shortcode('yandex_address_popup_link', function ($atts) {
    if (!ygp_is_licensed()) return '';
    $atts = shortcode_atts([
        'text' => 'Указать адрес',
        'class' => 'open-yandex-address-popup'
    ], $atts, 'yandex_address_popup_link');

    $text = sanitize_text_field($atts['text']);
    $class = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string)$atts['class']);

    return '<a href="#" class="' . esc_attr(trim($class)) . '" data-open-yandex-popup="1">' . esc_html($text) . '</a>';
});

add_action('wp_footer', 'yandex_zones_address_popup');
function yandex_zones_address_popup() {
    if (!ygp_is_licensed()) return;
    ?>
    <div id="yandex-address-popup" class="modal-overlay">
        <div class="modal-card">
            
            <!-- Интерфейс -->
            <div class="sidebar" id="ygp-sidebar">
                <div class="sidebar-header">
                    <div class="back-btn close-geo-target">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg>
                    </div>
                    <h2 id="modal-title">Адрес доставки</h2>
                </div>

                <!-- МОБИЛЬНЫЙ ПЕРЕКЛЮЧАТЕЛЬ -->
                <div class="mobile-tabs" data-delivery-toggle="1">
                    <div class="mobile-tab-btn active" id="m-tab-delivery" data-type="delivery">Доставка</div>
                    <div class="mobile-tab-btn" id="m-tab-pickup" data-type="pickup">Самовывоз</div>
                </div>

                <!-- ПК ПЕРЕКЛЮЧАТЕЛЬ -->
                <div class="pc-tabs" data-delivery-toggle="1">
                    <div class="tab-btn active" id="p-tab-delivery" data-type="delivery">Доставка</div>
                    <div class="tab-btn" id="p-tab-pickup" data-type="pickup">Самовывоз</div>
                </div>

                <div class="search-container">
                    <div class="input-wrapper">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        <input type="text" id="yandex-address-input" placeholder="Введите адрес">
                    </div>
                    <div id="autocompleteResults" class="autocomplete-results"></div>
                </div>

                <div class="scroll-content" id="content-area">
                    <!-- Контент (Форма или Филиалы) будет рендериться через JS -->
                </div>

                <div class="bottom-bar">
                    <button class="confirm-btn" id="confirm-address">Подтвердить</button>
                    <div id="delivery-notice" class="delivery-notice" style="display: none;"></div>
                    <div id="address-result" class="address-result" style="display: none;"></div>
                    <div id="min-order-message" class="min-order-message" style="display: none;"></div>
                </div>
            </div>
            
            <!-- Карта -->
            <div class="map-section" id="map-wrapper">
                <div class="close-btn close-geo-target">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </div>
                <div class="zoom-controls">
                    <button class="zoom-btn" id="ygp-zoom-in" title="Увеличить">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <rect x="11" y="5" width="2" height="14" rx="1"/>
                            <rect x="5" y="11" width="14" height="2" rx="1"/>
                        </svg>
                    </button>
                    <button class="zoom-btn" id="ygp-zoom-out" title="Уменьшить">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <rect x="5" y="11" width="14" height="2" rx="1"/>
                        </svg>
                    </button>
                </div>
                <div class="geo-btn" id="ygp-map-geolocate">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm8.94 3c-.46-4.17-3.77-7.48-7.94-7.94V1h-2v2.06C6.83 3.52 3.52 6.83 3.06 11H1v2h2.06c.46 4.17 3.77 7.48 7.94 7.94V23h2v-2.06c4.17-.46 7.48-3.77 7.94-7.94H23v-2h-2.06zM12 19c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z"/></svg>
                    Определить местоположение
                </div>
                <div class="styles_marker__SydyV css-j8p1wg" id="ygp-center-marker">
                    <div class="styles_head__Jg58X css-q82mwo">
                        <div class="chakra-spinner css-1ddbuvn" id="ygp-loader" style="display:none"></div>
                    </div>
                    <div class="styles_stick__hpgFV"></div>
                    <div class="styles_shadow__UAytO"></div>
                </div>
                <div id="map" class="map-canvas"></div>
            </div>
        </div>
    </div>

<?php
}

function ygp_ajax_save_billing_address() {
    $address  = sanitize_text_field($_POST['address'] ?? '');
    $flat     = sanitize_text_field($_POST['flat'] ?? '');
    $intercom = sanitize_text_field($_POST['intercom'] ?? '');
    $entrance = sanitize_text_field($_POST['entrance'] ?? '');
    $floor    = sanitize_text_field($_POST['floor'] ?? '');

    if (!$address) {
        wp_send_json_error('Адрес не передан');
    }

    // Store in Woo session with delivery prefix (жёсткое разделение)
    ygp_wc_session_set('ygp_delivery_address', $address);
    ygp_wc_session_set('ygp_delivery_flat', $flat);
    ygp_wc_session_set('ygp_delivery_intercom', $intercom);
    ygp_wc_session_set('ygp_delivery_entrance', $entrance);
    ygp_wc_session_set('ygp_delivery_floor', $floor);

    // Persist for logged-in users.
    $user_id = get_current_user_id();
    if ($user_id > 0) {
        update_user_meta($user_id, 'billing_address_1', $address);
        update_user_meta($user_id, 'billing_address_flat', $flat);
        update_user_meta($user_id, 'billing_address_intercom', $intercom);
        update_user_meta($user_id, 'billing_address_entrance', $entrance);
        update_user_meta($user_id, 'billing_address_floor', $floor);
    }

    wp_send_json_success('Адрес и доп. поля сохранены');
}
add_action('wp_ajax_save_billing_address', 'ygp_ajax_save_billing_address');
add_action('wp_ajax_nopriv_save_billing_address', 'ygp_ajax_save_billing_address');
add_action('wp_ajax_get_billing_address', function () {
    if (!is_user_logged_in()) {  wp_send_json_error('Пользователь не авторизован');  }
    $user_id = get_current_user_id();
    $address = get_user_meta($user_id, 'billing_address_1', true);
    if (!empty($address)) {  wp_send_json_success(['address' => $address]); } 
	else { wp_send_json_error('Адрес в профиле отсутствует'); }
});

// AJAX: сохраняем тип (delivery/pickup) в user_meta или сессию
function ygp_ajax_set_delivery_type() {
    $type = isset($_POST['delivery_type']) ? sanitize_text_field(wp_unslash($_POST['delivery_type'])) : 'delivery';
    $type = ($type === 'pickup') ? 'pickup' : 'delivery';
    ygp_set_user_delivery_type($type);

    $pickup_point_id = isset($_POST['pickup_point_id']) ? sanitize_text_field(wp_unslash($_POST['pickup_point_id'])) : '';
    // Не очищаем выбранный ПВЗ при переключении на "доставка" — сохраняем последний выбор,
    // чтобы при возврате на самовывоз он оставался выбранным.
    if ($type === 'pickup') {
        if ($pickup_point_id === '') {
            $pickup_point_id = ygp_get_pickup_point_id();
        }
        ygp_set_pickup_point_id($pickup_point_id);
    }

    wp_send_json_success([
        'delivery_type' => ygp_get_user_delivery_type(),
        'pickup_point_id' => ygp_get_pickup_point_id()
    ]);
}
add_action('wp_ajax_yandex_set_delivery_type', 'ygp_ajax_set_delivery_type');
add_action('wp_ajax_nopriv_yandex_set_delivery_type', 'ygp_ajax_set_delivery_type');


add_action('wp_ajax_save_min_order_amount', 'save_min_order_amount');
add_action('wp_ajax_nopriv_save_min_order_amount', 'save_min_order_amount');

function save_min_order_amount() {
    $raw = $_POST['min_amount'] ?? null;
    // Пустое значение = очистка (чтобы не оставались старые данные зоны)
    if ($raw === null || $raw === '') {
        ygp_wc_session_set('ygp_min_order_amount', '');
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            delete_user_meta($user_id, 'yandex_min_order_amount');
        } else {
            ygp_start_session_if_needed();
            unset($_SESSION['yandex_min_order_amount']);
        }
        wp_send_json_success('Минимальная сумма заказа очищена');
    }
    $min_amount = floatval($raw);
    // Store in Woo session (preferred).
    ygp_wc_session_set('ygp_min_order_amount', $min_amount);

    // Also persist for logged-in users for compatibility with old shortcodes.
    $user_id = get_current_user_id();
    if ($user_id > 0) {
        update_user_meta($user_id, 'yandex_min_order_amount', $min_amount);
    } else {
        // Backward fallback (old PHP sessions).
        ygp_start_session_if_needed();
        $_SESSION['yandex_min_order_amount'] = $min_amount;
    }
    wp_send_json_success('Минимальная сумма заказа сохранена');
}

add_action('wp_ajax_save_zone_price', 'ygp_save_zone_price');
add_action('wp_ajax_nopriv_save_zone_price', 'ygp_save_zone_price');
function ygp_save_zone_price() {
    $raw = $_POST['zone_price'] ?? null;
    if ($raw === null || $raw === '') {
        ygp_wc_session_set('ygp_zone_price', '');
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            delete_user_meta($user_id, 'yandex_zone_price');
        } else {
            ygp_start_session_if_needed();
            unset($_SESSION['yandex_zone_price']);
        }
        wp_send_json_success('Цена зоны очищена');
    }
    $zone_price = floatval($raw);
    ygp_wc_session_set('ygp_zone_price', $zone_price);

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        update_user_meta($user_id, 'yandex_zone_price', $zone_price);
    } else {
        ygp_start_session_if_needed();
        $_SESSION['yandex_zone_price'] = $zone_price;
    }
    wp_send_json_success('Цена зоны сохранена');
}

add_action('wp_ajax_save_delivery_time', 'ygp_save_delivery_time');
add_action('wp_ajax_nopriv_save_delivery_time', 'ygp_save_delivery_time');
function ygp_save_delivery_time() {
    $raw = $_POST['delivery_time'] ?? null;
    if ($raw === null || $raw === '') {
        ygp_wc_session_set('ygp_delivery_time', '');
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            delete_user_meta($user_id, 'yandex_delivery_time');
        } else {
            ygp_start_session_if_needed();
            unset($_SESSION['yandex_delivery_time']);
        }
        wp_send_json_success('Время доставки очищено');
    }
    $delivery_time = sanitize_text_field($raw);
    ygp_wc_session_set('ygp_delivery_time', $delivery_time);

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        update_user_meta($user_id, 'yandex_delivery_time', $delivery_time);
    } else {
        ygp_start_session_if_needed();
        $_SESSION['yandex_delivery_time'] = $delivery_time;
    }
    wp_send_json_success('Время доставки сохранено');
}

// ===== ЕДИНЫЙ AJAX для атомарного сохранения ВСЕХ данных доставки =====
add_action('wp_ajax_save_delivery_data_atomic', 'ygp_save_delivery_data_atomic');
add_action('wp_ajax_nopriv_save_delivery_data_atomic', 'ygp_save_delivery_data_atomic');
function ygp_save_delivery_data_atomic() {
    // Инициализируем WC session для AJAX с любой страницы (товар, корзина, главная)
    if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
        if (method_exists(WC()->session, 'set_customer_session_cookie')) {
            WC()->session->set_customer_session_cookie(true);
        }
    }

    // Принимает ВСЕ данные доставки разом и сохраняет АТОМАРНО в одной операции
    $delivery_type = isset($_POST['delivery_type']) ? sanitize_text_field(wp_unslash($_POST['delivery_type'])) : 'delivery';
    $delivery_type = ($delivery_type === 'pickup') ? 'pickup' : 'delivery';

    // Данные зоны (для доставки)
    $min_order = isset($_POST['min_order']) ? floatval($_POST['min_order']) : 0;
    $zone_price = isset($_POST['zone_price']) ? floatval($_POST['zone_price']) : 0;
    $delivery_time = isset($_POST['delivery_time']) ? sanitize_text_field($_POST['delivery_time']) : '';

    // Адрес + доп поля (для доставки)
    $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
    $flat = isset($_POST['flat']) ? sanitize_text_field($_POST['flat']) : '';
    $intercom = isset($_POST['intercom']) ? sanitize_text_field($_POST['intercom']) : '';
    $entrance = isset($_POST['entrance']) ? sanitize_text_field($_POST['entrance']) : '';
    $floor = isset($_POST['floor']) ? sanitize_text_field($_POST['floor']) : '';

    // Pickup point
    $pickup_point_id = isset($_POST['pickup_point_id']) ? sanitize_text_field($_POST['pickup_point_id']) : '';

    // АТОМАРНАЯ запись в WC()->session
    ygp_wc_session_set('ygp_delivery_type', $delivery_type);
    
    if ($delivery_type === 'delivery') {
        ygp_wc_session_set('ygp_min_order_amount', $min_order);
        ygp_wc_session_set('ygp_zone_price', $zone_price);
        ygp_wc_session_set('ygp_delivery_time', $delivery_time);
        ygp_wc_session_set('ygp_delivery_address', $address);
        ygp_wc_session_set('ygp_delivery_flat', $flat);
        ygp_wc_session_set('ygp_delivery_intercom', $intercom);
        ygp_wc_session_set('ygp_delivery_entrance', $entrance);
        ygp_wc_session_set('ygp_delivery_floor', $floor);
        ygp_set_user_delivery_type('delivery');
    } else {
        ygp_set_user_delivery_type('pickup');
        ygp_set_pickup_point_id($pickup_point_id);
    }

    // Также persist для авторизованных
    $user_id = get_current_user_id();
    if ($user_id > 0 && $delivery_type === 'delivery') {
        update_user_meta($user_id, 'billing_address_1', $address);
        update_user_meta($user_id, 'billing_address_flat', $flat);
        update_user_meta($user_id, 'billing_address_intercom', $intercom);
        update_user_meta($user_id, 'billing_address_entrance', $entrance);
        update_user_meta($user_id, 'billing_address_floor', $floor);
        update_user_meta($user_id, 'yandex_min_order_amount', $min_order);
        update_user_meta($user_id, 'yandex_zone_price', $zone_price);
        update_user_meta($user_id, 'yandex_delivery_time', $delivery_time);
    }

    // КРИТИЧНО: Инвалидируем кеш shipping rates и синхронизируем chosen_shipping_methods
    if (function_exists('WC') && WC() && WC()->cart && WC()->session) {
        $packages = WC()->cart->get_shipping_packages();
        foreach ($packages as $package_key => $package) {
            WC()->session->set('shipping_for_package_' . $package_key, false);
        }
        WC()->cart->calculate_shipping();
        // Синхронизируем chosen_shipping_methods — чтобы переключение в попапе отражалось на чекауте
        $prefix = ($delivery_type === 'pickup') ? 'ygp_pickup' : 'ygp_delivery';
        $chosen = [];
        foreach (WC()->cart->get_shipping_packages() as $package_key => $package) {
            $pkg = WC()->session->get('shipping_for_package_' . $package_key);
            $rates = is_array($pkg) && isset($pkg['rates']) ? $pkg['rates'] : [];
            $found = '';
            foreach ($rates as $rate) {
                $id = is_object($rate) && method_exists($rate, 'get_id') ? $rate->get_id() : (is_array($rate) && isset($rate['id']) ? $rate['id'] : '');
                if ($id && strpos((string)$id, $prefix) === 0) {
                    $found = $id;
                    break;
                }
            }
            $chosen[] = $found ?: $prefix . ':0';
        }
        if (!empty($chosen)) {
            WC()->session->set('chosen_shipping_methods', $chosen);
        }
    }

    // DEBUG: логируем сохранённые данные
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[YGP Atomic Save] Saved: ' . json_encode([
            'delivery_type' => $delivery_type,
            'min_order' => $min_order,
            'zone_price' => $zone_price,
            'delivery_time' => $delivery_time,
            'address' => $address,
            'pickup_point_id' => $pickup_point_id,
        ]));
        // Проверяем что данные реально записались в сессию
        error_log('[YGP Atomic Save] Session check: ' . json_encode([
            'ygp_min_order_amount' => ygp_wc_session_get('ygp_min_order_amount'),
            'ygp_zone_price' => ygp_wc_session_get('ygp_zone_price'),
            'ygp_delivery_time' => ygp_wc_session_get('ygp_delivery_time'),
        ]));
    }

    wp_send_json_success([
        'delivery_type' => $delivery_type,
        'min_order' => $min_order,
        'zone_price' => $zone_price,
        'delivery_time' => $delivery_time,
        'pickup_point_id' => $delivery_type === 'pickup' ? $pickup_point_id : '',
    ]);
}

// Функция для получения минимальной суммы заказа
function get_user_min_order_amount() {
    if (ygp_is_pickup_selected()) {
        return 0;
    }
    $from_wc = ygp_wc_session_get('ygp_min_order_amount', null);
    if ($from_wc !== null) return floatval($from_wc);

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $min_amount = get_user_meta($user_id, 'yandex_min_order_amount', true);
        if ($min_amount !== '' && $min_amount !== null) return floatval($min_amount);
    }

    ygp_start_session_if_needed();
    if (isset($_SESSION['yandex_min_order_amount'])) {
        return floatval($_SESSION['yandex_min_order_amount']);
    }
    return 0; // По умолчанию нет ограничений
}

// ===== Checkout integration (fields, session sync, validation, order meta) =====
add_filter('woocommerce_checkout_fields', function ($fields) {
    // Ensure our additional fields exist (so user doesn't have to add them manually).
    $fields['billing'] = $fields['billing'] ?? [];

    // Порядок как в попапе: Подъезд, Этаж, Квартира, Домофон
    if (!isset($fields['billing']['billing_pod'])) {
        $fields['billing']['billing_pod'] = [
            'type'        => 'text',
            'label'       => 'Подъезд',
            'required'    => false,
            'class'       => ['form-row-first', 'form-row-pod'],
            'priority'    => 121,
            'clear'       => false,
            'autocomplete'=> 'off',
        ];
    }
    if (!isset($fields['billing']['billing_etaj'])) {
        $fields['billing']['billing_etaj'] = [
            'type'        => 'text',
            'label'       => 'Этаж',
            'required'    => false,
            'class'       => ['form-row-last', 'form-row-etaj'],
            'priority'    => 122,
            'clear'       => false,
            'autocomplete'=> 'off',
        ];
    }
    if (!isset($fields['billing']['billing_kv'])) {
        $fields['billing']['billing_kv'] = [
            'type'        => 'text',
            'label'       => 'Квартира',
            'required'    => false,
            'class'       => ['form-row-first', 'form-row-kv'],
            'priority'    => 123,
            'clear'       => false,
            'autocomplete'=> 'off',
        ];
    }
    if (!isset($fields['billing']['billing_domofon'])) {
        $fields['billing']['billing_domofon'] = [
            'type'        => 'text',
            'label'       => 'Домофон',
            'required'    => false,
            'class'       => ['form-row-last', 'form-row-domofon'],
            'priority'    => 124,
            'clear'       => true,
            'autocomplete'=> 'off',
        ];
    }

    // Адрес всегда обязателен (и для доставки, и для самовывоза)
    if (isset($fields['billing']['billing_address_1'])) {
        $fields['billing']['billing_address_1']['required'] = true;
    }

    return $fields;
}, 20);

// ===== Pretty checkout shipping selector (labels + CSS) =====
function ygp_format_work_hours(?array $point): string {
    if (!$point) return '';
    $start = trim((string)($point['work_start'] ?? ''));
    $end   = trim((string)($point['work_end'] ?? ''));
    if ($start && $end) return $start . '–' . $end;
    // Legacy support: "10:00 - 22:00"
    $legacy = trim((string)($point['work_hours'] ?? ''));
    if ($legacy) {
        if (preg_match('/(\d{1,2}:\d{2}).*(\d{1,2}:\d{2})/', $legacy, $m)) {
            return $m[1] . '–' . $m[2];
        }
    }
    return '';
}

function ygp_get_delivery_address_for_display(): string {
    $addr = (string) ygp_wc_session_get('ygp_delivery_address', '');
    return trim($addr);
}

function ygp_get_delivery_time_for_display(): string {
    $t = (string) ygp_wc_session_get('ygp_delivery_time', '');
    return trim($t);
}

function ygp_get_delivery_min_order_for_display(): float {
    $v = ygp_wc_session_get('ygp_min_order_amount', 0);
    return (float)$v;
}

function ygp_get_delivery_zone_price_for_display(): float {
    $v = ygp_wc_session_get('ygp_zone_price', 0);
    return (float)$v;
}

function ygp_shipping_method_full_label_html(string $label, $method): string {
    // Woo passes WC_Shipping_Rate here.
    if (!is_object($method) || !method_exists($method, 'get_method_id')) return $label;
    $mid = (string) $method->get_method_id();
    if ($mid !== 'ygp_delivery' && $mid !== 'ygp_pickup') return $label;

    $cost = 0;
    if (method_exists($method, 'get_cost')) {
        $cost = (float) $method->get_cost();
    }
    $price_html = $cost > 0 ? wc_price($cost) : '<span class="ygp-shipping-free">Бесплатно</span>';

    if ($mid === 'ygp_delivery') {
        // Подсказка про бесплатную доставку (оставляем только это)
        $minOrder = ygp_get_delivery_min_order_for_display();
        $freeHint = '';
        if ($cost > 0 && $minOrder > 0) {
            $curr = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '₽';
            $amount_html = '<span class="ygp-shipping-hint-amount">' . esc_html(ygp_format_amount($minOrder)) . ' ' . esc_html($curr) . '</span>';
            $freeHint = '<span class="ygp-shipping-meta"><span class="ygp-shipping-hint">Доставка бесплатно от ' . $amount_html . '</span></span>';
        }

        $html = '
          <span class="ygp-shipping-card ygp-shipping-card--delivery">
            <span class="ygp-shipping-top">
              <span class="ygp-shipping-title">
                <span class="ygp-shipping-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 7h11v10H3z"></path>
                    <path d="M14 10h4l3 3v4h-7z"></path>
                    <circle cx="7" cy="19" r="2"></circle>
                    <circle cx="17" cy="19" r="2"></circle>
                  </svg>
                </span>
                Доставка
              </span>
              <span class="ygp-shipping-price"><span class="ygp-shipping-price-amount">' . wp_kses_post($price_html) . '</span></span>
            </span>
            ' . $freeHint . '
          </span>
        ';
        return wp_kses_post($html);
    }

    // pickup - только название и цена
    $html = '
      <span class="ygp-shipping-card ygp-shipping-card--pickup">
        <span class="ygp-shipping-top">
          <span class="ygp-shipping-title">
            <span class="ygp-shipping-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 10l9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <path d="M9 22V12h6v10"></path>
              </svg>
            </span>
            Самовывоз
          </span>
          <span class="ygp-shipping-price"><span class="ygp-shipping-price-amount">' . wp_kses_post($price_html) . '</span></span>
        </span>
      </span>
    ';
    return wp_kses_post($html);
}

add_filter('woocommerce_cart_shipping_method_full_label', 'ygp_shipping_method_full_label_html', 10, 2);
add_filter('woocommerce_shipping_method_full_label', 'ygp_shipping_method_full_label_html', 10, 2);

add_action('wp_enqueue_scripts', function () {
    if (!ygp_is_licensed() || !function_exists('is_checkout') || !is_checkout() || is_order_received_page()) return;
    
    // Минималистичные стили - показываем только выбранный метод
    $css = '
    /* Базовые стили для методов доставки */
    .woocommerce-shipping-methods {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    
    /* Скрываем невыбранные методы; показываем только выбранный. Самовывоз скрыт отдельно (не добавляется в список). */
    .woocommerce-shipping-methods li {
      display: none !important;
      margin: 0 !important;
    }
    .woocommerce-shipping-methods li:has(input.shipping_method:checked) {
      display: block !important;
    }
    /* Fallback: когда ни один метод не отмечен (напр. самовывоз закрыт) — показать первый (доставку) */
    .woocommerce-shipping-methods:not(:has(input.shipping_method:checked)) li:first-child {
      display: block !important;
    }
    @supports not selector(:has(*)) {
      .woocommerce-shipping-methods li:first-child { display: block !important; }
    }
    
    /* Скрываем радио-кнопки */
    .woocommerce-shipping-methods input.shipping_method {
      position: absolute !important;
      opacity: 0 !important;
      pointer-events: none !important;
    }
    
    /* Простой label без карточки */
    .woocommerce-shipping-methods label {
      display: block;
      cursor: default;
    }
    
    /* Верхняя строка: название + цена */
    .woocommerce-shipping-methods .ygp-shipping-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }
    
    /* Название метода */
    .woocommerce-shipping-methods .ygp-shipping-title {
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    
    /* Иконка (акцентный цвет из настроек) */
    .woocommerce-shipping-methods .ygp-shipping-icon {
      display: inline-flex;
      width: 16px;
      height: 16px;
      color: ' . esc_attr(sanitize_hex_color(get_option('ygp_accent_color', '#51267d')) ?: '#51267d') . ';
    }
    
    /* Цена */
    .woocommerce-shipping-methods .ygp-shipping-price {
      font-weight: 600;
    }
    .woocommerce-shipping-methods .ygp-shipping-free {
      color: #12B76A;
    }
    
    /* Мета-информация (только hint - минимальный заказ) */
    .woocommerce-shipping-methods .ygp-shipping-meta {
      margin-top: 4px;
      font-size: 12px;
      color: #667085;
    }
    .woocommerce-shipping-methods .ygp-shipping-hint {
      display: block;
      font-style: italic;
      opacity: 0.8;
    }
    
    /* Скрываем заголовок "Доставка" */
    .woocommerce-checkout-review-order-table .woocommerce-shipping-totals th {
      display: none;
    }
    
    /* Убираем отступ слева */
    .woocommerce-checkout-review-order-table .woocommerce-shipping-totals td {
      padding-left: 0px;
    }
    
    /* Поле адреса и доп. полей — кликабельные (открывают попап) */
    #billing_address_1_field input,
    #billing_address_1_field,
    #billing_kv_field, #billing_kv_field input,
    #billing_pod_field, #billing_pod_field input,
    #billing_etaj_field, #billing_etaj_field input,
    #billing_domofon_field, #billing_domofon_field input {
      cursor: pointer;
    }
    ';

    // Attach to our already enqueued style handle if available, otherwise inline in head.
    if (wp_style_is('yandex-geocoder-style', 'enqueued')) {
        wp_add_inline_style('yandex-geocoder-style', $css);
    } else {
        wp_register_style('ygp-checkout-inline', false);
        wp_enqueue_style('ygp-checkout-inline');
        wp_add_inline_style('ygp-checkout-inline', $css);
    }
}, 30);

/**
 * During checkout AJAX refresh, sync "delivery_type" into WC session based on chosen shipping method.
 * This keeps existing logic (min order, popup state) consistent with Woo shipping selection.
 */
add_action('woocommerce_checkout_update_order_review', function ($posted_data) {
    if (!function_exists('WC') || !WC() || !WC()->session) return;

    // 1) Если все точки закрыты — сбрасываем самовывоз и переключаем метод доставки на доставку
    //    (и адрес, и chosen_shipping_methods), чтобы в чекауте показывалась доставка
    if (!ygp_has_open_pickup_points()) {
        $chosen = WC()->session->get('chosen_shipping_methods');
        $chosen = is_array($chosen) ? $chosen : [];
        $has_pickup = !empty(array_filter($chosen, function ($m) { return $m && strpos((string)$m, 'ygp_pickup') === 0; }));
        if ($has_pickup) {
            ygp_set_pickup_point_id('');
            ygp_wc_session_set('ygp_delivery_type', 'delivery');
            WC()->cart->calculate_shipping();
            $packages = WC()->cart->get_shipping_packages();
            $new_chosen = [];
            foreach (array_keys($packages) as $k) {
                $pkg = WC()->session->get('shipping_for_package_' . $k);
                $rates = is_array($pkg) && isset($pkg['rates']) ? $pkg['rates'] : [];
                $found = '';
                foreach ($rates as $r) {
                    $id = is_object($r) && method_exists($r, 'get_id') ? $r->get_id() : (is_array($r) && isset($r['id']) ? $r['id'] : '');
                    if ($id && strpos((string)$id, 'ygp_delivery') === 0) { $found = $id; break; }
                }
                if (!$found && !empty($rates)) {
                    $first = reset($rates);
                    $found = is_object($first) && method_exists($first, 'get_id') ? $first->get_id() : (is_array($first) && isset($first['id']) ? $first['id'] : '');
                }
                $new_chosen[] = $found;
            }
            if (!empty($new_chosen) && array_filter($new_chosen)) {
                WC()->session->set('chosen_shipping_methods', array_values($new_chosen));
            }
        }
    }

    // 2) Синхронизация delivery_type из posted_data
    parse_str((string)$posted_data, $data);
    $method = '';
    if (isset($data['shipping_method']) && is_array($data['shipping_method']) && !empty($data['shipping_method'][0])) {
        $method = (string)$data['shipping_method'][0];
    }
    if ($method && strpos($method, 'ygp_pickup') === 0) {
        ygp_wc_session_set('ygp_delivery_type', 'pickup');
        $pid = ygp_get_pickup_point_id();
        if ($pid) {
            $point = ygp_find_pickup_point($pid);
            if ($point && !ygp_is_pickup_point_open($point)) {
                ygp_set_pickup_point_id('');
            }
        }
    } elseif ($method && strpos($method, 'ygp_delivery') === 0) {
        ygp_wc_session_set('ygp_delivery_type', 'delivery');
    }
}, 5);

/**
 * Перед отрисовкой методов доставки: если все точки закрыты и выбран самовывоз —
 * переключаем chosen_shipping_methods на доставку (чтобы метод доставки показывался, а не скрывался).
 */
add_action('woocommerce_review_order_before_shipping', function () {
    if (!function_exists('WC') || !WC() || !WC()->session) return;
    if (ygp_has_open_pickup_points()) return;

    $chosen = WC()->session->get('chosen_shipping_methods');
    $chosen = is_array($chosen) ? $chosen : [];
    $has_pickup = !empty(array_filter($chosen, function ($m) { return $m && strpos((string)$m, 'ygp_pickup') === 0; }));
    if (!$has_pickup) return;

    ygp_set_pickup_point_id('');
    ygp_wc_session_set('ygp_delivery_type', 'delivery');
    WC()->cart->calculate_shipping();
    $packages = WC()->cart->get_shipping_packages();
    $new_chosen = [];
    foreach (array_keys($packages) as $k) {
        $pkg = WC()->session->get('shipping_for_package_' . $k);
        $rates = is_array($pkg) && isset($pkg['rates']) ? $pkg['rates'] : [];
        $found = '';
        foreach ($rates as $r) {
            $id = is_object($r) && method_exists($r, 'get_id') ? $r->get_id() : (is_array($r) && isset($r['id']) ? $r['id'] : '');
            if ($id && strpos((string)$id, 'ygp_delivery') === 0) { $found = $id; break; }
        }
        if (!$found && !empty($rates)) {
            $first = reset($rates);
            $found = is_object($first) && method_exists($first, 'get_id') ? $first->get_id() : (is_array($first) && isset($first['id']) ? $first['id'] : '');
        }
        $new_chosen[] = $found;
    }
    if (!empty($new_chosen) && array_filter($new_chosen)) {
        WC()->session->set('chosen_shipping_methods', array_values($new_chosen));
    }
});

add_action('woocommerce_checkout_process', function () {
    $is_pickup = ygp_is_pickup_selected();
    
    if ($is_pickup) {
        // Если все точки закрыты — запрет на самовывоз
        if (!ygp_has_open_pickup_points()) {
            wc_add_notice('Сейчас все пункты самовывоза закрыты. Выберите доставку или попробуйте позже.', 'error');
            return;
        }
        $pid = ygp_get_pickup_point_id();
        if (!$pid) {
            wc_add_notice('Выберите пункт самовывоза. Нажмите на поле "Адрес" и выберите точку на карте.', 'error');
            return;
        }
        $point = ygp_find_pickup_point($pid);
        if (!$point) {
            wc_add_notice('Выбранный пункт самовывоза не найден. Выберите другой пункт.', 'error');
            return;
        }
        if (!ygp_is_pickup_point_open($point)) {
            wc_add_notice('Выбранный пункт самовывоза сейчас закрыт. Откройте карту и выберите открытую точку.', 'error');
        }
    } else {
        // Для доставки требуем адрес, выбранный через попап (сохранённый в сессии)
        $addr = ygp_wc_session_get('ygp_delivery_address', '');
        if (empty(trim($addr))) {
            wc_add_notice('Укажите адрес доставки. Нажмите на поле "Адрес" и выберите точку на карте.', 'error');
        }
    }
});

function ygp_find_pickup_point(string $id): ?array {
    $id = (string)$id;
    if (!$id) return null;
    $pts = get_option('yandex_pickup_points', []);
    if (!is_array($pts)) return null;
    foreach ($pts as $p) {
        if (!is_array($p)) continue;
        if (($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

/**
 * Проверяет, открыта ли точка самовывоза в текущий момент (по work_start/work_end).
 * Логика соответствует frontend.js getPickupStatus.
 */
function ygp_is_pickup_point_open(array $point): bool {
    $tz = function_exists('wp_timezone') ? wp_timezone() : null;
    $now = $tz ? new DateTime('now', $tz) : new DateTime('now');

    // Проверка выходного по воскресеньям
    if (!empty($point['is_closed_on_sunday']) && (int)$now->format('w') === 0) {
        return false;
    }

    $start = trim((string)($point['work_start'] ?? ''));
    $end   = trim((string)($point['work_end'] ?? ''));
    if (!$start && !$end && !empty($point['work_hours'])) {
        if (preg_match('/(\d{1,2}:\d{2}).*(\d{1,2}:\d{2})/', (string)$point['work_hours'], $m)) {
            $start = $m[1];
            $end   = $m[2];
        }
    }
    if (!$start || !$end) return true; // без расписания считаем открытой
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $start, $ms) || !preg_match('/^(\d{1,2}):(\d{2})$/', $end, $me)) return true;
    $startMin = (int)$ms[1] * 60 + (int)$ms[2];
    $endMin   = (int)$me[1] * 60 + (int)$me[2];
    if ($startMin === $endMin) return true; // круглосуточно
    $nowMin = (int)$now->format('G') * 60 + (int)$now->format('i');
    if ($startMin < $endMin) {
        return $nowMin >= $startMin && $nowMin < $endMin;
    }
    return $nowMin >= $startMin || $nowMin < $endMin; // через полночь
}

/**
 * Возвращает true, если есть хотя бы одна открытая точка самовывоза.
 */
function ygp_has_open_pickup_points(): bool {
    $pts = get_option('yandex_pickup_points', []);
    if (!is_array($pts)) return false;
    foreach ($pts as $p) {
        if (!is_array($p) || empty($p['coords'])) continue;
        if (ygp_is_pickup_point_open($p)) return true;
    }
    return false;
}

/**
 * Возвращает массив ID открытых точек (для JS).
 */
function ygp_get_open_pickup_ids(): array {
    $pts = get_option('yandex_pickup_points', []);
    if (!is_array($pts)) return [];
    $ids = [];
    foreach ($pts as $p) {
        if (!is_array($p) || empty($p['coords']) || empty($p['id'])) continue;
        if (ygp_is_pickup_point_open($p)) $ids[] = (string)$p['id'];
    }
    return $ids;
}

add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if (!is_a($order, 'WC_Order')) return;

    $delivery_type = ygp_is_pickup_selected() ? 'pickup' : 'delivery';
    $order->update_meta_data('_ygp_delivery_type', $delivery_type);

    // Save custom billing fields to order meta (so they don't get lost).
    $kv      = isset($_POST['billing_kv']) ? sanitize_text_field(wp_unslash($_POST['billing_kv'])) : '';
    $pod     = isset($_POST['billing_pod']) ? sanitize_text_field(wp_unslash($_POST['billing_pod'])) : '';
    $etaj    = isset($_POST['billing_etaj']) ? sanitize_text_field(wp_unslash($_POST['billing_etaj'])) : '';
    $domofon = isset($_POST['billing_domofon']) ? sanitize_text_field(wp_unslash($_POST['billing_domofon'])) : '';
    $order->update_meta_data('billing_address_flat', $kv);
    $order->update_meta_data('billing_address_entrance', $pod);
    $order->update_meta_data('billing_address_floor', $etaj);
    $order->update_meta_data('billing_address_intercom', $domofon);

    // Delivery: минималка, время, цена зоны (для отчётности)
    if ($delivery_type === 'delivery') {
        $min = ygp_get_delivery_min_order_for_display();
        $time = ygp_get_delivery_time_for_display();
        $zprice = ygp_get_delivery_zone_price_for_display();
        if ($min > 0) $order->update_meta_data('_ygp_min_order_amount', $min);
        if ($time) $order->update_meta_data('_ygp_delivery_time', $time);
        if ($zprice > 0) $order->update_meta_data('_ygp_zone_price', $zprice);
    }

    // Pickup info
    $pid = ygp_get_pickup_point_id();
    if ($pid) {
        $order->update_meta_data('_ygp_pickup_point_id', $pid);
        $point = ygp_find_pickup_point($pid);
        if ($point) {
            $order->update_meta_data('_ygp_pickup_point_name', sanitize_text_field($point['name'] ?? ''));
            $order->update_meta_data('_ygp_pickup_point_address', sanitize_text_field($point['address'] ?? ''));
        }
    }
}, 10, 2);

add_action('woocommerce_admin_order_data_after_shipping_address', function ($order) {
    if (!is_a($order, 'WC_Order')) return;
    $type = (string) $order->get_meta('_ygp_delivery_type');
    $pid  = (string) $order->get_meta('_ygp_pickup_point_id');
    $pname = (string) $order->get_meta('_ygp_pickup_point_name');
    $paddr = (string) $order->get_meta('_ygp_pickup_point_address');

    if (!$type && !$pid) return;

    echo '<div class="ygp-order-meta" style="margin-top:10px;">';
    echo '<p><strong>Способ получения:</strong> ' . esc_html($type === 'pickup' ? 'Самовывоз' : 'Доставка') . '</p>';
    if ($type === 'pickup' && $pid) {
        $label = trim($pname);
        if ($label) {
            echo '<p><strong>Пункт самовывоза:</strong> ' . esc_html($label) . '</p>';
            if ($paddr) {
                echo '<p><strong>Адрес ПВЗ:</strong> ' . esc_html($paddr) . '</p>';
            }
        } elseif ($paddr) {
            echo '<p><strong>Пункт самовывоза:</strong> ' . esc_html($paddr) . '</p>';
        }
    }
    // Адрес доставки с доп. полями в одну строку
    if ($type === 'delivery') {
        $addr = trim((string) $order->get_billing_address_1());
        $flat = trim((string) $order->get_meta('billing_address_flat'));
        $entrance = trim((string) $order->get_meta('billing_address_entrance'));
        $floor = trim((string) $order->get_meta('billing_address_floor'));
        $intercom = trim((string) $order->get_meta('billing_address_intercom'));
        $detailParts = [];
        if ($flat) $detailParts[] = 'Квартира: ' . esc_html($flat);
        if ($entrance) $detailParts[] = 'Подъезд: ' . esc_html($entrance);
        if ($floor) $detailParts[] = 'Этаж: ' . esc_html($floor);
        if ($intercom) $detailParts[] = 'Домофон: ' . esc_html($intercom);
        $details = !empty($detailParts) ? ' (' . implode(', ', $detailParts) . ')' : '';
        echo '<p style="margin:0;"><strong>Адрес доставки:</strong> ' . esc_html($addr ?: '—') . $details . '</p>';
    }
    echo '</div>';
});

// Адрес доставки с доп. полями в письмах (только при способе "Доставка")
add_action('woocommerce_email_customer_details', function ($order, $sent_to_admin, $plain_text, $email) {
    if (!is_a($order, 'WC_Order')) return;
    $type = (string) $order->get_meta('_ygp_delivery_type');
    if ($type !== 'delivery') return;

    $addr = trim((string) $order->get_billing_address_1());
    $flat = trim((string) $order->get_meta('billing_address_flat'));
    $entrance = trim((string) $order->get_meta('billing_address_entrance'));
    $floor = trim((string) $order->get_meta('billing_address_floor'));
    $intercom = trim((string) $order->get_meta('billing_address_intercom'));
    $parts = [];
    if ($flat) $parts[] = 'Квартира: ' . $flat;
    if ($entrance) $parts[] = 'Подъезд: ' . $entrance;
    if ($floor) $parts[] = 'Этаж: ' . $floor;
    if ($intercom) $parts[] = 'Домофон: ' . $intercom;
    $details = !empty($parts) ? ' (' . implode(', ', $parts) . ')' : '';
    $line = ($addr ?: '—') . $details;

    if ($plain_text) {
        echo "\nАдрес доставки: " . $line . "\n";
    } else {
        echo '<div style="margin:0;"><strong>Адрес доставки:</strong> ' . esc_html($line) . '</div>';
    }
}, 20, 4);

// JavaScript для сохранения минимальной суммы при выборе адреса
add_action('wp_footer', 'add_min_order_save_script');

function add_min_order_save_script() {
    if (!ygp_is_licensed() || is_admin()) return;
    $open_ids = ygp_get_open_pickup_ids();
        ?>
        <script>
        var YGP_OPEN_PICKUP_IDS = <?php echo json_encode($open_ids); ?>;

        // Защита от циклов update_checkout -> fragments -> update_checkout и лишних AJAX
        var ygpUpdateLock = false;
        var ygpUpdateUnlockTimer = null;
        var ygpLastCheckoutState = null;

        function ygpGetCheckoutState() {
            return {
                shippingMethod: getChosenShippingMethod(),
                deliveryAddress: readLs('ygp_delivery_address'),
                pickupPointId: readLs('ygp_pickup_point_id'),
                minOrder: readLs('ygp_delivery_min_order'),
                zonePrice: readLs('ygp_delivery_zone_price'),
            };
        }

        function ygpCheckoutStateChanged(prev, curr) {
            if (!prev) return true;
            return prev.shippingMethod !== curr.shippingMethod ||
                   prev.deliveryAddress !== curr.deliveryAddress ||
                   prev.pickupPointId !== curr.pickupPointId ||
                   prev.minOrder !== curr.minOrder ||
                   prev.zonePrice !== curr.zonePrice;
        }

        function ygpTriggerUpdateCheckout(force) {
            try {
                if (!window.jQuery || !document.body) return;
                if (!document.body.classList.contains('woocommerce-checkout')) return;
                
                // При force=true игнорируем лок (данные точно изменились)
                if (!force && ygpUpdateLock) return;

                ygpUpdateLock = true;
                if (ygpUpdateUnlockTimer) clearTimeout(ygpUpdateUnlockTimer);
                // Fallback unlock увеличен до 5 секунд
                ygpUpdateUnlockTimer = setTimeout(function() { ygpUpdateLock = false; }, 5000);
                window.jQuery(document.body).trigger('update_checkout');
            } catch (e) {}
        }

        // Отдельные функции saveMinOrderAmount, saveZonePrice, saveDeliveryTime УДАЛЕНЫ
        // Теперь используется ЕДИНЫЙ AJAX save_delivery_data_atomic
document.addEventListener('DOMContentLoaded', function() {
    // Проверяем, что мы на странице checkout
    const isCheckout = !!document.querySelector('form.checkout') || (document.body && document.body.classList.contains('woocommerce-checkout'));
    if (!isCheckout) return;
    
    // Функция для заполнения полей
function fillCheckoutFields() {
    const isPickup = isPickupChosen();
    let address = '';
    
    if (isPickup) {
        // Для самовывоза - берём адрес выбранной точки
        address = readLs('ygp_pickup_point_address') || '';
    } else {
        // Для доставки - адрес доставки
        address = readLs('ygp_delivery_address') || readLs('yandex_delivery_address_text') || readLs('yandex_address_text') || '';
    }
    
    const flat = readLs('ygp_delivery_flat') || readLs('yandex_address_flat');
    const intercom = readLs('ygp_delivery_intercom') || readLs('yandex_address_intercom');
    const entrance = readLs('ygp_delivery_entrance') || readLs('yandex_address_entrance');
    const floor = readLs('ygp_delivery_floor') || readLs('yandex_address_floor');

    const addressField = document.getElementById('billing_address_1');
    if (addressField) {
        addressField.value = address || '';
        addressField.dispatchEvent(new Event('change', { bubbles: true }));
    }

    const flatField = document.getElementById('billing_kv');
    if (flatField) {
        flatField.value = flat || '';
        flatField.dispatchEvent(new Event('change', { bubbles: true }));
    }

    const intercomField = document.getElementById('billing_domofon');
    if (intercomField) {
        intercomField.value = intercom || '';
        intercomField.dispatchEvent(new Event('change', { bubbles: true }));
    }

    const entranceField = document.getElementById('billing_pod');
    if (entranceField) {
        entranceField.value = entrance || '';
        entranceField.dispatchEvent(new Event('change', { bubbles: true }));
    }

    const floorField = document.getElementById('billing_etaj');
    if (floorField) {
        floorField.value = floor || '';
        floorField.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function getChosenShippingMethod() {
    const el = document.querySelector('input[name^="shipping_method"]:checked');
    return el ? String(el.value || '') : '';
}

function isPickupChosen() {
    const m = getChosenShippingMethod();
    return m && m.indexOf('ygp_pickup') === 0;
}

function toggleAddressFields() {
    const pickup = isPickupChosen();
    
    // Адрес ВСЕГДА виден (и для доставки, и для самовывоза)
    // Скрываем только доп. поля при самовывозе
    const extraFieldIds = ['billing_kv_field', 'billing_domofon_field', 'billing_pod_field', 'billing_etaj_field'];
    extraFieldIds.forEach((fid) => {
        const row = document.getElementById(fid);
        if (row) row.style.display = pickup ? 'none' : '';
    });

    // Синхронизация с попапом (чтобы он открывался в правильной вкладке)
    try {
        const m = getChosenShippingMethod();
        // Не перезатираем localStorage, если выбран не наш метод доставки.
        if (m && (m.indexOf('ygp_pickup') === 0 || m.indexOf('ygp_delivery') === 0)) {
            localStorage.setItem('yandex_delivery_type', pickup ? 'pickup' : 'delivery');
            localStorage.setItem('ygp_current_mode', pickup ? 'pickup' : 'delivery');
        }
    } catch (e) {}
}

function readLs(key) {
    try {
        const v = localStorage.getItem(key);
        return v == null ? '' : String(v);
    } catch (e) {
        return '';
    }
}

function postAjax(action, data) {
    const body = new URLSearchParams(Object.assign({ action }, data || {}));
    return fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
    }).then(r => r.json()).catch(() => null);
}

function pickShippingByPrefix(prefix) {
    const inputs = Array.from(document.querySelectorAll('input[name^="shipping_method"]'));
    if (!inputs.length) return false;
    const names = Array.from(new Set(inputs.map((i) => i.name).filter(Boolean)));
    let changed = false;
    names.forEach((name) => {
        const candidate = inputs.find((i) => i.name === name && String(i.value || '').indexOf(prefix) === 0);
        if (candidate && !candidate.checked) {
            candidate.checked = true;
            candidate.dispatchEvent(new Event('change', { bubbles: true }));
            changed = true;
        }
    });
    return changed;
}

async function bootstrapFromLocalStorage() {
    // 1) Читаем данные из YGPStorage (с fallback на legacy)
    let deliveryType = readLs('ygp_current_mode') || (readLs('yandex_delivery_type') === 'pickup' ? 'pickup' : 'delivery');
    let pickupPointId = readLs('ygp_pickup_point_id') || readLs('yandex_pickup_point_id');
    if (deliveryType === 'pickup' && pickupPointId && typeof YGP_OPEN_PICKUP_IDS !== 'undefined' && Array.isArray(YGP_OPEN_PICKUP_IDS) && YGP_OPEN_PICKUP_IDS.indexOf(pickupPointId) === -1) {
        pickupPointId = '';
        try { localStorage.setItem('ygp_pickup_point_id', ''); localStorage.setItem('yandex_pickup_point_id', ''); } catch(e) {}
    }
    if (deliveryType === 'pickup' && (!YGP_OPEN_PICKUP_IDS || !YGP_OPEN_PICKUP_IDS.length)) {
        deliveryType = 'delivery';
        pickupPointId = '';
        try { localStorage.setItem('ygp_current_mode', 'delivery'); localStorage.setItem('yandex_delivery_type', 'delivery'); } catch(e) {}
    }
    const minOrder = readLs('ygp_delivery_min_order') || readLs('yandex_delivery_min_order') || readLs('yandex_min_order') || '0';
    const zonePrice = readLs('ygp_delivery_zone_price') || readLs('yandex_delivery_zone_price') || readLs('yandex_zone_price') || '0';
    const deliveryTime = readLs('ygp_delivery_time') || readLs('yandex_delivery_time') || '';
    const address = readLs('ygp_delivery_address') || readLs('yandex_delivery_address_text') || readLs('yandex_address_text') || '';
    const flat = readLs('ygp_delivery_flat') || readLs('yandex_address_flat') || '';
    const intercom = readLs('ygp_delivery_intercom') || readLs('yandex_address_intercom') || '';
    const entrance = readLs('ygp_delivery_entrance') || readLs('yandex_address_entrance') || '';
    const floor = readLs('ygp_delivery_floor') || readLs('yandex_address_floor') || '';

    try {
        // ЕДИНЫЙ AJAX — атомарно сохраняем ВСЕ данные одним запросом
        // ЖДЁМ полного завершения перед update_checkout
        await postAjax('save_delivery_data_atomic', {
            delivery_type: deliveryType,
            min_order: minOrder,
            zone_price: zonePrice,
            delivery_time: deliveryTime,
            address: address,
            flat: flat,
            intercom: intercom,
            entrance: entrance,
            floor: floor,
            pickup_point_id: deliveryType === 'pickup' ? pickupPointId : ''
        });
        
        console.log('[YGP] Данные синхронизированы с сервером');
        
        // ТОЛЬКО ПОСЛЕ успешного сохранения — переключаем shipping method и обновляем checkout
        const prefix = deliveryType === 'pickup' ? 'ygp_pickup' : 'ygp_delivery';
        pickShippingByPrefix(prefix);
        toggleAddressFields();
        ygpTriggerUpdateCheckout(true);
    } catch (err) {
        console.error('[YGP] Ошибка синхронизации:', err);
    }
}
    
    // Заполняем поля сразу при загрузке
    fillCheckoutFields();
    toggleAddressFields();
    bootstrapFromLocalStorage(); // Один AJAX для синхронизации данных с сервером
    
    // При AJAX обновлениях checkout — обновляем поля без задержек
    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', function() {
            ygpUpdateLock = false;
            fillCheckoutFields();
            toggleAddressFields();
        });
    }

    document.body.addEventListener('change', function(e) {
        const t = e && e.target;
        if (t && t.name && String(t.name).indexOf('shipping_method') === 0) {
            fillCheckoutFields(); // Обновляем адрес при смене метода
            toggleAddressFields();
            ygpTriggerUpdateCheckout(true);
        }
    });

    // При клике на поле адреса и доп. полях — открываем попап (все изменения только в попапе)
    function setupAddressFieldClick() {
        const addressField = document.getElementById('billing_address_1');
        const addressWrapper = document.getElementById('billing_address_1_field');
        const extraFieldIds = ['billing_kv_field', 'billing_domofon_field', 'billing_pod_field', 'billing_etaj_field'];

        function openPopup() {
            if (typeof window.openYandexAddressPopup === 'function') {
                window.openYandexAddressPopup();
            }
        }

        if (addressField) {
            addressField.readOnly = true;
            addressField.addEventListener('click', function(e) { e.preventDefault(); openPopup(); });
            addressField.addEventListener('focus', function(e) { e.preventDefault(); addressField.blur(); openPopup(); });
        }
        if (addressWrapper) {
            addressWrapper.addEventListener('click', function(e) {
                if (e.target.tagName !== 'INPUT') { e.preventDefault(); openPopup(); }
            });
        }

        // Доп. поля (Квартира, Подъезд, Этаж, Домофон) — тоже открывают попап
        extraFieldIds.forEach(function(id) {
            const wrap = document.getElementById(id);
            if (!wrap) return;
            const input = wrap.querySelector('input');
            if (input) {
                input.readOnly = true;
                input.addEventListener('click', function(e) { e.preventDefault(); openPopup(); });
                input.addEventListener('focus', function(e) { e.preventDefault(); input.blur(); openPopup(); });
            }
            wrap.style.cursor = 'pointer';
            wrap.addEventListener('click', function(e) {
                e.preventDefault();
                openPopup();
            });
        });
    }
    
    // Инициализируем сразу и после updated_checkout (DOM может перерисоваться)
    setupAddressFieldClick();
    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', setupAddressFieldClick);
    }
});
        </script>
        <?php
}

// === Admin UI assets (бывший дизайн-плагин) ===
add_action('wp_enqueue_scripts', function() {
    if (!ygp_is_licensed()) return;
    wp_enqueue_style(
        'yandex-geocoder-style',
        plugin_dir_url(__FILE__) . 'assets/style.css',
        [],
        filemtime(plugin_dir_path(__FILE__) . 'assets/style.css')
    );
    $accent = sanitize_hex_color(get_option('ygp_accent_color', '#51267d')) ?: '#51267d';
    $accent_light = ygp_lighten_hex($accent, 90);
    wp_add_inline_style('yandex-geocoder-style', sprintf(
        '.modal-overlay { --primary: %s; --primary-light: %s; }',
        esc_attr($accent),
        esc_attr($accent_light)
    ));

    // Frontend logic (popup, delivery/pickup maps)
    $js = plugin_dir_path(__FILE__) . 'assets/frontend.js';
    wp_enqueue_script(
        'yandex-geocoder-frontend',
        plugin_dir_url(__FILE__) . 'assets/frontend.js',
        [],
        file_exists($js) ? filemtime($js) : '1.0.0',
        true
    );

    $default_city = (string) get_option('ygp_default_city', '');
    $default_coords = ygp_get_default_city_coords();
    $currency = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '₽';
    $accent_color = sanitize_hex_color(get_option('ygp_accent_color', '#51267d')) ?: '#51267d';
    wp_localize_script('yandex-geocoder-frontend', 'YGP_DATA', [
        'apiKey' => (string) get_option('yandex_api_key'),
        'zones' => get_option('yandex_delivery_zones', []),
        'pickupPoints' => get_option('yandex_pickup_points', []),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'defaultCity' => $default_city,
        'defaultCityCoords' => $default_coords,
        'currencySymbol' => $currency,
        'accentColor' => $accent_color,
    ]);
});

add_action('admin_enqueue_scripts', function($hook){
    if (strpos($hook, '_page_yandex_delivery') === false && $hook !== 'toplevel_page_yandex_delivery') {
        return;
    }
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
    $api_key = get_option('yandex_api_key', '');

    $css = plugin_dir_path(__FILE__) . 'assets/admin.css';
    $js  = plugin_dir_path(__FILE__) . 'assets/admin.js';

    wp_enqueue_style(
        'ygp-admin-ui',
        plugin_dir_url(__FILE__) . 'assets/admin.css',
        [],
        file_exists($css) ? filemtime($css) : '1.0.0'
    );
    $deps = ['jquery'];
    if ($api_key && ($tab === 'settings' || !$tab)) {
        wp_enqueue_script(
            'yandex-maps-api',
            'https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=' . esc_attr($api_key),
            [],
            null,
            false
        );
        $deps[] = 'yandex-maps-api';
    }

    wp_enqueue_script(
        'ygp-admin-ui',
        plugin_dir_url(__FILE__) . 'assets/admin.js',
        $deps,
        time(),
        true
    );
});
