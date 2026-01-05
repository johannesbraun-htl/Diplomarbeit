function openForm() {
  const el = document.getElementById("myForm");
  if (el) el.style.display = "block";
}

function closeForm() {
  const el = document.getElementById("myForm");
  if (el) el.style.display = "none";
}

async function reloadPresentationsTable() {
  const wrap = document.getElementById("presentationsWrap");
  if (!wrap) return;

  try {
    const res = await fetch("../Backend/php/home/get_presentations_table.php", {
      method: "GET",
      headers: { "Accept": "application/json" }
    });

    const data = await res.json();
    if (!data.ok) {
      alert(data.message || "Tabelle konnte nicht geladen werden.");
      return;
    }

    wrap.innerHTML = data.html;
  } catch (e) {
    alert("Tabelle konnte nicht geladen werden (Netzwerkfehler).");
  }
}

/* ===========================
   Upload mit Progress + ETA
   =========================== */
(function initUploadWithProgress() {
  const form = document.getElementById("uploadForm");
  if (!form) return;

  if (form.dataset.bound === "1") return;
  form.dataset.bound = "1";

  const progressWrap = document.getElementById("uploadProgressWrap");
  const progressBar  = document.getElementById("uploadProgressBar");
  const progressText = document.getElementById("uploadProgressText");
  const etaText      = document.getElementById("uploadEtaText");
  const phaseText    = document.getElementById("uploadPhaseText");
  const submitBtn    = document.getElementById("uploadSubmitBtn");

  function formatTime(sec) {
    if (!isFinite(sec) || sec < 0) return "--:--";
    const s = Math.round(sec);
    const mm = String(Math.floor(s / 60)).padStart(2, "0");
    const ss = String(s % 60).padStart(2, "0");
    return `${mm}:${ss}`;
  }

  function setUiUploading(isUploading) {
    if (submitBtn) submitBtn.disabled = isUploading;

    const title = form.querySelector('input[name="title"]');
    if (title) title.readOnly = isUploading;
  }

  function resetProgressUi() {
    if (progressWrap) progressWrap.style.display = "none";
    if (progressBar) progressBar.style.width = "0%";
    if (progressText) progressText.textContent = "0%";
    if (etaText) etaText.textContent = "ETA: --:--";
    if (phaseText) phaseText.textContent = "Warte auf Upload…";
  }

  form.addEventListener("submit", function (e) {
    e.preventDefault();

    const titleInput = form.querySelector('input[name="title"]');
    const fileInput  = form.querySelector('input[name="presentationFile"]');

    const titleVal = (titleInput?.value || "").trim();
    if (!titleVal) {
      alert("Titel fehlt.");
      return;
    }

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
      alert("Bitte eine MP4 auswählen.");
      return;
    }

    const file = fileInput.files[0];
    const extOk = (file.name || "").toLowerCase().endsWith(".mp4") || (file.type === "video/mp4");
    if (!extOk) {
      alert("Nur MP4 erlaubt.");
      return;
    }

    if (progressWrap) progressWrap.style.display = "block";
    if (progressBar) progressBar.style.width = "0%";
    if (progressText) progressText.textContent = "0%";
    if (etaText) etaText.textContent = "ETA: --:--";
    if (phaseText) phaseText.textContent = "Upload läuft…";

    const fd = new FormData(form);
    setUiUploading(true);

    const xhr = new XMLHttpRequest();
    const startTs = Date.now();

    xhr.open("POST", form.action, true);
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhr.setRequestHeader("Accept", "application/json");

    xhr.upload.onprogress = function (evt) {
      if (!evt.lengthComputable) return;

      const loaded = evt.loaded;
      const total = evt.total;
      const pct = total > 0 ? (loaded / total) * 100 : 0;

      if (progressBar) progressBar.style.width = `${pct.toFixed(1)}%`;
      if (progressText) progressText.textContent = `${pct.toFixed(1)}%`;

      const elapsed = (Date.now() - startTs) / 1000;
      const speed = loaded / Math.max(elapsed, 0.001);
      const remaining = (total - loaded) / Math.max(speed, 1);
      if (etaText) etaText.textContent = `ETA: ${formatTime(remaining)}`;
    };

    xhr.upload.onload = function () {
      if (phaseText) phaseText.textContent = "Upload abgeschlossen, Verarbeitung läuft…";
    };

    xhr.onreadystatechange = async function () {
      if (xhr.readyState !== 4) return;

      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch (_) {}

      if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
        if (progressBar) progressBar.style.width = "100%";
        if (progressText) progressText.textContent = "100%";
        if (etaText) etaText.textContent = "ETA: 00:00";
        if (phaseText) phaseText.textContent = "Fertig. Aktualisiere Liste…";

        await reloadPresentationsTable();

        form.reset();
        closeForm();
        resetProgressUi();

        setUiUploading(false);
        return;
      }

      const msg = (data && data.message) ? data.message : ("Upload fehlgeschlagen. Status: " + xhr.status);
      alert(msg);

      setUiUploading(false);
    };

    xhr.onerror = function () {
      alert("Upload fehlgeschlagen (Netzwerk/Server).");
      setUiUploading(false);
    };

    xhr.send(fd);
  });
})();
