<?php
/**
 * Plugin Name: Generador de Repartidos y Evaluaciones
 * Plugin URI: https://culturalibre.edu.uy
 * Description: Armá repartidos y evaluaciones a partir de tus propios documentos: extrae los ejercicios y las figuras y genera un archivo LibreOffice (.odt) listo para imprimir o editar. Incluye página de ayuda.
 * Version: 1.0.13
 * Author: Leonardo - Cultura Libre
 * Author URI: https://culturalibre.edu.uy
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: generador-evaluaciones
 */

/*
 * Generador de Repartidos y Evaluaciones
 * Copyright (C) 2026 Leonardo Trujillo
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GE_VERSION', '1.0.13');
define('GE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GE_MAX_UPLOAD_BYTES', 15 * 1024 * 1024);
// Nada persiste: el archivo subido se borra al extraer; figuras y .odt viven como mucho GE_TTL_SECONDS.
define('GE_TTL_SECONDS', 2 * HOUR_IN_SECONDS);

/**
 * Acceso (Ajustes → Generador): por defecto puede usarlo cualquier usuario con sesión iniciada (p. ej. con Google).
 * En modo «docente» solo los usuarios con el permiso `use_generador_evaluaciones` (rol "Docente (generador)")
 * y, por compatibilidad, quienes pueden editar entradas; ahí un Suscriptor queda sin acceso hasta que se lo
 * habilite (a mano o por la lista de correos).
 */
function ge_access_mode() {
    $m = get_option('ge_access_mode', 'logged_in');
    return $m === 'docente' ? 'docente' : 'logged_in';
}

function ge_can_use() {
    // Modo por defecto: cualquier usuario con sesión iniciada (los límites de uso por hora acotan el abuso).
    if (ge_access_mode() === 'logged_in') {
        return is_user_logged_in();
    }
    $caps = apply_filters('ge_allowed_capabilities', array('use_generador_evaluaciones', 'edit_posts'));
    foreach ($caps as $cap) {
        if (current_user_can($cap)) return true;
    }
    return false;
}

function ge_contact_email() {
    $e = get_option('ge_contact_email', 'trujillo.leonardo@gmail.com');
    return is_email($e) ? $e : 'trujillo.leonardo@gmail.com';
}

/**
 * Rol "Docente (generador)": solo lectura + permiso del generador.
 */
function ge_ensure_role() {
    if (!get_role('ge_docente')) {
        add_role('ge_docente', 'Docente (generador)', array('read' => true, 'use_generador_evaluaciones' => true));
    }
}
add_action('init', 'ge_ensure_role');

/**
 * Habilitación automática por lista (Ajustes → Generador): una línea por correo o por dominio (@institucion.edu.uy).
 * Solo se aplica a usuarios que son únicamente Suscriptores, y conviene usarla con ingreso por Google
 * (que entrega el correo ya verificado).
 */
function ge_email_in_allowlist($email) {
    return is_email($email) && ge_email_matches_list($email, get_option('ge_auto_approve', ''));
}

function ge_maybe_auto_approve($user) {
    if (!($user instanceof WP_User) || !$user->exists()) return;
    if ($user->roles !== array('subscriber') && $user->roles !== array()) return;
    if (ge_email_in_allowlist($user->user_email)) {
        $user->set_role('ge_docente');
    }
}
add_action('user_register', function ($user_id) { ge_maybe_auto_approve(get_user_by('id', $user_id)); });
add_action('wp_login', function ($login, $user) { ge_maybe_auto_approve($user); }, 10, 2);

/**
 * Página de ajustes: Ajustes → Generador.
 */
add_action('admin_menu', function () {
    add_options_page('Generador de Repartidos y Evaluaciones', 'Generador', 'manage_options', 'ge-ajustes', 'ge_render_settings_page');
});
add_action('admin_init', function () {
    register_setting('ge_ajustes', 'ge_auto_approve', array('type' => 'string', 'sanitize_callback' => function ($v) {
        $lines = preg_split('/[\r\n,;\s]+/', strtolower((string) $v), -1, PREG_SPLIT_NO_EMPTY);
        $ok = array();
        foreach ($lines as $l) {
            if (is_email($l) || preg_match('/^@[a-z0-9.-]+\.[a-z]{2,}$/', $l)) $ok[] = $l;
        }
        return implode("\n", array_unique($ok));
    }));
    register_setting('ge_ajustes', 'ge_access_mode', array('type' => 'string', 'sanitize_callback' => function ($v) {
        return $v === 'docente' ? 'docente' : 'logged_in';
    }));
    register_setting('ge_ajustes', 'ge_contact_email', array('type' => 'string', 'sanitize_callback' => 'sanitize_email'));
});
function ge_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>Generador de Repartidos y Evaluaciones</h1>
      <form method="post" action="options.php">
        <?php settings_fields('ge_ajustes'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Quién puede usar el generador</th>
            <td>
              <label><input type="radio" name="ge_access_mode" value="logged_in" <?php checked(ge_access_mode(), 'logged_in'); ?>>
                <strong>Cualquier persona con sesión iniciada</strong> (por ejemplo con Google). Los límites por hora (60 subidas y 30 generaciones por usuario) acotan el abuso.</label><br>
              <label><input type="radio" name="ge_access_mode" value="docente" <?php checked(ge_access_mode(), 'docente'); ?>>
                <strong>Solo docentes habilitados</strong> (rol «Docente (generador)»), a mano o por la lista de abajo.</label>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ge_auto_approve">Habilitar automáticamente como docentes</label></th>
            <td>
              <textarea id="ge_auto_approve" name="ge_auto_approve" rows="6" cols="50" class="large-text code"><?php echo esc_textarea(get_option('ge_auto_approve', '')); ?></textarea>
              <p class="description">Una línea por correo (<code>docente@gmail.com</code>) o por dominio (<code>@liceo.edu.uy</code>).
              Quien ingrese con una cuenta de esa lista recibe el rol «Docente (generador)». Solo importa en el modo
              «Solo docentes habilitados»: el resto queda como Suscriptor y se habilita a mano desde Usuarios. Usalo con ingreso por Google (correo verificado).</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ge_contact_email">Correo de contacto</label></th>
            <td>
              <input type="email" id="ge_contact_email" name="ge_contact_email" class="regular-text" value="<?php echo esc_attr(ge_contact_email()); ?>">
              <p class="description">Se muestra en la ayuda, en el pie y en el aviso a quienes todavía no están habilitados.</p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
}

/**
 * Límite de uso por usuario y por hora (filtrable con 'ge_rate_limits'). Los administradores quedan exentos.
 */
function ge_rate_limit($action) {
    if (current_user_can('manage_options')) return true;
    $limits = apply_filters('ge_rate_limits', array('upload' => 60, 'generate' => 30));
    $max = isset($limits[$action]) ? (int) $limits[$action] : 0;
    if ($max <= 0) return true;
    $key = 'ge_rl_' . $action . '_' . get_current_user_id();
    $n = (int) get_transient($key);
    if ($n >= $max) {
        return new WP_Error('rate_limited', 'Alcanzaste el límite de uso por hora. Probá de nuevo en un rato.', array('status' => 429));
    }
    set_transient($key, $n + 1, HOUR_IN_SECONDS);
    return true;
}

function ge_license_url() {
    return apply_filters('ge_license_url', home_url('/licencia/'));
}

function ge_privacy_url() {
    return apply_filters('ge_privacy_url', home_url('/politica-de-privacidad/'));
}

function ge_help_url() {
    return apply_filters('ge_help_url', home_url('/generador-evaluaciones/ayuda/'));
}

function ge_rest_permission() {
    if (ge_can_use()) return true;
    if (is_user_logged_in()) {
        return new WP_Error('rest_forbidden', 'Tu cuenta todavía no está habilitada como docente.', array('status' => 403));
    }
    return new WP_Error('rest_forbidden', 'Necesitás iniciar sesión con un usuario docente para usar el generador.', array('status' => rest_authorization_required_code()));
}

require_once GE_PLUGIN_DIR . 'includes/functions.php';
require_once GE_PLUGIN_DIR . 'includes/class-extractor.php';
require_once GE_PLUGIN_DIR . 'includes/class-generator.php';

/**
 * Aviso destacado: los archivos no se guardan en el servidor.
 */
function ge_render_privacy_banner() {
    ob_start();
    include GE_PLUGIN_DIR . 'templates/privacy-banner.php';
    return ob_get_clean();
}

/**
 * Pie de página común (mismo estilo que graficador.culturalibre.edu.uy).
 */
function ge_render_footer() {
    ob_start();
    include GE_PLUGIN_DIR . 'templates/footer.php';
    return ob_get_clean();
}

/**
 * Encola los estilos del plugin (versionados por fecha de modificación del archivo).
 */
function ge_enqueue_styles() {
    wp_enqueue_style(
        'ge-styles',
        GE_PLUGIN_URL . 'assets/css/app.css',
        array(),
        GE_VERSION . '.' . filemtime(GE_PLUGIN_DIR . 'assets/css/app.css')
    );
    // La tipografía Inter se sirve desde el propio plugin (assets/fonts): no se contacta a Google.
}

/**
 * Shortcode: [generador_evaluaciones_ayuda] — guía de uso (pública, no requiere sesión).
 */
add_shortcode('generador_evaluaciones_ayuda', 'ge_render_help_shortcode');
function ge_render_help_shortcode($atts) {
    ge_enqueue_styles();
    ob_start();
    include GE_PLUGIN_DIR . 'templates/ayuda-view.php';
    return ob_get_clean();
}

/**
 * Activación del plugin: crear carpetas de trabajo en wp-content/uploads/
 */
register_activation_hook(__FILE__, 'ge_plugin_activate');
function ge_plugin_activate() {
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'] . '/generador_evaluaciones';
    wp_mkdir_p($base_dir . '/cache');
    wp_mkdir_p($base_dir . '/output');

    // Archivo index.php de protección
    $index_file = '<?php // Silence is golden';
    @file_put_contents($base_dir . '/index.php', $index_file);
    @file_put_contents($base_dir . '/cache/index.php', $index_file);
    @file_put_contents($base_dir . '/output/index.php', $index_file);
    // Apache: sin listado ni ejecución. En nginx ver nginx-ejemplo.conf.
    @file_put_contents($base_dir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n");

    ge_ensure_cleanup_schedule();
}

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('ge_cleanup_event');
});

// Limpieza cada hora. Se reprograma también al actualizar copiando archivos (no corre el hook de activación)
// y migra el evento diario de la versión anterior.
function ge_ensure_cleanup_schedule() {
    $ev = wp_get_scheduled_event('ge_cleanup_event');
    if ($ev && $ev->schedule !== 'hourly') {
        wp_clear_scheduled_hook('ge_cleanup_event');
        $ev = false;
    }
    if (!$ev) {
        wp_schedule_event(time() + 300, 'hourly', 'ge_cleanup_event');
    }
}
add_action('init', 'ge_ensure_cleanup_schedule');

/**
 * Limpieza: figuras extraídas y .odt generados no duran más de GE_TTL_SECONDS.
 * La carpeta uploads/ (versiones anteriores guardaban ahí los archivos subidos) se vacía casi de inmediato.
 */
add_action('ge_cleanup_event', 'ge_cleanup_storage');
function ge_cleanup_storage() {
    $storage = ge_get_storage();
    $base = dirname($storage['cache_dir']);
    $now = time();
    $targets = array(
        $storage['cache_dir']  => GE_TTL_SECONDS,
        $storage['output_dir'] => GE_TTL_SECONDS,
        $base . '/uploads'     => 15 * MINUTE_IN_SECONDS,
    );
    foreach ($targets as $dir => $ttl) {
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $name = $f->getFilename();
            if ($name === 'index.php' || $name === '.htaccess') continue;
            if ($f->isDir()) {
                // Al vaciarse, la carpeta renueva su fecha: se borra si quedó vacía y no es recién creada.
                if ($f->getMTime() < $now - 5 * MINUTE_IN_SECONDS) @rmdir($f->getPathname());
                continue;
            }
            if ($f->getMTime() < $now - $ttl) @unlink($f->getPathname());
        }
    }
}

/**
 * Renueva la fecha de las figuras de un documento para que la limpieza no las borre
 * mientras el docente sigue armando evaluaciones con esos archivos.
 */
function ge_touch_cache_dir($hash, $cache_dir) {
    if (!ge_is_valid_doc_hash($hash)) return;
    $dir = $cache_dir . '/' . $hash;
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..') @touch($dir . '/' . basename($f));
    }
    @touch($dir);
}

/**
 * Borra una carpeta de figuras (cache/<hash>) o un .odt generado, validando el nombre.
 */
function ge_delete_cache_dir($hash, $cache_dir) {
    if (!ge_is_valid_doc_hash($hash)) return;
    $dir = $cache_dir . '/' . $hash;
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..') @unlink($dir . '/' . basename($f));
    }
    @rmdir($dir);
}

/**
 * Helper para obtener las rutas y URLs de almacenamiento
 */
function ge_get_storage() {
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'] . '/generador_evaluaciones';
    $base_url = $upload_dir['baseurl'] . '/generador_evaluaciones';

    return array(
        'upload_dir' => $base_dir . '/uploads',
        'cache_dir'  => $base_dir . '/cache',
        'output_dir' => $base_dir . '/output',
        'cache_url'  => $base_url . '/cache',
        'output_url' => $base_url . '/output'
    );
}

/**
 * Registro de rutas en la REST API de WordPress
 */
add_action('rest_api_init', 'ge_register_rest_routes');
function ge_register_rest_routes() {
    register_rest_route('generador-evaluaciones/v1', '/upload', array(
        'methods' => 'POST',
        'callback' => 'ge_rest_upload_handler',
        'permission_callback' => 'ge_rest_permission'
    ));

    register_rest_route('generador-evaluaciones/v1', '/generate', array(
        'methods' => 'POST',
        'callback' => 'ge_rest_generate_handler',
        'permission_callback' => 'ge_rest_permission'
    ));

    register_rest_route('generador-evaluaciones/v1', '/cleanup', array(
        'methods' => 'POST',
        'callback' => 'ge_rest_cleanup_handler',
        'permission_callback' => 'ge_rest_permission'
    ));

    register_rest_route('generador-evaluaciones/v1', '/keepalive', array(
        'methods' => 'POST',
        'callback' => 'ge_rest_keepalive_handler',
        'permission_callback' => 'ge_rest_permission'
    ));

    register_rest_route('generador-evaluaciones/v1', '/status', array(
        'methods' => 'GET',
        'callback' => 'ge_rest_status_handler',
        'permission_callback' => 'ge_rest_permission'
    ));
}

/**
 * Endpoint REST: /upload
 */
function ge_rest_upload_handler($request) {
    $rl = ge_rate_limit('upload');
    if (is_wp_error($rl)) return $rl;
    $files = $request->get_file_params();
    if (empty($files) || !isset($files['file'])) {
        return new WP_Error('no_file', 'No se recibió ningún archivo.', array('status' => 400));
    }

    $uploaded_file = $files['file'];
    if (!empty($uploaded_file['error']) || !is_uploaded_file($uploaded_file['tmp_name'])) {
        return new WP_Error('upload_error', 'Error al recibir el archivo.', array('status' => 400));
    }
    if ($uploaded_file['size'] > GE_MAX_UPLOAD_BYTES) {
        return new WP_Error('too_big', 'El archivo supera el máximo de ' . (GE_MAX_UPLOAD_BYTES / 1048576) . ' MB.', array('status' => 413));
    }

    $orig_name = sanitize_file_name($uploaded_file['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, array('odt', 'docx', 'pdf'), true)) {
        return new WP_Error('invalid_extension', 'Formato no soportado. Debe ser .odt, .docx o .pdf', array('status' => 400));
    }

    // Validar el contenido real, no solo la extensión.
    $ok = ge_validate_upload_content($uploaded_file['tmp_name'], $ext);
    if (!$ok) {
        return new WP_Error('invalid_content', 'El contenido del archivo no coincide con su extensión.', array('status' => 400));
    }

    $storage = ge_get_storage();
    wp_mkdir_p($storage['cache_dir']);

    // Archivo temporal fuera de uploads/ y de la web, con nombre aleatorio; se borra siempre al terminar.
    $tmp_path = get_temp_dir() . 'ge_' . wp_generate_password(16, false, false) . '.' . $ext;
    $filename = $orig_name;
    if (!move_uploaded_file($uploaded_file['tmp_name'], $tmp_path)) {
        return new WP_Error('upload_error', 'No se pudo procesar el archivo en el servidor.', array('status' => 500));
    }

    try {
        $result = Generador_Evaluaciones_Extractor::extract_exercises_from_file(
            $tmp_path,
            $storage['cache_dir'],
            $storage['cache_url'],
            $filename
        );

        return rest_ensure_response(array(
            'status' => 'ok',
            'filename' => $filename,
            'exercises' => $result['exercises'],
            'count' => count($result['exercises']),
            'total_images' => count($result['images'])
        ));
    } catch (Exception $e) {
        return new WP_Error('extractor_error', $e->getMessage(), array('status' => 500));
    } finally {
        @unlink($tmp_path);
    }
}

/**
 * Endpoint REST: /cleanup — el navegador pide borrar lo que ya no necesita
 * (figuras de los documentos subidos y el .odt ya descargado).
 */
function ge_rest_cleanup_handler($request) {
    $params = $request->get_json_params();
    if (!is_array($params)) $params = array();
    $storage = ge_get_storage();

    $hashes = isset($params['hashes']) && is_array($params['hashes']) ? array_slice($params['hashes'], 0, 50) : array();
    foreach ($hashes as $h) {
        ge_delete_cache_dir($h, $storage['cache_dir']);
    }

    $files = isset($params['files']) && is_array($params['files']) ? array_slice($params['files'], 0, 20) : array();
    foreach ($files as $f) {
        if (is_string($f) && preg_match('/^(Evaluacion|Repartido)_[A-Za-z0-9_-]+\.odt$/', $f)) {
            @unlink($storage['output_dir'] . '/' . $f);
        }
    }

    return rest_ensure_response(array('status' => 'ok'));
}

/**
 * Endpoint REST: /keepalive — latido de la página abierta: mantiene las figuras cargadas.
 */
function ge_rest_keepalive_handler($request) {
    $params = $request->get_json_params();
    $storage = ge_get_storage();
    $hashes = is_array($params) && isset($params['hashes']) && is_array($params['hashes']) ? array_slice($params['hashes'], 0, 50) : array();
    foreach ($hashes as $h) {
        ge_touch_cache_dir($h, $storage['cache_dir']);
    }
    return rest_ensure_response(array('status' => 'ok'));
}

/**
 * Endpoint REST: /generate
 */
function ge_rest_generate_handler($request) {
    $rl = ge_rate_limit('generate');
    if (is_wp_error($rl)) return $rl;
    $params = $request->get_json_params();
    if (empty($params) || empty($params['ejercicios'])) {
        return new WP_Error('no_data', 'No se recibieron ejercicios para armar la prueba.', array('status' => 400));
    }

    $storage = ge_get_storage();
    wp_mkdir_p($storage['output_dir']);

    // Se pueden armar varias evaluaciones con los mismos archivos: renovar las figuras en uso.
    if (is_array($params['ejercicios'])) {
        foreach ($params['ejercicios'] as $ex) {
            if (is_array($ex) && isset($ex['doc_hash'])) ge_touch_cache_dir($ex['doc_hash'], $storage['cache_dir']);
        }
    }

    try {
        $res = Generador_Evaluaciones_Generator::generate_odt(
            $params,
            $storage['cache_dir'],
            $storage['output_dir'],
            $storage['output_url']
        );

        return rest_ensure_response($res);
    } catch (Exception $e) {
        return new WP_Error('generator_error', $e->getMessage(), array('status' => 500));
    }
}

/**
 * Endpoint REST: /status (verificar dependencias)
 */
function ge_rest_status_handler() {
    return rest_ensure_response(array(
        'pandoc_available' => ge_find_binary('pandoc') !== '',
        'poppler_available' => ge_find_binary('pdfimages') !== '' && ge_find_binary('pdftotext') !== '',
        'gd_available' => function_exists('imagecreatefrompng'),
        'zip_available' => class_exists('ZipArchive')
    ));
}

/**
 * Shortcode: [generador_evaluaciones]
 */
add_shortcode('generador_evaluaciones', 'ge_render_shortcode');
function ge_render_shortcode($atts) {
    if (!ge_can_use()) {
        ge_enqueue_styles();
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            $mail = ge_contact_email();
            $subject = rawurlencode('Habilitación como docente');
            $body = rawurlencode('Hola, quiero usar el Generador de Repartidos y Evaluaciones. Mi cuenta es ' . $u->user_email . '.');
            $msg = '<p class="ge-notice"><strong>Hola, ' . esc_html($u->display_name) . '.</strong> Tu cuenta (' . esc_html($u->user_email) . ') todavía no está habilitada como docente. '
                 . 'Pedí la habilitación escribiendo a <a href="mailto:' . esc_attr($mail) . '?subject=' . $subject . '&body=' . $body . '">' . esc_html($mail) . '</a> '
                 . 'y te avisamos cuando esté lista. Mientras tanto, mirá la <a href="' . esc_url(ge_help_url()) . '">ayuda</a>.</p>';
        } else {
            $login = esc_url(wp_login_url(get_permalink()));
            if (ge_access_mode() === 'logged_in') {
                $msg = '<p class="ge-notice">Para usar el generador de repartidos y evaluaciones, <a href="' . $login . '">iniciá sesión</a> con tu cuenta de Google o con tu usuario. Es gratis. ¿Querés saber cómo funciona? Mirá la <a href="' . esc_url(ge_help_url()) . '">ayuda</a>.</p>';
            } else {
                $msg = '<p class="ge-notice">Para usar el generador de repartidos y evaluaciones tenés que <a href="' . $login . '">iniciar sesión</a> con un usuario docente. ¿Querés saber cómo funciona? Mirá la <a href="' . esc_url(ge_help_url()) . '">ayuda</a>.</p>';
            }
        }
        return '<div class="ge-app-container">' . $msg . ge_render_footer() . '</div>';
    }
    $storage = ge_get_storage();

    // La página lleva un nonce por usuario: que no la cachee WP Super Cache y similares.
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!headers_sent()) {
        nocache_headers();
    }

    ge_enqueue_styles();

    // Encolar JavaScript
    wp_enqueue_script(
        'ge-app',
        GE_PLUGIN_URL . 'assets/js/app.js',
        array(),
        GE_VERSION . '.' . filemtime(GE_PLUGIN_DIR . 'assets/js/app.js'),
        true
    );

    // Pasar variables a JavaScript
    wp_localize_script('ge-app', 'geConfig', array(
        'rest_url' => esc_url_raw(rest_url('generador-evaluaciones/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'ajax_url' => admin_url('admin-ajax.php'),
        'cache_url' => $storage['cache_url']
    ));

    // Renderizar plantilla
    ob_start();
    include GE_PLUGIN_DIR . 'templates/frontend-view.php';
    return ob_get_clean();
}
