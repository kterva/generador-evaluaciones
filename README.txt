=== Generador de Repartidos y Evaluaciones ===
Contributors: leonardo
Tags: fisica, educacion, evaluaciones, odt, libreoffice, pandoc
Requires at least: 5.9
Tested up to: 6.7
Stable tag: 1.0.14
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Banco interactivo de ejercicios y generador de pruebas en formato LibreOffice (.odt) para docentes.

== Description ==

Este plugin permite a los docentes de Física (y otras asignaturas) subir sus repartidos y pruebas anteriores en formato `.odt`, `.docx` o `.pdf`. El plugin extrae automáticamente los ejercicios y las figuras asociadas, permitiendo armar nuevas evaluaciones en minutos y descargarlas listas para imprimir o editar en LibreOffice Writer.

== Installation ==

1. Subir la carpeta `generador-evaluaciones` al directorio `/wp-content/plugins/` o instalar el archivo `generador-evaluaciones.zip` desde el panel de WordPress (`Plugins -> Añadir nuevo -> Subir plugin`).
2. Activar el plugin desde el menú de Plugins de WordPress.
3. Crear una nueva página (ej: "Generador de Repartidos y Evaluaciones", con la dirección /generador-evaluaciones/) e insertar el shortcode:

   `[generador_evaluaciones]`

4. Asegurarse de que el servidor tenga instalado Pandoc (`sudo apt install pandoc`).

5. Solo pueden usarlo usuarios con sesión iniciada y permiso `edit_posts` (filtrable con `ge_required_capability`). Máximo 15 MB por archivo; los archivos subidos, la caché y las salidas son temporales (ver Changelog 1.0.4).
6. En nginx, bloquear la carpeta de trabajo con las reglas de `nginx-ejemplo.conf`.

== Shortcodes ==

* `[generador_evaluaciones_ayuda]`: Página de ayuda (crearla como página hija, /generador-evaluaciones/ayuda/).
* `[generador_evaluaciones]`: Muestra la interfaz completa de arrastrar y soltar, selección de ejercicios y generador de pruebas.

== Desarrollo ==

Tests automáticos (sin WordPress): `php tests/run.php` (PHP 8+ con zip, dom y mbstring; poppler-utils y pandoc para las pruebas de PDF y de generación del .odt, que se omiten si faltan). Se ejecutan en GitHub en cada push.

== Changelog ==

= 1.0.14 =
* Los ejercicios de un archivo recién subido se muestran en el orden del documento (1, 2, 3…) y no al revés. Los de un archivo nuevo siguen apareciendo arriba de los anteriores.

= 1.0.13 =
* Cierre de la auditoría de código. Seguridad: el identificador de documento de la caché pasa de 12 a 32 caracteres hexadecimales (128 bits), y se valida en un único lugar; `/status` ya no ejecuta programas del sistema (busca los binarios en rutas conocidas) y solo informa disponibilidad.
* Figuras en el lugar correcto: en DOCX se asignan por su posición real en el documento (siguiendo las relaciones internas) y en PDF por página (antes se repartían por orden global y un ejercicio sin figura corría las siguientes). Se descartan imágenes decorativas muy chicas y formatos no usables (EMF/WMF), y se omite la extracción si el PDF trae más de 400 imágenes.
* Subpreguntas: solo se reconocen `a)`…`h)` como marcadores independientes; ya no se parten palabras ni paréntesis como «(a)» o «2a)». Se reconocen enunciados que empiezan con `1.-`.
* 38 tests automáticos (`tests/`) con documentos de prueba sintéticos, y ejecución automática en GitHub (`.github/workflows/tests.yml`).

= 1.0.12 =
* Privacidad: la tipografía Inter ya no se descarga de Google Fonts; se sirve desde el propio plugin (`assets/fonts`, subconjunto latino, licencia SIL OFL 1.1 incluida en `assets/fonts/OFL.txt`). El navegador del visitante ya no se conecta a Google al usar el generador ni la ayuda.

= 1.0.11 =
* El recuadro verde de «tus archivos no se guardan» ya no se muestra antes de iniciar sesión: aparece en la herramienta una vez que la persona ingresó (y sigue en la página de ayuda).

= 1.0.10 =
* El aviso para quien todavía no inició sesión ya no dice «con un usuario docente» cuando el acceso es para cualquier persona con sesión: ahora invita a iniciar sesión con la cuenta de Google o con el usuario, y aclara que es gratis. En el modo «Solo docentes habilitados» el texto anterior se mantiene.

= 1.0.9 =
* Licencia unificada con el Graficador: **GPL v3 o posterior** (antes GPL v2 o posterior). Texto oficial en `LICENSE`, encabezado de licencia en el archivo principal y enlace en el pie a una página propia que explica la licencia (`/licencia/`, filtro `ge_license_url`).

= 1.0.8 =
* Aviso muy destacado (recuadro verde con candado) de que **los archivos no se guardan en el servidor**: el archivo subido se borra al leer los ejercicios, el documento generado se borra al descargarlo y las figuras se eliminan al cerrar la página. Se muestra en la herramienta, antes de iniciar sesión y en la ayuda, con refuerzos en la zona de carga, en el botón de descarga y en el mensaje de éxito.

= 1.0.7 =
* Nuevo ajuste «Quién puede usar el generador» (Ajustes → Generador). Por defecto puede usarlo **cualquier persona con sesión iniciada** (por ejemplo con Google), sin habilitación previa; los límites por hora acotan el abuso. El modo «Solo docentes habilitados» (rol, lista de correos) sigue disponible.
* La ayuda explica el ingreso según el modo elegido.

= 1.0.6 =
* Preparado para ingreso con Google u otros accesos sociales: quien se registra queda como Suscriptor y **no** tiene acceso hasta que se lo habilite. Nuevo rol «Docente (generador)» con el permiso `use_generador_evaluaciones`; los usuarios que ya podían editar entradas siguen teniendo acceso.
* Nueva página Ajustes → Generador: lista de correos o dominios (`@liceo.edu.uy`) que se habilitan solos como docentes al ingresar, y correo de contacto.
* Quien inició sesión pero no está habilitado ve un aviso con un enlace para pedir la habilitación (en vez del aviso de «iniciar sesión»); la API responde 403 con ese motivo.
* Límite de uso por usuario y por hora: 60 subidas y 30 generaciones (filtro `ge_rate_limits`; los administradores están exentos). Respuesta 429 con mensaje claro.
* La ayuda explica cómo ingresar y pedir la habilitación.

= 1.0.5 =
* El plugin pasa a llamarse "Generador de Repartidos y Evaluaciones" (la herramienta sirve para ambos).
* Nuevo selector "Tipo de documento": Evaluación (con estudiante, grupo y calificación) o Repartido (sin esos datos); cambia los textos, el título por defecto y el prefijo del archivo (`Evaluacion_…` / `Repartido_…`).
* Nueva página de ayuda con el shortcode `[generador_evaluaciones_ayuda]` (pública, no requiere sesión): para qué sirve, quién puede usarla, paso a paso, consejos para detectar ejercicios, privacidad y problemas frecuentes. Se enlaza desde la herramienta y desde el pie.
* Nuevo pie de página con el mismo estilo que graficador.culturalibre.edu.uy (autor, licencia, código fuente, versión, contacto).
* Corrige un error del JS que podía dejar el botón «Generar» trabado sin mostrar mensaje.

= 1.0.4 =
* Nada persiste en el servidor: el archivo subido se procesa desde la carpeta temporal del sistema y se borra siempre al terminar (también si falla); el .odt generado se borra apenas el navegador lo descarga; las figuras extraídas se borran al cerrar o recargar la página.
* Respaldo: una limpieza cada hora (antes diaria) borra figuras y .odt de más de 2 horas y cualquier resto en `uploads/` de más de 15 minutos, incluidos los dejados por versiones anteriores.
* Se pueden armar varias evaluaciones con los mismos archivos cargados: las figuras se conservan mientras la página esté abierta (latido cada 15 minutos y al generar), y el botón de descarga vuelve a guardar el archivo desde la memoria del navegador.
* Nuevos endpoints `/cleanup` y `/keepalive`, con los mismos permisos que el resto.

= 1.0.3 =
* La evaluación generada se descarga sola al terminar (se baja el archivo con `fetch` y se guarda con nombre y tipo correctos), sin depender de `target="_blank"`.
* Mensajes claros en cada paso: "Armando la evaluación…", "Se descargó <archivo>", aviso si la descarga automática falla (con botón de respaldo) y mensaje de error visible en la página si falla la generación, no solo un aviso que desaparece.
* El CSS y el JS se piden con la fecha de modificación del archivo como versión, así los navegadores no usan una copia vieja después de actualizar.

= 1.0.2 =
* Corrige el error 403 ("Cookie check failed") al generar el archivo final cuando la página venía de caché con un nonce de otro usuario: la página no se cachea (DONOTCACHEPAGE + cabeceras no-cache), las llamadas a la API envían la cookie de sesión y, ante un 401/403, piden un nonce nuevo (`admin-ajax.php?action=rest-nonce`) y reintentan una vez.
* El .odt ya no repite el título (salía dos veces) ni lo corrompe con tildes: se quitó `--metadata=title` porque `escapeshellarg` descarta los caracteres no ASCII bajo el locale de PHP-FPM. El campo Fecha ya no duplica la etiqueta "Fecha:".
* Requiere WordPress 5.9 o superior (acción `rest-nonce`).
* El nombre del .odt generado ya no pierde los acentos del tema (antes "Dinámica" salía "Din_mica").

= 1.0.1 =
* Seguridad: los endpoints REST exigen sesión y permiso; validación del contenido de los archivos, nombres aleatorios en disco, límite de tamaño, validación de rutas de imágenes, escapado del texto antes de pandoc, limpieza diaria y reglas de acceso a la carpeta de trabajo.

= 1.0.0 =
* Versión inicial con soporte para extracción de ODT, DOCX, PDF y compilación a LibreOffice Writer (.odt) con ajuste automático de figuras y encabezado institucional.
