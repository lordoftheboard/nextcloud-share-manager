# Share Manager 0.2.0 installieren

Voraussetzung: Nextcloud 30–33, PHP mindestens 8.1 (zusätzlich gelten die PHP-Anforderungen deiner Nextcloud-Version). Getestet mit Nextcloud 33/PHP 8.4. Die App ist für die manuelle Installation vorgesehen und nicht für den App Store signiert.

1. `sharemanager-0.2.0.tar.gz` oder die ZIP-Datei herunterladen und optional mit `SHA256SUMS` prüfen.
2. Das Archiv im konfigurierten Nextcloud-App-Verzeichnis entpacken, üblicherweise `custom_apps`. Es muss anschließend `custom_apps/sharemanager/appinfo/info.xml` geben. Keine zusätzliche Verzeichnisebene anlegen.
3. Lesezugriff für den Nextcloud-Webserver sicherstellen. Bei einem Update vorhandene App-Dateien vorher sichern und den bisherigen App-Ordner durch das neue Paket ersetzen.
4. Im Nextcloud-Hauptverzeichnis als Webserver-Benutzer aktivieren:

```sh
sudo -u www-data php occ app:enable sharemanager
```

Bei einem Update einer bereits installierten Version zuerst nach dem Austausch der Dateien ausführen:

```sh
sudo -u www-data php occ upgrade
sudo -u www-data php occ app:enable sharemanager
```

Passe `www-data` an deinen Webserver-Benutzer an. Bei Docker führe `php occ ...` innerhalb deines Nextcloud-Containers mit dessen Webserver-Benutzer aus.

5. Als Eigentümer eines geteilten Ordners anmelden und **Share Manager** im App-Menü öffnen.

## Umfang

Die App zeigt eigene Ordnerbäume einschließlich Weiterfreigaben anderer Benutzer. Drei kombinierbare Filter markieren fremde Freigabeersteller, Freigaben unterhalb der Top-Ebene und Abweichungen von den eigenen Top-Freigaben.

Der hierarchische Rechteentzug prüft eine Vorschau und den verbliebenen Freigabezugriff. Gruppenfreigaben und öffentliche Links erfordern eine zusätzliche Zustimmung: Ihr Widerruf betrifft weitere Benutzer. Neue Freigaben bleiben später möglich. Dateien bleiben erhalten. Weitere Freigabearten und nicht vollständig prüfbare Einbindungen können den vollständigen Entzug blockieren.

Die ausführliche Screen-Dokumentation liegt im GitHub-Repository unter `docs/SCREEN-DOKU.html`.
