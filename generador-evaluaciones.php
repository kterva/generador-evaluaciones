<?php
/**
 * Plugin Name: Generador de Evaluaciones de Física
 * Plugin URI: https://culturalibre.edu.uy
 * Description: Banco interactivo de ejercicios y generador de pruebas en formato LibreOffice (.odt) para docentes de Física.
 * Version: 1.0.0
 * Author: Leonardo - Cultura Libre
 * Author URI: https://culturalibre.edu.uy
 * License: GPL-2.0+
 * Text Domain: generador-evaluaciones
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GE_VERSION', '1.0.0');
define('GE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GE_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GE_PLUGIN_DIR . 'includes/class-extractor.php';
require_once GE_PLUGIN_DIR . 'includes/class-generator.php';

/**
 * Activación del plugin: crear carpetas de trabajo en wp-content/uploads/
 */
register_activation_hook(__FILE__, 'ge_plugin_activate');
function ge_plugin_activate() {
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'] . '/generador_evaluaciones';
    wp_mkdir_p($base_dir . '/uploads');
    wp_mkdir_p($base_dir . '/cache');
    wp_mkdir_p($base_dir . '/output');

    // Archivo index.php de protección
    $index_file = '<?php // Silence is golden';
    @file_put_contents($base_dir . '/index.php', $index_file);
    @file_put_contents($base_dir . '/uploads/index.php', $index_file);
    @file_put_contents($base_dir . '/cache/index.php', $index_file);
    @file_put_contents($base_dir . '/output/index.php', $index_file);
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
        'permission_callback' => '__return_true'
    ));

    register_rest_route('generador-evaluaciones/v1', '/generate', array(
        'methods' => 'POST',
        'callback' => 'ge_rest_generate_handler',
        'permission_callback' => '__return_true'
    ));

    register_rest_route('generador-evaluaciones/v1', '/status', array(
        'methods' => 'GET',
        'callback' => 'ge_rest_status_handler',
        'permission_callback' => '__return_true'
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
    $filename = sanitize_file_name($uploaded_file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($ext, array('odt', 'docx', 'pdf'))) {
        return new WP_Error('invalid_extension', 'Formato no soportado. Debe ser .odt, .docx o .pdf', array('status' => 400));
    }

    $storage = ge_get_storage();
    wp_mkdir_p($storage['upload_dir']);
    wp_mkdir_p($storage['cache_dir']);

    $dest_path = $storage['upload_dir'] . '/' . $filename;
    if (!move_uploaded_file($uploaded_file['tmp_name'], $dest_path)) {
        return new WP_Error('upload_error', 'No se pudo guardar el archivo en el servidor.', array('status' => 500));
    }

    try {
        $result = Generador_Evaluaciones_Extractor::extract_exercises_from_file(
            $dest_path,
            $storage['cache_dir'],
            $storage['cache_url']
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
    }
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
        'pandoc_path' => $pandoc_path,
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
    $storage = ge_get_storage();

    // Encolar estilos
    wp_enqueue_style(
        'ge-styles',
        GE_PLUGIN_URL . 'assets/css/app.css',
        array(),
        GE_VERSION
    );

    // Encolar fuente Inter
    wp_enqueue_style(
        'ge-google-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        array(),
        null
    );

    // Encolar JavaScript
    wp_enqueue_script(
        'ge-app',
        GE_PLUGIN_URL . 'assets/js/app.js',
        array(),
        GE_VERSION,
        true
    );

    // Pasar variables a JavaScript
    wp_localize_script('ge-app', 'geConfig', array(
        'rest_url' => esc_url_raw(rest_url('generador-evaluaciones/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'cache_url' => $storage['cache_url']
    ));

    // Renderizar plantilla
    ob_start();
    include GE_PLUGIN_DIR . 'templates/frontend-view.php';
    return ob_get_clean();
}
