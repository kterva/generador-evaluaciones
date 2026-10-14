<?php
/**
 * Tests del plugin, sin WordPress ni PHPUnit: `php tests/run.php`
 * Requiere PHP 8+ con zip, dom y mbstring. Las pruebas con PDF necesitan poppler-utils y las de
 * generación del .odt necesitan pandoc; si faltan se omiten (SKIP).
 */
error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__);

// --- Dobles mínimos de las funciones de WordPress que usan las clases ---
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_strip_all_tags($s, $remove_breaks = false) { return trim(strip_tags((string) $s)); }
function wp_generate_password($len = 12) { return substr(bin2hex(random_bytes($len)), 0, $len); }
function get_temp_dir() { return rtrim(sys_get_temp_dir(), '/') . '/'; }
function remove_accents($s) { return iconv('UTF-8', 'ASCII//TRANSLIT', $s); }

require $root . '/includes/functions.php';
require $root . '/includes/class-extractor.php';
require $root . '/includes/class-generator.php';

// --- Mini framework ---
$GLOBALS['ok'] = 0; $GLOBALS['fail'] = 0; $GLOBALS['skip'] = 0;
function t($name, $fn) {
    try {
        $r = $fn();
        if ($r === 'SKIP') { $GLOBALS['skip']++; echo "  SKIP  $name\n"; return; }
        $GLOBALS['ok']++; echo "  ok    $name\n";
    } catch (Throwable $e) {
        $GLOBALS['fail']++; echo "  FALLA $name\n        " . $e->getMessage() . "\n";
    }
}
function eq($a, $b, $msg = '') { if ($a !== $b) throw new Exception(($msg ? "$msg: " : '') . 'esperaba ' . var_export($b, true) . ' y obtuve ' . var_export($a, true)); }
function ok($c, $msg = 'condición falsa') { if (!$c) throw new Exception($msg); }
function has($hay, $needle, $msg = '') { if (strpos((string) $hay, $needle) === false) throw new Exception(($msg ?: 'falta') . ': ' . var_export($needle, true) . ' en ' . var_export(mb_substr((string) $hay, 0, 200), true)); }
function hasnt($hay, $needle, $msg = '') { if (strpos((string) $hay, $needle) !== false) throw new Exception(($msg ?: 'sobra') . ': ' . var_export($needle, true)); }
function priv($class, $method, ...$args) { $m = new ReflectionMethod($class, $method); $m->setAccessible(true); return $m->invoke(null, ...$args); }
function tmpdir() { $d = sys_get_temp_dir() . '/ge_test_' . bin2hex(random_bytes(6)); mkdir($d, 0777, true); return $d; }
function rrmdir($d) { if (!is_dir($d)) return; foreach (scandir($d) as $f) { if ($f === '.' || $f === '..') continue; $p = "$d/$f"; is_dir($p) ? rrmdir($p) : unlink($p); } rmdir($d); }
$fx = __DIR__ . '/fixtures';
$has_poppler = ge_find_binary('pdftotext') !== '' && ge_find_binary('pdfimages') !== '';
$has_pandoc = ge_find_binary('pandoc') !== '';

echo "== Identificador de documento\n";
t('acepta 32 caracteres hexadecimales', fn() => eq(ge_is_valid_doc_hash(str_repeat('a1', 16)), true));
t('rechaza el formato viejo de 12', fn() => eq(ge_is_valid_doc_hash('a1b2c3d4e5f6'), false));
t('rechaza mayúsculas, rutas y vacíos', function () {
    foreach (array(strtoupper(str_repeat('a1', 16)), '../' . str_repeat('a', 29), str_repeat('a1', 16) . '/', '', null, 123, array()) as $bad) eq(ge_is_valid_doc_hash($bad), false);
});
t('el hash de un archivo es de 32 caracteres y estable', function () use ($fx) {
    $h = ge_make_doc_hash("$fx/dos_figuras.docx"); eq(strlen($h), 32); eq(ge_is_valid_doc_hash($h), true); eq(ge_make_doc_hash("$fx/dos_figuras.docx"), $h);
});

echo "== Validación del contenido subido\n";
t('PDF real válido', fn() => eq(ge_validate_upload_content("$fx/dos_figuras.pdf", 'pdf'), true));
t('DOCX real válido', fn() => eq(ge_validate_upload_content("$fx/dos_figuras.docx", 'docx'), true));
t('texto renombrado a .pdf se rechaza', function () { $f = tempnam(sys_get_temp_dir(), 'ge'); file_put_contents($f, 'no soy un pdf'); eq(ge_validate_upload_content($f, 'pdf'), false); unlink($f); });
t('un DOCX renombrado a .odt se rechaza (falta content.xml)', function () use ($fx) { eq(ge_validate_upload_content("$fx/dos_figuras.docx", 'odt'), false); });
t('un zip cualquiera como .docx se rechaza', function () { $f = tempnam(sys_get_temp_dir(), 'ge'); $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE); $z->addFromString('x.txt', 'hola'); $z->close(); eq(ge_validate_upload_content($f, 'docx'), false); unlink($f); });
t('extensión no permitida y archivo inexistente', function () use ($fx) { eq(ge_validate_upload_content("$fx/dos_figuras.pdf", 'exe'), false); eq(ge_validate_upload_content('/no/existe.pdf', 'pdf'), false); });

echo "== Programas externos\n";
t('ge_find_binary rechaza nombres con ruta o caracteres raros', function () { eq(ge_find_binary('../bin/sh'), ''); eq(ge_find_binary('sh; rm'), ''); eq(ge_find_binary(''), ''); });
t('ge_find_binary encuentra sh', fn() => ok(ge_find_binary('sh') !== '', 'no encontró sh'));

echo "== Lista de correos habilitados\n";
t('correo exacto, sin distinguir mayúsculas', function () { eq(ge_email_matches_list('Profe@Gmail.com', "profe@gmail.com\n"), true); });
t('dominio completo', function () { eq(ge_email_matches_list('ana@liceo.edu.uy', '@liceo.edu.uy'), true); });
t('no confunde subdominios ni dominios parecidos', function () { foreach (array('x@sub.liceo.edu.uy', 'x@evil-liceo.edu.uy', 'x@liceo.edu.uy.evil.com') as $e) eq(ge_email_matches_list($e, '@liceo.edu.uy'), false, $e); });
t('lista vacía y correos inválidos no habilitan a nadie', function () { eq(ge_email_matches_list('a@b.com', ''), false); eq(ge_email_matches_list('', '@b.com'), false); eq(ge_email_matches_list('sin-arroba', 'sin-arroba'), false); });
t('separadores: coma, punto y coma, espacios y saltos', function () { eq(ge_email_matches_list('b@x.org', "a@x.org, b@x.org;c@x.org"), true); });

echo "== Detección de ejercicios\n";
$starts = array('Ejercicio 1', 'ejercicio 2:', 'Problema 3.', 'Actividad 4 -', 'Pregunta 5', '1.- Un cuerpo', '2. Calcule', '3) ¿Cuál es', '¿A qué se debe?');
t('reconoce el inicio de un ejercicio', function () use ($starts) { foreach (array_slice($starts, 0, 8) as $l) eq(priv('Generador_Evaluaciones_Extractor', 'is_exercise_start', $l), true, $l); });
t('un texto común no es un ejercicio', function () { foreach (array('La fuerza es una magnitud vectorial.', '', '   ', 'a) Calcule la masa') as $l) eq(priv('Generador_Evaluaciones_Extractor', 'is_exercise_start', $l), false, $l); });
t('un número suelto cuenta solo si se permite (no en PDF)', function () { eq(priv('Generador_Evaluaciones_Extractor', 'is_exercise_start', '12'), true); eq(priv('Generador_Evaluaciones_Extractor', 'is_exercise_start', '12', false), false); });

echo "== Formato del enunciado\n";
t('quita el encabezado "Ejercicio N:"', fn() => eq(Generador_Evaluaciones_Generator::format_exercise_body('Ejercicio 3: Calcule la masa.'), 'Calcule la masa.'));
t('formatea subpreguntas a) b) c)', function () { $r = Generador_Evaluaciones_Generator::format_exercise_body('Un bloque. a) Calcule la fuerza. b) Dibuje el DCL. c) Halle la masa.'); has($r, '**a)** Calcule'); has($r, '**b)** Dibuje'); has($r, '**c)** Halle'); });
t('no toca "(a)", "2a)" ni palabras terminadas en letra y paréntesis', function () { $r = Generador_Evaluaciones_Generator::format_exercise_body('Vea la fórmula (a) y la figura 2a) o el item ba) del apunte; ver x) también.'); hasnt($r, '**'); });
t('subpregunta pegada al texto "a)Calcule"', fn() => has(Generador_Evaluaciones_Generator::format_exercise_body('Datos: a)Calcule la fuerza'), '**a)** Calcule'));
t('formatea afirmaciones', fn() => has(Generador_Evaluaciones_Generator::format_exercise_body('Afirmación 1: la fuerza es nula.'), '**Afirmación 1:**'));

echo "== Escapado hacia pandoc\n";
t('escapa los caracteres especiales de markdown', function () { $r = priv('Generador_Evaluaciones_Generator', 'escape_md', '**negrita** _cursiva_ `codigo` $x$ [a] a|b'); hasnt($r, '**negrita**'); has($r, '\\*\\*negrita\\*\\*'); has($r, '\\|'); has($r, '\\$x\\$'); });
t('elimina imágenes con ruta provista por el cliente', function () { $r = priv('Generador_Evaluaciones_Generator', 'escape_md', 'hola ![](/etc/passwd) fin'); hasnt($r, 'passwd'); hasnt($r, '!['); });
t('quita etiquetas HTML', function () { $r = priv('Generador_Evaluaciones_Generator', 'escape_md', '<script>alert(1)</script>texto'); hasnt($r, '<'); });
t('una línea: sin saltos de línea', fn() => hasnt(priv('Generador_Evaluaciones_Generator', 'one_line', "uno\n\ndos | tres"), "\n"));

echo "== Figuras por posición: DOCX\n";
t('cada figura queda en su ejercicio (el ejercicio 2 no tiene)', function () use ($fx) {
    $c = tmpdir(); $r = Generador_Evaluaciones_Extractor::extract_exercises_from_file("$fx/dos_figuras.docx", $c, 'http://x/c', 'mi.docx');
    eq(count($r['exercises']), 3, 'cantidad de ejercicios'); eq($r['exercises'][0]['image'], 'image1.png'); eq($r['exercises'][1]['image'], null, 'ejercicio 2'); eq($r['exercises'][2]['image'], 'image2.png');
    eq($r['exercises'][0]['source_file'], 'mi.docx'); ok(ge_is_valid_doc_hash($r['exercises'][0]['doc_hash'])); ok(is_file("$c/" . $r['exercises'][0]['doc_hash'] . '/image1.png'));
    rrmdir($c);
});

echo "== Figuras por posición: ODT\n";
t('ODT: la figura va al ejercicio donde está', function () {
    $f = tempnam(sys_get_temp_dir(), 'ge') . '.odt'; $z = new ZipArchive(); $z->open($f, ZipArchive::CREATE);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $z->addFromString('Pictures/a.png', $png);
    $ns = 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"';
    $z->addFromString('content.xml', "<?xml version=\"1.0\"?><office:document-content $ns><office:body><office:text><text:p>Ejercicio 1: sin figura.</text:p><text:p>Ejercicio 2: con figura.</text:p><text:p><draw:frame><draw:image xlink:href=\"Pictures/a.png\"/></draw:frame></text:p></office:text></office:body></office:document-content>");
    $z->close(); $c = tmpdir();
    $r = Generador_Evaluaciones_Extractor::extract_exercises_from_file($f, $c, 'http://x/c', 'p.odt');
    eq(count($r['exercises']), 2); eq($r['exercises'][0]['image'], null); eq($r['exercises'][1]['image'], 'a.png'); unlink($f); rrmdir($c);
});

echo "== Figuras por página: PDF\n";
$listing = "page   num  type   width height color comp bpc  enc interp  object ID x-ppi y-ppi size ratio\n----------------------------------------------\n   1     0 image     240   160  rgb     3   8  image  no         8  0   102   102 1015B 0.9%\n   1     1 smask     240   160  gray    1   8  image  no         8  0   102   102  100B 0.2%\n   3     2 image     500   300  rgb     3   8  image  no        20  0   102   102 1104B 1.0%\n";
t('interpreta la tabla de pdfimages y descarta máscaras', function () use ($listing) { $r = Generador_Evaluaciones_Extractor::parse_pdfimages_list($listing); eq(count($r), 2); eq($r[0]['page'], 1); eq($r[1]['num'], 2); eq($r[1]['page'], 3); });
t('reparto: una figura por ejercicio presente en la página', function () {
    $ex = array(array('image' => null, 'images_detected' => array()), array('image' => null, 'images_detected' => array()), array('image' => null, 'images_detected' => array()));
    Generador_Evaluaciones_Extractor::assign_images_by_page($ex, array(1 => array(0), 2 => array(0, 1), 3 => array(2)), array(1 => array('f1.png'), 3 => array('f3.png')), 'http://x');
    eq($ex[0]['image'], 'f1.png'); eq($ex[1]['image'], null, 'sin figura'); eq($ex[2]['image'], 'f3.png'); eq($ex[2]['image_url'], 'http://x/f3.png');
});
t('reparto: sobrantes se suman al último y páginas sin ejercicio se ignoran', function () {
    $ex = array(array('image' => null, 'images_detected' => array()));
    Generador_Evaluaciones_Extractor::assign_images_by_page($ex, array(1 => array(0)), array(1 => array('a.png', 'b.png'), 5 => array('x.png')), 'u');
    eq($ex[0]['image'], 'a.png'); eq($ex[0]['images_detected'], array('a.png', 'b.png'));
});
t('"1.- Texto" se reconoce como ejercicio', function () { foreach (array('1.- Un cuerpo', '2.- Calcule') as $l) eq(priv('Generador_Evaluaciones_Extractor', 'is_exercise_start', $l), true, $l); });
t('una línea que continúa un renglón ("10). Calcula:") no es un ejercicio nuevo', function () { foreach (array('10). Calcula: a) la aceleración', '10), con q=2 nC') as $l) eq(priv('Generador_Evaluaciones_Extractor', 'is_exercise_start', $l), false, $l); });
t('PDF real: la figura 1 va al ejercicio 1, el 2 queda sin figura, la 2 va al ejercicio 3', function () use ($fx, $has_poppler) {
    if (!$has_poppler) return 'SKIP';
    $c = tmpdir(); $r = Generador_Evaluaciones_Extractor::extract_exercises_from_file("$fx/dos_figuras.pdf", $c, 'http://x/c', 'f.pdf');
    eq(count($r['exercises']), 3, 'ejercicios'); eq($r['exercises'][0]['image'], 'img-000.png'); eq($r['exercises'][1]['image'], null, 'ejercicio 2'); eq($r['exercises'][2]['image'], 'img-001.png');
    has($r['exercises'][1]['text'], 'no tiene figura'); rrmdir($c);
});

echo "== Generación del .odt (requiere pandoc)\n";
t('genera un .odt sin título duplicado, sin imágenes ajenas y con subpreguntas', function () use ($has_pandoc, $fx) {
    if (!$has_pandoc) return 'SKIP';
    $cache = tmpdir(); $out = tmpdir();
    $ext = Generador_Evaluaciones_Extractor::extract_exercises_from_file("$fx/dos_figuras.docx", $cache, 'http://x/c', 'f.docx');
    $exs = $ext['exercises'];
    $exs[1]['text'] = 'Ejercicio 2: Texto con a) primera y b) segunda.';
    $evil = $exs[0]; $evil['doc_hash'] = '../../../../../etc'; $evil['image'] = 'passwd'; $exs[] = $evil;
    $res = Generador_Evaluaciones_Generator::generate_odt(array('tipo' => 'evaluacion', 'titulo' => 'Evaluación *Final* <b>x</b>', 'tema' => 'Dinámica', 'ejercicios' => $exs), $cache, $out, 'http://x/o');
    ok(is_file("$out/" . $res['filename']), 'no se creó el archivo'); has($res['filename'], 'Evaluacion_Dinamica_', 'nombre sin acentos');
    $z = new ZipArchive(); $z->open("$out/" . $res['filename']); $x = $z->getFromName('content.xml');
    $txt = preg_replace('/\s+/', ' ', strip_tags($x));
    eq(substr_count($txt, 'Final'), 1, 'el título debe aparecer una sola vez'); has($txt, 'Estudiante', 'fila de estudiante en la evaluación');
    has($txt, 'a) primera'); $imgs = array(); for ($i = 0; $i < $z->numFiles; $i++) if (strpos($z->getNameIndex($i), 'Pictures/') === 0) $imgs[] = $z->getNameIndex($i);
    eq(count($imgs), 2, 'solo las 2 figuras legítimas, ninguna ruta externa'); $z->close();
    $r2 = Generador_Evaluaciones_Generator::generate_odt(array('tipo' => 'repartido', 'titulo' => 'R', 'ejercicios' => array($exs[0])), $cache, $out, 'http://x/o');
    // La hoja debe ser A4 (21 x 29,7 cm) y no carta.
    $z2 = new ZipArchive(); $z2->open("$out/" . $res['filename']); $st = $z2->getFromName('styles.xml'); $z2->close();
    has($st, 'fo:page-width="21cm"', 'ancho A4'); has($st, 'fo:page-height="29.7cm"', 'alto A4'); hasnt($st, '8.5in', 'no debe quedar tamaño carta');
    has($r2['filename'], 'Repartido_'); $z = new ZipArchive(); $z->open("$out/" . $r2['filename']); hasnt(strip_tags($z->getFromName('content.xml')), 'Calificación', 'el repartido no lleva calificación'); $z->close();
    rrmdir($cache); rrmdir($out);
});

echo "\nResultado: {$GLOBALS['ok']} ok, {$GLOBALS['fail']} fallas, {$GLOBALS['skip']} omitidos\n";
exit($GLOBALS['fail'] ? 1 : 0);
