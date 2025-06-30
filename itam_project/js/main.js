// Unterseiten-Suche
document.addEventListener("DOMContentLoaded", function() {
    let searchInputs = document.querySelectorAll(".search-input");

    searchInputs.forEach(input => {
        input.addEventListener("keyup", function() {
            let filter = input.value.toLowerCase();
            let tableId = input.getAttribute("data-table");
            let table = document.getElementById(tableId);
            let rows = table.getElementsByTagName("tr");

            for (let i = 1; i < rows.length; i++) { // Überschrift ignorieren
                let rowText = rows[i].innerText.toLowerCase();
                rows[i].style.display = rowText.includes(filter) ? "" : "none";
            }
        });
    });
});

// Live-Suche über alle Tabellen und Unterseiten
document.addEventListener("DOMContentLoaded", function () {
    let searchInput = document.querySelector(".search-input");
    let resultsContainer = document.getElementById("search-results");
    let customerTable = document.getElementById("customerTableBody"); // Tabelle der Kunden

    if (!searchInput || !resultsContainer) {
        console.error("Fehler: Suchfeld oder Ergebnis-Container nicht gefunden!");
        return;
    }

    searchInput.addEventListener("keyup", function () {
        let query = searchInput.value.trim().toLowerCase();
        console.log("Suchanfrage (JS):", query);

        if (query.length < 2) {
            console.log("Suche zurückgesetzt.");
            resultsContainer.innerHTML = "";
            resultsContainer.style.display = "none"; // Ergebnisse verstecken
            if (customerTable) {
                Array.from(customerTable.children).forEach(row => row.style.display = ""); // Alle Zeilen wieder anzeigen
            }
            return;
        }

        fetch(`ajax_search.php?query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                console.log("Ergebnisse (JS):", data);

                resultsContainer.innerHTML = "";
                if (data.length === 0) {
                    resultsContainer.innerHTML = "<p class='no-results text-center'>Keine Ergebnisse gefunden.</p>";
                    resultsContainer.style.display = "block";
                    return;
                }

                let resultList = document.createElement("ul");
                resultList.classList.add("search-result-list");

                data.forEach(item => {
                    let link;

                    switch (item.type) {
                        case "customer":
                            link = `index.php?act=list_customer&id=${item.customer_id}`;
                            break;
                        case "hardware":
                            link = `index.php?act=list_customer_hardware&customer_id=${item.customer_id}`;
                            break;
                        case "software":
                            link = `index.php?act=list_customer_software&customer_id=${item.customer_id}`;
                            break;
                        case "license":
                            link = `index.php?act=list_software_license&software_id=${item.software_id}`;
                            break;
                        case "employee":
                            link = `index.php?act=list_customer_employees&customer_id=${item.customer_id}`;
                            break;
                        default:
                            link = "#";
                    }

                    let listItem = document.createElement("li");
                    let resultLink = document.createElement("a");
                    resultLink.href = link;
                    resultLink.classList.add("search-result-item");
                    resultLink.textContent = item.name;

                    // Wenn es ein Kunde ist, blende andere Kunden aus
                    if (item.type === "customer") {
                        resultLink.addEventListener("click", function (event) {
                            event.preventDefault(); // Verhindert Seiten-Neuladen
                            if (customerTable) {
                                Array.from(customerTable.children).forEach(row => {
                                    if (!row.innerText.toLowerCase().includes(item.name.toLowerCase())) {
                                        row.style.display = "none"; // Andere Kunden ausblenden
                                    } else {
                                        row.style.display = ""; // Den gesuchten Kunden anzeigen
                                    }
                                });
                            }
                        });
                    }

                    listItem.appendChild(resultLink);
                    resultList.appendChild(listItem);
                });

                resultsContainer.appendChild(resultList);
                resultsContainer.style.display = "block"; // Ergebnisse anzeigen
            })
            .catch(error => console.error("Fehler bei der AJAX-Suche:", error));
    });

    // Ergebnisse verstecken, wenn außerhalb geklickt wird
    document.addEventListener("click", function (event) {
        if (!searchInput.contains(event.target) && !resultsContainer.contains(event.target)) {
            resultsContainer.style.display = "none";
        }
    });
});

// Bestätigungsdialog für Löschaktionen
document.addEventListener("DOMContentLoaded", function () {
    // Alle Lösch-Buttons abrufen
    document.querySelectorAll(".delete-btn").forEach(button => {
        button.addEventListener("click", function (event) {
            event.preventDefault(); // Verhindert das direkte Springen zum Link
            
            let deleteUrl = this.getAttribute("href"); // Holt die URL zum Löschen
            
            Swal.fire({
                title: "Bist du sicher?",
                text: "Dieser Eintrag wird dauerhaft gelöscht!",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                cancelButtonColor: "#3085d6",
                confirmButtonText: "Ja, löschen!",
                cancelButtonText: "Abbrechen"
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = deleteUrl; // Führt die Löschung aus
                }
            });
        });
    });
});
