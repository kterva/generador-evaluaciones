<?php
if (!defined('ABSPATH')) {
    exit;
}

class Generador_Evaluaciones_Generator {

    private static function get_image_attribute($img_path) {
        $info = @getimagesize($img_path);
        if (!$info) {
            return '{ width=50% }';
        }
        $w = $info[0];
        $h = $info[1];
        $aspect = ($h > 0) ? ($w / $h) : 1.3;

        if ($aspect >= 2.0) {
            return '{ width=70% }';
        } elseif ($aspect >= 1.2) {
            return '{ width=50% }';
        } elseif ($aspect <= 0.8) {
            return '{ width=38% }';
        } else {
            return '{ width=45% }';
        }
    }

    private static function prepare_optimized_image($img_path, $temp_dir) {
        if (!function_exists('imagecreatefrompng') || !file_exists($img_path)) {
            return $img_path;
        }

        $info = @getimagesize($img_path);
        if (!$info) return $img_path;

        $w = $info[0];
        $h = $info[1];
        $max_dim = 850;

        if ($w <= $max_dim && $h <= $max_dim) {
            return $img_path;
        }

        $scale = min($max_dim / $w, $max_dim / $h);
        $new_w = round($w * $scale);
        $new_h = round($h * $scale);

        $mime = $info['mime'];
        $src_img = null;
        if ($mime === 'image/png') {
            $src_img = @imagecreatefrompng($img_path);
        } elseif ($mime === 'image/jpeg') {
            $src_img = @imagecreatefromjpeg($img_path);
        }

        if (!$src_img) return $img_path;

        $dst_img = imagecreatetruecolor($new_w, $new_h);
        if ($mime === 'image/png') {
            imagealphablending($dst_img, false);
            imagesavealpha($dst_img, true);
        }

        imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
        $opt_path = $temp_dir . '/opt_' . basename($img_path);
        if ($mime === 'image/png') {
            imagepng($dst_img, $opt_path, 6);
        } else {
            imagejpeg($dst_img, $opt_path, 85);
        }

        imagedestroy($src_img);
        imagedestroy($dst_img);

        return $opt_path;
    }

    public static function generate_odt($data, $cache_dir, $output_dir, $output_url) {
        $titulo = !empty($data['titulo']) ? trim($data['titulo']) : 'Evaluación de Física';
        $tema = !empty($data['tema']) ? trim($data['tema']) : 'General';
        $institucion = !empty($data['institucion']) ? trim($data['institucion']) : 'Institución Educativa';
        $curso = !empty($data['curso']) ? trim($data['curso']) : '6to Año';
        $fecha = !empty($data['fecha']) ? trim($data['fecha']) : date('d/m/Y');
        $pautas = !empty($data['pautas']) ? trim($data['pautas']) : '';
        $ejercicios = !empty($data['ejercicios']) ? $data['ejercicios'] : array();

        $temp_dir = get_temp_dir() . 'ge_' . uniqid();
        wp_mkdir_p($temp_dir);

        $md = array();
        $md[] = "# " . $titulo;
        $md[] = "";

        // Tabla de encabezado institucional
        $md[] = "| **Institución:** " . $institucion . " | **Curso:** " . $curso . " | **Fecha:** " . $fecha . " |";
        $md[] = "| :--- | :--- | :--- |";
        $md[] = "| **Estudiante:** | **Grupo:** | **Calificación:** |";
        $md[] = "";

        if (!empty($pautas)) {
            $md[] = "* **Pautas:** " . $pautas;
            $md[] = "";
        }
        $md[] = "---";
        $md[] = "";

        $count = 1;
        foreach ($ejercicios as $ex) {
            $md[] = "### Ejercicio " . $count;
            $md[] = "";

            $raw_text = !empty($ex['text']) ? $ex['text'] : '';
            // Limpiar "Ejercicio X:" al inicio para no duplicar
            $clean_body = preg_replace('/^(?:ejercicio|problema|actividad|pregunta)\s*\d+[:\.\-]?\s*/i', '', trim($raw_text));
            $clean_body = preg_replace('/^\d+\s*[\.\-\)]\s*/', '', $clean_body);

            // Formatear subpreguntas a), b), etc.
            $clean_body = preg_replace('/(?<!\n)([a-z]\))\s*/i', "\n\n**$1** ", $clean_body);
            // Formatear afirmaciones
            $clean_body = preg_replace('/(?<!\n)Afirmaci[oó]n\s*(\d*)\s*:\s*/i', "\n\n* **Afirmación $1:** ", $clean_body);

            $md[] = trim($clean_body);
            $md[] = "";

            // Imagen asociada
            $img_name = !empty($ex['image']) ? $ex['image'] : null;
            $doc_hash = !empty($ex['doc_hash']) ? $ex['doc_hash'] : '';

            if (!empty($img_name) && !empty($doc_hash)) {
                $full_img_path = $cache_dir . '/' . $doc_hash . '/' . $img_name;
                if (file_exists($full_img_path)) {
                    $opt_img_path = self::prepare_optimized_image($full_img_path, $temp_dir);
                    $dim_attr = self::get_image_attribute($opt_img_path);
                    $md[] = "![](" . $opt_img_path . ")" . $dim_attr;
                    $md[] = "";
                }
            }

            $md[] = "";
            $count++;
        }

        $md_content = implode("\n", $md);
        $temp_md = $temp_dir . '/document.md';
        file_put_contents($temp_md, $md_content);

        // Nombre de archivo sanitizado
        $clean_tema = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $tema);
        $clean_tema = trim($clean_tema, '_');
        $filename = 'Evaluacion_' . $clean_tema . '_' . date('Ymd_His') . '.odt';
        $output_file = $output_dir . '/' . $filename;

        // Comprobar comando pandoc
        $pandoc_cmd = 'pandoc';
        // Verificar si existe en /usr/bin/pandoc
        if (file_exists('/usr/bin/pandoc')) {
            $pandoc_cmd = '/usr/bin/pandoc';
        }

        $cmd = escapeshellcmd($pandoc_cmd) . ' ' . escapeshellarg($temp_md) . ' -o ' . escapeshellarg($output_file) . ' --metadata=title=' . escapeshellarg($titulo) . ' 2>&1';
        $output = array();
        $return_var = 0;
        exec($cmd, $output, $return_var);

        // Limpiar archivos temporales
        @unlink($temp_md);
        $files = scandir($temp_dir);
        foreach ($files as $f) {
            if ($f !== '.' && $f !== '..') @unlink($temp_dir . '/' . $f);
        }
        @rmdir($temp_dir);

        if ($return_var !== 0 || !file_exists($output_file)) {
            $err_msg = !empty($output) ? implode("\n", $output) : "Error desconocido al ejecutar pandoc (código $return_var)";
            throw new Exception("Pandoc falló: " . $err_msg);
        }

        $filesize_kb = round(filesize($output_file) / 1024, 1);

        return array(
            'status' => 'ok',
            'filename' => $filename,
            'download_url' => $output_url . '/' . $filename,
            'size_kb' => $filesize_kb
        );
    }
}
