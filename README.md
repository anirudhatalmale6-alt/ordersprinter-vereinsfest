# OrderSprinter-Konfiguration – Vereinsfest SG Hüttenfeld

Arbeitsstand der Konfiguration von OrderSprinter 3.0.8 (Kerwe / Vereinsfest).

## Inhalt

| Datei | Was drin ist |
|---|---|
| `patch/` | Codeanpassung „Bonschleuder“: Einzelbons an der Kasse und Sammelbons bei den Bedienungen gleichzeitig. Inklusive Einbauanleitung und Diff. |
| `speisekarte-kerwe.txt` | Komplette Karte im Speisekarten-Format von OrderSprinter: 12 Speisen, 14 Getränke, Pfand, Freibons – mit Zuordnung der Arbeitsbondrucker. Lässt sich in der Administrationsansicht unter *Speisekarte* einfügen. |
| `setup-testinstanz.sh` | Baut die komplette Testinstallation von null auf: Datenbank, Installation, Karte, Rollen, Benutzer, Raum, Konfiguration. Dient als Dokumentation, welche Einstellungen gesetzt werden. |

## Aufbau der Druckerverteilung

| Drucker | Steht | Bekommt |
|---|---|---|
| 1 | Speisenausgabe | Speisen-Arbeitsbons von Tischbestellungen |
| 2 | Getränkestand | Getränke-Arbeitsbons von Tischbestellungen |
| 3 | Kasse | alles, was ohne Tisch verkauft wird (Bonschleuder) |

Die Steuerung dafür:

* Produktgruppen mit Druckerpriorität **RD** (Raumdrucker) und Kategoriedrucker 1 (Speisen) bzw. 2 (Getränke)
* Raum *Festzelt* **ohne** eigenen Raumdrucker → greift der Kategoriedrucker
* Konfigurationswert `togoworkprinter = 3` → Verkauf ohne Tisch geht an die Kasse

## Noch offen

* Wählt die Bedienung einen Tisch aus? Davon hängt die Druckerverteilung ab.
* SumUp: kann das Solo von der SumUp-App auf dem Android-Tablet angesteuert werden?
* Pfandbetrag und Liste der Artikel, die immer mit Pfand laufen
* Automatisches Mitdrucken des Pfandbons (zweite Codeanpassung, noch nicht gebaut)
