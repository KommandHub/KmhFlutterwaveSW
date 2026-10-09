# 0.9.0-beta.2

Vorabversion für interne Entwicklung, QA und Sandbox-/Staging-Tests. Behebt
Fehler, die bei End-to-End-Tests von `0.9.0-beta.1` in der Sandbox gefunden
wurden; mehrere davon sind sicherheitsrelevant, ein Update wird dringend empfohlen.

**Update von 0.9.0-beta.1:** Der technische Name des Plugins wurde von
`KommandhubFlutterwaveSW` in `KmhFlutterwaveSW` geändert, daher behandelt
Shopware es als neues Plugin. Deinstallieren Sie das alte Plugin, installieren
und aktivieren Sie `KmhFlutterwaveSW` und geben Sie die Plugin-Einstellungen
(API-Schlüssel, Webhook-Secret-Hash) erneut ein. Zahlungsart und bestehende
Bestellungen bleiben erhalten; unter beta.1 gespeicherte Bankdaten von Kunden
werden nicht übernommen und müssen neu eingegeben werden.

- Sicherheit: Eine Zahlung wird nur noch für die Bestellung akzeptiert, für die sie erstellt wurde. Bisher konnte eine frühere erfolgreiche Zahlung mit gleichem Betrag verwendet werden, um eine andere Bestellung als bezahlt zu markieren.
- Sicherheit: `refund.completed`-Webhooks bestätigen den Rückerstattungsstatus jetzt bei Flutterwave, statt dem Webhook-Inhalt zu vertrauen.
- Sicherheit: Der Checkout wird mit einer verständlichen Meldung blockiert, wenn der API-Schlüssel nicht zum Modus passt (Test-Schlüssel im Live-Modus oder Live-Schlüssel im Sandbox-Modus).
- Sicherheit: Die Bankkontoprüfung ist begrenzt (10 Abfragen pro Kunde und Stunde), und gespeichert werden kann nur das von Flutterwave geprüfte Konto mit dem von Flutterwave gelieferten Namen.
- Behoben: Kunden landeten nach 3-D Secure auf einer Fehlerseite, obwohl die Zahlung erfolgreich war. Flutterwave leitet jetzt auf eine eigene, manipulationssichere Rücksprungadresse weiter.
- Behoben: Jeder `refund.completed`-Webhook schlug fehl, sodass Rückerstattungen in Shopware nie abgeschlossen wurden.
- Behoben: Die Prüfung gegen Überrückerstattung berücksichtigte frühere Rückerstattungen nicht; sie liest jetzt den vollständigen Rückerstattungsverlauf von Flutterwave.
- Behoben: Rückerstattungen, die Flutterwave sofort abschließt, werden direkt abgeschlossen, statt auf einen Webhook zu warten.
- Behoben: Ein nur für einen Verkaufskanal konfigurierter Webhook-Secret-Hash wird jetzt akzeptiert.
- Behoben: Das Formular zur Bankkontoprüfung im Kundenkonto wurde nicht geladen.
- Behoben: Unübersetzte Textbausteine im Bankdaten-Formular und im Checkout.
- Behoben (Administration): Weitere Rückerstattungen nach einer Teilrückerstattung sind möglich; der Rückerstattungsdialog schließt sich nach Erfolg; eine Rückerstattung kann nicht mehr doppelt ausgelöst werden; der Transaktionsstatus wird direkt nach einer Rückerstattung aktualisiert.
- Aufrufe an Flutterwave brechen jetzt nach 30 Sekunden ab, statt den Checkout zu blockieren.
- Unterstützt Shopware 6.6 und 6.7.

# 0.9.0-beta.1

Vorabversion für interne Entwicklung, QA und Sandbox-/Staging-Tests. Noch
nicht im Shopware Store eingereicht. Die öffentliche API und Namespaces können
sich vor `1.0.0` noch ändern.

- Flutterwave-Zahlung für Shopware 6: Karte, Banküberweisung und Mobile Money.
- Zahlungsprüfung kontrolliert Status, Betrag und Währung, bevor eine Bestellung als bezahlt markiert wird.
- Rückerstattungen aus der Bestelldetailseite, inklusive Teilrückerstattungen, mit Live-Rückerstattungsverlauf und serverseitiger Absicherung gegen Überrückerstattung.
- Eigene Berechtigung „Flutterwave-Rückerstattung", die Rollen zugewiesen werden kann (abhängig von der Bestell-Editor-Berechtigung).
- Webhook-Verarbeitung für `charge.completed` und `refund.completed` mit Signaturprüfung sowie idempotenter, wiederholungssicherer Verarbeitung.
- Bankkontoprüfung im Kundenkonto (Kontoauflösung über Flutterwave), mit optionalem BVN-Feld.
- Beträge werden im Hauptwährungsformat an Flutterwave übermittelt, wie von dessen API erwartet, und mit der jeweiligen Dezimalgenauigkeit exakt verglichen — auch bei Währungen mit null und drei Dezimalstellen (z. B. RWF, UGX, KWD). Das Plugin legt keine Währungen oder Sprachen im Shop an.
- Plugin-Oberfläche auf Englisch, Deutsch und Französisch verfügbar.
- Konfigurierbares Logging (pro Verkaufskanal), Sandbox-/Live-Modus und ein Mindestrückerstattungsbetrag.
- Unterstützt Shopware 6.6 und 6.7.
