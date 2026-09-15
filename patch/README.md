# Anpassungen für OrderSprinter 3.0.8

SG Hüttenfeld – Kerwe / Vereinsfest

Die Datei `queuecontent.php` enthält **zwei** Anpassungen:

1. **Bonschleuder** – Einzelbons an der Kasse, Sammelbons bei den Bedienungen
2. **Pflicht-Pfandbon** – für Flaschengetränke wird der Pfandbon automatisch
   mitgebucht und mitgedruckt

Beide sind über Einträge in der Konfigurationstabelle abschaltbar. Ohne diese
Einträge verhält sich OrderSprinter exakt wie im Original.

---

# 1. Bonschleuder

## Wozu das gut ist

OrderSprinter kann pro Installation entweder **einen Bon je Artikel** drucken
(Einstellung *Nur ein Produkt pro Arbeitsbon*) **oder** einen **Sammelbon je
Bestellung** – aber nicht beides gleichzeitig. Die Einstellung gilt global.

Für das Vereinsfest werden beide Betriebsarten parallel gebraucht:

| Wer bucht | Was gedruckt werden soll | Wohin |
|---|---|---|
| Kasse („Bonschleuder“), Verkauf ohne Tisch | ein Bon **je Artikel** – 5 Bier = 5 Bons | Kassendrucker |
| Bedienung, Bestellung auf einen Tisch | ein **Sammelbon** je Bestellung | Getränke- bzw. Speisenstand |

Diese Anpassung macht die Entscheidung vom **buchenden Benutzer** abhängig
statt von einer globalen Einstellung.

## Wie es funktioniert

In der Konfigurationstabelle der Datenbank (`os_config`) steht unter dem Namen
`singlebonusers` eine mit Komma getrennte Liste von Benutzer-IDs:

```sql
INSERT INTO os_config (name, setting) VALUES ('singlebonusers', '2');
```

* Benutzer, die in der Liste stehen → **Einzelbons** (ein Bon je Artikel)
* alle anderen Benutzer → **Sammelbon** je Bestellung und Kategorie
* Liste leer oder Eintrag fehlt → OrderSprinter verhält sich **exakt wie im
  Original**, es gelten wieder `oneprodworkrecf` / `oneprodworkrecd`

Die ID des Kassenbenutzers steht in der Administrationsansicht unter *Benutzer*
bzw. in der Tabelle `os_user`.

## Einbau

1. `php/queuecontent.php` auf dem Webserver sichern.
2. Die Datei `queuecontent.php` aus diesem Ordner nach `php/queuecontent.php`
   hochladen.
3. Die Zeile `singlebonusers` einmalig in `os_config` eintragen (siehe oben).

Betroffen ist ausschließlich die Funktion `doWorkPrint()` plus eine neue
Hilfsfunktion `isSingleBonUser()` direkt darüber. Wer lieber von Hand editiert,
findet die Änderung in `bonschleuder.diff`.

## Nach einem Versionsupdate von OrderSprinter

Ein Update überschreibt `php/queuecontent.php`. Die Anpassung muss dann **erneut
eingespielt** werden – Schritt 1–2 wiederholen. Der Datenbankeintrag
`singlebonusers` bleibt beim Update erhalten.

Falls sich der Originalcode in einer neuen Version geändert hat, bitte melden,
statt die alte Datei blind zu überschreiben.

## Nebenwirkung: ein Fehler der Originalversion ist mit behoben

In der Originalfassung von 3.0.8 werden die **Getränke-Arbeitsbons komplett
verschluckt**, wenn `oneprodworkrecf = 1` (Speisen einzeln) und
`oneprodworkrecd = 0` (Getränke gesammelt) eingestellt ist – ohne Fehlermeldung.
Ursache ist die Verschachtelung der `if`-Blöcke in `doWorkPrint()`. Diese
Fassung behandelt Speisen und Getränke getrennt und kann den Fehler nicht mehr
auslösen.

## Getestet

Auf einer lokalen OrderSprinter-3.0.8-Installation (PHP 8.3, MariaDB 11) mit
echten Testbestellungen; geprüft wurde jeweils die Druckerwarteschlange:

| Testfall | Erwartet | Ergebnis |
|---|---|---|
| Kasse, ohne Tisch, 5× Pils | 5 Druckjobs, Drucker 3 | 5 Jobs, Drucker 3 ✓ |
| Janine, Tisch 3, 5× Pils + 2× Bratwurst | 1 Getränkejob „5x Pils“ Drucker 2, 1 Speisejob Drucker 1 | genau so ✓ |
| `singlebonusers` leer | Originalverhalten | Originalverhalten ✓ |
| `oneprodworkrecf=1`, `oneprodworkrecd=0` | Getränkebon vorhanden | vorhanden ✓ (Originalfehler behoben) |

---

# 2. Pflicht-Pfandbon für Flaschengetränke

## Regel

* **Getränk in der Flasche** → Pfandbon wird **zwingend** mitgebucht, mitgedruckt
  und berechnet (2,00 €)
* **Getränk im Glas** → passiert automatisch **nichts**; die Person an der
  Bonschleuder entscheidet und bucht den Artikel *Pfandbon Glas* bei Bedarf dazu

## Einstellung

Zwei Einträge in `os_config`:

```sql
-- Artikel-IDs der Flaschengetränke, die Pfand auslösen
INSERT INTO os_config (name, setting) VALUES ('pfandautotriggers', '13,14,15,19,22');
-- Artikel-ID des Pfandartikels, der dazugebucht wird
INSERT INTO os_config (name, setting) VALUES ('pfandautoprodid', '27');
```

Die IDs stehen in der Administrationsansicht bei den Artikeln bzw. in der
Tabelle `os_products`. Ist `pfandautotriggers` leer oder fehlt einer der beiden
Einträge, ist die Automatik abgeschaltet.

## Getestet

| Testfall | Erwartet | Ergebnis |
|---|---|---|
| Kasse: 2× Cola (Flasche) + 1× Pils vom Fass | je Cola ein Bon **und** ein Pfandbon 2,00 €, Pils ohne Pfand | genau so ✓ |
| Bedienung, Tisch 7: 2× Cola + 1× Hefeweizen | Sammelbon mit beiden Pfandposten, Hefeweizen ohne Pfand | genau so ✓ |
| `pfandautotriggers` leer | kein automatisches Pfand | kein Pfand ✓ |
| Gegen die markierte Karte: Cola 0,5 / Wasser 0,5 / Weizen alkoholfrei → Pfand; Wasser 1,0 / Weizen vom Fass → kein Pfand | wie markiert | genau so ✓ |

Die Liste der pfandpflichtigen Artikel entspricht der vom Auftraggeber gelb
markierten Getränkekarte vom 15.09.2026: Cola 0,5 l, Apfelsaftschorle 0,5 l,
Mineralwasser 0,5 l, Pils alkoholfrei 0,33 l, Hefeweizen alkoholfrei 0,5 l.
**Mineralwasser 1,0 l ist bewusst nicht dabei** – es war als einziges der
Wasser/Limo-Getränke nicht markiert (Rückfrage läuft).

## Offen

Zur Zeit greift die Automatik bei **allen** Buchungen, also auch bei den
Bedienungen. Soll sie nur an der Bonschleuder gelten, lässt sie sich mit einer
Zeile auf den Kassenbenutzer einschränken – bitte Bescheid geben.
