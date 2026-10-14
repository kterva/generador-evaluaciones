=== Generador de Evaluaciones de Física ===
Contributors: leonardo
Tags: fisica, educacion, evaluaciones, odt, libreoffice, pandoc
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.0
License: GPLv2 or later

Banco interactivo de ejercicios y generador de pruebas en formato LibreOffice (.odt) para docentes.

== Description ==

Este plugin permite a los docentes de Física (y otras asignaturas) subir sus repartidos y pruebas anteriores en formato `.odt`, `.docx` o `.pdf`. El plugin extrae automáticamente los ejercicios y las figuras asociadas, permitiendo armar nuevas evaluaciones en minutos y descargarlas listas para imprimir o editar en LibreOffice Writer.

== Installation ==

1. Subir la carpeta `generador-evaluaciones` al directorio `/wp-content/plugins/` o instalar el archivo `generador-evaluaciones.zip` desde el panel de WordPress (`Plugins -> Añadir nuevo -> Subir plugin`).
2. Activar el plugin desde el menú de Plugins de WordPress.
3. Crear una nueva página (ej: "Generador de Evaluaciones") e insertar el shortcode:

   `[generador_evaluaciones]`

4. Asegurarse de que el servidor tenga instalado Pandoc (`sudo apt install pandoc`).

== Shortcodes ==

* `[generador_evaluaciones]`: Muestra la interfaz completa de arrastrar y soltar, selección de ejercicios y generador de pruebas.

== Changelog ==

= 1.0.0 =
* Versión inicial con soporte para extracción de ODT, DOCX, PDF y compilación a LibreOffice Writer (.odt) con ajuste automático de figuras y encabezado institucional.
