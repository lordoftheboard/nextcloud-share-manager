# Nextcloud Share Manager

Version **0.2.0**: Eigentümerübersicht über Freigaben und Weiterfreigaben im Ordnerbaum.

## Das A–F-Beispiel

A besitzt `Projekt`, gibt es an B frei, B gibt es an C weiter. C teilt `subordner` mit D und E sowie `subordner2` mit F.

Als A zeigt Share Manager alle diese Freigaben mit **Ersteller → Empfänger**, Rechten und der jeweiligen Freigabestelle. Der Baum enthält auch Ordner ohne direkte Freigaben und zeigt geerbte Zugriffe. Eine Benutzerübersicht pro Baum enthält alle lokalen Empfänger einschließlich Gruppenmitgliedern.

Drei Filter sind unabhängig kombinierbar:

- **Von anderen vergeben**: Der Freigabeersteller ist nicht der angemeldete Eigentümer.
- **Unterhalb der Top-Ebene**: Die Freigabe wurde explizit unterhalb des obersten geteilten Ordners angelegt.
- **Von der Top-Ebene abweichend**: Kein identischer Eintrag in den vom Eigentümer selbst vergebenen Top-Freigaben bezüglich Freigabeart, Empfänger, Berechtigungen, Ablaufdatum und vorhandenem Passwortschutz. Unterschiedliche Passwörter werden nicht verglichen oder ausgegeben.

Damit ist B → C auf `Projekt` fremd und abweichend, aber auf der Top-Ebene. C → D auf `subordner` trifft alle drei Filter. Eine zusätzliche Freigabe an B auf einem Unterordner mit exakt gleichen Eigenschaften liegt unterhalb der Top-Ebene, weicht aber nicht ab.

## Hierarchischer Rechteentzug

Wähle unter **Benutzerrechte im gesamten Baum entziehen** einen obersten Ordnerbaum und einen Benutzer. **Entzug prüfen** erstellt eine Vorschau der direkten und weitergegebenen Freigaben an diesen Benutzer über den gesamten Baum. Der ursprüngliche Eigentümer kann diese auch widerrufen, wenn andere Benutzer sie angelegt haben.

Gruppenfreigaben und öffentliche Links erfordern eine ausdrückliche Zusatzwahl: Eine Gruppenfreigabe lässt sich nicht dauerhaft nur für ein einzelnes Mitglied sperren, und ein öffentlicher Link kennt keinen einzelnen Empfänger. Mit der Zusatzwahl werden die betroffenen Gruppenfreigaben für **alle Mitglieder** und **alle öffentlichen Links im Baum** widerrufen. Ohne Zustimmung wird ein vollständiger Entzug blockiert; es erfolgen keine Teiländerungen.

Die Ausführung prüft die Vorschau erneut, einschließlich Gruppenmitgliedschaften. Eine geänderte Liste wird mit HTTP 409 abgelehnt. Anschließend werden tatsächliche Zugriffslisten auf verbleibenden Freigabezugriff geprüft. Nextcloud kann abhängige Weiterfreigaben löschen oder einem anderen Ersteller zuordnen. Fehler während der Ausführung können Teiländerungen hinterlassen; die App meldet bereits widerrufene Freigaben und behauptet dann keinen vollständig geprüften Erfolg.

Das ist ein **Entzug des aktuellen Freigabezugriffs im ausgewählten Baum**, keine dauerhafte Benutzersperre. Neue Freigaben bleiben möglich. Andere Ordnerbäume, heruntergeladene Kopien und andere Berechtigungssysteme werden nicht verändert. Dateien bleiben erhalten; es gibt kein direktes Rückgängigmachen.

## Grenzen

- Verwaltung erfolgt im Eigentümerkontext. Empfänger erhalten keine globale Sicht auf fremde Ordner.
- Der geprüfte Rechteentzug unterstützt lokale Benutzer-, Gruppen- und öffentliche Linkfreigaben. Weitere erkannte Freigabearten werden angezeigt, blockieren aber den vollständigen Entzug.
- Fremde/eigentümerlose Einbindungen und mehr als 5000 Ordner bzw. Freigabestellen führen zu einer ausdrücklichen Ablehnung statt einer unvollständigen Auswertung.
- Kein globaler Ausschluss aus Nextcloud-Gruppen und keine Änderungen an Teamfolder-ACLs.
- Anmeldung, CSRF-Schutz, Eigentümerprüfung und eine Sperre gegen parallele Aktionen dieser App. Andere Nextcloud-Apps beachten diese Sperre nicht; gleichzeitige Änderungen können deshalb eine erneute Prüfung erfordern.
- Kein Passwort oder öffentlicher Linktoken wird an die Oberfläche ausgegeben.

## Anleitung mit Screenshots

[Screen-Dokumentation](docs/SCREEN-DOKU.md) · [PDF](docs/SCREEN-DOKU.pdf) · [HTML](docs/SCREEN-DOKU.html)

## Installation

Voraussetzung: Nextcloud 30–33. Entwicklungsvalidierung erfolgt auf Nextcloud 33 mit PHP 8.4; ältere Versionen wurden nicht separat getestet.

Die fertigen TAR.GZ- und ZIP-Pakete und die SHA-256-Prüfsummen liegen in [`dist`](dist). Die kurze Installationsanleitung steht in [INSTALL.md](INSTALL.md). Ein Paket lässt sich mit `python3 scripts/build.py` ohne weitere Abhängigkeiten erneut erzeugen.

1. Das Archiv `dist/sharemanager-0.2.0.tar.gz` in `custom_apps` entpacken. Alternativ `appinfo`, `lib`, `templates`, `js`, `css`, `img` und `LICENSE` nach `custom_apps/sharemanager` kopieren.
2. Als Webserver-Benutzer `php occ app:enable sharemanager` ausführen. Bei einem Update anschließend `php occ upgrade` ausführen.
3. **Share Manager** im Nextcloud-App-Menü öffnen.

Die App nutzt öffentliche Nextcloud-APIs, benötigt keine eigenen Datenbanktabellen, externen Zugangsdaten oder Frontend-Build-Abhängigkeiten. Sie ist noch nicht für den App Store signiert oder veröffentlicht.

## Entwicklung

Docker-Daemon, Python 3 und Node.js sind erforderlich.

```sh
cd /workspace/nextcloud-share-manager
python3 dev.py up
python3 dev.py test
```

`up` startet eine isolierte Nextcloud-33-Instanz mit SQLite im Container `sharemanager-dev`, kopiert die App, führt erforderliche App-Upgrades aus und aktiviert sie. Port 8080 ist ausschließlich an Loopback gebunden. Der zufällig erzeugte Admin-Zugang wird nicht ausgegeben. Einen lokalen Testnutzer kannst du mit `docker exec -it -u www-data sharemanager-dev php occ user:add developer` erstellen.

Nach Änderungen `python3 dev.py sync`, bei einer Versionsänderung `python3 dev.py up`. Stoppen: `python3 dev.py stop`. Daten bleiben im Container erhalten; lösche ihn nur, wenn die Testdaten nicht mehr benötigt werden. SQLite ist für diese Entwicklungsinstanz vorgesehen.

Die Integrationstests erzeugen echte A–F-Weiterfreigaben, zusätzliche direkte Zugriffswege und Gruppen-/Linkfreigaben. Sie prüfen Tree, Vererbung, Abweichungen, Eigentümerrechte, stale Vorschauen, hierarchischen Entzug und dessen Auswirkungen auf andere Benutzer. HTTP-Tests prüfen Template, Routing und CSRF-Schutz; JavaScript-Tests prüfen alle Filterkombinationen. Temporäre Testnutzer werden entfernt.

Der zusätzliche Browsertest `tests/browser.cjs` wurde mit Playwright und Chromium ausgeführt. Er prüft alle Filter, Suche, Abbrechen, eine ungültige Serverantwort, eine geänderte Vorschau und den echten Entzug für D bei erhaltenem Zugriff von E/F. Er erstellt die Screenshots für die Doku. Dafür benötigt er einen nur lokal erreichbaren CDP-Browser auf Port 9222 und eine private temporäre JSON-Datei mit den von `tests/browser_fixture.php` erzeugten Demo-Zugängen. Zugangsdaten nicht in Logs ausgeben oder ins Repository übernehmen; die Demo-Benutzer nach der Prüfung entfernen. Dieser optionale Browsertest gehört nicht zum normalen `dev.py test`-Lauf.

## Lizenz

AGPL-3.0 (siehe LICENSE).
