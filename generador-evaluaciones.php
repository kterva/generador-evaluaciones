<?php
/**
 * Plugin Name: Generador de Repartidos y Evaluaciones
 * Plugin URI: https://culturalibre.edu.uy
 * Description: Armá repartidos y evaluaciones a partir de tus propios documentos: extrae los ejercicios y las figuras y genera un archivo LibreOffice (.odt) listo para imprimir o editar. Incluye página de ayuda.
 * Version: 1.0.5
 * Author: Leonardo - Cultura Libre
 * Author URI: https://culturalibre.edu.uy
 * License: GPL-2.0+
 * Text Domain: generador-evaluaciones
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GE_VERSION', '1.0.5');
define('GE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GE_MAX_UPLOAD_BYTES', 15 * 1024 * 1024);
// Nada persiste: el archivo subido se borra al extraer; figuras y .odt viven como mucho GE_TTL_SECONDS.
define('GE_TTL_SECONDS', 2 * HOUR_IN_SECONDS);

/**
 * Permiso requerido para usar el generador (filtrable con 'ge_required_capability').
 */
function ge_can_use() {
    return current_user_can(apply_filters('ge_required_capability', 'edit_posts'));
}

function ge_help_url() {
    return apply_filters('ge_help_url', home_url('/generador-evaluaciones/ayuda/'));
}

function ge_rest_permission() {
    if (!ge_can_use()) {
        return new WP_Error('rest_forbidden', 'Necesitás iniciar sesión con un usuario docente para usar el generador.', array('status' => rest_authorization_required_code()));
    }
    return true;
}

require_once GE_PLUGIN_DIR . 'includes/class-extractor.php';
require_once GE_PLUGIN_DIR . 'includes/class-generator.php';

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
    wp_enqueue_style(
        'ge-google-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        array(),
        null
    );
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
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{12}$/', $hash)) return;
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
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{12}$/', $hash)) return;
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
    $head = (string) file_get_contents($uploaded_file['tmp_name'], false, null, 0, 8);
    if ($ext === 'pdf') {
        $ok = strncmp($head, '%PDF-', 5) === 0;
    } else {
        $ok = strncmp($head, "PK\x03\x04", 4) === 0;
        if ($ok) {
            $zip = new ZipArchive();
            $expected = ($ext === 'docx') ? 'word/document.xml' : 'content.xml';
            $ok = $zip->open($uploaded_file['tmp_name']) === true && $zip->locateName($expected) !== false;
            if ($ok && $zip->numFiles > 2000) $ok = false;
            $zip->close();
        }
    }
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
    $pandoc_path = @exec('which pandoc');
    if (empty($pandoc_path) && file_exists('/usr/bin/pandoc')) {
        $pandoc_path = '/usr/bin/pandoc';
    }

    $pdfimages_path = @exec('which pdfimages');
    $pdftotext_path = @exec('which pdftotext');

    return rest_ensure_response(array(
        'pandoc_available' => !empty($pandoc_path),
        'poppler_available' => !empty($pdfimages_path) && !empty($pdftotext_path),
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
        return '<div class="ge-app-container"><p class="ge-notice">Para usar el generador de repartidos y evaluaciones tenés que <a href="' . esc_url(wp_login_url(get_permalink())) . '">iniciar sesión</a> con un usuario docente. ¿Querés saber cómo funciona? Mirá la <a href="' . esc_url(ge_help_url()) . '">ayuda</a>.</p>' . ge_render_footer() . '</div>';
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
