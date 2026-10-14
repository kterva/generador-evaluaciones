<?php
/**
 * Funciones puras (sin dependencias de WordPress) para poder probarlas con tests/run.php.
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Identificador de documento en la caché: 32 caracteres hexadecimales (128 bits).
 */
function ge_is_valid_doc_hash($hash) {
    return is_string($hash) && preg_match('/^[a-f0-9]{32}$/', $hash) === 1;
}

function ge_make_doc_hash($file_path) {
    return substr(hash_file('sha256', $file_path), 0, 32);
}

/**
 * Valida que el contenido real del archivo coincida con su extensión (no solo el nombre).
 */
function ge_validate_upload_content($path, $ext) {
    if (!is_file($path)) return false;
    $head = (string) file_get_contents($path, false, null, 0, 8);
    if ($ext === 'pdf') {
        return strncmp($head, '%PDF-', 5) === 0;
    }
    if (!in_array($ext, array('odt', 'docx'), true)) return false;
    if (strncmp($head, "PK\x03\x04", 4) !== 0) return false;
    $zip = new ZipArchive();
    $expected = ($ext === 'docx') ? 'word/document.xml' : 'content.xml';
    $ok = $zip->open($path) === true && $zip->locateName($expected) !== false;
    if ($ok && $zip->numFiles > 2000) $ok = false;
    $zip->close();
    return $ok;
}

/**
 * Busca un programa en las rutas habituales sin ejecutar nada. Devuelve la ruta o ''.
 */
function ge_find_binary($name) {
    if (!preg_match('/^[a-z0-9._-]+$/i', $name)) return '';
    foreach (array('/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin') as $dir) {
        $p = $dir . '/' . $name;
        if (is_file($p) && is_executable($p)) return $p;
    }
    return '';
}

/**
 * ¿El correo coincide con la lista (una entrada por correo o por dominio "@institucion.edu.uy")?
 */
function ge_email_matches_list($email, $list) {
    $email = strtolower(trim((string) $email));
    if ($email === '' || strpos($email, '@') === false) return false;
    $domain = '@' . substr(strrchr($email, '@'), 1);
    $items = preg_split('/[\r\n,;\s]+/', strtolower((string) $list), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($items as $l) {
        if ($l === $email || $l === $domain) return true;
    }
    return false;
}
