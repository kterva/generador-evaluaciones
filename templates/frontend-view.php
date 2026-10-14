<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ge-app-container" id="geAppRoot">
  <!-- ENCABEZADO DE LA HERRAMIENTA -->
  <div class="ge-header">
    <div class="ge-brand">
      <span class="ge-brand-icon">⚡</span>
      <div>
        <h2 class="ge-title">Generador de Repartidos y Evaluaciones</h2>
        <p class="ge-subtitle">Subí tus repartidos o pruebas y armá un documento nuevo en formato LibreOffice (.odt) · <a href="<?php echo esc_url(ge_help_url()); ?>">¿Cómo se usa?</a></p>
      </div>
    </div>
  </div>

  <?php echo ge_render_privacy_banner(); ?>

  <!-- ZONA DE CARGA MANUAL (DRAG & DROP) -->
  <section class="ge-upload-section">
    <div class="ge-upload-dropzone" id="dropZone">
      <input type="file" id="fileInput" multiple accept=".odt,.docx,.pdf" style="display:none;">
      <div class="ge-dropzone-content">
        <div class="ge-dropzone-icon-box">
          <span class="ge-dropzone-icon">📤</span>
        </div>
        <div class="ge-dropzone-text">
          <h3>Arrastrá acá tus repartidos o pruebas (.odt, .docx, .pdf)</h3>
          <p class="ge-dropzone-sub">Podés soltar varios archivos a la vez. Los enunciados y figuras se extraerán en segundos. <strong>🔒 Tus archivos no se guardan en el servidor.</strong></p>
        </div>
        <div class="ge-dropzone-actions">
          <button type="button" class="ge-btn ge-btn-outline-primary" onclick="document.getElementById('fileInput').click()">
            📁 Elegir desde mi equipo
          </button>
        </div>
      </div>
      <div id="uploadProgress" class="ge-upload-progress hidden">
        <div class="ge-spinner"></div>
        <span id="uploadStatusText">Procesando archivo...</span>
      </div>
    </div>
  </section>

  <!-- AREA DE TRABAJO EN 2 COLUMNAS -->
  <div class="ge-workspace-grid">
    <!-- COLUMNA IZQUIERDA: EJERCICIOS DISPONIBLES -->
    <section class="ge-column">
      <div class="ge-column-header">
        <div>
          <h3>Ejercicios Disponibles <span id="exerciseCount" class="ge-badge">0</span></h3>
        </div>
        <div>
          <input type="text" id="searchInput" class="ge-search-input" placeholder="Buscar palabras...">
        </div>
      </div>

      <div id="exerciseList" class="ge-exercise-list">
        <div class="ge-empty-state">
          <p>Arrastrá un archivo <strong>.odt</strong>, <strong>.docx</strong> o <strong>.pdf</strong> arriba para extraer las preguntas.</p>
        </div>
      </div>
    </section>

    <!-- COLUMNA DERECHA: EXAMEN EN CONSTRUCCION -->
    <section class="ge-column">
      <div class="ge-column-header">
        <h3 id="docTitleHeading">Tu Prueba en Curso <span id="selectedCount" class="ge-badge ge-badge-primary">0</span></h3>
        <button id="btnClearExam" class="ge-btn ge-btn-sm ge-btn-outline-danger" onclick="geClearExam()">Vaciar</button>
      </div>

      <!-- FORMULARIO DE ENCABEZADO INSTITUCIONAL -->
      <div class="ge-card ge-metadata-card">
        <div class="ge-form-row">
          <div class="ge-form-col ge-full-width">
            <label>Tipo de documento:</label>
            <select id="examType">
              <option value="evaluacion" selected>Evaluación (con estudiante, grupo y calificación)</option>
              <option value="repartido">Repartido (sin datos del estudiante)</option>
            </select>
          </div>
        </div>
        <div class="ge-form-row">
          <div class="ge-form-col">
            <label>Título:</label>
            <input type="text" id="examTitle" value="Evaluación Escrita de Física">
          </div>
          <div class="ge-form-col">
            <label>Tema:</label>
            <input type="text" id="examTopic" value="Física General">
          </div>
        </div>
        <div class="ge-form-row">
          <div class="ge-form-col">
            <label>Institución:</label>
            <input type="text" id="examInst" value="Liceo N° 1">
          </div>
          <div class="ge-form-col">
            <label>Curso:</label>
            <input type="text" id="examCourse" value="6to Año">
          </div>
          <div class="ge-form-col">
            <label>Fecha:</label>
            <input type="text" id="examDate" value="___ / ___ / 2026">
          </div>
        </div>
        <div class="ge-form-row">
          <div class="ge-form-col ge-full-width">
            <label>Pautas (opcional):</label>
            <input type="text" id="examGuidelines" value="Resolución individual en hoja aparte. Justificar todas las respuestas e indicar unidades.">
          </div>
        </div>
      </div>

      <!-- LISTA DE SELECCIONADOS -->
      <div id="selectedList" class="ge-selected-list">
        <div class="ge-empty-state-mini">
          <p>Todavía no agregaste ejercicios a la prueba. Hacé clic en <strong>+ Agregar a la prueba</strong> en los ejercicios que te gusten.</p>
        </div>
      </div>

      <!-- BOTON DE GENERACION -->
      <div class="ge-generate-section">
        <button id="btnGenerate" class="ge-btn ge-btn-success ge-btn-lg ge-btn-block" onclick="geGenerateExam()" disabled>
          📥 Generar Evaluación en LibreOffice (.odt)
        </button>
        <div id="generateProgress" class="ge-generate-progress hidden" role="status" aria-live="polite"></div>
        <div id="generateSuccess" class="ge-generate-result hidden" role="status" aria-live="polite">
          <p id="generateStatus">✅ <strong>¡Evaluación generada con éxito!</strong></p>
          <a id="downloadLink" href="#" class="ge-btn ge-btn-primary ge-btn-block" download>Descargar archivo .odt</a>
          <p class="ge-generate-hint">Si la descarga no empezó sola, hacé clic en el botón. El archivo se abre con LibreOffice Writer. <strong>🔒 El servidor borra el documento apenas lo descargás.</strong></p>
        </div>
        <div id="generateError" class="ge-generate-error hidden" role="alert"></div>
      </div>
    </section>
  </div>

  <!-- MODAL PARA VINCULAR O CAMBIAR IMAGEN MANUALMENTE -->
  <div id="imageModal" class="ge-modal-overlay hidden" onclick="if(event.target === this) geCloseModal()">
    <div class="ge-modal-content">
      <div class="ge-modal-header">
        <div>
          <h3>Vincular Figura al Ejercicio</h3>
          <p id="modalDocName" class="ge-modal-subtitle">Documento fuente: ...</p>
        </div>
        <button id="btnCloseModal" class="ge-modal-close" onclick="geCloseModal()">&times;</button>
      </div>
      <div class="ge-modal-body">
        <p class="ge-modal-instruction">Seleccioná la figura que corresponde a este problema entre todas las extraídas:</p>
        <div id="imageGallery" class="ge-image-gallery">
          <!-- Galería de miniaturas -->
        </div>
      </div>
      <div class="ge-modal-footer">
        <button id="btnRemoveImage" class="ge-btn ge-btn-outline-danger" onclick="geRemoveModalImage()">Quitar figura</button>
        <button id="btnSaveImageModal" class="ge-btn ge-btn-primary" onclick="geSaveModalChoice()">Confirmar Selección</button>
      </div>
    </div>
  </div>
  <?php echo ge_render_footer(); ?>
</div>
