<?php
if (!defined('ABSPATH')) {
    exit;
}
$app_url = home_url('/generador-evaluaciones/');
?>
<div class="ge-app-container ge-help">
  <div class="ge-header">
    <div class="ge-brand">
      <span class="ge-brand-icon">📝</span>
      <div>
        <h2 class="ge-title">Ayuda: Generador de Repartidos y Evaluaciones</h2>
        <p class="ge-subtitle">Armá repartidos y pruebas nuevas a partir de tus propios documentos, en minutos.</p>
      </div>
    </div>
  </div>

  <p class="ge-help-cta"><a class="ge-btn ge-btn-primary" href="<?php echo esc_url($app_url); ?>">Ir a la herramienta →</a></p>

  <section class="ge-help-section">
    <h3>¿Para qué sirve?</h3>
    <p>
      Si ya tenés repartidos, pruebas anteriores o apuntes con ejercicios, esta herramienta los lee, separa cada ejercicio
      (con sus figuras) y te deja <strong>combinarlos en un documento nuevo</strong>: una evaluación o un repartido.
      El resultado es un archivo de <strong>LibreOffice Writer (.odt)</strong> con encabezado institucional, listo para imprimir
      o para seguir editando.
    </p>
    <ul>
      <li><strong>Evaluación:</strong> incluye el encabezado con los datos del estudiante, el grupo y la calificación.</li>
      <li><strong>Repartido:</strong> mismo formato, pero sin la fila de estudiante, grupo y calificación.</li>
    </ul>
  </section>

  <section class="ge-help-section">
    <h3>¿Quién puede usarla?</h3>
    <p>
      La herramienta es de uso gratuito para docentes y requiere <strong>iniciar sesión con un usuario docente</strong>.
      Si todavía no tenés uno, escribinos a
      <a href="mailto:trujillo.leonardo@gmail.com">trujillo.leonardo@gmail.com</a> para pedirlo.
    </p>
  </section>

  <section class="ge-help-section">
    <h3>Paso a paso</h3>
    <ol class="ge-help-steps">
      <li>
        <strong>Subí tus documentos.</strong> Arrastralos a la zona de carga o usá <em>📁 Elegir desde mi equipo</em>.
        Podés subir varios archivos a la vez: <strong>.odt</strong>, <strong>.docx</strong> o <strong>.pdf</strong> (hasta 15 MB cada uno).
      </li>
      <li>
        <strong>Revisá los ejercicios.</strong> Aparecen en la columna <em>Ejercicios Disponibles</em>, cada uno con su figura si la tiene.
        Con el buscador podés filtrar por palabras o por nombre de archivo.
      </li>
      <li>
        <strong>Elegí los que querés.</strong> Hacé clic en <em>+ Agregar a la prueba</em>. Se van sumando a <em>Tu Prueba en Curso</em>,
        donde podés quitarlos o vaciar toda la lista con <em>Vaciar</em>.
      </li>
      <li>
        <strong>Ajustá las figuras</strong> si hace falta: <em>Vincular Figura</em> o <em>Cambiar Figura</em> te deja elegir cuál de las
        imágenes del documento corresponde a ese ejercicio.
      </li>
      <li>
        <strong>Completá el encabezado.</strong> Elegí el <em>Tipo de documento</em> (evaluación o repartido) y completá título, tema,
        institución, curso, fecha y pautas (opcional).
      </li>
      <li>
        <strong>Generá el archivo.</strong> Hacé clic en el botón verde. En unos segundos el archivo <strong>se descarga solo</strong> y
        aparece un aviso con su nombre. Si la descarga no empezó, usá el botón <em>Descargar</em>.
      </li>
      <li>
        <strong>Abrilo con LibreOffice Writer</strong> para imprimirlo o retocarlo (ordenar, agregar espacio para responder, ajustar puntajes).
      </li>
    </ol>
  </section>

  <section class="ge-help-section">
    <h3>Podés armar más de un documento con los mismos archivos</h3>
    <p>
      Mientras no cierres la página, tus archivos siguen cargados: cambiá la selección, el título o el tipo y generá otra evaluación
      o un repartido distinto, tantas veces como quieras.
    </p>
  </section>

  <section class="ge-help-section">
    <h3>Para que detecte bien los ejercicios</h3>
    <ul>
      <li>Cada ejercicio debería empezar con una marca como <code>Ejercicio 1</code>, <code>Problema 2</code>, <code>Actividad 3</code>, <code>Pregunta 4</code>, o con una numeración tipo <code>1.</code>, <code>1.-</code> o <code>1)</code>.</li>
      <li>Los <strong>.odt</strong> y <strong>.docx</strong> dan mejores resultados; con ellos las figuras se asocian a cada ejercicio.</li>
      <li>Los <strong>.pdf</strong> funcionan si tienen texto seleccionable. Un PDF escaneado (una foto de la hoja) no se puede leer.</li>
      <li>En PDF y DOCX las figuras se asignan por orden de aparición; si alguna quedó cruzada, corregila con <em>Cambiar Figura</em>.</li>
      <li>Si no se detecta ningún ejercicio, revisá que el texto tenga alguna de las marcas de arriba.</li>
    </ul>
  </section>

  <section class="ge-help-section">
    <h3>Tus archivos no se guardan</h3>
    <p>
      Los documentos que subís se procesan y <strong>se borran del servidor apenas se extraen los ejercicios</strong>.
      El archivo generado se borra en cuanto se descarga, y las figuras se eliminan al cerrar o recargar la página
      (como respaldo, una limpieza automática borra cualquier resto a las pocas horas). Si recargás la página, tendrás que volver a subir tus archivos.
    </p>
  </section>

  <section class="ge-help-section">
    <h3>Problemas frecuentes</h3>
    <dl class="ge-help-faq">
      <dt>No puedo subir mi archivo</dt>
      <dd>Verificá que sea .odt, .docx o .pdf, que no pese más de 15 MB y que no esté dañado.</dd>
      <dt>La descarga no empezó</dt>
      <dd>Usá el botón <em>Descargar</em> que aparece debajo del botón verde. Si el navegador lo bloquea, permití las descargas para este sitio.</dd>
      <dt>Me aparece “iniciar sesión” o un error 403</dt>
      <dd>Tu sesión expiró. Volvé a iniciar sesión y probá de nuevo (la herramienta intenta renovarla sola antes de avisarte).</dd>
      <dt>El título o las tildes salen mal</dt>
      <dd>Escribí el título directamente en el campo <em>Título</em>; el archivo se genera en UTF-8 y acepta tildes y ñ.</dd>
    </dl>
  </section>

  <p class="ge-help-cta"><a class="ge-btn ge-btn-primary" href="<?php echo esc_url($app_url); ?>">Ir a la herramienta →</a></p>

  <?php echo ge_render_footer(); ?>
</div>
