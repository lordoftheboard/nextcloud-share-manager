<?php
script('sharemanager', 'main');
style('sharemanager', 'main');
?>
<div id="share-manager">
    <h1>Freigaben im Ordnerbaum</h1>
    <p>Eigene Ordner einschließlich Weiterfreigaben anderer Benutzer. Vergleichsbasis: deine Freigaben an der obersten Freigabeebene.</p>
    <div class="share-toolbar">
        <label for="share-search">Suche</label>
        <input id="share-search" type="search" placeholder="Ordner, Benutzer oder Freigabeersteller" />
        <label for="tree-root">Baum</label>
        <select id="tree-root"><option value="">Alle Bäume</option></select>
        <button id="share-refresh" type="button">Aktualisieren</button>
    </div>
    <fieldset class="share-filters">
        <legend>Freigaben filtern (kombinierbar)</legend>
        <label><input id="filter-foreign" type="checkbox" /> Von anderen vergeben</label>
        <label><input id="filter-below" type="checkbox" /> Unterhalb der Top-Ebene</label>
        <label><input id="filter-deviation" type="checkbox" /> Von der Top-Ebene abweichend</label>
    </fieldset>
    <p id="share-status" role="status" aria-live="polite">Freigaben werden geladen …</p>
    <div id="share-tree" aria-label="Ordnerbaum mit Freigaben"></div>
    <section class="revoke-panel" aria-labelledby="revoke-heading">
        <h2 id="revoke-heading">Benutzerrechte im gesamten Baum entziehen</h2>
        <p>Wähle einen Baum und einen Benutzer. Die Vorschau umfasst alle direkten und weitergegebenen Freigaben an diesen Benutzer, einschließlich Unterordnern.</p>
        <div class="share-toolbar">
            <label for="revoke-root">Ordnerbaum</label><select id="revoke-root"></select>
            <label for="revoke-user">Benutzer</label><select id="revoke-user"></select>
            <button id="revoke-preview" type="button">Entzug prüfen</button>
        </div>
        <label class="shared-access-option"><input id="include-shared-access" type="checkbox" />
            Auch betroffene Gruppenfreigaben und alle öffentlichen Links im Baum widerrufen. Dadurch verlieren weitere Benutzer bzw. alle Linkinhaber diesen Zugriffsweg.
        </label>
        <div id="revoke-plan" aria-live="polite"></div>
        <button id="revoke-confirm" type="button" disabled>Geprüften Entzug ausführen</button>
        <p class="revoke-note">Gruppen und Links können keinen einzelnen Benutzer dauerhaft ausschließen. Neue Freigaben nach dem Entzug bleiben möglich. Dateien werden nicht gelöscht.</p>
    </section>
</div>
