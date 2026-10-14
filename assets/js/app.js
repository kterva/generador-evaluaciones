/**
 * JavaScript para el Generador de Evaluaciones de Física (WordPress Plugin)
 */

(function() {
  let availableExercises = [];
  let selectedExercises = [];
  let activeExerciseForModal = null;
  let modalSelectedImage = null;

  function getApiUrl(endpoint) {
    if (typeof geConfig !== 'undefined' && geConfig.rest_url) {
      return geConfig.rest_url + endpoint;
    }
    return '/wp-json/generador-evaluaciones/v1/' + endpoint;
  }

  function getApiHeaders(isJson = false) {
    const headers = {};
    if (typeof geConfig !== 'undefined' && geConfig.nonce) {
      headers['X-WP-Nonce'] = geConfig.nonce;
    }
    if (isJson) {
      headers['Content-Type'] = 'application/json';
    }
    return headers;
  }

  // El nonce embebido en el HTML puede venir de una copia cacheada (WP Super Cache,
  // CDN) generada para otro usuario o para un visitante anónimo, y entonces la REST
  // API responde 403 "Cookie check failed". Se pide uno fresco con la cookie de sesión.
  async function refreshNonce() {
    if (typeof geConfig === 'undefined' || !geConfig.ajax_url) return false;
    try {
      const r = await fetch(geConfig.ajax_url + '?action=rest-nonce', { credentials: 'same-origin', cache: 'no-store' });
      if (!r.ok) return false;
      const n = (await r.text()).trim();
      if (!/^[a-f0-9]{6,20}$/i.test(n)) return false;
      geConfig.nonce = n;
      return true;
    } catch (_) {
      return false;
    }
  }

  async function apiFetch(endpoint, options) {
    const build = () => Object.assign({}, options, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: getApiHeaders(options.json)
    });
    let res = await fetch(getApiUrl(endpoint), build());
    if (res.status === 403 || res.status === 401) {
      if (await refreshNonce()) {
        res = await fetch(getApiUrl(endpoint), build());
      }
    }
    return res;
  }

  function showToast(message, isError = false) {
    let box = document.getElementById("geGlobalToast");
    if (!box) {
      box = document.createElement("div");
      box.id = "geGlobalToast";
      box.style.cssText = "position:fixed;bottom:24px;right:24px;max-width:440px;color:white;padding:14px 18px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.3);z-index:9999999;font-size:14px;line-height:1.4;display:flex;align-items:flex-start;gap:10px;";
      document.body.appendChild(box);
    }
    box.style.background = isError ? "#dc2626" : "#15803d";
    box.innerHTML = `
      <span style="font-size:1.3rem;">${isError ? '⚠️' : '✅'}</span>
      <div style="flex:1;"><strong>${isError ? 'Atención' : '¡Éxito!'}</strong><div style="margin-top:4px;">${escapeHtml(message)}</div></div>
      <button onclick="this.parentElement.remove()" style="background:none;border:none;color:white;font-size:1.2rem;cursor:pointer;line-height:1;">&times;</button>
    `;
    setTimeout(() => { if (box && box.parentElement) box.remove(); }, 6000);
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function init() {
    const root = document.getElementById('geAppRoot');
    if (!root) return;

    setupDragAndDrop();

    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
      searchInput.addEventListener('input', filterExercises);
    }
  }

  function setupDragAndDrop() {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');

    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        if (e.target.files && e.target.files.length > 0) {
          handleFilesUpload(Array.from(e.target.files));
          fileInput.value = '';
        }
      });
    }

    if (dropZone) {
      ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
          e.preventDefault();
          e.stopPropagation();
          dropZone.classList.add('drag-active');
        }, false);
      });

      ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
          e.preventDefault();
          e.stopPropagation();
          dropZone.classList.remove('drag-active');
        }, false);
      });

      dropZone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt ? Array.from(dt.files) : [];
        if (files.length > 0) {
          handleFilesUpload(files);
        }
      }, false);
    }
  }

  async function handleFilesUpload(files) {
    const validExtensions = ['.odt', '.docx', '.pdf'];
    const validFiles = files.filter(f => {
      const ext = f.name.substring(f.name.lastIndexOf('.')).toLowerCase();
      return validExtensions.includes(ext);
    });

    if (validFiles.length === 0) {
      alert("Por favor arrastrá archivos en formato .odt (LibreOffice), .docx (Word) o .pdf");
      return;
    }

    const progress = document.getElementById('uploadProgress');
    const statusText = document.getElementById('uploadStatusText');
    if (progress) progress.classList.remove('hidden');

    let totalAdded = 0;

    for (let i = 0; i < validFiles.length; i++) {
      const file = validFiles[i];
      if (statusText) statusText.innerText = `Extrayendo de "${file.name}" (${i+1}/${validFiles.length})...`;

      const formData = new FormData();
      formData.append('file', file);

      try {
        const res = await apiFetch('upload', {
          method: 'POST',
          body: formData
        });

        if (!res.ok) {
          let errText = `Error ${res.status}`;
          try {
            const errData = await res.json();
            if (errData.message) errText = errData.message;
          } catch (_) {}
          throw new Error(errText);
        }

        const data = await res.json();
        const exs = data.exercises || [];
        exs.forEach(e => { if (e.doc_hash) usedHashes.add(e.doc_hash); });

        if (exs.length === 0) {
          showToast(`No se detectaron enunciados separados en "${file.name}".`, true);
        } else {
          for (const e of exs) {
            if (!availableExercises.some(existing => existing.id === e.id)) {
              availableExercises.unshift(e);
              totalAdded++;
            }
          }
        }
      } catch (err) {
        console.error("❌ Error al subir:", err);
        showToast(`Error procesando "${file.name}": ${err.message}`, true);
      }
    }

    if (progress) progress.classList.add('hidden');

    if (totalAdded > 0) {
      showToast(`Se extrajeron ${totalAdded} ejercicios listos para usar.`);
      filterExercises();
    }
  }

  function renderExercises(list) {
    const container = document.getElementById('exerciseList');
    const countBadge = document.getElementById('exerciseCount');
    if (countBadge) countBadge.innerText = list.length;

    if (list.length === 0) {
      if (availableExercises.length === 0) {
        container.innerHTML = `
          <div class="ge-empty-state">
            <p>Arrastrá un archivo <strong>.odt</strong>, <strong>.docx</strong> o <strong>.pdf</strong> arriba para extraer las preguntas.</p>
          </div>`;
      } else {
        container.innerHTML = `
          <div class="ge-empty-state">
            <p>No se encontraron ejercicios con ese criterio de búsqueda.</p>
          </div>`;
      }
      return;
    }

    container.innerHTML = list.map(ex => {
      const isSelected = selectedExercises.some(s => s.id === ex.id);
      const hasImage = !!ex.image;
      const imgUrl = ex.image_url || (hasImage && geConfig?.cache_url ? `${geConfig.cache_url}/${ex.doc_hash}/${ex.image}` : null);

      return `
        <div class="ge-exercise-card" id="card-${ex.id}">
          <div class="ge-card-meta">
            <span class="ge-card-source"><span class="ge-badge ge-badge-success">📂 Archivo</span> ${escapeHtml(ex.source_file || '')}</span>
            <span class="ge-card-id">#${(ex.id && ex.id.includes('_')) ? ex.id.split("_")[1] : ex.id}</span>
          </div>
          <div class="ge-card-text">${escapeHtml(ex.text)}</div>
          
          <div class="ge-card-image-box ${hasImage ? '' : 'hidden'}" id="img-box-${ex.id}">
            <img src="${imgUrl || ''}" id="img-${ex.id}" alt="Figura del problema">
          </div>

          <div class="ge-card-actions">
            <button type="button" class="ge-btn ge-btn-sm ge-btn-outline-primary" onclick="window.geOpenImageModal('${ex.id}')">
              🖼️ ${hasImage ? 'Cambiar Figura' : 'Vincular Figura'}
            </button>
            
            <button type="button" class="ge-btn ge-btn-sm ${isSelected ? 'ge-btn-outline-danger' : 'ge-btn-primary'}" onclick="window.geToggleSelect('${ex.id}')">
              ${isSelected ? '✕ Quitar de la prueba' : '+ Agregar a la prueba'}
            </button>
          </div>
        </div>
      `;
    }).join('');
  }

  function filterExercises() {
    const query = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
    if (!query) {
      renderExercises(availableExercises);
      return;
    }
    const filtered = availableExercises.filter(ex =>
      (ex.text && ex.text.toLowerCase().includes(query)) ||
      (ex.source_file && ex.source_file.toLowerCase().includes(query))
    );
    renderExercises(filtered);
  }

  window.geToggleSelect = function(id) {
    const idx = selectedExercises.findIndex(s => s.id === id);
    if (idx >= 0) {
      selectedExercises.splice(idx, 1);
    } else {
      const ex = availableExercises.find(e => e.id === id);
      if (ex) {
        selectedExercises.push({ ...ex });
      }
    }
    renderSelected();
    filterExercises();
  };

  function renderSelected() {
    const container = document.getElementById('selectedList');
    const badge = document.getElementById('selectedCount');
    const btnGen = document.getElementById('btnGenerate');

    if (badge) badge.innerText = selectedExercises.length;
    if (btnGen) btnGen.disabled = selectedExercises.length === 0;

    if (selectedExercises.length === 0) {
      container.innerHTML = `
        <div class="ge-empty-state-mini">
          <p>Todavía no agregaste ejercicios a la prueba. Hacé clic en <strong>+ Agregar a la prueba</strong> en los ejercicios que te gusten.</p>
        </div>`;
      return;
    }

    container.innerHTML = selectedExercises.map((ex, idx) => {
      const hasImage = !!ex.image;
      const imgUrl = ex.image_url || (hasImage && geConfig?.cache_url ? `${geConfig.cache_url}/${ex.doc_hash}/${ex.image}` : null);

      return `
        <div class="ge-selected-card">
          <div class="ge-selected-num">${idx + 1}</div>
          <div class="ge-selected-info">
            <div class="ge-selected-snippet">${escapeHtml(ex.text.substring(0, 70))}...</div>
            <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
              ${escapeHtml(ex.source_file || '')} ${hasImage ? '• 🖼️ con figura' : ''}
            </div>
          </div>
          ${hasImage && imgUrl ? `<img src="${imgUrl}" class="ge-selected-thumb" alt="Miniatura">` : ''}
          <div class="ge-selected-actions">
            ${idx > 0 ? `<button type="button" class="ge-btn ge-btn-sm" style="background:#e2e8f0;padding:2px 6px;" onclick="window.geMoveSelected(${idx}, -1)" title="Subir">▲</button>` : ''}
            ${idx < selectedExercises.length - 1 ? `<button type="button" class="ge-btn ge-btn-sm" style="background:#e2e8f0;padding:2px 6px;" onclick="window.geMoveSelected(${idx}, 1)" title="Bajar">▼</button>` : ''}
            <button type="button" class="ge-btn ge-btn-sm ge-btn-outline-danger" onclick="window.geToggleSelect('${ex.id}')" title="Quitar">&times;</button>
          </div>
        </div>
      `;
    }).join('');
  }

  window.geMoveSelected = function(index, delta) {
    const newIdx = index + delta;
    if (newIdx < 0 || newIdx >= selectedExercises.length) return;
    const temp = selectedExercises[index];
    selectedExercises[index] = selectedExercises[newIdx];
    selectedExercises[newIdx] = temp;
    renderSelected();
  };

  window.geClearExam = function() {
    if (selectedExercises.length === 0) return;
    if (confirm("¿Estás seguro de que querés vaciar la prueba en curso?")) {
      selectedExercises = [];
      renderSelected();
      filterExercises();
    }
  };

  // Image linking modal
  window.geOpenImageModal = function(exerciseId) {
    const ex = availableExercises.find(e => e.id === exerciseId);
    if (!ex) return;

    activeExerciseForModal = ex;
    modalSelectedImage = ex.image;

    document.getElementById('modalDocName').innerText = `Documento: ${ex.source_file || ''}`;
    const gallery = document.getElementById('imageGallery');

    const images = ex.all_images || (ex.image ? [ex.image] : []);
    if (images.length === 0) {
      gallery.innerHTML = '<p class="text-muted" style="grid-column: 1/-1; text-align:center;">Este documento no contiene imágenes extraídas.</p>';
    } else {
      gallery.innerHTML = images.map(imgName => {
        const isSel = (imgName === modalSelectedImage);
        const imgUrl = `${geConfig.cache_url}/${ex.doc_hash}/${imgName}`;
        return `
          <div class="ge-gallery-item ${isSel ? 'selected' : ''}" onclick="window.geSelectModalImage('${imgName}', this)">
            <img src="${imgUrl}" alt="${imgName}">
            <span>${imgName.length > 20 ? imgName.substring(0, 18) + '...' : imgName}</span>
          </div>
        `;
      }).join('');
    }

    document.getElementById('imageModal').classList.remove('hidden');
  };

  window.geSelectModalImage = function(imgName, elem) {
    modalSelectedImage = imgName;
    document.querySelectorAll('.ge-gallery-item').forEach(el => el.classList.remove('selected'));
    if (elem) elem.classList.add('selected');
  };

  window.geCloseModal = function() {
    document.getElementById('imageModal').classList.add('hidden');
    activeExerciseForModal = null;
    modalSelectedImage = null;
  };

  window.geRemoveModalImage = function() {
    modalSelectedImage = null;
    window.geSaveModalChoice();
  };

  window.geSaveModalChoice = function() {
    if (!activeExerciseForModal) return;
    activeExerciseForModal.image = modalSelectedImage;
    if (modalSelectedImage) {
      activeExerciseForModal.image_url = `${geConfig.cache_url}/${activeExerciseForModal.doc_hash}/${modalSelectedImage}`;
    } else {
      activeExerciseForModal.image_url = null;
    }

    const selIdx = selectedExercises.findIndex(s => s.id === activeExerciseForModal.id);
    if (selIdx >= 0) {
      selectedExercises[selIdx].image = modalSelectedImage;
      selectedExercises[selIdx].image_url = activeExerciseForModal.image_url;
      renderSelected();
    }

    filterExercises();
    window.geCloseModal();
  };

  // Descarga el archivo generado y lo guarda con un clic programático. Devuelve true si
  // el navegador recibió el archivo completo; si no, el llamador deja el enlace manual.
  const savedBlobs = {};

  function triggerSave(blob, filename) {
    const objUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = objUrl;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(objUrl), 60000);
  }

  async function saveGeneratedFile(url, filename) {
    // Si ya se bajó una vez, el servidor lo borró: se vuelve a guardar desde la memoria del navegador.
    if (savedBlobs[filename]) {
      triggerSave(savedBlobs[filename], filename);
      return savedBlobs[filename].size;
    }
    const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
    if (!res.ok) throw new Error(`el servidor respondió ${res.status}`);
    const buf = await res.arrayBuffer();
    if (!buf.byteLength) throw new Error('el archivo llegó vacío');
    const blob = new Blob([buf], { type: 'application/vnd.oasis.opendocument.text' });
    savedBlobs[filename] = blob;
    triggerSave(blob, filename);
    return buf.byteLength;
  }

  // Nada debe quedar en el servidor: se pide borrar las figuras extraídas y los .odt ya descargados.
  const usedHashes = new Set();
  const pendingFiles = new Set();

  function cleanupPayload(extra) {
    return JSON.stringify({ hashes: Array.from(usedHashes), files: Array.from(pendingFiles).concat(extra || []) });
  }

  function cleanupOnServer(files) {
    return apiFetch('cleanup', { method: 'POST', json: true, body: JSON.stringify({ files: files }) }).catch(() => {});
  }

  // Al cerrar o recargar la página: borrar figuras y archivos pendientes (sendBeacon sobrevive al cierre).
  window.addEventListener('pagehide', () => {
    if (!usedHashes.size && !pendingFiles.size) return;
    if (typeof geConfig === 'undefined' || !geConfig.nonce) return;
    const url = getApiUrl('cleanup') + (getApiUrl('cleanup').includes('?') ? '&' : '?') + '_wpnonce=' + encodeURIComponent(geConfig.nonce);
    try {
      navigator.sendBeacon(url, new Blob([cleanupPayload()], { type: 'application/json' }));
    } catch (_) {}
  });

  // El tipo de documento cambia los textos (y el título por defecto, si el docente no lo editó).
  const TIPOS = {
    evaluacion: { titulo: 'Evaluación Escrita de Física', heading: 'Tu Prueba en Curso', boton: '📥 Generar Evaluación en LibreOffice (.odt)' },
    repartido:  { titulo: 'Repartido de Física',          heading: 'Tu Repartido en Curso', boton: '📥 Generar Repartido en LibreOffice (.odt)' }
  };
  function applyDocType() {
    const sel = document.getElementById('examType');
    if (!sel) return;
    const t = TIPOS[sel.value] || TIPOS.evaluacion;
    const other = sel.value === 'repartido' ? TIPOS.evaluacion : TIPOS.repartido;
    const title = document.getElementById('examTitle');
    if (title && title.value === other.titulo) title.value = t.titulo;
    const h = document.getElementById('docTitleHeading');
    if (h) h.firstChild.textContent = t.heading + ' ';
    const b = document.getElementById('btnGenerate');
    if (b) b.innerHTML = t.boton;
  }
  document.addEventListener('change', (ev) => { if (ev.target && ev.target.id === 'examType') applyDocType(); });

  // Latido: mientras la página esté abierta con archivos cargados, el servidor conserva sus figuras
  // (se pueden armar varias evaluaciones con los mismos archivos).
  setInterval(() => {
    if (!usedHashes.size || document.hidden) return;
    apiFetch('keepalive', { method: 'POST', json: true, body: JSON.stringify({ hashes: Array.from(usedHashes) }) }).catch(() => {});
  }, 15 * 60 * 1000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && usedHashes.size) {
      apiFetch('keepalive', { method: 'POST', json: true, body: JSON.stringify({ hashes: Array.from(usedHashes) }) }).catch(() => {});
    }
  });

  function setBox(id, html, visible) {
    const el = document.getElementById(id);
    if (!el) return;
    if (html !== null) el.innerHTML = html;
    el.classList.toggle('hidden', !visible);
  }

  // Generate Exam
  window.geGenerateExam = async function() {
    if (selectedExercises.length === 0) return;

    const isRep = document.getElementById('examType')?.value === 'repartido';
    const docWord = isRep ? 'repartido' : 'evaluación';
    const DocWord = isRep ? 'Repartido' : 'Evaluación';

    const btn = document.getElementById('btnGenerate');
    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `⏳ Generando el ${docWord}...`;

    setBox('generateSuccess', null, false);
    setBox('generateError', null, false);
    setBox('generateProgress', `⏳ Armando el ${docWord}, puede tardar unos segundos...`, true);

    const payload = {
      tipo: document.getElementById('examType')?.value === 'repartido' ? 'repartido' : 'evaluacion',
      titulo: document.getElementById('examTitle')?.value || 'Evaluación de Física',
      tema: document.getElementById('examTopic')?.value || 'Física',
      institucion: document.getElementById('examInst')?.value || 'Liceo N° 1',
      curso: document.getElementById('examCourse')?.value || '6to Año',
      fecha: document.getElementById('examDate')?.value || '',
      pautas: document.getElementById('examGuidelines')?.value || '',
      ejercicios: selectedExercises
    };

    try {
      const res = await apiFetch('generate', {
        method: 'POST',
        json: true,
        body: JSON.stringify(payload)
      });

      if (!res.ok) {
        let errText = `Error ${res.status}`;
        try {
          const errData = await res.json();
          if (errData.message) errText = errData.message;
        } catch (_) {}
        throw new Error(errText);
      }

      const data = await res.json();
      pendingFiles.add(data.filename);
      const dlLink = document.getElementById('downloadLink');
      if (dlLink) {
        dlLink.href = data.download_url;
        dlLink.setAttribute('download', data.filename);
        dlLink.innerText = `Descargar ${data.filename} (${data.size_kb} KB)`;
        // Mismo guardado que el automático, con el enlace directo como respaldo.
        dlLink.onclick = async (ev) => {
          ev.preventDefault();
          try {
            await saveGeneratedFile(data.download_url, data.filename);
          } catch (_) {
            window.location.href = data.download_url;
          }
        };
      }

      setBox('generateProgress', null, false);
      try {
        const bytes = await saveGeneratedFile(data.download_url, data.filename);
        pendingFiles.delete(data.filename);
        cleanupOnServer([data.filename]);
        setBox('generateStatus', `✅ <strong>¡${DocWord} ${isRep ? 'generado' : 'generada'}!</strong> Se descargó <em>${escapeHtml(data.filename)}</em> (${Math.round(bytes / 102.4) / 10} KB). Buscala en tu carpeta de Descargas.`, true);
        showToast(`${DocWord} descargado: ${data.filename}`);
      } catch (dlErr) {
        console.error('❌ Descarga automática falló:', dlErr);
        setBox('generateStatus', `⚠️ <strong>El ${docWord} se generó, pero la descarga automática falló</strong> (${escapeHtml(dlErr.message)}). Usá el botón de abajo para bajarla.`, true);
        showToast(`El ${docWord} se generó pero no se pudo descargar sola. Usá el botón de descarga.`, true);
      }
      setBox('generateSuccess', null, true);
    } catch (err) {
      console.error("❌ Error al generar examen:", err);
      setBox('generateProgress', null, false);
      setBox('generateError', `❌ <strong>No se pudo generar el ${docWord}.</strong> ${escapeHtml(err.message)}<br>Probá de nuevo; si sigue fallando, avisale al administrador.`, true);
      showToast(`Error generando el ${docWord}: ${err.message}`, true);
    } finally {
      btn.disabled = false;
      btn.innerHTML = oldHtml;
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
