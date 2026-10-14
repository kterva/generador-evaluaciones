<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<!-- Pie de página (mismo estilo que graficador.culturalibre.edu.uy) -->
<footer class="ge-footer">
  <p><strong>Generador de Repartidos y Evaluaciones</strong> | Desarrollado por <strong>Leonardo Trujillo</strong></p>
  <p class="ge-footer-small">
    📜 Software libre bajo licencia <a href="<?php echo esc_url(ge_license_url()); ?>">GPL v3 o posterior</a> |
    <a href="https://github.com/kterva/generador-evaluaciones" target="_blank" rel="noopener">🔗 Ver código fuente en GitHub</a>
  </p>
  <p class="ge-footer-version">Versión <?php echo esc_html(GE_VERSION); ?> · <a href="<?php echo esc_url(ge_help_url()); ?>">Ayuda</a> · <a href="<?php echo esc_url(ge_manual_url()); ?>">Manual</a></p>
  <p class="ge-footer-version">
    ¡Contribuciones y sugerencias son bienvenidas! 🚀 Escribime a
    <a href="mailto:<?php echo esc_attr(ge_contact_email()); ?>"><?php echo esc_html(ge_contact_email()); ?></a>
  </p>
</footer>
