// Backend/js/tabs.js

// Tabs umschalten
function openTab(evt, tabName) {
    // Alle Tab-Inhalte ausblenden
    var tabcontent = document.getElementsByClassName("tabcontent");
    for (var i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }

    // Alle Tab-Buttons "deaktivieren"
    var tablinks = document.getElementsByClassName("tablinks");
    for (var j = 0; j < tablinks.length; j++) {
        tablinks[j].classList.remove("active");
    }

    // Gewählten Tab einblenden
    var content = document.getElementById(tabName);
    if (content) {
        content.style.display = "block";
    }

    // Aktiven Button markieren (wenn über Klick aufgerufen)
    if (evt && evt.currentTarget) {
        evt.currentTarget.classList.add("active");
    }
}

// Beim Laden der Seite anhand der URL den richtigen Tab öffnen
document.addEventListener("DOMContentLoaded", function () {
    var urlParams = new URLSearchParams(window.location.search);
    var tab = urlParams.get("tab") || "home";

    var buttonId;
    if (tab === "analyse") {
        buttonId = "tabAnalyseButton";
    } else if (tab === "einstellungen") {
        buttonId = "tabSettingsButton";
    } else {
        buttonId = "tabHomeButton";
        tab = "home";
    }

    var btn = document.getElementById(buttonId);

    if (btn) {
        // openTab mit "künstlichem" Event aufrufen, damit der Button aktiv wird
        openTab({ currentTarget: btn }, tab);
    } else {
        // Fallback: wenn irgendwas schiefgeht, den Home-Tab öffnen
        var homeBtn = document.getElementById("tabHomeButton");
        if (homeBtn) {
            openTab({ currentTarget: homeBtn }, "home");
        }
    }
});
