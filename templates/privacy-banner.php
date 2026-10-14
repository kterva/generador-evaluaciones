<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="ge-privacy-banner" role="note">
  <span class="ge-privacy-icon" aria-hidden="true">🔒</span>
  <div class="ge-privacy-text">
    <p class="ge-privacy-title">Tus archivos NO se guardan en el servidor</p>
    <ul>
      <li><strong>El archivo que subís</strong> se borra apenas se leen los ejercicios.</li>
      <li><strong>El documento que se genera</strong> se borra en cuanto lo descargás.</li>
      <li>Las figuras extraídas se eliminan al cerrar o recargar esta página.</li>
    </ul>
    <p class="ge-privacy-more">Más detalles en la <a href="<?php echo esc_url(ge_privacy_url()); ?>">política de privacidad</a>.</p>
  </div>
</div>
