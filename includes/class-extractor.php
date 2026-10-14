<?php
if (!defined('ABSPATH')) {
    exit;
}

class Generador_Evaluaciones_Extractor {

    /** Nombre original del archivo subido (en disco se guarda con nombre aleatorio). */
    private static $source_name = '';

    public static function extract_exercises_from_file($file_path, $cache_dir, $cache_url, $source_name = '') {
        self::$source_name = $source_name !== '' ? $source_name : basename($file_path);
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($ext === 'odt') {
            return self::extract_from_odt($file_path, $cache_dir, $cache_url);
        } elseif ($ext === 'docx') {
            return self::extract_from_docx($file_path, $cache_dir, $cache_url);
        } elseif ($ext === 'pdf') {
            return self::extract_from_pdf($file_path, $cache_dir, $cache_url);
        }
        return array('exercises' => array(), 'images' => array());
    }

    /** Solo imágenes que el navegador y pandoc pueden usar (se descartan EMF, WMF, etc.). */
    private static function is_usable_image($name) {
        return (bool) preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|gif)$/i', $name);
    }

    private static function is_exercise_start($line, $allow_bare_number = true) {
        $l = trim($line);
        if (empty($l)) return false;
        // Ejercicio 1, Problema 2, Actividad 3, Pregunta 4
        if (preg_match('/^(?:ejercicio|problema|actividad|pregunta)\s*\d+[:\.\-]?/i', $l)) {
            return true;
        }
        // 1.- Texto, 2. Texto, 3) Texto
        if (preg_match('/^\d+\s*(?:\.-|[\.\-\)])\s+[A-ZÁÉÍÓÚa-záéíóú¿¡]/u', $l)) {
            return true;
        }
        // Solo número aislado
        if ($allow_bare_number && preg_match('/^\d{1,2}$/', $l)) {
            return true;
        }
        return false;
    }

    private static function extract_from_odt($file_path, $cache_dir, $cache_url) {
        $doc_hash = ge_make_doc_hash($file_path);
        $doc_cache_dir = $cache_dir . '/' . $doc_hash;
        $doc_cache_url = $cache_url . '/' . $doc_hash;
        if (!is_dir($doc_cache_dir)) {
            wp_mkdir_p($doc_cache_dir);
        }

        $all_images = array();
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== TRUE) {
            return array('exercises' => array(), 'images' => array());
        }

        // 1. Extraer imágenes en Pictures/
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'Pictures/') === 0) {
                $img_name = basename($name);
                if (!empty($img_name) && self::is_usable_image($img_name)) {
                    $dest = $doc_cache_dir . '/' . $img_name;
                    if (!file_exists($dest)) {
                        $contents = $zip->getFromIndex($i);
                        file_put_contents($dest, $contents);
                    }
                    if (!in_array($img_name, $all_images)) {
                        $all_images[] = $img_name;
                    }
                }
            }
        }

        // 2. Extraer contenido de content.xml
        $content_xml = $zip->getFromName('content.xml');
        $zip->close();

        if (empty($content_xml)) {
            return array('exercises' => array(), 'images' => $all_images);
        }

        $exercises = array();
        $curr_ex = null;

        // Cargar XML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if ($dom->loadXML($content_xml)) {
            $paragraphs = $dom->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'p');
            if ($paragraphs->length === 0) {
                $paragraphs = $dom->getElementsByTagName('p');
            }

            foreach ($paragraphs as $p) {
                $text = trim($p->textContent);

                // Buscar imágenes dentro del párrafo
                $p_imgs = array();
                $frames = $p->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:drawing:1.0', 'frame');
                foreach ($frames as $frame) {
                    $imgs = $frame->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:drawing:1.0', 'image');
                    foreach ($imgs as $img) {
                        $href = $img->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
                        if (!empty($href)) {
                            $im_fn = basename($href);
                            if (in_array($im_fn, $all_images)) {
                                $p_imgs[] = $im_fn;
                            }
                        }
                    }
                }

                if (empty($text) && empty($p_imgs)) {
                    continue;
                }

                if (self::is_exercise_start($text)) {
                    if ($curr_ex && !empty(trim($curr_ex['text']))) {
                        $exercises[] = $curr_ex;
                    }
                    $curr_ex = array(
                        'id' => $doc_hash . '_' . (count($exercises) + 1),
                        'title' => mb_strlen($text) < 30 ? $text : mb_substr($text, 0, 30) . '...',
                        'text' => $text,
                        'image' => !empty($p_imgs) ? $p_imgs[0] : null,
                        'images_detected' => $p_imgs,
                        'doc_hash' => $doc_hash,
                        'source_file' => self::$source_name,
                        'all_images' => $all_images,
                        'tipo' => 'subido',
                        'image_url' => !empty($p_imgs) ? $doc_cache_url . '/' . $p_imgs[0] : null
                    );
                } else {
                    if ($curr_ex) {
                        if (!empty($text)) {
                            $curr_ex['text'] .= "\n" . $text;
                        }
                        foreach ($p_imgs as $im) {
                            if (!in_array($im, $curr_ex['images_detected'])) {
                                $curr_ex['images_detected'][] = $im;
                            }
                            if (empty($curr_ex['image'])) {
                                $curr_ex['image'] = $im;
                                $curr_ex['image_url'] = $doc_cache_url . '/' . $im;
                            }
                        }
                    }
                }
            }

            if ($curr_ex && !empty(trim($curr_ex['text']))) {
                $exercises[] = $curr_ex;
            }
        }

        // Si no se detectó ningún ejercicio por encabezados, guardar como un bloque único
        if (empty($exercises) && !empty($all_images)) {
            $exercises[] = array(
                'id' => $doc_hash . '_1',
                'title' => self::$source_name,
                'text' => strip_tags($content_xml),
                'image' => $all_images[0],
                'images_detected' => $all_images,
                'doc_hash' => $doc_hash,
                'source_file' => self::$source_name,
                'all_images' => $all_images,
                'tipo' => 'subido',
                'image_url' => $doc_cache_url . '/' . $all_images[0]
            );
        }

        return array('exercises' => $exercises, 'images' => $all_images);
    }

    private static function new_exercise($doc_hash, $doc_cache_url, $file_path, $n, $text, $all_images, $imgs) {
        $first = !empty($imgs) ? $imgs[0] : null;
        return array(
            'id' => $doc_hash . '_' . $n,
            'title' => mb_strlen($text) < 30 ? $text : mb_substr($text, 0, 30) . '...',
            'text' => $text,
            'image' => $first,
            'images_detected' => array_values($imgs),
            'doc_hash' => $doc_hash,
            'source_file' => self::$source_name,
            'all_images' => $all_images,
            'tipo' => 'subido',
            'image_url' => $first ? $doc_cache_url . '/' . $first : null
        );
    }

    /**
     * DOCX: las figuras se asignan por su posición real en el documento. Cada párrafo declara sus
     * imágenes con un identificador de relación (r:embed) que se resuelve con word/_rels/document.xml.rels.
     */
    private static function extract_from_docx($file_path, $cache_dir, $cache_url) {
        $doc_hash = ge_make_doc_hash($file_path);
        $doc_cache_dir = $cache_dir . '/' . $doc_hash;
        $doc_cache_url = $cache_url . '/' . $doc_hash;
        if (!is_dir($doc_cache_dir)) {
            wp_mkdir_p($doc_cache_dir);
        }

        $all_images = array();
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== TRUE) {
            return array('exercises' => array(), 'images' => array());
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'word/media/') === 0) {
                $img_name = basename($name);
                if (!empty($img_name) && self::is_usable_image($img_name)) {
                    $dest = $doc_cache_dir . '/' . $img_name;
                    if (!file_exists($dest)) {
                        file_put_contents($dest, $zip->getFromIndex($i));
                    }
                    if (!in_array($img_name, $all_images, true)) {
                        $all_images[] = $img_name;
                    }
                }
            }
        }

        $xml_content = $zip->getFromName('word/document.xml');
        $rels_xml = $zip->getFromName('word/_rels/document.xml.rels');
        $zip->close();

        if (empty($xml_content)) {
            return array('exercises' => array(), 'images' => $all_images);
        }

        // rId -> nombre de archivo de imagen
        $rel_map = array();
        if (!empty($rels_xml)) {
            $rd = new DOMDocument();
            libxml_use_internal_errors(true);
            if ($rd->loadXML($rels_xml)) {
                foreach ($rd->getElementsByTagName('Relationship') as $rel) {
                    $target = $rel->getAttribute('Target');
                    if (strpos($target, 'media/') !== false) {
                        $rel_map[$rel->getAttribute('Id')] = basename($target);
                    }
                }
            }
        }

        $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $V = 'urn:schemas-microsoft-com:vml';

        $exercises = array();
        $curr_ex = null;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if ($dom->loadXML($xml_content)) {
            foreach ($dom->getElementsByTagNameNS($W, 'p') as $p) {
                $text = trim($p->textContent);

                $p_imgs = array();
                foreach ($p->getElementsByTagNameNS($A, 'blip') as $blip) {
                    $rid = $blip->getAttributeNS($R, 'embed');
                    if ($rid !== '' && isset($rel_map[$rid]) && in_array($rel_map[$rid], $all_images, true)) {
                        $p_imgs[] = $rel_map[$rid];
                    }
                }
                foreach ($p->getElementsByTagNameNS($V, 'imagedata') as $vi) {
                    $rid = $vi->getAttributeNS($R, 'id');
                    if ($rid !== '' && isset($rel_map[$rid]) && in_array($rel_map[$rid], $all_images, true)) {
                        $p_imgs[] = $rel_map[$rid];
                    }
                }
                $p_imgs = array_values(array_unique($p_imgs));

                if ($text === '' && empty($p_imgs)) continue;

                if ($text !== '' && self::is_exercise_start($text)) {
                    if ($curr_ex && !empty(trim($curr_ex['text']))) {
                        $exercises[] = $curr_ex;
                    }
                    $curr_ex = self::new_exercise($doc_hash, $doc_cache_url, $file_path, count($exercises) + 1, $text, $all_images, $p_imgs);
                } elseif ($curr_ex) {
                    if ($text !== '') {
                        $curr_ex['text'] .= "\n" . $text;
                    }
                    foreach ($p_imgs as $im) {
                        if (!in_array($im, $curr_ex['images_detected'], true)) {
                            $curr_ex['images_detected'][] = $im;
                        }
                        if (empty($curr_ex['image'])) {
                            $curr_ex['image'] = $im;
                            $curr_ex['image_url'] = $doc_cache_url . '/' . $im;
                        }
                    }
                }
            }
            if ($curr_ex && !empty(trim($curr_ex['text']))) {
                $exercises[] = $curr_ex;
            }
        }

        return array('exercises' => $exercises, 'images' => $all_images);
    }

    /**
     * Lista de imágenes del PDF con su página (pdfimages -list). Devuelve filas
     * array('num' => n, 'page' => p, 'w' => ancho, 'h' => alto) solo para imágenes reales (no máscaras).
     */
    public static function parse_pdfimages_list($listing) {
        $rows = array();
        foreach (preg_split('/\R/', (string) $listing) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(image|smask|mask|stencil)\s+(\d+)\s+(\d+)\s/', $line, $m)) {
                if ($m[3] === 'image') {
                    $rows[] = array('page' => (int) $m[1], 'num' => (int) $m[2], 'w' => (int) $m[4], 'h' => (int) $m[5]);
                }
            }
        }
        return $rows;
    }

    /**
     * Reparte las figuras entre ejercicios según la página: en cada página, la n-ésima figura va al
     * n-ésimo ejercicio presente en esa página que todavía no tenga figura (las sobrantes se suman a
     * "images_detected" del último). $page_exercises: página => lista de índices de ejercicio;
     * $page_images: página => lista de nombres de archivo.
     */
    public static function assign_images_by_page(array &$exercises, array $page_exercises, array $page_images, $doc_cache_url) {
        foreach ($page_images as $page => $imgs) {
            $cands = isset($page_exercises[$page]) ? array_values(array_unique($page_exercises[$page])) : array();
            if (empty($cands)) continue;
            foreach ($imgs as $img) {
                $target = null;
                foreach ($cands as $ci) {
                    if (empty($exercises[$ci]['image'])) { $target = $ci; break; }
                }
                if ($target === null) $target = end($cands);
                if (empty($exercises[$target]['image'])) {
                    $exercises[$target]['image'] = $img;
                    $exercises[$target]['image_url'] = $doc_cache_url . '/' . $img;
                }
                if (!in_array($img, $exercises[$target]['images_detected'], true)) {
                    $exercises[$target]['images_detected'][] = $img;
                }
            }
        }
    }

    private static function extract_from_pdf($file_path, $cache_dir, $cache_url) {
        $doc_hash = ge_make_doc_hash($file_path);
        $doc_cache_dir = $cache_dir . '/' . $doc_hash;
        $doc_cache_url = $cache_url . '/' . $doc_hash;
        if (!is_dir($doc_cache_dir)) {
            wp_mkdir_p($doc_cache_dir);
        }

        $pdfimages = ge_find_binary('pdfimages');
        $pdftotext = ge_find_binary('pdftotext');
        if ($pdfimages === '') $pdfimages = 'pdfimages';
        if ($pdftotext === '') $pdftotext = 'pdftotext';

        // 1. Figuras: tabla de imágenes por página y extracción a PNG (se omite si hay demasiadas).
        $listing = (string) @shell_exec(escapeshellarg($pdfimages) . ' -list ' . escapeshellarg($file_path) . ' 2>/dev/null');
        $rows = self::parse_pdfimages_list($listing);
        $rows = array_values(array_filter($rows, function ($r) { return $r['w'] >= 40 && $r['h'] >= 40; }));
        $all_images = array();
        $page_images = array();
        if (!empty($rows) && count($rows) <= 400) {
            $prefix = $doc_cache_dir . '/img';
            @shell_exec(escapeshellarg($pdfimages) . ' -png ' . escapeshellarg($file_path) . ' ' . escapeshellarg($prefix) . ' 2>&1');
            foreach ($rows as $r) {
                $fname = sprintf('img-%03d.png', $r['num']);
                if (is_file($doc_cache_dir . '/' . $fname)) {
                    $all_images[] = $fname;
                    $page_images[$r['page']][] = $fname;
                }
            }
        }

        // 2. Texto por página (pdftotext separa las páginas con un salto de página).
        $output_text = @shell_exec(escapeshellarg($pdftotext) . ' -layout ' . escapeshellarg($file_path) . ' - 2>/dev/null');
        $pages = !empty($output_text) ? explode("\f", $output_text) : array();

        $exercises = array();
        $curr_ex = null;
        $page_exercises = array();

        foreach ($pages as $pi => $page_text) {
            $page_no = $pi + 1;
            $seen_line = false;
            foreach (explode("\n", $page_text) as $line) {
                $s = trim($line);
                if ($s === '') continue;
                $seen_line = true;

                if (self::is_exercise_start($s, false)) {
                    if ($curr_ex && !empty(trim($curr_ex['text']))) {
                        $exercises[] = $curr_ex;
                    }
                    $curr_ex = self::new_exercise($doc_hash, $doc_cache_url, $file_path, count($exercises) + 1, $s, $all_images, array());
                    $page_exercises[$page_no][] = count($exercises);
                } elseif ($curr_ex) {
                    $curr_ex['text'] .= "\n" . $s;
                    // El ejercicio que viene de la página anterior está presente solo si su texto sigue acá.
                    $page_exercises[$page_no][] = count($exercises);
                }
            }
            // Página sin texto (por ejemplo, solo una figura): pertenece al ejercicio en curso.
            if (!$seen_line && $curr_ex !== null) {
                $page_exercises[$page_no][] = count($exercises);
            }
        }
        if ($curr_ex && !empty(trim($curr_ex['text']))) {
            $exercises[] = $curr_ex;
        }

        self::assign_images_by_page($exercises, $page_exercises, $page_images, $doc_cache_url);

        return array('exercises' => $exercises, 'images' => $all_images);
    }
}
