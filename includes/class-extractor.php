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

    private static function is_exercise_start($line, $allow_bare_number = true) {
        $l = trim($line);
        if (empty($l)) return false;
        // Ejercicio 1, Problema 2, Actividad 3, Pregunta 4
        if (preg_match('/^(?:ejercicio|problema|actividad|pregunta)\s*\d+[:\.\-]?/i', $l)) {
            return true;
        }
        // 1.- Texto, 2. Texto, 3) Texto
        if (preg_match('/^\d+\s*[\.\-\)]\s+[A-ZÁÉÍÓÚa-záéíóú¿¡]/u', $l)) {
            return true;
        }
        // Solo número aislado
        if ($allow_bare_number && preg_match('/^\d{1,2}$/', $l)) {
            return true;
        }
        return false;
    }

    private static function extract_from_odt($file_path, $cache_dir, $cache_url) {
        $doc_hash = substr(md5_file($file_path), 0, 12);
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
                if (!empty($img_name)) {
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

    private static function extract_from_docx($file_path, $cache_dir, $cache_url) {
        $doc_hash = substr(md5_file($file_path), 0, 12);
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

        // 1. Extraer imágenes en word/media/
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'word/media/') === 0) {
                $img_name = basename($name);
                if (!empty($img_name)) {
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

        $xml_content = $zip->getFromName('word/document.xml');
        $zip->close();

        if (empty($xml_content)) {
            return array('exercises' => array(), 'images' => $all_images);
        }

        $exercises = array();
        $curr_ex = null;
        $img_idx = 0;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if ($dom->loadXML($xml_content)) {
            $paragraphs = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p');
            foreach ($paragraphs as $p) {
                $text = trim($p->textContent);
                if (empty($text)) continue;

                if (self::is_exercise_start($text)) {
                    if ($curr_ex && !empty(trim($curr_ex['text']))) {
                        $exercises[] = $curr_ex;
                    }
                    $suggested_img = ($img_idx < count($all_images)) ? $all_images[$img_idx] : null;
                    if ($suggested_img) $img_idx++;

                    $curr_ex = array(
                        'id' => $doc_hash . '_' . (count($exercises) + 1),
                        'title' => mb_strlen($text) < 30 ? $text : mb_substr($text, 0, 30) . '...',
                        'text' => $text,
                        'image' => $suggested_img,
                        'images_detected' => $suggested_img ? array($suggested_img) : array(),
                        'doc_hash' => $doc_hash,
                        'source_file' => self::$source_name,
                        'all_images' => $all_images,
                        'tipo' => 'subido',
                        'image_url' => $suggested_img ? $doc_cache_url . '/' . $suggested_img : null
                    );
                } else {
                    if ($curr_ex) {
                        $curr_ex['text'] .= "\n" . $text;
                    }
                }
            }
            if ($curr_ex && !empty(trim($curr_ex['text']))) {
                $exercises[] = $curr_ex;
            }
        }

        return array('exercises' => $exercises, 'images' => $all_images);
    }

    private static function extract_from_pdf($file_path, $cache_dir, $cache_url) {
        $doc_hash = substr(md5_file($file_path), 0, 12);
        $doc_cache_dir = $cache_dir . '/' . $doc_hash;
        $doc_cache_url = $cache_url . '/' . $doc_hash;
        if (!is_dir($doc_cache_dir)) {
            wp_mkdir_p($doc_cache_dir);
        }

        // 1. pdfimages
        $prefix = $doc_cache_dir . '/img';
        @exec('pdfimages -png ' . escapeshellarg($file_path) . ' ' . escapeshellarg($prefix) . ' 2>&1');

        $all_images = array();
        $files = scandir($doc_cache_dir);
        foreach ($files as $f) {
            if (strpos($f, 'img-') === 0 && substr($f, -4) === '.png') {
                $all_images[] = $f;
            }
        }
        sort($all_images);

        // 2. pdftotext
        $output_text = @shell_exec('pdftotext -layout ' . escapeshellarg($file_path) . ' - 2>&1');
        $lines = !empty($output_text) ? explode("\n", $output_text) : array();

        $exercises = array();
        $curr_ex = null;
        $img_idx = 0;

        foreach ($lines as $line) {
            $s = trim($line);
            if (empty($s)) continue;

            if (self::is_exercise_start($s, false)) {
                if ($curr_ex && !empty(trim($curr_ex['text']))) {
                    $exercises[] = $curr_ex;
                }
                $suggested_img = ($img_idx < count($all_images)) ? $all_images[$img_idx] : null;
                if ($suggested_img) $img_idx++;

                $curr_ex = array(
                    'id' => $doc_hash . '_' . (count($exercises) + 1),
                    'title' => mb_strlen($s) < 30 ? $s : mb_substr($s, 0, 30) . '...',
                    'text' => $s,
                    'image' => $suggested_img,
                    'images_detected' => $suggested_img ? array($suggested_img) : array(),
                    'doc_hash' => $doc_hash,
                    'source_file' => self::$source_name,
                    'all_images' => $all_images,
                    'tipo' => 'subido',
                    'image_url' => $suggested_img ? $doc_cache_url . '/' . $suggested_img : null
                );
            } else {
                if ($curr_ex) {
                    $curr_ex['text'] .= "\n" . $s;
                }
            }
        }

        if ($curr_ex && !empty(trim($curr_ex['text']))) {
            $exercises[] = $curr_ex;
        }

        return array('exercises' => $exercises, 'images' => $all_images);
    }
}
